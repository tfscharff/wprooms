// Tests for the booking widget script (rar_widget_script() in reserve-a-room.php), run as the
// plugin emits it, in node:vm with a minimal fake DOM. Run: node --test tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { extractScript, FakeDate, FakeEl, flush, jsonResponse, runScript } from './fake-dom.mjs';

const SOURCE = extractScript('rar_widget_script');
const BASE_CFG = { availUrl: 'https://x.test/avail', configUrl: 'https://x.test/config', reserveUrl: 'https://x.test/reserve', nonce: 'N0NCE', fmt12: false, domains: [] };

const AVAIL = {
	date: '2027-01-28', maxMin: 120, dailyMaxMin: 0,
	slots: ['09:00', '09:30', '10:00', '10:30', '13:00'],
	rooms: [{ id: 'r1', name: 'Room <A>', notes: '' }, { id: 'r2', name: 'Room B', notes: '' }],
	taken: { r1: ['10:00'] },
};

async function boot({ cfg = {}, avail = AVAIL, config = { rules: { advanceDays: 14 } }, reserve } = {}) {
	const env = { calls: [], cells: [], dyn: {}, errs: {}, posted: null };
	const ids = ['rar-rooms', 'rar-rooms-grid', 'rar-rooms-form', 'rar-rooms-date', 'rar-rooms-fmt24', 'rar-rooms-fmt12'];
	const stat = {};
	ids.forEach(i => { stat[i] = new FakeEl('div'); });
	const grid = stat['rar-rooms-grid'], form = stat['rar-rooms-form'];
	grid.querySelectorAll = sel => {
		if (sel !== '.slot.free') return [];
		env.cells = (grid.innerHTML.match(/<span class="slot free"[^>]*>/g) || []).map(tag => {
			const c = new FakeEl('span');
			tag.replace(/([\w-]+)="([^"]*)"/g, (_, k, v) => { c.attrs[k] = v; });
			return c;
		});
		return env.cells;
	};
	form.onHtml = () => { env.dyn = {}; env.errs = {}; };
	const go = new FakeEl('button'), note = new FakeEl('div');
	form.querySelector = sel => {
		const m = /^\[data-e="(\w+)"\]$/.exec(sel);
		if (m) return env.errs[m[1]] || (env.errs[m[1]] = new FakeEl('div'));
		if (sel === '.go') return go;
		if (sel === '.note') return note;
		return null;
	};
	env.go = go; env.note = note; env.form = form; env.grid = grid; env.date = stat['rar-rooms-date'];
	env.el = id => stat[id] || env.dyn[id] || (env.dyn[id] = new FakeEl('div'));
	class FormData { constructor() { this.m = {}; } append(k, v) { this.m[k] = v; } }
	const fetch = (url, opts) => {
		env.calls.push({ url, opts });
		if (url.startsWith(BASE_CFG.configUrl)) return config === null ? Promise.reject(new Error('offline')) : Promise.resolve(jsonResponse(config));
		if (url.startsWith(BASE_CFG.availUrl)) return Promise.resolve(jsonResponse(avail, !avail.error));
		if (url.startsWith(BASE_CFG.reserveUrl)) { env.posted = opts.body.m; return Promise.resolve(reserve || jsonResponse({ ok: true })); }
		return Promise.reject(new Error('unexpected fetch ' + url));
	};
	const CFG = { ...BASE_CFG, ...cfg };
	runScript(SOURCE, { window: { RAR_CFG: CFG }, document: { getElementById: env.el }, fetch, FormData, Date: FakeDate });
	await flush();
	return env;
}

const headers = html => [...html.matchAll(/<th scope="col">([^<]*)<\/th>/g)].map(m => m[1]);

test('script comes from the plugin and is a self-contained closure', () => {
	assert.ok(SOURCE.includes('rar-rooms-grid') && SOURCE.includes('function dispTime'));
});

