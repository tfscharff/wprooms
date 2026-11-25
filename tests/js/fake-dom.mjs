// Shared helpers for the JS tests: pull the plugin's real inline scripts out of
// reserve-a-room.php and run them in node:vm against a minimal hand-written DOM.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const PHP = readFileSync(fileURLToPath(new URL('../../reserve-a-room.php', import.meta.url)), 'utf8').replace(/\r\n/g, '\n');

// The scripts live in nowdocs (<<<'HTML' ... HTML;), so the PHP text is exactly what the browser gets.
export function extractScript(fnName) {
	const start = PHP.indexOf('function ' + fnName + '(');
	if (start < 0) throw new Error('function not found: ' + fnName);
	const open = PHP.indexOf("<<<'HTML'\n", start);
	const close = PHP.indexOf('\nHTML;', open);
	const body = PHP.slice(open + "<<<'HTML'\n".length, close);
	const s = body.indexOf('<script>');
	const e = body.lastIndexOf('</script>');
	if (s < 0 || e < 0) throw new Error('no <script> in ' + fnName);
	return body.slice(s + '<script>'.length, e);
}

// 2027-01-28 10:00 local time, so "today" and the booking window are deterministic.
const FIXED = new Date(2027, 0, 28, 10, 0, 0).getTime();
export class FakeDate extends Date {
	constructor(...a) { if (a.length) { super(...a); } else { super(FIXED); } }
	static now() { return FIXED; }
}

export class FakeEl {
	constructor(tag) {
		this.tagName = tag; this.children = []; this.attrs = {}; this.listeners = {};
		this.value = ''; this.textContent = ''; this.className = ''; this.hidden = false;
		this.disabled = false; this.tabIndex = 0; this.checked = false; this.style = {};
		this._html = '';
		const cls = new Set();
		this.classList = { toggle: (c, on) => { if (on === undefined ? !cls.has(c) : on) cls.add(c); else cls.delete(c); }, contains: c => cls.has(c) };
	}
	get innerHTML() { return this._html; }
	set innerHTML(v) { this._html = v; if (v === '') this.children = []; if (this.onHtml) this.onHtml(v); }
	appendChild(c) { this.children.push(c); return c; }
	setAttribute(k, v) { this.attrs[k] = String(v); }
	getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
	addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); }
	fire(t, ev) { (this.listeners[t] || []).forEach(f => f(ev || {})); }
	focus() {}
	scrollIntoView() {}
	querySelectorAll() { return []; }
	querySelector() { return null; }
}

export function walk(el, pred, out = []) {
	if (pred(el)) out.push(el);
	el.children.forEach(c => walk(c, pred, out));
	return out;
}

// Let every pending promise callback run.
export async function flush() { for (let i = 0; i < 20; i++) await new Promise(r => setImmediate(r)); }

export const jsonResponse = (body, ok = true) => ({ ok, json: () => Promise.resolve(body) });

export function runScript(source, sandbox) {
	const ctx = vm.createContext(sandbox);
	new vm.Script(source, { filename: 'plugin-inline-script.js' }).runInContext(ctx);
	return ctx;
}
