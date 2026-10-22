const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{chromium}=require('playwright');
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME});try{
 for(const width of [1440,390]){
  const page=await browser.newPage({viewport:{width,height:900}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.setContent('<input type="radio" name="shipping_method[0]" value="ecpay:1" checked><input type="radio" name="shipping_method[0]" value="payuni_shipping_711_c2c_frozen:2"><input type="radio" name="shipping_method[0]" value="payuni_shipping_tcat_normal:3"><input id="ship-to-different-address-checkbox" type="checkbox"><button id="wutm-payuni-choose-cvs">選擇超商</button><div class="woocommerce-shipping-fields__field-wrapper"><p id="shipping_address_1_field"><input value="買家地址"></p><p id="shipping_phone_field"><input value="0912345678"></p><p id="foreign-hidden" style="display:none">其他物流隱藏欄位</p><p class="payuni-shipping-field" id="payuni_storename_field"><input id="payuni_storename" value="測試門市"></p></div><input id="CVSStoreID" value="ECPAY-STORE">');
  await page.addStyleTag({content:fs.readFileSync('modules/payuni-shipping/vendor/assets/css/styles-public.css','utf8')});
  await page.addScriptTag({content:fs.readFileSync(path.join(process.env.WUTM_TEST_WP,'wp-includes/js/jquery/jquery.js'),'utf8')});
  await page.evaluate(()=>{window.payuni_shipping_object={};window.submissions=[];HTMLFormElement.prototype.submit=function(){window.submissions.push({action:this.action,fields:Object.fromEntries(new FormData(this))});};});
  await page.addScriptTag({content:fs.readFileSync('modules/payuni-shipping/assets/checkout.js','utf8')});await page.evaluate(()=>new Promise(r=>jQuery(r)));
  await page.evaluate(()=>jQuery(document.body).trigger('updated_checkout'));assert.deepEqual(errors,[],'Missing Woo fragments must not throw');
  assert.equal(await page.locator('#payuni_storename_field').isVisible(),false);
  await page.locator('[value="payuni_shipping_711_c2c_frozen:2"]').check();
  await page.evaluate(()=>jQuery(document.body).trigger('updated_checkout',[{fragments:{payuni_shipping_info:{shipping_data:{ShipType:'1',is_payuni_cvs:true,ajax_url:'https://sandbox-api.payuni.com.tw/api/logistics/ship_map',MerID:'FIXTURE',Version:'1.1',EncryptInfo:'CIPHER',HashInfo:'HASH'}}}}]));
  assert.equal(await page.locator('#shipping_address_1_field').isVisible(),false);assert.equal(await page.locator('#payuni_storename_field').isVisible(),true);assert.equal(await page.locator('#ship-to-different-address-checkbox').isChecked(),true);
  await page.locator('#wutm-payuni-choose-cvs').click();const submitted=await page.evaluate(()=>window.submissions);assert.equal(submitted.length,1);assert.equal(submitted[0].fields.EncryptInfo,'CIPHER');assert.equal(submitted[0].fields.HashInfo,'HASH');
  await page.locator('[value="payuni_shipping_tcat_normal:3"]').check();await page.evaluate(()=>jQuery(document.body).trigger('updated_checkout',[{}]));assert.equal(await page.locator('#shipping_address_1_field').isVisible(),true);assert.equal(await page.locator('#shipping_phone_field').isVisible(),true);
  await page.locator('[value="ecpay:1"]').check();await page.evaluate(()=>jQuery(document.body).trigger('updated_checkout',[{fragments:{}}]));assert.equal(await page.locator('#foreign-hidden').isVisible(),false,'Never show a field another provider hid');assert.equal(await page.locator('#shipping_address_1_field input').inputValue(),'買家地址');assert.equal(await page.locator('#CVSStoreID').inputValue(),'ECPAY-STORE');assert.deepEqual(errors,[]);await page.close();
 }
 console.log('PAYUNi shipping desktop/mobile browser passed: map payload, missing fragments, CVS/TCat switching and other-provider fields preserved.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1);});
