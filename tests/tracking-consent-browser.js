const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const native = process.env.WUTM_ATM_TEST_BASE;
const base = new URL(native || 'https://tracking.example.test');
const moduleDir = path.join(__dirname, '../modules/advanced-tracking-manager');
const endpoint = '/wp-admin/admin-ajax.php?action=wutm_atm_privacy';
const assets = Object.fromEntries(['consent.css', 'consent.js', 'events.js'].map(file =>
    [file, fs.readFileSync(path.join(moduleDir, 'assets', file), 'utf8')]));
const render = () => {
    const result = spawnSync(process.env.WUTM_TEST_PHP || 'php', [], { encoding:'utf8', input:`<?php
        define('ABSPATH', __DIR__);
        function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
        function esc_url($s) { return esc_html($s); }
        $settings=['title'=>'Cookie 與隱私偏好','description'=>'Cookie 分類','reject'=>'拒絕非必要','accept'=>'同意全部','preferences'=>'偏好設定','save'=>'儲存偏好'];
        $url='/privacy';
        require ${JSON.stringify(path.join(moduleDir, 'templates/consent-banner.php').replaceAll('\\', '/'))};
    `});
    assert.equal(result.status, 0, result.stderr);
    return '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/consent.css"></head><body>' + result.stdout +
        '<div class="wutm-atm-privacy-footer"><button data-wutm-privacy-open>Cookie 偏好設定</button></div>' +
        '<form><label>結帳姓名<input name="checkout-name"></label></form>' +
        '<script>window.wutmAtmPrivacy=' + JSON.stringify({url:endpoint,page:{kind:'page'}}) + '</script>' +
        '<script src="/events.js"></script><script src="/consent.js"></script></body></html>';
};

const fixture = native ? '' : render();
const external = /www\.googletagmanager\.com|connect\.facebook\.net/;
async function browserVisitor(browser) {
    const context = await browser.newContext({ viewport:{width:390,height:844} });
    const page = await context.newPage();
    let state = {chosen:false, analytics:false, marketing:false, tags:{}, csrf:'fixture', expires:0};
    const scripts = [];
    const api = [];
    await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (external.test(url.hostname)) {
            scripts.push(url.href);
            return route.fulfill({contentType:'application/javascript',body:''});
        }
        if (url.origin !== base.origin) return route.abort();
        if (native) return route.continue();
        if (url.pathname.endsWith('/admin-ajax.php')) {
            const data = route.request().postDataJSON();
            api.push(data.op);
            if (data.op === 'save') {
                state = {...state, chosen:true, analytics:data.analytics, marketing:data.marketing};
                state.tags = {
                    ga4:state.analytics ? 'G-FIXTURE' : '',
                    ads:state.marketing ? 'AW-123456' : '',
                    gtm:state.analytics && state.marketing ? 'GTM-FIXTURE' : '',
                    pixel:state.marketing ? '123456789' : '', visit:state.marketing
                };
            }
            return route.fulfill({json:{success:true,data:data.op === 'visit' ?
                [{id:'fixture-page-view',name:'PageView',data:{}}] : state}});
        }
        const asset = assets[url.pathname.slice(1)];
        if (asset) return route.fulfill({body:asset,contentType:url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript'});
        return route.fulfill({body:fixture,contentType:'text/html; charset=utf-8'});
    });
    return {context,page,scripts,api};
}