test('does nothing when the shortcode config or markup is missing', async () => {
	const ctxEnv = { fetched: false };
	runScript(SOURCE, { window: {}, document: { getElementById: () => null }, fetch: () => { ctxEnv.fetched = true; }, Date: FakeDate });
	assert.equal(ctxEnv.fetched, false);
});

test('date window: min is today, max is today + advanceDays, value is today', async () => {
	const env = await boot({ config: { rules: { advanceDays: 14 } } });
	assert.equal(env.date.min, '2027-01-28');
	assert.equal(env.date.value, '2027-01-28');
	assert.equal(env.date.max, '2027-02-11'); // crosses a month end
});

test('date window: missing or zero advanceDays falls back to 14; large values are used as given', async () => {
	assert.equal((await boot({ config: { rules: {} } })).date.max, '2027-02-11');
	assert.equal((await boot({ config: {} })).date.max, '2027-02-11');
	assert.equal((await boot({ config: { rules: { advanceDays: 90 } } })).date.max, '2027-04-28');
});

test('availability is requested for today with an encoded date and a cache-buster', async () => {
	const env = await boot();
	const u = env.calls.find(c => c.url.startsWith(BASE_CFG.availUrl)).url;
	assert.match(u, /^https:\/\/x\.test\/avail\?date=2027-01-28&v=\d+$/);
});

test('availability URL that already has a query string gets &', async () => {
	const env = await boot({ cfg: { availUrl: 'https://x.test/avail?rest_route=/a' } });
	assert.ok(env.calls.some(c => /^https:\/\/x\.test\/avail\?rest_route=\/a&date=2027-01-28&v=\d+$/.test(c.url)));
});

test('24-hour headers are the raw HH:MM slot values', async () => {
	const env = await boot();
	assert.deepEqual(headers(env.grid.innerHTML), AVAIL.slots);
});

test('12-hour display: AM/PM, noon and midnight, no leading zero', async () => {
	const slots = ['00:00', '00:30', '09:05', '11:30', '12:00', '12:30', '13:00', '23:30'];
	const env = await boot({ cfg: { fmt12: true }, avail: { ...AVAIL, slots, taken: {} } });
	assert.deepEqual(headers(env.grid.innerHTML), ['12:00 AM', '12:30 AM', '9:05 AM', '11:30 AM', '12:00 PM', '12:30 PM', '1:00 PM', '11:30 PM']);
	assert.equal(env.el('rar-rooms-fmt12').getAttribute('aria-pressed'), 'true');
	assert.equal(env.el('rar-rooms-fmt24').getAttribute('aria-pressed'), 'false');
});

test('toggle is display-only: switching redraws the grid but data-start stays 24-hour', async () => {
	const env = await boot();
	env.el('rar-rooms-fmt12').fire('click');
	assert.deepEqual(headers(env.grid.innerHTML).slice(-1), ['1:00 PM']);
	assert.match(env.grid.innerHTML, /data-start="13:00" aria-label="Book Room &lt;A&gt; at 1:00 PM, 2027-01-28"/);
	env.el('rar-rooms-fmt24').fire('click');
	assert.deepEqual(headers(env.grid.innerHTML).slice(-1), ['13:00']);
	assert.equal(env.el('rar-rooms-fmt24').getAttribute('aria-pressed'), 'true');
});

test('names are HTML-escaped in the grid', async () => {
	const env = await boot();
	assert.ok(env.grid.innerHTML.includes('Room &lt;A&gt;'));
	assert.ok(!env.grid.innerHTML.includes('Room <A>'));
});

test('taken and past slots are not offered; the rest are', async () => {
	const env = await boot({ avail: { ...AVAIL, nowMin: 9 * 60 + 30 } }); // 09:30 now: 09:00 is past
	const free = r => env.cells.filter(c => c.attrs['data-room'] === r).map(c => c.attrs['data-start']);
	assert.deepEqual(free('r1'), ['09:30', '10:30', '13:00']); // 10:00 taken
	assert.deepEqual(free('r2'), ['09:30', '10:00', '10:30', '13:00']);
	assert.equal((env.grid.innerHTML.match(/title="Past"/g) || []).length, 2);
	assert.equal((env.grid.innerHTML.match(/title="Booked"/g) || []).length, 1);
});

