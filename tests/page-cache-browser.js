// Optional local visual QA; uses only the isolated PHP fixture, never a live site.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
(async () => {
  const url = process.argv[2] || 'http://127.0.0.1:8823/admin/';
  const browser = await chromium.launch({ headless: true, executablePath: process.env.WUTM_QA_BROWSER });
  const origin = new URL(url).origin;
  try {
    for (const width of [1280, 768, 390, 320]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      const errors = [], external = [];
      page.on('pageerror', e => errors.push(e.message));
      page.on('request', req => { if (!req.url().startsWith(origin + '/')) external.push(req.url()); });
      for (const tab of ['overview', 'status', 'settings', 'exclusions']) {
        await page.goto(url + '?tab=' + tab);
        assert.equal(await page.locator('.wutm-cache-tabs [aria-current=page]').count(), 1);
        assert.equal(await page.locator('[data-id="wutm-clear-page-cache"]').getAttribute('href'), '/wp-admin/admin.php?page=wu-page-cache');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, `${tab} no overflow at ${width}px`);
        if (tab === 'settings') {
          await page.locator('#wutm-cache-ttl').fill('7200');
          await page.locator('input[name=auto_invalidate]').uncheck();
          const data = await page.locator('form').evaluate(form => Object.fromEntries(new FormData(form)));
          assert.equal(data.ttl, '7200'); assert.equal(data.return_tab, 'settings'); assert.equal(data.auto_invalidate, undefined);
        }
        if (tab === 'exclusions') {
          await page.locator('#wutm-cache-excluded').fill('/cart/\n/checkout/\n/private/');
          const data = await page.locator('form').evaluate(form => Object.fromEntries(new FormData(form)));
          assert.equal(data.excluded_uri, '/cart/\n/checkout/\n/private/');
        }
        if (process.argv[3]) {
          fs.mkdirSync(process.argv[3], { recursive: true });
          await page.screenshot({ path: path.join(process.argv[3], `page-cache-${tab}-${width}.png`), fullPage: true });
        }
      }
      assert.deepEqual(errors, []); assert.deepEqual(external, []);
      await page.close();
    }
    console.log('Page-cache browser checks passed: all tabs at 1280/768/390/320 px, toolbar settings link, accessible navigation and preserved form fields.');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
