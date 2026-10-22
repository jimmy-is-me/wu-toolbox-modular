const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),http=require('node:http'),{execFileSync}=require('node:child_process'),{chromium}=require('playwright');
(async()=>{
 const root=path.resolve(__dirname,'..'),php=process.env.WUTM_TEST_PHP||'php',args=JSON.parse(process.env.WUTM_TEST_PHP_ARGS||'[]');
 const html=execFileSync(php,[...args,'tests/order-report-export.php','page'],{cwd:root,encoding:'utf8'});
 const css=['assets/css/admin.css','assets/css/admin-components.css','modules/order-report-export/admin.css'].map(file=>fs.readFileSync(file,'utf8')).join('\n');
 const server=http.createServer((req,res)=>{res.writeHead(200,{'content-type':'text/html; charset=utf-8'});res.end('<!doctype html><meta charset="utf-8"><style>body{margin:0}*{box-sizing:border-box}</style><style>'+css+'</style><body class="wutm-admin-screen"><div id="wpbody-content">'+html+'</div>');});
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const url='http://127.0.0.1:'+server.address().port;
 const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME});
 try{for(const width of [1440,768,390,320]){
  const page=await browser.newPage({viewport:{width,height:1000}}),errors=[],requests=[];let failure=false,delay=0;
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://report.example.test/wp-admin/admin-ajax.php',async route=>{
   const post=route.request().postData(),action=/name="action"\r?\n\r?\n([^\r\n]+)/.exec(post)?.[1];requests.push({action,post});if(delay)await new Promise(r=>setTimeout(r,delay));
   const scan={success:true,data:{scanned:10,fields:[{scope:'order',key:'_billing_tax_id',label:'統一編號',recognized:true,count:10,sample:'01234567'}]}};
   const preview={success:true,data:{headers:['中文欄名','備註'],rows:[['王小明','<img src=x onerror="window.xss=1">'],['第二列','換行\n資料']],period:'全部期間',statuses:['已完成'],mode:'每張訂單一列',limited:true}};
   await route.fulfill({status:failure?500:200,headers:{'access-control-allow-origin':'*'},contentType:'application/json',body:JSON.stringify(action==='wctre_v3_scan'?scan:(failure?{success:false,data:{message:'預覽失敗，請重試'}}:preview))});
  });
  await page.goto(url);await page.waitForFunction(()=>document.querySelector('#wctre-scan-status').textContent.includes('10 筆'));
  assert.equal(await page.locator('#wctre-fields tr').count(),11);assert.equal(await page.locator('.wutm-ui-header').count(),1);
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Page overflow '+width);
  await page.locator('[data-range="today"]').click();assert.equal(await page.locator('#wctre-from').inputValue(),'2026-10-22');await page.locator('[data-range="all"]').click();assert.equal(await page.locator('#wctre-from').inputValue(),'');
  await page.locator('#wctre-add-select').selectOption({label:'Email'});await page.locator('#wctre-add-btn').click();assert.equal(await page.locator('#wctre-fields tr').count(),12);
  await page.locator('.wctre-manual').evaluate(el=>el.closest('details').open=true);await page.locator('#wctre-manual-key').fill('_billing_tax_id');await page.locator('#wctre-manual-add').click();assert.equal(await page.locator('#wctre-fields tr').count(),13);
  const last=page.locator('#wctre-fields tr').last();await last.locator('.wctre-up').click();assert.equal(await page.locator('#wctre-fields tr').nth(11).getAttribute('data-meta'),'_billing_tax_id');
  await page.locator('#wctre-preview-btn').click();await page.locator('.wctre-preview-scroll table').waitFor();assert.equal(await page.locator('.wctre-preview-scroll tbody tr').count(),2);assert.equal(await page.evaluate(()=>window.xss),undefined);assert.equal(await page.locator('.wctre-preview-scroll img').count(),0,'Customer values rendered as text, not HTML');
  await page.locator('#wctre-fields tr').first().locator('.wctre-label').fill('自訂欄名');assert.equal(await page.locator('.wctre-preview-scroll').count(),0);assert.match(await page.locator('#wctre-preview-status').textContent(),/設定已變更/);
  failure=true;await page.locator('#wctre-preview-btn').click();await page.waitForFunction(()=>document.querySelector('#wctre-preview-status').dataset.error==='1');assert.equal(await page.locator('#wctre-preview-btn').isEnabled(),true,'Failure unlocks preview');
  failure=false;delay=200;await page.locator('#wctre-preview-btn').click();await page.locator('#wctre-fields tr').first().locator('.wctre-label').fill('更新欄名');await page.waitForTimeout(300);assert.equal(await page.locator('.wctre-preview-scroll').count(),0,'Stale preview cannot replace changed settings');
  assert.ok(requests.some(r=>r.action==='wctre_v3_preview'&&r.post.includes('field_order[]')),'Original complete form sent for preview');assert.deepEqual(errors,[]);await page.close();
 }
 console.log('Order report UI passed: four widths, field add/reorder, filters, read-only preview, text-only rendering, stale-result invalidation and error recovery.');
 }finally{await browser.close();server.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
