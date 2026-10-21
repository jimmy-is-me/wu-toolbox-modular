const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process'),{chromium}=require('playwright');
const php=process.env.WUTM_TEST_PHP||'php',args=JSON.parse(process.env.WUTM_TEST_PHP_ARGS||'[]');
const fixture=mode=>execFileSync(php,[...args,'tests/media-safe-cleaner.php',mode],{encoding:'utf8'});
const shell=fixture('shell'),empty=fixture('empty'),script=fixture('js');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME});
 try{
  const page=await browser.newPage(),errors=[],calls=[];let fail=false,slow=false;page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://example.test/wp-admin/admin-ajax.php',async route=>{
   const fields=Object.fromEntries(new URLSearchParams(route.request().postData()));calls.push(fields);
   if(slow)await new Promise(resolve=>setTimeout(resolve,200));
   if(fail)return route.fulfill({status:503,contentType:'application/json',headers:{'access-control-allow-origin':'*'},body:JSON.stringify({success:false,data:{message:'fixture check failed'}})});
   const ids=fields.mode==='all'?[1,2,3]:JSON.parse(fields.ids);
   const outcomes=ids.map(id=>({id,status:id===2?'skipped':'deleted',note:id===2?'保護使用中':'已永久刪除'}));
   await route.fulfill({contentType:'application/json',headers:{'access-control-allow-origin':'*'},body:JSON.stringify({success:true,data:{processed:ids.length,deleted:outcomes.filter(x=>x.status==='deleted').length,skipped:outcomes.filter(x=>x.status==='skipped').length,failed:0,messages:['Fixture only'],outcomes}})});
  });
  async function load(html=shell){await page.setContent(html);await page.addStyleTag({content:fs.readFileSync('assets/css/admin.css','utf8')+'\n'+fs.readFileSync('assets/css/admin-components.css','utf8')});await page.evaluate(()=>document.body.classList.add('wutm-admin-screen'));await page.addScriptTag({path:'assets/js/admin-design.js'});await page.addScriptTag({content:script});}
  for(const width of [1440,1024,768,390]){
   await page.setViewportSize({width,height:1000});await load();
   assert.equal(await page.locator('.wumc-file-card').count(),3);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'No page overflow at '+width);
   await page.locator('[data-view=list]').click();assert.equal(await page.locator('#wumc-viewport').getAttribute('data-view-mode'),'list');
   await page.locator('.wumc-image-checkbox-mirror').first().check();assert.equal(await page.locator('.wumc-image-checkbox').first().isChecked(),true,'List/grid selection sync');
   await page.locator('[data-view=grid]').click();
   if(process.env.WUTM_VISUAL_OUTPUT){fs.mkdirSync(process.env.WUTM_VISUAL_OUTPUT,{recursive:true});await page.screenshot({path:path.join(process.env.WUTM_VISUAL_OUTPUT,'cleaner-'+width+'.png'),fullPage:true});}
  }
  assert.equal(calls.length,0,'Rendering/view switching never deletes or scans via AJAX');
  page.on('dialog',d=>d.accept());await load();
  await page.locator('#wumc-select-clean').click();assert.equal(await page.locator('.wumc-image-checkbox:checked').count(),3,'Select clean items');
  await page.locator('#wumc-delete-selected').click();assert.equal(calls.length,0,'Typed confirmation required');
  await page.locator('#wumc-confirm').fill('永久刪除');await page.locator('#wumc-delete-selected').click();
  await page.waitForFunction(()=>document.querySelectorAll('.wumc-file-card.is-deleted').length===2);
  assert.equal(calls.length,1);assert.equal(calls[0].action,'wutm_mc_delete_images');assert.equal(calls[0].nonce,'fixture');assert.equal(calls[0].token,'fixture-token');assert.equal(calls[0].confirmation,'永久刪除');
  assert.equal(await page.locator('.wumc-image-checkbox:disabled').count(),2,'Deleted entries disabled');
  assert.ok((await page.locator('[data-media-row="2"]').textContent()).includes('保護略過'));
  await load();await page.locator('#wumc-confirm').fill('永久刪除');await page.locator('#wumc-delete-all').click();await page.waitForFunction(()=>document.querySelectorAll('.wumc-file-card.is-deleted').length===2);assert.equal(calls.at(-1).mode,'all');
  const many=shell.replace('data-total="3"','data-total="60"');await load(many);slow=true;
  await page.locator('#wumc-confirm').fill('永久刪除');const before=calls.length;await page.locator('#wumc-delete-all').click();await page.locator('#wumc-pause').click();await page.waitForFunction(()=>document.querySelector('#wumc-pause').hidden);await page.waitForTimeout(300);assert.equal(calls.length,before+1,'Pause prevents subsequent destructive batches');slow=false;
  await load();fail=true;await page.locator('#wumc-confirm').fill('永久刪除');const prior=calls.length;await page.locator('#wumc-delete-all').click();await page.waitForFunction(()=>document.querySelector('#wumc-log').textContent.includes('fixture check failed'));await page.waitForTimeout(200);assert.equal(calls.length,prior+1,'Server failure stops without automatic retry');fail=false;
  await load(empty);assert.equal(await page.locator('#wumc-delete-all').count(),0,'No deletion without preview');
  assert.deepEqual(errors,[]);console.log('Media cleaner desktop/mobile, grid/list, selection, confirmation and batch UI tests passed');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
