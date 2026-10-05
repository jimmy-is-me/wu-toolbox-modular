/* Optional headless browser QA of the scoped scan controller at desktop/mobile widths. */
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const fs = require('node:fs'), path = require('node:path');
(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: process.env.WUTM_QA_BROWSER });
    try {
        for (const width of [1280, 768, 390, 320]) {
            const page = await browser.newPage({ viewport: { width, height: 800 } });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            let revision = 0;
            await page.route('https://fixture.test/admin-ajax.php', async route => {
                const start = route.request().postData().includes('start');
                if (!start) revision++;
                await new Promise(resolve => setTimeout(resolve, 80));
                await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {
                    token: 'browser-job', revision, processed: revision, total: 10, done: revision >= 10, count: revision >= 10 ? 6100 : 0,
                    message: revision >= 10 ? '掃描完成！已探索字串：6100 筆。' : '讀取頁面：' + revision + ' / 10 篇；完成前保留舊清單。'
                } }) });
            });
            await page.setContent('<meta charset="utf-8"><style>*{box-sizing:border-box}body{margin:16px;background:#f0f0f1;font:14px sans-serif}main{padding:16px;background:white;border-radius:8px}button{padding:8px;margin:4px;max-width:100%}span{overflow-wrap:anywhere}</style><main><h2>步驟一：掃描全站</h2><p>目前已探索字串總數：<strong id="wu-ait-discovered-count">5953</strong></p><button id="wu-ait-scan-btn">立即分批掃描全站</button><span id="wu-ait-scan-result"></span></main>');
            await page.addScriptTag({ content: 'window.wuAitScanConfig={url:"https://fixture.test/admin-ajax.php",nonce:"nonce"};' });
            await page.addScriptTag({ path: path.join(__dirname, '../assets/js/ai-translate-scan.js') });
            await page.evaluate(() => { window.wuAitRunBatchedScan(); });
            await page.waitForFunction(() => document.getElementById('wu-ait-scan-progress').value > 0);
            await page.click('#wu-ait-scan-pause');
            await page.waitForFunction(() => !document.getElementById('wu-ait-scan-btn').disabled);
            assert.equal(await page.locator('#wu-ait-discovered-count').textContent(), '5953');
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No horizontal overflow at ' + width);
            if (process.env.WUTM_QA_DIR) {
                fs.mkdirSync(process.env.WUTM_QA_DIR, { recursive: true });
                await page.screenshot({ path: path.join(process.env.WUTM_QA_DIR, 'ai-scan-' + width + '.png') });
            }
            await page.evaluate(() => { window.wuAitRunBatchedScan(); });
            await page.waitForFunction(() => document.getElementById('wu-ait-discovered-count').textContent === '6100');
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('Headless scan controller RWD/progress/pause/resume passed at 1280, 768, 390 and 320 px.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
