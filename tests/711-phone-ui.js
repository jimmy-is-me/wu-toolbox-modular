const assert=require('node:assert/strict'),vm=require('node:vm'),path=require('node:path'),fs=require('node:fs');
const {execFileSync}=require('node:child_process');
const fixture=JSON.parse(execFileSync(process.env.WUTM_TEST_PHP||'php',['tests/711-phone.php','script'],{encoding:'utf8'}));
new vm.Script(fixture.script);
if(!process.env.WUTM_TEST_JQUERY){console.log('7-11 checkout phone script syntax passed.');process.exit(0);}
const {chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME});
 try{
  const page=await browser.newPage(),errors=[];let requests=0;
  page.on('pageerror',e=>errors.push(e.message));page.on('request',()=>requests++);
  await page.setViewportSize({width:390,height:800});
  await page.setContent('<meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:16px Arial;margin:20px}input{max-width:100%;box-sizing:border-box}p{padding:10px}label{display:block;margin-bottom:8px}.required{color:red}</style><form><label>配送方式<select name="shipping_method[0]"><option value="flat_rate:1">宅配</option><option value="seven_eleven_pickup:2">7-11 取貨</option></select></label><input id="ship-to-different-address-checkbox" type="checkbox"><p id="shipping_phone_field"><label for="shipping_phone">收件者電話 <span class="optional">（選填）</span></label><input id="shipping_phone" type="tel" data-wutm-phone-required-default="0"></p><div id="wutm_711_store_field"><input id="cvs_store_id"><input id="cvs_store_name"><input id="cvs_store_address"></div></form>');
  await page.addScriptTag({path:process.env.WUTM_TEST_JQUERY});await page.evaluate(()=>jQuery.fx.off=true);
  await page.addScriptTag({content:fixture.script});await page.waitForTimeout(60);
  assert.equal(await page.locator('#shipping_phone').evaluate(el=>el.required),false);
  await page.selectOption('select','seven_eleven_pickup:2');
  assert.equal(await page.locator('#shipping_phone').evaluate(el=>el.required),true);
  assert.equal(await page.locator('#shipping_phone').getAttribute('aria-required'),'true');
  assert.equal(await page.locator('#shipping_phone_field .optional').isVisible(),false);
  assert.equal(await page.locator('#shipping_phone_field .required').isVisible(),true);
  assert.equal(await page.locator('#shipping_phone').evaluate(el=>el.checkValidity()),false);
  await page.fill('#shipping_phone','+886912345678');
  assert.equal(await page.locator('#shipping_phone').evaluate(el=>el.checkValidity()),true);
  for(let i=0;i<5;i++)await page.evaluate(()=>jQuery(document.body).trigger('updated_checkout'));
  assert.equal(await page.locator('#shipping_phone_field .required').count(),1,'No duplicate required markers');
  const out=process.env.WUTM_VISUAL_OUTPUT;if(out){fs.mkdirSync(out,{recursive:true});await page.screenshot({path:path.join(out,'711-phone-390.png')});}
  await page.selectOption('select','flat_rate:1');
  assert.equal(await page.locator('#shipping_phone').evaluate(el=>el.required),false,'Normal optional requirement restored');
  assert.equal(await page.locator('#shipping_phone_field .optional').isVisible(),true);
  await page.evaluate(()=>{jQuery('#shipping_phone').attr('data-wutm-phone-required-default','1');jQuery(document.body).trigger('updated_checkout');});
  assert.equal(await page.locator('#shipping_phone').evaluate(el=>el.required),true,'Other plugins normal requirement retained');
  await page.evaluate(()=>{jQuery('#shipping_phone').attr('data-wutm-phone-required-default','0');jQuery('form').append('<input type="hidden" name="shipping_method[1]" value="cvs_711_shipping:4">');jQuery(document.body).trigger('updated_checkout');});
  assert.equal(await page.locator('#shipping_phone').evaluate(el=>el.required),true,'Secondary pickup package requires phone');
  assert.equal(requests,0,'No extra checkout network request introduced');
  assert.deepEqual(errors,[]);
  console.log('7-11 phone Chromium passed: required/optional labels, validity, refresh, switching, original rules and multiple packages.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
