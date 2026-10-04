'use strict';
// Actual HTTP requests: output nesting, header privacy, compression and disk stats.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const net = require('node:net');
const zlib = require('node:zlib');
const { spawn } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'wutm-cache-http-'));
const cache = path.join(temporary, 'cache', 'wutm-page-cache');
let server, port, serverLog = '';
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
function request(url, headers = {}, method = 'GET') {
  return new Promise((resolve, reject) => {
    const req = http.request({ hostname: '127.0.0.1', port, path: url, headers, method }, res => {
      const chunks = [];
      res.on('data', chunk => chunks.push(chunk));
      res.on('error', reject);
      res.on('end', () => {
        const raw = Buffer.concat(chunks);
        resolve({ status: res.statusCode, headers: res.headers, raw,
          body: (res.headers['content-encoding'] === 'gzip' ? zlib.gunzipSync(raw) : raw).toString() });
      });
    });
    req.on('error', reject); req.setTimeout(8000, () => req.destroy(new Error('HTTP timeout'))); req.end();
  });
}
async function cachedFiles() {
  await pause(25);
  return fs.existsSync(cache) ? fs.readdirSync(cache).filter(file => file.endsWith('.html.gz')) : [];
}
async function checkCached(url, headers = {}) {
  const first = await request(url, headers);
  assert.equal(first.headers['x-wutm-page-cache'], 'MISS', url + ': first visit');
  const second = await request(url, headers);
  assert.equal(second.headers['x-wutm-page-cache'], 'HIT', url + ': second visit');
  assert.equal(second.body, first.body, url + ': exact complete response');
  assert.ok(second.body.endsWith('</body></html>'), url + ': closing HTML');
  assert.equal(second.headers['content-length'], undefined, url + ': no fixed byte length');
  return second;
}
async function checkBypass(url, reason, headers = {}, method = 'GET') {
  const before = (await cachedFiles()).length;
  const res = await request(url, headers, method);
  assert.equal(res.headers['x-wutm-page-cache'], reason ? 'BYPASS' : undefined, url);
  assert.equal(res.headers['x-wutm-cache-reason'], reason || undefined, url);
  assert.equal((await cachedFiles()).length, before, url + ': no private file');
}
(async () => {
  const finder = net.createServer(); await new Promise(resolve => finder.listen(0, '127.0.0.1', resolve));
  port = finder.address().port; await new Promise(resolve => finder.close(resolve));
  server = spawn(process.env.PHP_BINARY || 'php', ['-d', 'output_buffering=0', '-S', `127.0.0.1:${port}`, 'tests/page-cache-fixture.php'], {
    cwd: root, env: { ...process.env, WUTM_CACHE_TEST_DIR: temporary }, windowsHide: true,
  });
  server.stdout.on('data', data => { serverLog += data; }); server.stderr.on('data', data => { serverLog += data; });
  server.on('error', error => { serverLog += error.message; });
  let ready = false;
  for (let i = 0; i < 50; i++) { await pause(50); try { await request('/test-control/'); ready = true; break; } catch {} }
  assert.ok(ready, 'PHP fixture started: ' + serverLog);
  await checkCached('/normal/');
  await checkCached('/nested-empty/');
  await checkCached('/early-flush/');
  const transformed = await checkCached('/transformed/');
  assert.ok(transformed.body.includes('AFTER-TRANSFORM'), 'Inner theme/WP enhancement transformation saved');
  await checkCached('/normal/', { 'User-Agent': 'iPhone Mobile' });
  await checkCached('/normal/', { 'User-Agent': 'iPad' });
  const devices = JSON.parse((await request('/test-control/')).body).items.filter(item => item.url.endsWith('/normal/')).map(item => item.device).sort();
  assert.deepEqual(devices, ['desktop', 'mobile', 'tablet']);
  const compressed = await checkCached('/outer-gzip/', { 'Accept-Encoding': 'gzip' });
  assert.equal(compressed.headers['content-encoding'], 'gzip', 'Existing PHP compression still works without double compression');
  const zero = await request('/normal/', { 'Accept-Encoding': 'gzip;q=0' });
  assert.equal(zero.headers['content-encoding'], undefined, 'No forced gzip for q=0');
  await checkBypass('/normal/', null, { Cookie: 'test_logged_in=1' });
  await checkBypass('/normal/', 'authorization-header', { Authorization: 'Bearer TEST_ONLY' });
  for (const cookie of ['PHPSESSID=x', 'woocommerce_cart_hash=x', 'woocommerce_recently_viewed=42', 'wordpress_logged_in_test=x', 'wp_woocommerce_session_test=x', 'edd_cart_token=x']) {
    await checkBypass('/normal/', 'private-cookie', { Cookie: cookie });
  }
  await checkBypass('/checkout/', 'excluded-path');
  await checkBypass('/search/', 'dynamic-request');
  await checkBypass('/password/', 'dynamic-request');
  await checkBypass('/not-found/', 'dynamic-request');
  await checkBypass('/server-error/', 'http-status');
  await checkBypass('/normal/?token=secret', 'method-or-query');
  await checkBypass('/normal/', 'method-or-query', {}, 'POST');
  await checkBypass('/en/product/', 'translation-page');
  await checkBypass('/late-private/', 'do-not-cache');
  await checkBypass('/cookie/', 'private-response');
  await checkBypass('/private/', 'private-response');
  await checkBypass('/non-html/', 'private-response');
  await checkBypass('/vary-cookie/', 'private-response');
  await checkBypass('/vary-language/', 'private-response');
  for (const name of ['late-cookie', 'late-flag', 'late-no-cache', 'chunked', 'cleaned', 'large']) {
    const before = (await cachedFiles()).length;
    await request(`/${name}/`);
    assert.equal((await cachedFiles()).length, before, name + ': final-phase rejection');
  }
  const files = await cachedFiles();
  for (const file of files) {
    assert.ok(zlib.gunzipSync(fs.readFileSync(path.join(cache, file))).toString().includes('</html>'), 'Each on-disk gzip is valid and complete');
    assert.equal(JSON.parse(fs.readFileSync(path.join(cache, file + '.json'))).post_id, 42);
  }
  // Recover from corrupt gzip; do not report HIT or render a broken response.
  const normal = files.find(file => JSON.parse(fs.readFileSync(path.join(cache, file + '.json'))).url.endsWith('/normal/') && JSON.parse(fs.readFileSync(path.join(cache, file + '.json'))).device === 'desktop');
  fs.writeFileSync(path.join(cache, normal), 'broken-gzip');
  const recovered = await request('/normal/'); assert.equal(recovered.headers['x-wutm-page-cache'], 'MISS');
  assert.equal((await request('/normal/')).headers['x-wutm-page-cache'], 'HIT');
  fs.utimesSync(path.join(cache, normal), new Date(Date.now() - 65000), new Date(Date.now() - 65000));
  const aged = JSON.parse((await request('/test-control/')).body); assert.equal(aged.expired, 1);
  assert.equal(aged.count, files.length - 1);
  assert.equal((await request('/normal/')).headers['x-wutm-page-cache'], 'MISS', 'TTL expiry rebuilds');
  // Hidden/invalid metadata does not crash the dashboard.
  fs.writeFileSync(path.join(cache, normal + '.json'), 'invalid-json');
  const dashboard = await request('/admin/');
  assert.ok(dashboard.body.includes('無法取得網址'));
  assert.ok(dashboard.body.includes('data-id="wutm-clear-page-cache" href="/wp-admin/admin.php?page=wu-page-cache"'), 'Toolbar parent navigates to settings, not a destructive action');
  for (const tab of ['overview', 'status', 'settings', 'exclusions']) {
    const admin = await request('/admin/?tab=' + tab); assert.equal(admin.status, 200); assert.ok(admin.body.includes('wutm-cache-panel'));
  }
  // Diagnostic sampling is bounded and contains no URL, IP or Cookie values.
  const sample = path.join(cache, '.wutm-last-result.json');
  const beforeSample = fs.readFileSync(sample, 'utf8');
  await request('/normal/?token=secret'); assert.equal(fs.readFileSync(sample, 'utf8'), beforeSample);
  fs.utimesSync(sample, new Date(Date.now() - 31000), new Date(Date.now() - 31000));
  await request('/normal/', { Cookie: 'PHPSESSID=PRIVATE_VALUE' });
  assert.deepEqual(Object.keys(JSON.parse(fs.readFileSync(sample))), ['time', 'status', 'reason']);
  assert.equal(JSON.parse(fs.readFileSync(sample)).reason, 'private-cookie');
  assert.ok(!fs.readFileSync(sample, 'utf8').includes('PRIVATE_VALUE'));
  await request('/test-control/?op=invalidate');
  // The corrupted metadata fixture is intentionally not matched; remove just its two owned files.
  fs.unlinkSync(path.join(cache, normal)); fs.unlinkSync(path.join(cache, normal + '.json'));
  assert.equal((await cachedFiles()).length, 0, 'Related invalidation cleared all matching page/device files');
  await request('/invalidated-during-render/');
  assert.equal((await cachedFiles()).length, 0, 'In-flight render cannot repopulate a cleared generation');
  await checkCached('/after-clear/');
  // Frontend must bypass immediately rather than block behind a cleanup lock.
  const locker = spawn(process.env.PHP_BINARY || 'php', ['-r', "$f=fopen(getenv('WUTM_TEST_LOCK'),'c');flock($f,LOCK_EX);fwrite(STDOUT,'LOCKED');fgets(STDIN);flock($f,LOCK_UN);fclose($f);"], {
    env: { ...process.env, WUTM_TEST_LOCK: path.join(cache, '.wutm-cache.lock') }, windowsHide: true,
  });
  await new Promise((resolve, reject) => { locker.stdout.once('data', resolve); locker.once('error', reject); });
  try {
    const start = Date.now();
    const busy = await request('/after-clear/');
    assert.equal(busy.headers['x-wutm-page-cache'], 'BYPASS');
    assert.equal(busy.headers['x-wutm-cache-reason'], 'directory-or-lock');
    assert.ok(Date.now() - start < 2000, 'Busy cache lock never stalls the frontend');
  } finally { locker.stdin.end('\n'); await new Promise(resolve => locker.once('exit', resolve)); }
  // Impossible directory layout: page still renders and a useful header is sent.
  const backup = cache + '-fixture-backup';
  assert.ok(cache.startsWith(temporary + path.sep) && backup.startsWith(temporary + path.sep));
  fs.renameSync(cache, backup); fs.writeFileSync(cache, 'not-a-directory');
  try {
    const unavailable = await request('/no-directory/');
    assert.equal(unavailable.status, 200); assert.ok(unavailable.body.endsWith('</html>'));
    assert.equal(unavailable.headers['x-wutm-cache-reason'], 'directory-or-lock');
  } finally { fs.unlinkSync(cache); fs.renameSync(backup, cache); }
  // Large directories retain correct totals but only 100 recent table rows.
  for (let i = 0; i < 105; i++) fs.writeFileSync(path.join(cache, `bounded-fixture-${i}.html.gz`), zlib.gzipSync('<html>test</html>'));
  const bounded = JSON.parse((await request('/test-control/')).body);
  assert.equal(bounded.count, 106); assert.equal(bounded.items.length, 100);
  const cleared = JSON.parse((await request('/test-control/?op=clear')).body); assert.equal(cleared.count, 0);
  assert.ok(!fs.readdirSync(cache).some(file => file.endsWith('.tmp') || file.startsWith('.wutm-') && !['.wutm-cache.lock', '.wutm-generation'].includes(file)), 'No temporary/probe/diagnostic leftovers after clear');
  assert.ok(!/PHP (?:Fatal|Warning|Deprecated|Notice)/.test(serverLog), serverLog);
  console.log('Page-cache HTTP checks passed: nested buffers, complete output, device variants, privacy, compression, TTL, diagnostics, atomic writes and invalidation.');
})().catch(error => { console.error(error); console.error(serverLog); process.exitCode = 1; }).finally(async () => {
  if (server && server.exitCode === null) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
  assert.ok(path.basename(temporary).startsWith('wutm-cache-http-') && path.dirname(temporary) === os.tmpdir());
  fs.rmSync(temporary, { recursive: true, force: true }); // Only this test's mkdtemp-owned directory.
});