test('closed day, no rooms and hidden rooms each get their own message', async () => {
	assert.match((await boot({ avail: { ...AVAIL, slots: [] } })).grid.innerHTML, /library is closed/);
	assert.match((await boot({ avail: { ...AVAIL, rooms: [] } })).grid.innerHTML, /No rooms are set up yet/);
	assert.match((await boot({ avail: { ...AVAIL, rooms: [], roomsHidden: true } })).grid.innerHTML, /No rooms are available to book on this date/);
});

test('daily limit wording: whole hours, singular hour, otherwise minutes', async () => {
	const say = async m => (await boot({ avail: { ...AVAIL, dailyMaxMin: m } })).grid.innerHTML.match(/up to ([^<]*?) per day/)[1];
	assert.equal(await say(30), '30 minutes');
	assert.equal(await say(60), '1 hour');
	assert.equal(await say(90), '90 minutes');
	assert.equal(await say(120), '2 hours');
	assert.equal(await say(480), '8 hours');
	assert.ok(!(await boot()).grid.innerHTML.includes('per day'));
});

test('a failed availability call shows the server message, escaped', async () => {
	const env = await boot({ avail: { error: true, message: 'Hours <not> set' } });
	assert.match(env.grid.innerHTML, /Hours &lt;not&gt; set/);
});

test('a failed config call says booking is unavailable', async () => {
	const env = await boot({ config: null });
	assert.match(env.grid.innerHTML, /temporarily unavailable/);
});

function click(env, room, start) {
	env.cells.find(c => c.attrs['data-room'] === room && c.attrs['data-start'] === start).fire('click');
}
const optionsOf = html => [...html.matchAll(/<option value="(\d+)">([^<]*)<\/option>/g)].map(m => [m[1], m[2]]);

test('length options: up to maxMin in 30-minute steps, with start and end times', async () => {
	const env = await boot({ avail: { ...AVAIL, slots: ['09:00', '09:30', '10:00', '10:30', '11:00'], taken: {} } });
	click(env, 'r1', '09:00');
	assert.deepEqual(optionsOf(env.form.innerHTML), [
		['30', '30 min (09:00 – 09:30)'], ['60', '60 min (09:00 – 10:00)'],
		['90', '90 min (09:00 – 10:30)'], ['120', '120 min (09:00 – 11:00)'],
	]);
});

test('length options stop at a taken slot and at the end of open slots', async () => {
	const env = await boot();
	click(env, 'r1', '09:00'); // 10:00 taken in r1 -> 09:00 and 09:30 only
	assert.deepEqual(optionsOf(env.form.innerHTML).map(o => o[0]), ['30', '60']);
	click(env, 'r2', '10:30'); // next slot 13:00 is not contiguous: 11:00 is not an open slot
	assert.deepEqual(optionsOf(env.form.innerHTML).map(o => o[0]), ['30']);
});

test('length options respect a smaller maxMin and the 12-hour toggle', async () => {
	const env = await boot({ avail: { ...AVAIL, maxMin: 60, slots: ['11:30', '12:00', '12:30', '13:00'], taken: {} } });
	click(env, 'r2', '11:30');
	assert.deepEqual(optionsOf(env.form.innerHTML).map(o => o[0]), ['30', '60']);
	env.el('rar-rooms-fmt12').fire('click'); // open form is redrawn in 12-hour time
	assert.deepEqual(optionsOf(env.form.innerHTML), [['30', '30 min (11:30 AM – 12:00 PM)'], ['60', '60 min (11:30 AM – 12:30 PM)']]);
	assert.match(env.form.innerHTML, /starting <strong>11:30 AM<\/strong>/);
});

async function openAndFill(env, { first = 'Ada', last = 'Lovelace', email = 'ada@a.edu', dur = '60' } = {}) {
	click(env, 'r2', '13:00');
	env.el('rar-rf-first').value = '  ' + first + ' ';
	env.el('rar-rf-last').value = last;
	env.el('rar-rf-email').value = ' ' + email + ' ';
	env.el('rar-rf-dur').value = dur;
	env.el('rar-rf').fire('submit', { preventDefault() {} });
	await flush();
}

