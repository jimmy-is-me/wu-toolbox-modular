const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME});
 try {
  for(const width of [1440,390]) {
   const page=await browser.newPage({viewport:{width,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.setContent('<input type="radio" name="shipping_method[0]" value="ecpay_cvs:1" checked><input type="radio" name="shipping_method[0]" value="wu_np_newebpay_shipping_cvs:2"><div class="woocommerce-shipping-fields__field-wrapper"><p class="form-row" id="shipping_first_name_field"><input value="WU buyer"></p></div><input id="CVSStoreID" value="ECPAY-STORE"><input id="LogisticsSubType" value="FAMIC2C">');
   await page.addScriptTag({content:fs.readFileSync(path.join(process.env.WUTM_TEST_WP,'wp-includes/js/jquery/jquery.js'),'utf8')});
   await page.addScriptTag({content:fs.readFileSync('modules/newebpay-tools/vendor/style/js/wu_np_shipping.js','utf8')});await page.evaluate(()=>new Promise(r=>jQuery(r)));
   assert.equal(await page.locator('.wu-np-selected').count(),0);assert.equal(await page.locator('#shipping_first_name_field').isVisible(),true);
   await page.locator('[value="wu_np_newebpay_shipping_cvs:2"]').check();await page.evaluate(()=>jQuery(document.body).trigger('updated_checkout'));
   assert.equal(await page.locator('#shipping_first_name_field').isVisible(),false,'Only own shipping view hides fields');
   await page.locator('[value="ecpay_cvs:1"]').check();await page.evaluate(()=>jQuery(document.body).trigger('updated_checkout'));
   assert.equal(await page.locator('.wu-np-selected').count(),0);assert.equal(await page.locator('#shipping_first_name_field').isVisible(),true,'Other provider display restored');
   assert.equal(await page.locator('#CVSStoreID').inputValue(),'ECPAY-STORE');assert.equal(await page.locator('#LogisticsSubType').inputValue(),'FAMIC2C');
   assert.equal(await page.locator('#shipping_first_name_field input').inputValue(),'WU buyer');assert.deepEqual(errors,[]);await page.close();
  }
  console.log('WU shipping browser checks passed: desktop/mobile switching preserves other provider fields and user data.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
