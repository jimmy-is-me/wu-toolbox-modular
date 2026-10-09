const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const {execFileSync}=require('node:child_process');
const php=process.env.WUTM_TEST_PHP||'php';
const scripts=JSON.parse(execFileSync(php,['tests/product-badges.php','scripts'],{encoding:'utf8'}));
new vm.Script(scripts.admin);new vm.Script(scripts.front);
if(!process.env.WUTM_TEST_JQUERY){console.log('Badge scripts syntax passed.');process.exit(0);}
const {chromium}=require('playwright');
(async()=>{
const out=process.env.WUTM_VISUAL_OUTPUT;fs.mkdirSync(out,{recursive:true});
const browser=await chromium.launch({executablePath:process.env.WUTM_TEST_CHROME,headless:true});
try{
const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
for(const width of [1440,390,320]){
 await page.setViewportSize({width,height:1000});
 await page.setContent(execFileSync(php,['tests/product-badges.php','visual'],{encoding:'utf8'}));
 await page.addScriptTag({path:process.env.WUTM_TEST_JQUERY});await page.addScriptTag({content:scripts.front});await page.waitForTimeout(50);
 assert.equal(await page.locator('.woocommerce-product-gallery>.wcrm-marks--single').count(),1);
 assert.equal(await page.locator('.wcrm-single-source').count(),0);
 assert.equal(await page.locator('head #wcrm-product-marks-inline').count(),1,'Late badge CSS survives fallback removal');
 assert.equal(await page.locator('.woocommerce-product-gallery .wcrm-mark').first().evaluate(el=>getComputedStyle(el).fontWeight),'600','Badge styles remain applied');
 assert.equal(await page.locator('input[name="wcrm_marks[]"]:checked').count(),3,'Multi-select includes disabled choices');
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'RWD without overflow '+width);
 await page.screenshot({path:path.join(out,'badges-'+width+'.png'),fullPage:true});
}
await page.setContent('<table class="wp-list-table products"><tr id="post-7"><td><span class="wcrm-row-data" hidden data-marks="[1,3]"></span></td></tr></table><div id="edit-7"><fieldset class="wcrm-quick-fieldset"><input name="wcrm_quick_ready" value="0"><input type="checkbox" name="wcrm_quick_marks[]" value="1"><input type="checkbox" name="wcrm_quick_marks[]" value="2"><input type="checkbox" name="wcrm_quick_marks[]" value="3"><p class="wcrm-quick-error" hidden>error</p></fieldset></div>');
await page.addScriptTag({path:process.env.WUTM_TEST_JQUERY});
await page.evaluate(()=>{window.inlineEditPost={edit(){window.originalCalls=(window.originalCalls||0)+1;},getId(){return 7;}};});
await page.addScriptTag({content:scripts.admin});await page.waitForTimeout(50);
await page.evaluate(()=>inlineEditPost.edit(7));
assert.equal(await page.locator('input[name="wcrm_quick_ready"]').inputValue(),'1');
assert.equal(await page.locator('input[type="checkbox"]:checked').count(),2);
await page.evaluate(()=>document.querySelector('.wcrm-row-data').setAttribute('data-marks','invalid'));
await page.evaluate(()=>inlineEditPost.edit(7));
assert.equal(await page.locator('input[name="wcrm_quick_ready"]').inputValue(),'0');
assert.equal(await page.locator('input[type="checkbox"]:disabled').count(),3);
assert.equal(await page.locator('.wcrm-quick-error').isVisible(),true);
assert.equal(await page.evaluate(()=>window.originalCalls),2,'Original quick-edit handler retained');
assert.deepEqual(errors,[]);
console.log('Badge Chromium passed: RWD, gallery placement, multi-select and quick-edit failure safeguards.');
}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
