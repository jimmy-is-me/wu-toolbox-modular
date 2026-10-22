const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{chromium}=require('playwright');
if(!process.env.WUTM_TEST_WP)throw Error('WUTM_TEST_WP required for native jQuery fixture');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME});
 try{
  for(const provider of ['ezpay','ecpay']){
   const page=await browser.newPage(),calls=[],dialogs=[],errors=[];const ez=provider==='ezpay';
   page.on('pageerror',e=>errors.push(e.message));page.on('dialog',async d=>{dialogs.push(d.message());await d.accept();});
   await page.route('https://invoice.example.test/admin-ajax.php',async route=>{
    calls.push(Object.fromEntries(new URLSearchParams(route.request().postData())));await new Promise(r=>setTimeout(r,100));
    await route.fulfill({status:502,contentType:'application/json',headers:{'access-control-allow-origin':'*'},body:JSON.stringify({success:false,data:{message:'請先查詢服務商紀錄，勿重複送出'}})});
   });
   const field=ez?'_ezpay_invoice':'_invoice';
   await page.setContent(`<select name="${field}_type"><option value="company">company</option></select><input name="${field}_company_name" value="Fixture Co"><input name="${field}_tax_id" value="12345678"><button type="button" class="${ez?'btnGenerateInvoiceEzPay':'btnGenerateInvoice'}" value="123">issue</button><button type="button" class="${ez?'btnInvalidInvoiceEzPay':'btnInvalidInvoice'}" value="123">void</button><button type="button" id="${ez?'btnUpdateInvoiceDataEzPay':'btnUpdateInvoiceData'}" value="123">update</button>`);
   await page.addScriptTag({content:fs.readFileSync(path.join(process.env.WUTM_TEST_WP,'wp-includes/js/jquery/jquery.min.js'),'utf8')});
   await page.evaluate(()=>{window.ajaxurl='https://invoice.example.test/admin-ajax.php';window.wu_ezpay_invoice_params=window.wu_ecpay_invoice_params={ajax_nonce:'fixture'};window.blocked=false;jQuery.blockUI=()=>window.blocked=true;jQuery.unblockUI=()=>window.blocked=false;});
   await page.addScriptTag({path:`modules/${provider}-invoice/assets/js/admin.js`});await page.waitForTimeout(80);
   const buttons=[ez?'.btnGenerateInvoiceEzPay':'.btnGenerateInvoice',ez?'.btnInvalidInvoiceEzPay':'.btnInvalidInvoice',ez?'#btnUpdateInvoiceDataEzPay':'#btnUpdateInvoiceData'];
   const expected=[ez?'gen_invoice_ezpay':'gen_invoice',ez?'invalid_invoice_ezpay':'invalid_invoice',ez?'update_invoice_ezpay':'update_invoice'];
   for(let i=0;i<buttons.length;i++){
    const before=calls.length;await page.locator(buttons[i]).click();
    if(i===0)await page.locator(buttons[i]).click();
    await page.waitForFunction(()=>window.blocked===false);await page.waitForTimeout(160);
    assert.equal(calls.length,before+1,'No duplicate operation or automatic retry');
    assert.equal(calls.at(-1).action,expected[i]);assert.equal(calls.at(-1).nonce,'fixture');assert.equal(calls.at(-1).orderId,'123');
    if(i!==1){assert.equal(calls.at(-1)[field+'_company_name'],'Fixture Co');assert.equal(calls.at(-1)[field+'_tax_id'],'12345678');}
    assert.ok(dialogs.some(t=>t.includes('服務商紀錄')),'Structured error is shown, not object text');
   }
   assert.deepEqual(errors,[]);await page.close();
  }
  console.log('Invoice admin UI preserved payloads, prevents double submission, unlocks on HTTP failure and never retries automatically.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
