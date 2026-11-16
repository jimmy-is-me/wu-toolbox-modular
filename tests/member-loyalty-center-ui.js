const fs = require('node:fs'), assert = require('node:assert/strict'), {execFileSync} = require('node:child_process');
const {chromium} = require('playwright'), jsQR = require('jsqr');
(async () => {
 const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME});
 try {
  const html=execFileSync(process.env.WUTM_TEST_PHP||'php',['tests/member-loyalty-center.php','--html'],{encoding:'utf8'});
  for(const width of [1440,768,390,320]){
   const page=await browser.newPage({viewport:{width,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.setContent('<meta name="viewport" content="width=device-width"><style>body{margin:0;padding:24px;font:16px/1.6 Arial;background:#f6f8fa}body>section,body>div{margin:0 auto 30px!important;max-width:1050px!important}</style>'+html);
   await page.addStyleTag({content:fs.readFileSync('modules/member-loyalty/account.css','utf8')});
   await page.evaluate(()=>{window.copied='';Object.defineProperty(navigator,'clipboard',{value:{writeText:async v=>{window.copied=v;}},configurable:true});});
   await page.addScriptTag({content:fs.readFileSync('modules/wu-ai-card/assets/qrcode.min.js','utf8')});
   await page.addScriptTag({content:fs.readFileSync('modules/member-loyalty/member-card.js','utf8')});
   await page.addScriptTag({content:fs.readFileSync('modules/member-loyalty/member-center.js','utf8')});
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'No overflow at '+width);
   const compare=page.locator('.wutm-tier-comparison');assert.equal(await compare.evaluate(e=>e.open),false);
   await compare.locator('summary').click();assert.equal(await compare.evaluate(e=>e.open),true);
   assert(await compare.getByRole('heading',{name:'金卡會員'}).isVisible());
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Open tiers fit '+width);
   const active=page.locator('.wmt-coupon-item.is-available'),inactive=page.locator('.wmt-coupon-item.is-unavailable');
   assert.equal(await active.count(),1);assert.equal(await inactive.count(),3);
   assert.notEqual(await active.evaluate(e=>getComputedStyle(e).backgroundColor),await inactive.first().evaluate(e=>getComputedStyle(e).backgroundColor));
   await page.locator('[data-coupon-copy]').click();assert.equal(await page.evaluate(()=>window.copied),'ACTIVE-CODE');
   assert.equal(await inactive.locator('button').count(),0);
   assert((await page.locator('[data-member-expires]').textContent()).includes('小時'));
   const qr=await page.locator('.wutm-member-card-qr').evaluate(e=>{const c=e.querySelector('canvas');return {pixels:Array.from(c.getContext('2d').getImageData(0,0,c.width,c.height).data),width:c.width,height:c.height,url:e.dataset.qr};});
   const decoded=jsQR(new Uint8ClampedArray(qr.pixels),qr.width,qr.height);assert(decoded,'QR is decodable at '+width);assert.equal(decoded.data,qr.url);assert(!decoded.data.includes('user_id='));
   await page.emulateMedia({reducedMotion:'reduce'});assert.equal(await page.locator('.wutm-expiry').evaluate(e=>getComputedStyle(e,'::before').animationName),'none');
   if(process.env.WUTM_SCREENSHOTS){fs.mkdirSync(process.env.WUTM_SCREENSHOTS,{recursive:true});await page.screenshot({path:process.env.WUTM_SCREENSHOTS+'/member-center-'+width+'.png',fullPage:true});}
   await page.locator('.wutm-member-card-qr').evaluate(e=>e.dataset.cardExpires='1');await page.waitForTimeout(1100);
   assert(await page.locator('.wutm-card-status').textContent().then(s=>s.includes('已過期')));assert.equal(await page.locator('.wutm-member-card-qr canvas').isVisible(),false);
   assert.deepEqual(errors,[]);await page.close();
  }
  const admin=execFileSync(process.env.WUTM_TEST_PHP||'php',['tests/member-loyalty-center.php','--admin-html'],{encoding:'utf8'});
  for(const width of [1440,768,390,320]){
   const page=await browser.newPage({viewport:{width,height:900}});
   await page.setContent('<style>body{font:14px/1.7 Arial;margin:12px}*{box-sizing:border-box}</style><main class="wutm-loyalty-content">'+admin+'</main>');
   await page.addStyleTag({content:fs.readFileSync('modules/member-loyalty/admin.css','utf8')});
   assert(await page.getByRole('heading',{name:'會員卡怎麼使用？'}).isVisible());
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Admin card fits '+width);
   if(process.env.WUTM_SCREENSHOTS)await page.screenshot({path:process.env.WUTM_SCREENSHOTS+'/member-admin-'+width+'.png',fullPage:true});
   await page.close();
  }
  const page=await browser.newPage();await page.setContent(html);await page.addScriptTag({content:fs.readFileSync('modules/member-loyalty/member-card.js','utf8')});assert((await page.locator('.wutm-card-status').textContent()).includes('載入失敗'));await page.close();
  console.log('Member center browser passed: 1440/768/390/320 widths, real PHP render, QR decode and expiry, tier comparison, coupon copy/status, reduced motion and missing-library fallback.');
 } finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
