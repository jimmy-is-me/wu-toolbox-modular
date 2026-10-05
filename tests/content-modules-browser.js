/** Optional local Chromium visual/responsive verification; not shipped in ZIP. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');
const php = process.env.WUTM_TEST_PHP || 'php';
const fixture = (mode, view) => execFileSync(php, ['tests/content-modules.php', mode, ...(view ? [view] : [])], { encoding: 'utf8' });
const out = process.env.WUTM_VISUAL_OUTPUT || path.resolve('content-ui-validation');
async function main() {
    fs.mkdirSync(out, { recursive: true });
    const browser = await chromium.launch({ executablePath: process.env.WUTM_TEST_CHROME, headless: true });
    const errors = [];
    let ajaxCalls = 0;
    const page = await browser.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
        if (route.request().url().includes('admin-ajax.php')) {
            ajaxCalls++;
            const payload = route.request().postData() || '';
            const selected = /name="view"\r?\n\r?\n([^\r\n]+)/.exec(payload);
            await route.fulfill({ contentType: 'application/json', body: fixture('data', selected ? selected[1] : 'pages') });
        } else await route.fulfill({ contentType: 'text/html', body: '<html><body style="background:#ccdce0;font:20px sans-serif">Google Maps preview</body></html>' });
    });
    const wpCSS = 'body{margin:20px;background:#f0f0f1;font-family:Arial,sans-serif}.screen-reader-text{position:absolute;clip:rect(1px,1px,1px,1px);width:1px;height:1px;overflow:hidden}input,button,select,textarea{font:inherit}button{cursor:pointer}.button{padding:8px 14px;border:1px solid #c3c4c7;background:#fff;white-space:nowrap;text-decoration:none}';
    const adminCSS = fs.readFileSync('assets/css/admin.css', 'utf8');
    for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 960 });
        await page.goto('https://fixture.test/');
        await page.setContent(fixture('dashboard'));
        await page.addStyleTag({ content: wpCSS + adminCSS });
        await page.getByRole('tab', { name: '已開啟模組' }).click();
        await page.waitForTimeout(230);
        assert.equal(await page.locator('.wutm-card:not([hidden])').count(), 2);
        await page.screenshot({ path: path.join(out, 'dashboard-'+width+'.png'), fullPage: false });
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
        assert.equal(overflow, false, 'Dashboard fits viewport '+width);
        await page.getByRole('tab', { name: '全部模組' }).click();
        await page.getByRole('tab', { name: '全部模組' }).press('End');
        assert.equal(await page.getByRole('tab', { name: '未開啟模組' }).getAttribute('aria-selected'), 'true');
        assert.equal(ajaxCalls, 0, 'Dashboard switching has no AJAX calls');
    }
    for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 960 });
        await page.goto('https://fixture.test/');
        await page.setContent(fixture('overview'));
        await page.addStyleTag({ content: wpCSS + adminCSS });
        await page.locator('[data-view="pages"] .wco-tree-item').first().waitFor();
        await page.getByRole('tab', { name: '商品', exact: true }).click();
        await page.locator('[data-product-body] tr').first().waitFor();
        assert.equal(await page.locator('[data-product-body] tr').count(), 1);
        await page.screenshot({ path: path.join(out, 'overview-'+width+'.png'), fullPage: true });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, 'Overview fits viewport '+width);
        await page.locator('[data-columns-button]').click();
        assert.equal(await page.locator('#wco-column-menu').isVisible(), true);
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('#wco-column-menu').isVisible(), false);
        await page.getByRole('tab', { name: '頁面', exact: true }).click();
        assert.equal(await page.locator('[data-view="pages"] .wco-tree-item').count(), 5);
    }
    for (const width of [1440, 390, 320]) {
        await page.setViewportSize({ width, height: 960 });
        await page.goto('https://fixture.test/');
        await page.setContent(fixture('map-admin'));
        await page.addStyleTag({ content: wpCSS });
        await page.screenshot({ path: path.join(out, 'map-admin-'+width+'.png'), fullPage: true });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, 'Map editor fits viewport '+width);
        await page.setContent(fixture('map'));
        await page.addStyleTag({ content: wpCSS });
        assert.equal(await page.locator('iframe').count(), 2);
        assert.equal(await page.locator('script').count(), 0, 'Maps use no custom front-end JavaScript');
        assert.equal(await page.locator('iframe').first().getAttribute('loading'), 'lazy');
        const height = await page.locator('.wumetax-map').first().evaluate(element => element.getBoundingClientRect().height);
        assert.equal(height, width < 768 ? 320 : 500);
        await page.screenshot({ path: path.join(out, 'maps-'+width+'.png'), fullPage: true });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, 'Maps fit viewport '+width);
    }
    assert.deepEqual(errors, [], 'No browser script errors');
    await browser.close();
    console.log('Chromium RWD passed at 1440/768/390/320px: dashboard tabs, overview interactions, complete map editor and lazy multi-map rendering.');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
