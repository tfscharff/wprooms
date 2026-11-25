// Tests for the wp-admin editor script (inside rar_editor_markup() in reserve-a-room.php), run as
// the plugin emits it, in node:vm with a minimal fake DOM. Run: node --test tests/js/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { extractScript, FakeDate, FakeEl, flush, jsonResponse, runScript, walk } from './fake-dom.mjs';

const SOURCE = extractScript('rar_editor_markup');

async function boot({ server, saved } = {}) {
	const env = { els: {}, posts: [], flashes: [] };
	env.el = id => env.els[id] || (env.els[id] = new FakeEl('div'));
	const fetch = (url, opts) => {
		if (opts && opts.method === 'POST') {
			env.posts.push(JSON.parse(opts.body));
			return Promise.resolve(saved instanceof Error ? jsonResponse({}, false) : jsonResponse(saved || {}));
		}
		return Promise.resolve(jsonResponse(server || { rules: {}, rooms: [] }));
	};
	const document = { getElementById: env.el, createElement: t => new FakeEl(t), createTextNode: t => { const n = new FakeEl('#text'); n.textContent = t; return n; } };
	runScript(SOURCE, { document, fetch, RAR_ADMIN: { root: '/cfg', nonce: 'N' }, Date: FakeDate, confirm: () => true });
	await flush();
	env.cards = () => env.el('rar-admin-rooms').children.filter(c => c.className === 'card');
	return env;
}

const ROOM = { id: 'r1', name: 'Quiet', capacity: 4, notes: '', hidden: false, hideFrom: '', hideUntil: '' };
// Today in the fake clock is 2027-01-28.
const statusOf = async room => {
	const env = await boot({ server: { rules: {}, rooms: [room] } });
	const card = env.cards()[0];
	return { text: walk(card, e => e.id === 'rar-admin-hide-0')[0].textContent, num: walk(card, e => e.className === 'num')[0].textContent };
};

test('visible room: no hide status, plain title', async () => {
	const s = await statusOf(ROOM);
	assert.equal(s.text, '');
	assert.equal(s.num, 'Room 1');
});

test('hidden with no dates: hidden until unticked', async () => {
	const s = await statusOf({ ...ROOM, hidden: true });
	assert.match(s.text, /^Hidden until you untick “Hide”\. Patrons can’t see or book it then\./);
	assert.equal(s.num, 'Room 1 (hidden)');
});

test('hidden with a range that includes today', async () => {
	const s = await statusOf({ ...ROOM, hidden: true, hideFrom: '2027-01-20', hideUntil: '2027-02-01' });
	assert.match(s.text, /^Hidden from 2027-01-20 through 2027-02-01\./);
	assert.equal(s.num, 'Room 1 (hidden)');
});

test('hiding starts in the future: scheduled', async () => {
	const s = await statusOf({ ...ROOM, hidden: true, hideFrom: '2027-03-01', hideUntil: '2027-03-05' });
	assert.match(s.text, /^Will be hidden from 2027-03-01 through 2027-03-05\./);
	assert.equal(s.num, 'Room 1 (hiding scheduled)');
});

test('hiding period is over: says so', async () => {
	const s = await statusOf({ ...ROOM, hidden: true, hideFrom: '2026-12-01', hideUntil: '2027-01-27' });
	assert.match(s.text, /^This period is over, so patrons can book the room again\./);
	assert.equal(s.num, 'Room 1 (hiding ended)');
});

test('hidden through today counts as still hidden (Until is inclusive); From today is not "will be"', async () => {
	assert.match((await statusOf({ ...ROOM, hidden: true, hideUntil: '2027-01-28' })).text, /^Hidden through 2027-01-28\./);
	assert.match((await statusOf({ ...ROOM, hidden: true, hideFrom: '2027-01-28' })).text, /^Hidden from 2027-01-28 until you untick/);
});

