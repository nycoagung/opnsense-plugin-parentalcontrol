// The dashboard widget's switch (ParentalControl.js). Run:  node --test tests/WidgetTest.mjs
//
// After a switch is flicked the widget must show what the firewall now has, even when
// the change request gets no answer in time: the dashboard gives a request 5 seconds,
// sends it again, and then gives up, while the firewall may well have applied it.
import test from 'node:test';
import assert from 'node:assert/strict';

// --- what the dashboard provides, reduced to what the widget touches ----------
let answer;                 // (url, data, method) => a Promise, set per test
let calls;                  // every request the widget made
globalThis.BaseWidget = class {
    constructor(config) { this.config = config; }
    ajaxCall(url, data = {}, method = 'GET') {
        calls.push({ url, data, method });
        return answer(url, data, method);
    }
};
// jQuery: each selector records what the widget wrote to it; $('<div>').text(s).html()
// escapes, as the widget's _esc() relies on.
let page;
const node = (selector) => {
    const r = page[selector] || (page[selector] = { css: {} });
    const api = {
        0: undefined,
        html(v) {
            if (v === undefined) {
                return String(r.text ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }
            r.html = v; return api;
        },
        text(v) { if (v === undefined) return r.text; r.text = v; return api; },
        css(k, v) { r.css[k] = v; return api; },
        on() { return api; }, off() { return api; },
        closest() { return node('(none)'); },
    };
    return api;
};
globalThis.document = {};
globalThis.$ = (s) => node(s === globalThis.document ? '(document)' : s === '<div>' ? `<div>${Math.random()}` : s);

const ParentalControl = (await import('../src/opnsense/www/js/widgets/ParentalControl.js')).default;

const status = (blocked, override) => ({
    enabled: true, alias: 'NoInternet', in_alias: blocked ? 1 : 0,
    devices: [{ uuid: 'dev-1', name: 'Tablet-Example', address: '192.0.2.10', is_mac: false, resolved: '192.0.2.10',
                blocked, override, reason: blocked ? 'outside allowed hours' : 'override: allow' }],
});
const fresh = () => {
    page = {}; calls = [];
    const w = new ParentalControl({});
    w.followUpMs = 5;           // the follow-up status read, without the wait
    return w;
};
const pause = (ms) => new Promise((r) => setTimeout(r, ms));
const methods = () => calls.map((c) => c.method);

test('a switch posts the override once, then shows the state the firewall reports', async () => {
    const w = fresh();
    answer = async (url) => (url.includes('setOverride') ? { result: 'saved', override: 'allow' } : status(false, 'allow'));
    await w._setOverride('dev-1', 'allow');
    assert.deepEqual(calls.map((c) => [c.method, c.url]), [
        ['POST', '/api/parentalcontrol/settings/setOverride/dev-1'],
        ['GET', '/api/parentalcontrol/service/status']]);
    assert.equal(JSON.parse(calls[0].data).override, 'allow');
    assert.match(page['.pc-body'].html, /btn-success/);
    assert.match(page['.pc-body'].html, /override: allow/);
    assert.equal(page['.pc-msg small'].text, '0 blocked via NoInternet');
    w.onWidgetClose();
});

test('when the change gets no answer in time, the widget still shows what the firewall has, and says so', async () => {
    const w = fresh();
    answer = async (url) => {
        if (url.includes('setOverride')) throw { textStatus: 'timeout' };
        return status(false, 'allow');      // the firewall applied it all the same
    };
    await w._setOverride('dev-1', 'allow');
    assert.deepEqual(calls.map((c) => c.method), ['POST', 'GET'], 'the status is read after the failed change');
    assert.match(page['.pc-body'].html, /btn-success/, 'the switch shows the state the firewall reports');
    assert.match(page['.pc-msg small'].text, /not confirmed/i, 'the note says the change was not confirmed');
    assert.match(page['.pc-msg small'].text, /what the firewall has now/i);
    w.onWidgetClose();
});

test('a change the firewall refuses is reported with its reason, and the status still read', async () => {
    const w = fresh();
    answer = async (url) => (url.includes('setOverride')
        ? { result: 'failed', message: 'unknown device' } : status(true, 'none'));
    await w._setOverride('dev-1', 'allow');
    assert.deepEqual(methods(), ['POST', 'GET']);
    assert.match(page['.pc-msg small'].text, /refused: unknown device/i);
    w.onWidgetClose();
});

test('when the status cannot be read either, the note does not claim to show the firewall', async () => {
    const w = fresh();
    answer = async () => { throw { textStatus: 'error' }; };
    await w._setOverride('dev-1', 'allow');
    assert.match(page['.pc-body'].html, /Unable to read status/);
    assert.match(page['.pc-msg small'].text, /not confirmed/i);
    assert.doesNotMatch(page['.pc-msg small'].text, /firewall has now/i);
    w.onWidgetClose();
});

test('the status is read again once the background sync has had time, so the block count catches up', async () => {
    const w = fresh();
    let synced = false;         // pf's table changes only when the background sync has run
    answer = async (url) => {
        if (url.includes('setOverride')) return { result: 'saved', override: 'block' };
        const s = status(true, 'block');
        s.in_alias = synced ? 1 : 0;
        return s;
    };
    await w._setOverride('dev-1', 'block');
    assert.equal(page['.pc-msg small'].text, '0 blocked via NoInternet');
    synced = true;
    await pause(30);
    assert.deepEqual(methods(), ['POST', 'GET', 'GET'], 'one more status read after the change');
    assert.equal(page['.pc-msg small'].text, '1 blocked via NoInternet');
    w.onWidgetClose();
});

test('closing the widget cancels the follow-up status read', async () => {
    const w = fresh();
    answer = async (url) => (url.includes('setOverride') ? { result: 'saved', override: 'allow' } : status(false, 'allow'));
    await w._setOverride('dev-1', 'allow');
    w.onWidgetClose();
    await pause(30);
    assert.deepEqual(methods(), ['POST', 'GET']);
});

test('the loading cover is taken down when the change and the refresh are done', async () => {
    const w = fresh();
    answer = async (url) => { if (url.includes('setOverride')) throw { textStatus: 'error' }; return status(true, 'none'); };
    await w._setOverride('dev-1', 'block');
    assert.equal(w._n, 0);
    assert.equal(page['.pc-loading'].css.display, 'none');
    w.onWidgetClose();
});