(async () => {
    const browser = await chromium.launch({executablePath:process.env.WUTM_TEST_CHROME,headless:true});
    try {
        const one = await browserVisitor(browser);
        const two = await browserVisitor(browser);
        const {page, scripts} = one;
        await page.goto(base.href);
        const banner = page.locator('#wutm-atm-privacy');
        const dialog = page.locator('#wutm-atm-privacy-dialog');
        await banner.waitFor();
        assert.equal(scripts.length, 0, 'No tracking before a choice.');
        assert.equal(await banner.locator('button').count(), 3);
        assert.equal(await banner.evaluate(el => getComputedStyle(el).position), 'static');
        await banner.getByRole('button', {name:'偏好設定'}).click();
        await dialog.waitFor();
        await dialog.locator('[name=analytics]').check();
        await Promise.all([
            page.waitForRequest(request => request.url().includes('gtag/js?id=G-FIXTURE')),
            dialog.getByRole('button', {name:'儲存偏好'}).click()
        ]);
        await banner.waitFor({state:'hidden'});
        assert(!scripts.some(url => /AW-123456|GTM-FIXTURE|facebook/.test(url)), 'Analytics only must leave Ads, GTM and Meta off.');
        const refreshed = await page.reload();
        await page.waitForFunction(() => window.wutmAtmConsent?.analytics === true);
        assert(!(await banner.isVisible()), 'Returning visitor keeps choice.');
        assert.deepEqual(await page.evaluate(() => ({...window.wutmAtmConsent})), {necessary:true,analytics:true,marketing:false});

        const secondPage = await two.page.goto(base.href);
        await two.page.locator('#wutm-atm-privacy').waitFor();
        assert.equal(two.scripts.length, 0, 'Another visitor cannot inherit consent from a shared page.');
        if (native) {
            assert.equal(refreshed.headers()['x-wutm-page-cache'], 'HIT');
            assert.equal(secondPage.headers()['x-wutm-page-cache'], 'HIT');
            const request = {headers:{origin:base.origin,'X-WUTM-Consent':'1'},data:{op:'state'}};
            const visitorState = await page.request.post(new URL(endpoint, base).href, request);
            assert.match(visitorState.headers()['cache-control'], /\bprivate\b/);
            assert.match(visitorState.headers()['cache-control'], /\bno-store\b/);
            assert.equal(visitorState.headers()['cdn-cache-control'], 'no-store');
            assert((await one.context.cookies()).some(cookie => cookie.name.startsWith('wutm_privacy_') && cookie.httpOnly));
            const stolenProof = (await visitorState.json()).data.csrf;
            const foreignWrite = await two.page.request.post(new URL(endpoint, base).href, {
                ...request, data:{op:'save',csrf:stolenProof,analytics:true,marketing:true}
            });
            assert.equal(foreignWrite.status(), 403);
            const invalidOrigin = await page.request.post(new URL(endpoint, base).href, {
                ...request, headers:{...request.headers,origin:'https://outside.example.test'}
            });
            assert.equal(invalidOrigin.status(), 403);
        }

        await page.locator('.wutm-atm-privacy-footer button').click();
        await dialog.locator('[name=marketing]').check();
        await Promise.all([
            page.waitForRequest(request => request.url().includes('fbevents.js')),
            dialog.getByRole('button',{name:'儲存偏好'}).click()
        ]);
        await dialog.waitFor({state:'hidden'});
        await page.waitForFunction(() => window.wutmAtmConsent?.analytics && window.wutmAtmConsent?.marketing);
        assert(scripts.some(url => url.includes('gtm.js?id=GTM-FIXTURE')), 'GTM can start after the selected categories grant.');
        const ads = await page.evaluate(() => window.dataLayer.some(x => x[0] === 'config' && x[1] === 'AW-123456'));
        assert(ads, 'Google Ads uses marketing control independently of the GA4 loader.');
        assert.equal(scripts.filter(url => url.includes('fbevents.js')).length, 1);

        await page.locator('.wutm-atm-privacy-footer button').click();
        await dialog.getByRole('button', {name:'拒絕非必要'}).click();
        await dialog.waitFor({state:'hidden'});
        await page.waitForFunction(() => window.wutmAtmConsent?.marketing === false && !window.wutmAtmConsent?.analytics);
        assert(await page.evaluate(() => window['ga-disable-G-FIXTURE'] === true));
        assert(await page.evaluate(() => window.fbq.queue.some(x => x[0] === 'consent' && x[1] === 'revoke')));
        scripts.length = 0;
        await page.reload();
        await page.waitForFunction(() => window.wutmAtmConsent?.marketing === false);
        assert(!(await banner.isVisible()), 'A remembered refusal does not reopen the banner.');
        assert.equal(scripts.length, 0);

        await two.page.locator('#wutm-atm-privacy button[data-wutm-privacy-choice=reject]').click();
        await two.page.locator('#wutm-atm-privacy').waitFor({state:'hidden'});
        assert.equal(two.scripts.length, 0);
        console.log(native ? 'Cookie consent passed with native WordPress, shared page cache and visitor endpoint security.' :
            'Cookie consent browser checks passed at mobile width.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