test('rules from the server fill the form; time format shows 12 only for "12"', async () => {
	const env = await boot({ server: { rules: { maxMin: 90, advanceDays: 7, dailyMaxMin: 0, timeFormat: '12', emailDomains: ['a.edu', 'b.edu'] }, rooms: [] } });
	assert.equal(env.el('rar-admin-max').value, 90);
	assert.equal(env.el('rar-admin-adv').value, 7);
	assert.equal(env.el('rar-admin-daily').value, 0);
	assert.equal(env.el('rar-admin-fmt').value, '12');
	assert.equal(env.el('rar-admin-domains').value, 'a.edu\nb.edu');
	const env2 = await boot({ server: { rules: { timeFormat: 'weird' }, rooms: [] } });
	assert.equal(env2.el('rar-admin-fmt').value, '24');
});

test('no rooms: shows the empty-state hint', async () => {
	const env = await boot();
	assert.match(env.el('rar-admin-rooms').children[0].textContent, /No rooms yet/);
});

function setForm(env, v) {
	env.el('rar-admin-max').value = v.max; env.el('rar-admin-adv').value = v.adv;
	env.el('rar-admin-daily').value = v.daily; env.el('rar-admin-fmt').value = v.fmt;
	env.el('rar-admin-domains').value = v.domains;
}

test('save payload: numbers parsed, blanks default, negative daily limit becomes 0, domains split', async () => {
	const env = await boot({ server: { rules: {}, rooms: [ROOM] } });
	setForm(env, { max: '90', adv: '7', daily: '-30', fmt: '12', domains: 'A.edu, b.edu;\nc.edu  ' });
	env.el('rar-admin-save').onclick();
	await flush();
	const rules = env.posts[0].rules;
	assert.equal(rules.maxMin, 90);
	assert.equal(rules.advanceDays, 7);
	assert.equal(rules.dailyMaxMin, 0);
	assert.equal(rules.timeFormat, '12');
	assert.deepEqual(rules.emailDomains, ['A.edu', 'b.edu', 'c.edu']);
	assert.deepEqual(env.posts[0].rooms, [ROOM]);
	setForm(env, { max: '', adv: '', daily: '', fmt: '24', domains: '' });
	env.el('rar-admin-save').onclick();
	await flush();
	assert.deepEqual([env.posts[1].rules.maxMin, env.posts[1].rules.advanceDays, env.posts[1].rules.dailyMaxMin, env.posts[1].rules.emailDomains], [120, 14, 0, []]);
});

test('"Add a room" appends a blank room with the default capacity and it is saved', async () => {
	const env = await boot();
	env.el('rar-admin-add').onclick();
	assert.equal(env.cards().length, 1);
	env.el('rar-admin-save').onclick();
	await flush();
	assert.deepEqual(env.posts[0].rooms, [{ id: '', name: '', capacity: 4, notes: '', hidden: false, hideFrom: '', hideUntil: '' }]);
});

test('editing a number field stores an integer, empty becomes 0; text fields store as typed', async () => {
	const env = await boot({ server: { rules: {}, rooms: [ROOM] } });
	const inputs = walk(env.cards()[0], e => e.tagName === 'input');
	const num = inputs.find(i => i.type === 'number'), txt = inputs.find(i => i.type === 'text');
	num.value = '12abc'; num.oninput();
	txt.value = 'New name'; txt.oninput();
	env.el('rar-admin-save').onclick();
	await flush();
	assert.equal(env.posts[0].rooms[0].capacity, 12);
	assert.equal(env.posts[0].rooms[0].name, 'New name');
	num.value = ''; num.oninput();
	env.el('rar-admin-save').onclick();
	await flush();
	assert.equal(env.posts[1].rooms[0].capacity, 0);
});

test('save status: success, count of reservations on hidden days (singular/plural), and failure', async () => {
	const msg = async saved => {
		const env = await boot({ saved });
		env.el('rar-admin-save').onclick();
		await flush();
		return env.el('rar-admin-status').textContent;
	};
	assert.equal(await msg({ rules: { maxMin: 60 } }), 'Saved — it\'s live. ✓');
	assert.match(await msg({ inHidden: 1 }), /^Saved — it's live\. ✓ 1 upcoming reservation is on a day its room is hidden\./);
	assert.match(await msg({ inHidden: 3 }), /3 upcoming reservations are on a day/);
	assert.equal(await msg(new Error('x')), 'Save failed — please try again or reload.');
});
