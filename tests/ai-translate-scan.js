/* Browser-controller regression tests without a live website or API calls. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/ai-translate-scan.js'), 'utf8');
function fixture(responses) {
    const elements = {};
    function element(id = '') {
        const el = { id, style: {}, disabled: false, hidden: false, textContent: '', listeners: {},
            setAttribute() {}, addEventListener(event, fn) { this.listeners[event] = fn; },
            after(other) { elements[other.id] = other; } };
        if (id) elements[id] = el;
        return el;
    }
    for (const id of ['wu-ait-scan-btn', 'wu-ait-scan-result', 'wu-ait-discovered-count']) element(id);
    elements['wu-ait-discovered-count'].textContent = '5953';
    let requests = 0, active = 0, maxActive = 0;
    const context = { window: { confirm: () => true }, document: { getElementById: id => elements[id], createElement: () => element() },
        wuAitScanConfig: { url: '/wp-admin/admin-ajax.php', nonce: 'nonce' }, FormData, AbortController,
        setTimeout: (fn, ms) => ms === 150 ? setTimeout(fn, 0) : setTimeout(fn, ms), clearTimeout,
        fetch: async (_url, options) => {
            assert.equal(options.body.get('action'), 'wu_ait_scan_sitewide');
            assert.equal(options.credentials, 'same-origin');
            requests++; active++; maxActive = Math.max(maxActive, active);
            const response = responses.shift();
            assert.ok(response, 'unexpected background request');
            if (response.callback) response.callback(elements);
            active--;
            if (response.error) throw response.error;
            return { ok: response.status === undefined || response.status === 200, status: response.status || 200,
                redirected: response.redirected || false,
                text: async () => response.body || JSON.stringify({ success: true, data: response.data }) };
        } };
    vm.runInNewContext(source, context);
    return { context, elements, run: () => context.window.wuAitRunBatchedScan(), requests: () => requests, maxActive: () => maxActive };
}
function job(revision, done = false) { return { token: 'job', revision, done, processed: revision, total: 4, count: done ? 6000 : 0, message: done ? '掃描完成' : '讀取頁面' }; }
(async () => {
    let f = fixture([{ data: job(0) }, { data: job(1) }, { data: job(2) }, { data: job(3) }, { data: job(4, true) }]);
    await f.run();
    assert.equal(f.requests(), 5);
    assert.equal(f.maxActive(), 1);
    assert.equal(f.elements['wu-ait-discovered-count'].textContent, '6000');
    assert.equal(f.elements['wu-ait-scan-progress'].value, 4);
    assert.equal(f.elements['wu-ait-scan-btn'].disabled, false);
    for (const response of [
        { status: 504, body: '<!DOCTYPE html><html>Gateway timeout</html>' },
        { status: 403, body: '<html>Forbidden</html>' },
        { status: 200, redirected: true, body: '<html id="loginform">Login</html>' },
        { status: 500, body: '<html>Fatal error</html>' },
        { status: 403, body: JSON.stringify({ success: false, data: { message: '登入驗證已過期' } }) },
        { error: new TypeError('Failed to fetch') },
        { error: Object.assign(new Error('Timeout'), { name: 'AbortError' }) }
    ]) {
        f = fixture([{ data: job(0) }, response]);
        await f.run();
        assert.equal(f.elements['wu-ait-discovered-count'].textContent, '5953', 'failed scan preserves displayed count');
        assert.ok(f.elements['wu-ait-scan-result'].textContent.includes('掃描未完成'));
        assert.ok(!f.elements['wu-ait-scan-result'].textContent.includes('Unexpected token'));
        assert.equal(f.elements['wu-ait-scan-btn'].textContent, '繼續掃描全站');
    }
    f = fixture([{ data: job(0) }, { data: job(1), callback: elements => elements['wu-ait-scan-pause'].listeners.click() },
        { data: job(1) }, { data: job(4, true) }]);
    await f.run();
    assert.equal(f.requests(), 2, 'pause waits for current page and stops sending requests');
    assert.equal(f.elements['wu-ait-discovered-count'].textContent, '5953');
    await f.run();
    assert.equal(f.elements['wu-ait-discovered-count'].textContent, '6000', 'resume finishes');
    console.log('AI scan progress, pause/resume, JSON/HTTP/login/network errors passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
