const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {execFileSync}=require('node:child_process');
const php=process.env.WUTM_TEST_PHP||'php';
const {chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.WUTM_TEST_CHROME,headless:true});
 try{
 const page=await browser.newPage(),errors=[],requests=[];page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>requests.push(r.url()));
 const markup=execFileSync(php,['tests/email-tracking.php','visual'],{encoding:'utf8'});
 const detail=execFileSync(php,['tests/email-tracking.php','detail'],{encoding:'utf8'});
 const out=process.env.WUTM_VISUAL_OUTPUT;fs.mkdirSync(out,{recursive:true});
 let trackerFetched=false;await page.route('https://tracker.test/**',route=>{trackerFetched=true;return route.abort();});
 await page.route('https://example.test/wp-admin/admin-ajax.php',route=>route.fulfill({status:200,contentType:'application/json',body:detail,headers:{'access-control-allow-origin':'*'}}));
 for(const width of [1440,390,320]){
  await page.setViewportSize({width,height:900});await page.setContent(markup);
  await page.evaluate(()=>window.wuEmailAdmin={url:'https://example.test/wp-admin/admin-ajax.php',nonce:'valid'});
  await page.addScriptTag({path:'modules/email-tracking/admin.js'});
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'RWD page overflow '+width);
  const before=requests.length;await page.waitForTimeout(50);assert.equal(requests.length,before,'No polling');
  await page.locator('.wu-mail-view').click();await page.locator('.wu-mail-detail-meta dd').first().waitFor();
  assert.equal(await page.locator('.wu-mail-detail-meta dd').first().textContent(),'<b>Unsafe title</b>','Metadata is text, not HTML');
  const iframe=page.frameLocator('#wu-mail-dialog iframe');await iframe.locator('p').waitFor();
  assert.equal(await iframe.locator('p').textContent(),'PRIVATE BODY');
  assert.equal(await page.evaluate(()=>window.pwned),undefined,'Preview never executes scripts');
  assert.equal(trackerFetched,false,'External tracking pixel never reaches network');
  await page.screenshot({path:path.join(out,'email-modal-'+width+'.png'),fullPage:true});
  await page.keyboard.press('Escape');assert.equal(await page.locator('#wu-mail-dialog').evaluate(el=>el.open),false);
  assert.equal(await page.locator('.wu-mail-view').evaluate(el=>el===document.activeElement),true,'Focus returns to opener');
  await page.screenshot({path:path.join(out,'email-panel-'+width+'.png'),fullPage:true});
 }
 await page.unroute('https://example.test/wp-admin/admin-ajax.php');
 await page.route('https://example.test/wp-admin/admin-ajax.php',route=>route.fulfill({status:403,contentType:'text/html',body:'<!DOCTYPE html>Denied',headers:{'access-control-allow-origin':'*'}}));
 await page.locator('.wu-mail-view').click();await page.waitForFunction(()=>document.querySelector('.wu-mail-detail-state').textContent.includes('未回傳有效資料'));
 assert.deepEqual(errors,[]);console.log('Email UI passed: RWD, lazy details, isolated preview, blocked remote tracking, focus and HTML error recovery.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