test('form validation: required names and a valid email block the request', async () => {
	const env = await boot();
	await openAndFill(env, { first: '', last: '  ', email: 'nope' });
	assert.equal(env.errs.first.textContent, 'Required.');
	assert.equal(env.errs.last.textContent, 'Required.');
	assert.equal(env.errs.email.textContent, 'Enter a valid email.');
	assert.equal(env.posted, null);
});

test('email shape: needs one @, a dot after it and no spaces', async () => {
	for (const bad of ['a@b', 'a b@c.d', '@c.d', 'a@@c.d', 'a@c.d e']) {
		const env = await boot();
		await openAndFill(env, { email: bad });
		assert.equal(env.errs.email.textContent, 'Enter a valid email.', bad);
	}
});

test('submit sends 24-hour start even in 12-hour display, with trimmed fields', async () => {
	const env = await boot({ cfg: { fmt12: true } });
	await openAndFill(env);
	assert.deepEqual(env.posted, {
		_wpnonce: 'N0NCE', rar_hp: '', room_id: 'r2', date: '2027-01-28', start: '13:00',
		duration: '60', first: 'Ada', last: 'Lovelace', email: 'ada@a.edu',
	});
	assert.ok(env.calls.filter(c => c.url.startsWith(BASE_CFG.availUrl)).length >= 2, 'grid reloads after booking');
});

test('server error message is shown; "just booked" reloads the grid', async () => {
	const env = await boot({ reserve: jsonResponse({ message: 'Someone just booked that.' }, false) });
	const before = env.calls.length;
	await openAndFill(env);
	assert.match(env.note.innerHTML, /<strong>Someone just booked that\.<\/strong>/);
	assert.ok(env.calls.length > before + 1);
	assert.equal(env.go.disabled, false);
});

test('allowed-domain mirror: exact, subdomain and case-insensitive match; lookalikes rejected', async () => {
	const accepts = {
		'a@wheaton.edu': true, 'a@WHEATON.EDU': true, 'a@lib.wheaton.edu': true, 'a@x.y.wheaton.edu': true,
		'a@notwheaton.edu': false, 'a@wheaton.edu.evil.com': false, 'a@wheaton.com': false, 'a@edu': false,
	};
	for (const [email, ok] of Object.entries(accepts)) {
		const env = await boot({ cfg: { domains: ['wheaton.edu'] } });
		await openAndFill(env, { email });
		assert.equal(env.posted !== null, ok, email);
		if (!ok) assert.equal(env.errs.email.textContent, email === 'a@edu' ? 'Enter a valid email.' : 'Use an @wheaton.edu email address.', email);
	}
});

test('allowed-domain phrase for one, two and three domains; hint shown in the form', async () => {
	const phrase = async d => {
		const env = await boot({ cfg: { domains: d } });
		await openAndFill(env, { email: 'a@other.org' });
		return env.errs.email.textContent;
	};
	assert.equal(await phrase(['a.edu']), 'Use an @a.edu email address.');
	assert.equal(await phrase(['a.edu', 'b.edu']), 'Use an @a.edu or @b.edu email address.');
	assert.equal(await phrase(['a.edu', 'b.edu', 'c.edu']), 'Use an @a.edu, @b.edu, or @c.edu email address.');
	const env = await boot({ cfg: { domains: ['a.edu', 'b.edu'] } });
	click(env, 'r2', '13:00');
	assert.match(env.form.innerHTML, /Use your @a\.edu or @b\.edu address\./);
});

test('no domains configured: any valid email is accepted and no hint is shown', async () => {
	const env = await boot();
	await openAndFill(env, { email: 'anyone@example.org' });
	assert.equal(env.posted.email, 'anyone@example.org');
	assert.ok(!env.form.innerHTML.includes('rar-rf-email-h'));
});
