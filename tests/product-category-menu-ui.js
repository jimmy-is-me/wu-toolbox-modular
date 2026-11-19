const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ executablePath: process.env.WUTM_TEST_CHROME });
    try {
        const php = process.env.WUTM_TEST_PHP || 'php';
        const html = execFileSync(php, ['tests/product-category-menu-tree.php', 'interactive'], { encoding: 'utf8' });
        const page = await browser.newPage({ viewport: { width: 390, height: 760 } });
        await page.setContent(html.replace(/<script[^>]*><\/script>/g, ''));
        await page.addScriptTag({ path: 'modules/product-category-menu/menu.js' });

        const mobile = page.getByRole('button', { name: '商品分類' });
        const search = page.getByRole('searchbox');
        assert.equal(await mobile.getAttribute('aria-expanded'), 'false');
        assert.equal(await search.isVisible(), false);
        await mobile.click();
        assert.equal(await search.isVisible(), true);

        const parent = page.getByRole('button', { name: 'Empty parent' });
        const child = page.getByRole('link', { name: 'Populated child' });
        await parent.click({position:{x:16,y:20}});
        assert.equal(await child.isVisible(), true);
        assert.equal(await page.getByRole('link', {name:'查看全部：Empty parent'}).getAttribute('href'), 'https://example.test/category/1');
        await parent.press('Enter');
        assert.equal(await child.isVisible(), false);
        await search.fill('populated child');
        assert.equal(await child.isVisible(), true);
        assert.equal(await page.getByRole('link', { name: 'First by saved order' }).isVisible(), false);
        await search.fill('no match');
        assert.equal(await page.getByRole('status').isVisible(), true);
        await search.fill('');
        assert.equal(await child.isVisible(), false);
        await mobile.click();
        assert.equal(await search.isVisible(), false);
        await page.setViewportSize({width:1200,height:760});
        assert.equal(await mobile.isVisible(), false);
        assert.equal(await search.isVisible(), false, 'Mobile archive copy is hidden on desktop');
        await page.setContent(execFileSync(php, ['tests/product-category-menu-tree.php', 'inline'], {encoding:'utf8'}).replace(/<script[^>]*><\/script>/g, ''));
        assert.equal(await page.getByRole('searchbox').isVisible(), true, 'Desktop shortcode has top search');
        console.log('Product category menu browser checks passed.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
