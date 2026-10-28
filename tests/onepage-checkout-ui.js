const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { chromium } = require('playwright');
const root = path.join(__dirname, '..');
const wp = process.env.WUTM_TEST_WP || 'C:/Users/jimin/Documents/Codex/2026-10-15/new-chat/work/integration/wordpress';
const jquery = fs.readFileSync(path.join(wp, 'wp-includes/js/jquery/jquery.min.js'));
const js = fs.readFileSync(path.join(root, 'assets/js/onepage-checkout.js'));
const source = fs.readFileSync(path.join(root, 'modules/wc-optimization-tools/custom-features.php'), 'utf8');
const css = source.match(/<style>([\s\S]*?)<\/style>/)[1];
let qty = 1, calls = 0, active = 0, maxActive = 0, failOnce = false;
function cart() { return `<div class="wutm-wc-custom-onepage-cart-wrap"><form class="wutm-wc-custom-cart-form" data-update-url="/update" data-security="fixture"><table class="wutm-wc-custom-onepage-cart-table"><tbody><tr><td>商品</td><td><button type="button" class="minus">−</button><input class="qty" type="number" min="0" max="5" name="cart[item][qty]" value="${qty}"><button type="button" class="plus">+</button></td></tr></tbody></table><div class="wutm-wc-custom-onepage-cart-actions"><button name="update_cart">更新購物車</button></div></form></div>`; }
const server = http.createServer((req, res) => {
    if (req.url === '/jquery.js') {res.setHeader('Content-Type','text/javascript');return res.end(jquery);}
    if (req.url === '/app.js') {res.setHeader('Content-Type','text/javascript');return res.end(js);}
    if (req.url === '/update') {
        calls++;active++;maxActive=Math.max(active,maxActive);let body='';
        req.on('data',chunk=>body+=chunk);req.on('end',()=>setTimeout(()=>{
            res.setHeader('Content-Type','application/json');active--;
            if(failOnce){failOnce=false;res.statusCode=400;return res.end(JSON.stringify({success:false,data:{message:'庫存不足，請重試。'}}));}
            qty=Number(new URLSearchParams(body).get('cart[item][qty]'));
            return res.end(JSON.stringify({success:true,data:qty===0?{empty:true,redirect:'/empty'}:{html:cart()}}));
        },180));return;
    }
    if(req.url==='/empty')return res.end('購物車沒有商品');
    res.setHeader('Content-Type','text/html; charset=utf-8');res.end(`<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>${css}input{max-width:100px}body{margin:10px}</style><div class="wutm-wc-onepage-coupons"><form class="checkout_coupon"><label for="coupon_code">折扣碼</label><input id="coupon_code" name="coupon_code"><button>套用折扣碼</button></form></div><p id="wutm-onepage-status" role="status"></p>${cart()}<form class="checkout"><input id="billing_name" value="已填姓名"><button id="place_order">下單</button></form><script src="/jquery.js"></script><script>jQuery(function($){$('form.checkout_coupon').hide().on('submit',function(e){e.preventDefault();$(document.body).trigger('applied_coupon_in_checkout');});$(document).on('click','.plus,.minus',function(){var q=$(this).siblings('input')[0];q.value=Number(q.value)+($(this).hasClass('plus')?1:-1);});window.refreshes=0;$(document.body).on('update_checkout',function(){window.refreshes++;});});</script><script src="/app.js"></script>`);
});
(async()=>{
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const url=`http://127.0.0.1:${server.address().port}`;
    const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME});
    try {
        for(const width of [1280,768,390,320]){
            qty=1;calls=0;maxActive=0;failOnce=false;
            const page=await browser.newPage({viewport:{width,height:900}}), errors=[];page.on('pageerror',e=>errors.push(e.message));
            await page.goto(url);await page.waitForSelector('.wutm-onepage-auto');
            assert(await page.locator('#coupon_code').isVisible());assert(!(await page.locator('[name=update_cart]').isVisible()));
            await page.locator('#coupon_code').fill('SAVE10');await page.locator('#billing_name').fill('不應遺失');
            await page.locator('.plus').click();await page.waitForFunction(()=>document.querySelector('form.wutm-wc-custom-cart-form').getAttribute('aria-busy')==='true');
            assert.equal(await page.evaluate(()=>jQuery('form.checkout').triggerHandler('checkout_place_order')),false);
            await page.locator('.plus').click();
            await page.waitForFunction(()=>document.querySelector('.qty').value==='3'&&window.refreshes>0&&!document.querySelector('form.wutm-wc-custom-cart-form').hasAttribute('aria-busy'));
            assert.equal(qty,3);assert.equal(maxActive,1);assert.equal(calls,2);
            assert.equal(await page.locator('#billing_name').inputValue(),'不應遺失');assert.equal(await page.locator('#coupon_code').inputValue(),'SAVE10');
            failOnce=true;await page.locator('.qty').fill('4');
            try {await page.locator('#wutm-onepage-retry').waitFor({timeout:5000});} catch(error) {throw new Error(JSON.stringify({width,calls,qty,errors,status:await page.locator('#wutm-onepage-status').textContent(),html:await page.locator('.wutm-wc-custom-cart-form').getAttribute('aria-busy')}));}
            assert.equal(qty,3);
            await page.locator('#wutm-onepage-retry').click();await page.waitForFunction(()=>document.querySelector('.qty').value==='4'&&!document.querySelector('#wutm-onepage-retry'));
            assert.equal(qty,4);assert(await page.locator('#coupon_code').isVisible());
            assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
            await page.locator('.qty').fill('0');await page.waitForURL('**/empty');assert.deepEqual(errors,[]);await page.close();
        }
        const context=await browser.newContext({javaScriptEnabled:false});const page=await context.newPage();await page.goto(url);assert(await page.locator('[name=update_cart]').isVisible());await context.close();
        console.log('Onepage browser passed: visible coupon, four widths, auto quantities, serial requests, preserved fields, retry, empty cart and no-JS fallback.');
    } finally {await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;server.close();});
