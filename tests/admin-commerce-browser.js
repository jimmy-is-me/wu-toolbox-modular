const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
(async () => {
    const url = process.argv[2] || 'http://127.0.0.1:8821/tests/admin-commerce-preview.php';
    const browser = await chromium.launch({ headless: true, executablePath: process.env.WUTM_QA_BROWSER });
    try {
        for (const width of [1280, 768, 390, 320]) {
            const page = await browser.newPage({ viewport: { width, height: 900 } });
            const errors = [], requests = [];
            page.on('pageerror', error => errors.push(error.message));
            page.on('request', request => { if (!request.url().startsWith('http://127.0.0.1:8821/') && !request.url().startsWith('data:')) requests.push(request.url()); });
            page.on('dialog', dialog => dialog.accept());
            await page.goto(url);
            await page.waitForFunction(() => window.jQuery && document.querySelector('.product_data_tabs .active a').getAttribute('href') === '#inventory_product_data');
            assert.equal(await page.locator('#wutm-size-chart-columns>div').count(), 8);
            assert.equal(await page.locator('.wutm-sc-row').count(), 2);
            await page.locator('#wutm-size-chart-label-chest').fill('胸圍（cm）');
            assert.deepEqual(await page.locator('[data-column-key="chest"] .wutm-sc-cell-label').allTextContents(), ['胸圍（cm）', '胸圍（cm）']);
            await page.locator('#wutm-size-chart-add-column').click();
            const custom = page.locator('#wutm-size-chart-columns>div').last();
            await custom.locator('input').fill('新規格');
            await page.locator('#wutm-size-chart-add-row').click();
            assert.equal(await page.locator('.wutm-sc-row').last().locator('input').count(), 9);
            await page.locator('.wutm-sc-row').first().locator('.wutm-size-chart-remove-row').click();
            assert.equal(await page.locator('input[name="size_chart_rows[0][size]"]').inputValue(), 'M');
            await custom.locator('button').click();
            assert.equal(await page.locator('#wutm-size-chart-columns>div').count(), 8);
            assert.equal(await page.locator('.wutm-sc-row').first().locator('input').count(), 8);
            const chartKeys = await page.locator('#chart-fixture').evaluate(form => Array.from(new FormData(form).keys()));
            assert.equal(chartKeys.includes('size_chart_rows[0][chest]'), true);
            assert.equal(chartKeys.includes('wutm_size_chart_note'), true);
            await page.locator('#wutm-size-chart-remove-image').click();
            assert.equal(await page.locator('#wutm-size-chart-image-id').inputValue(), '');
            assert.equal(await page.locator('#wutm-size-chart-image-preview img').count(), 0);
            for (const panel of ['advanced_product_data', 'shipping_product_data', 'linked_product_data']) assert.equal(await page.locator('#' + panel).isVisible(), false);
            await page.locator('a[href="#product_attributes"]').click();
            assert.equal(await page.locator('#product_attributes').isVisible(), true);
            await page.locator('a[href="#variable_product_options"]').click();
            assert.equal(await page.locator('#variable_product_options').isVisible(), true);
            const submitted = await page.locator('#product-fixture').evaluate(form => Object.fromEntries(new FormData(form)));
            assert.equal(submitted['product-type'], 'variable');
            assert.equal(submitted.weight, '1.25');
            assert.equal(submitted['grouped_products[]'], '123');
            assert.equal(submitted.menu_order, '6');
            assert.equal(await page.locator('#product-type option[value="grouped"]').evaluate(node => node.hidden), true);
            await page.locator('#product-type').evaluate(node => { node.value = 'grouped'; node.dispatchEvent(new Event('change', { bubbles: true })); });
            await page.waitForFunction(() => !document.querySelector('#product-type option[value="grouped"]').hidden);
            assert.equal(await page.locator('#product-type').inputValue(), 'grouped');
            assert.equal(await page.locator('#wutm-sp-tracking-number').inputValue(), '001-000012345');
            assert.equal(await page.locator('.wutm-sp-tracking').count(), 1);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, `No horizontal overflow at ${width}px`);
            assert.deepEqual(errors, []); assert.deepEqual(requests, []);
            if (process.argv[3]) {
                fs.mkdirSync(process.argv[3], { recursive: true });
                await page.screenshot({ path: path.join(process.argv[3], `commerce-admin-${width}.png`), fullPage: true });
                await page.locator('.preview').first().screenshot({ path: path.join(process.argv[3], `size-chart-${width}.png`) });
            }
            await page.close();
        }
        console.log('Commerce admin browser tests passed: responsive chart CRUD, tracking, hidden-field preservation and remaining product tabs.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
