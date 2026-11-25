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
const render = (layout = 'inline', optOut = false) => {
    const result = spawnSync(process.env.WUTM_TEST_PHP || 'php', [], { encoding:'utf8', input:`<?php
        define('ABSPATH', __DIR__);
        function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
        function esc_attr($s) { return esc_html($s); }
        function esc_url($s) { return esc_html($s); }
        $layout=${JSON.stringify(layout)};
        $appearance=${JSON.stringify(optOut ? 'dark' : 'light')};
        $compact=${optOut ? 'true' : 'false'};
        $settings=['title'=>'Cookie 與隱私偏好','description'=>'Cookie 分類','reject'=>'拒絕非必要','accept'=>'同意全部','preferences'=>'偏好設定','save'=>'儲存偏好'];
        $url='/privacy';
        require ${JSON.stringify(path.join(moduleDir, 'templates/consent-banner.php').replaceAll('\\', '/'))};
    `});
    assert.equal(result.status, 0, result.stderr);
    return '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/consent.css"></head><body>' + result.stdout +
        '<form><label>結帳姓名<input name="checkout-name"></label></form>' +
        '<script>window.wutmAtmPrivacy=' + JSON.stringify({url:endpoint,page:{kind:'page'}}) + '</script>' +
        '<script src="/events.js"></script><script src="/consent.js"></script></body></html>';
};

const external = /www\.googletagmanager\.com|connect\.facebook\.net/;
async function browserVisitor(browser, layout = 'inline', optOut = false, width = 390, delay = 0, matching = false, gtmMode = 'both', failGtm = false) {
    const context = await browser.newContext({ viewport:{width,height:844} });
    const page = await context.newPage();
    const fixture = native ? '' : render(layout,optOut);
    let state = {chosen:false, defaults:optOut, analytics:optOut, marketing:optOut,
        tags:optOut ? {ga4:'G-FIXTURE',ads:'AW-123456',pixel:'123456789',visit:true} : {}, csrf:'fixture', expires:0};
    const scripts = [];
    const api = [];
    const visitRequests = [];
    await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (external.test(url.hostname)) {
            scripts.push(url.href);
            if (failGtm && url.pathname === '/gtm.js') {
                failGtm = false;
                return route.abort('failed');
            }
            return route.fulfill({contentType:'application/javascript',body:''});
        }
        if (url.origin !== base.origin) return route.abort();
        if (native) return route.continue();
        if (url.pathname.endsWith('/admin-ajax.php')) {
            const data = route.request().postDataJSON();
            api.push(data.op);
            if (['visit','visit_match'].includes(data.op)) visitRequests.push(data);
            if (delay) await new Promise(resolve => setTimeout(resolve, delay));
            if (data.op === 'save') {
                state = {...state, chosen:true, defaults:false, analytics:data.analytics, marketing:data.marketing};
                state.tags = {
                    ga4:state.analytics ? 'G-FIXTURE' : '',
                    ads:state.marketing ? 'AW-123456' : '',
                    gtm:(gtmMode === 'analytics' ? state.analytics : state.analytics && state.marketing) ? 'GTM-FIXTURE' : '',
                    pixel:state.marketing ? '123456789' : '', visit:state.marketing, match:state.marketing && matching
                };
            }
            return route.fulfill({json:{success:true,data:data.op === 'visit' ?
                [{id:'fixture-page-view',name:'PageView',data:{}}] : state}});
        }
        const asset = assets[url.pathname.slice(1)];
        if (asset) return route.fulfill({body:asset,contentType:url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript'});
        return route.fulfill({body:fixture,contentType:'text/html; charset=utf-8'});
    });
    return {context,page,scripts,api,visitRequests};
}

