// Optional local browser QA. Run against the PHP visual fixture with Playwright available.
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const { chromium } = require('playwright');
(async () => {
    const url = process.argv[2] || 'http://127.0.0.1:8821/tests/shipping-progress-preview.php';
    const out = process.argv[3];
    const browser = await chromium.launch({ headless: true, ...(process.env.WUTM_QA_BROWSER ? { executablePath: process.env.WUTM_QA_BROWSER } : {}) });
    try {
        for (const width of [1024, 768, 390, 320]) {
            const page = await browser.newPage({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
            const errors = [];
            const external = [];
            page.on('pageerror', error => errors.push(error.message));
            page.on('request', request => { if (!request.url().startsWith('http://127.0.0.1:8821/')) external.push(request.url()); });
            await page.goto(url);
            assert.equal(await page.locator('.wutm-sp-card').count(), 2, 'Both purchased products visible');
            await page.locator('details.wutm-sp-admin-card summary').first().click();
            const mode = page.locator('.wutm-sp-mode').first();
            await mode.selectOption('inherit');
            assert.equal(await page.locator('details.wutm-sp-admin-card').first().locator('fieldset').evaluate(node => node.disabled), true);
            await mode.selectOption('custom');
            assert.equal(await page.locator('details.wutm-sp-admin-card').first().locator('fieldset').evaluate(node => node.disabled), false);
            const productForm = page.locator('.wutm-sp-product-form').first();
            await productForm.locator('.wutm-sp-toggle').evaluate(node => { node.checked = false; node.dispatchEvent(new Event('change', { bubbles: true })); });
            const submitted = await productForm.evaluate(node => Array.from(new FormData(node).keys()));
            assert.equal(submitted.includes('wutm_sp_product[present]'), true, 'Unchecked group remains explicitly submitted');
            assert.equal(submitted.includes('wutm_sp_product[ship_from]'), false, 'Disabled schedule fields omitted safely');
            assert.equal(await page.locator('.wutm-sp-lookup-form').evaluate(node => getComputedStyle(node).gridTemplateColumns.split(' ').length), width <= 600 ? 1 : 2);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, `No horizontal overflow at ${width}px`);
            assert.deepEqual(errors, [], 'No browser errors');
            assert.deepEqual(external, [], 'No external calls from the progress UI');
            if (out) {
                fs.mkdirSync(out, { recursive: true });
                await page.screenshot({ path: path.join(out, `shipping-progress-${width}.png`), fullPage: true });
            }
            await page.close();
        }
        console.log('Shipping progress browser/RWD checks passed (1024, 768, 390 and 320 px).');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