(async () => {
    const browser = await chromium.launch({executablePath:process.env.WUTM_TEST_CHROME,headless:true});
    try {
        if (!native) {
            const matching = await browserVisitor(browser, 'inline', false, 390, 0, true);
            const guest = matching.page;
            const writeCookie = () => {
                document.cookie = '_fbp=fb.1.1712345678901.1234567890; path=/; SameSite=Lax';
            };
            await guest.goto(base.href);
            await guest.locator('#wutm-atm-privacy button[data-wutm-privacy-choice=accept]').click();
            await guest.waitForFunction(() => window.fbq?.queue?.some(entry => entry[0] === 'trackSingle'));
            assert.deepEqual(matching.api.filter(op => op.startsWith('visit')), ['visit'],
                'A conversion is sent to the Browser before waiting for the Pixel cookie.');
            await Promise.all([
                guest.waitForResponse(response => response.request().method() === 'POST' && response.request().postDataJSON()?.op === 'visit_match'),
                guest.evaluate(writeCookie)
            ]);
            const [initial, retry] = matching.visitRequests;
            assert.equal(initial.visit, retry.visit);
            assert.deepEqual(initial.page, retry.page);
            assert(!Object.hasOwn(retry, 'fbp'), 'The retry uses the real cookie header and does not post an identifier.');
            const count = matching.visitRequests.length;
            await guest.reload();
            await guest.waitForFunction(() => window.fbq?.queue?.some(entry => entry[0] === 'trackSingle'));
            assert.equal(matching.visitRequests.length, count + 1, 'A returning visitor with the cookie does not retry matching.');
            await matching.context.close();

            const revoked = await browserVisitor(browser, 'inline', false, 390, 0, true);
            await revoked.page.goto(base.href);
            await revoked.page.locator('#wutm-atm-privacy button[data-wutm-privacy-choice=accept]').click();
            await revoked.page.waitForFunction(() => window.fbq?.queue?.some(entry => entry[0] === 'trackSingle'));
            await revoked.page.locator('.wutm-atm-privacy-launcher').click();
            await revoked.page.locator('#wutm-atm-privacy-dialog').getByRole('button',{name:'拒絕非必要'}).click();
            await revoked.page.waitForFunction(() => window.wutmAtmConsent?.marketing === false);
            await revoked.page.evaluate(writeCookie);
            await revoked.page.waitForTimeout(180);
            assert(!revoked.api.includes('visit_match'), 'A late Pixel cookie after revocation does not cause a matching request.');
            await revoked.context.close();
        }
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
        const surface = await dialog.evaluate(el => ({
            border:getComputedStyle(el).borderTopWidth,
            shadow:getComputedStyle(el).boxShadow,
            width:el.getBoundingClientRect().width
        }));
        assert.equal(surface.border, '0px');
        assert.notEqual(surface.shadow, 'none');
        assert(surface.width < 390, 'The preferences dialog keeps a margin at mobile width.');
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

        await page.locator('.wutm-atm-privacy-launcher').click();
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

        await page.locator('.wutm-atm-privacy-launcher').click();
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
        if (!native) {
            const gtmVisitor = await browserVisitor(browser,'inline',false,1440,0,false,'analytics',true);
            const gtmPage = gtmVisitor.page;
            const container = 'script[src*="gtm.js?id=GTM-FIXTURE"]';
            const gtmRequest = request => request.url().includes('gtm.js?id=GTM-FIXTURE');
            await gtmPage.goto(base.href);
            await gtmPage.locator('#wutm-atm-privacy').getByRole('button',{name:'偏好設定'}).click();
            const prefs = gtmPage.locator('#wutm-atm-privacy-dialog');
            const save = prefs.getByRole('button',{name:'儲存偏好'});
            await prefs.locator('[name=analytics]').check();
            await Promise.all([gtmPage.waitForEvent('requestfailed',{predicate:gtmRequest}), save.click()]);
            await gtmPage.waitForFunction(selector => !document.querySelector(selector), container);
            const launcher = gtmPage.getByRole('button',{name:'Cookie 設定'});
            await launcher.click();
            await Promise.all([gtmPage.waitForEvent('requestfinished',{predicate:gtmRequest}), save.click()]);
            await launcher.click();
            await save.click();
            await prefs.waitFor({state:'hidden'});
            assert.equal(await gtmPage.locator(container).count(), 1);
            assert.equal(gtmVisitor.scripts.filter(url => url.includes('gtm.js?id=GTM-FIXTURE')).length, 2);
            assert.equal(await gtmPage.evaluate(() => window.dataLayer.filter(item => item.event === 'gtm.js').length), 1);
            assert.equal(await gtmPage.evaluate(() => window.wutmAtmConsent.marketing), false);
            assert(gtmVisitor.scripts.some(url => url.includes('gtag/js?id=G-FIXTURE')));
            await gtmVisitor.context.close();

            for (const layout of ['inline','bar','corner','center','side']) {
                const visitor = await browserVisitor(browser,layout,true,layout === 'bar' ? 1440 : 390);
                const {page} = visitor;
                await page.goto(base.href);
                const notice = page.locator('#wutm-atm-privacy');
                const launcher = page.getByRole('button',{name:'Cookie 設定'});
                await launcher.waitFor();
                assert.equal(await page.locator('.wutm-atm-privacy-footer').count(), 0);
                assert(!(await notice.isVisible()), 'Default-on visits start collapsed for every saved layout.');
                assert.equal(await launcher.evaluate(el => getComputedStyle(el).position), 'fixed');
                assert((await launcher.boundingBox()).width < 140);
                await page.waitForFunction(() => [...document.scripts].some(script => script.src.includes('fbevents.js')));
                assert(!visitor.api.includes('save'), 'Defaults do not write consent.');
                await launcher.click();
                const prefs = page.locator('#wutm-atm-privacy-dialog');
                await prefs.locator('[data-wutm-privacy-default]').waitFor();
                assert(await prefs.locator('[name=marketing]').isChecked());
                await prefs.getByRole('button',{name:'拒絕非必要'}).click();
                await prefs.waitFor({state:'hidden'});
                assert(!(await notice.isVisible()));
                visitor.scripts.length = 0;
                await page.reload();
                await page.waitForFunction(() => window.wutmAtmConsent?.marketing === false);
                assert.equal(visitor.scripts.length,0,'Saved rejection prevents tracking on return.');
                await visitor.context.close();
            }
            const slow = await browserVisitor(browser,'corner',true,390,1000);
            await slow.page.goto(base.href);
            await slow.page.getByRole('button',{name:'Cookie 設定'}).click();
            const slowDialog = slow.page.locator('#wutm-atm-privacy-dialog');
            await slowDialog.getByRole('button',{name:'拒絕非必要'}).click();
            await slow.page.locator('#wutm-atm-privacy-dialog[aria-busy="true"]').waitFor();
            assert(await slowDialog.getByRole('button',{name:'拒絕非必要'}).isDisabled());
            assert(!(await slowDialog.locator('.wutm-atm-privacy-status').isVisible()));
            assert(!((await slowDialog.textContent()).includes('正在儲存')));
            await slowDialog.waitFor({state:'hidden'});
            assert.deepEqual(slow.api,['state','save'], 'A click during initial loading must save exactly once.');
            assert.equal(slow.scripts.length,0,'Early refusal must not start tracking while its save is pending.');
            assert(await slow.page.getByRole('button',{name:'Cookie 設定'}).evaluate(el => document.activeElement === el));
            await slow.context.close();
        }
        console.log(native ? 'Cookie consent passed with native WordPress, shared page cache and visitor endpoint security.' :
            'Cookie consent browser checks passed at mobile width.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
