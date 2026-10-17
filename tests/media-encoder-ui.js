const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const {execFileSync}=require('node:child_process');
const script=fs.readFileSync('modules/media-encoder/admin.js','utf8');new vm.Script(script);
if(!process.env.WUTM_TEST_CHROME){console.log('Media encoder admin script syntax passed.');process.exit(0);}
const {chromium}=require('playwright');
const args=JSON.parse(process.env.WUTM_TEST_PHP_ARGS||'[]');
const shell=execFileSync(process.env.WUTM_TEST_PHP||'php',[...args,'tests/media-encoder.php','shell'],{encoding:'utf8'});
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME});
 try {
  const page=await browser.newPage(),errors=[],calls=[];page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
  const out=process.env.WUTM_VISUAL_OUTPUT;fs.mkdirSync(out,{recursive:true});
  const base=[
   {id:100,name:'brand-story.png',title:'品牌故事',mime:'image/png',width:2400,height:1600,bytes:1240000,thumb:'',edit:'#edit-100',convertible:true,saving:null},
   {id:99,name:'studio.webp',title:'工作室',mime:'image/webp',width:1920,height:1280,bytes:240000,thumb:'',edit:'#edit-99',convertible:false,saving:400000},
   {id:98,name:'animation.gif',title:'動態圖片',mime:'image/gif',width:400,height:300,bytes:480000,thumb:'',edit:'#edit-98',convertible:false,saving:null}
  ];let fail=false,hold=false,converted=0;
  await page.route('https://example.test/wp-admin/admin-ajax.php',async route=>{
   const fields=Object.fromEntries(new URLSearchParams(route.request().postData()));calls.push(fields);
   if(fail)return route.fulfill({status:500,contentType:'text/html',body:'<!doctype html>Server error',headers:{'access-control-allow-origin':'*'}});
   let data;
   if(fields.operation==='list')data={rows:fields.cursor==='0'?structuredClone(base):[{...base[0],id:97,name:'older.png'}],cursor:fields.cursor==='0'?98:97,more:fields.cursor==='0',at:'2026-10-17 12:30:00'};
   else {if(hold)await new Promise(resolve=>setTimeout(resolve,500));converted++;const row={...base[0],id:Number(fields.id),name:'brand-story.webp',mime:'image/webp',convertible:false,bytes:340000,saving:900000};data={row,record:{name:'brand-story.png',before:1240000,after:340000,at:'2026-10-17 12:31:00'}};}
   return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data}),headers:{'access-control-allow-origin':'*'}});
  });
  for(const width of [1440,768,390,320]){
   await page.setViewportSize({width,height:1000});await page.setContent(shell);
   await page.evaluate(()=>document.body.className='wu-toolbox_page_wu-media-encoder');
   await page.addStyleTag({content:'body{margin:0;background:#f0f0f1;font:14px Arial}#wpbody-content{padding-left:20px}button,input{font:inherit}'});
   await page.addStyleTag({path:'assets/css/admin.css'});await page.addStyleTag({path:'modules/media-encoder/admin.css'});
   await page.addScriptTag({content:script});const start=calls.length;await page.waitForTimeout(150);
   assert.equal(calls.length,start,'Opening page never scans library');
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'Cold RWD '+width);
   assert.equal(await page.locator('.wme-hero h2').evaluate(el=>getComputedStyle(el).color),'rgb(240, 245, 255)','Hero text stays readable with shared admin CSS');
   await page.locator('[data-load]').first().click();await page.waitForFunction(()=>document.querySelector('#wme-stat-count').textContent==='3');
   assert.equal(await page.locator('#wme-rows tr').count(),3);
   await page.locator('[data-tab="overview"]').click();
   assert.equal(await page.locator('#wme-percent').textContent(),'33%');
   await page.screenshot({path:path.join(out,'media-overview-'+width+'.png'),fullPage:true});
   await page.locator('[data-tab="library"]').click();
   await page.locator('#wme-search').fill('brand');assert.equal(await page.locator('#wme-rows tr').count(),1);
   await page.locator('#wme-search').fill('');await page.locator('[data-filter="webp"]').click();assert.equal(await page.locator('#wme-rows tr').count(),1);
   await page.locator('[data-filter="all"]').click();
   await page.locator('#wme-next').click();await page.waitForFunction(()=>document.querySelector('#wme-rows').textContent.includes('older.png'));
   await page.locator('#wme-prev').click();await page.waitForFunction(()=>document.querySelector('#wme-rows').textContent.includes('animation.gif'));
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'Library RWD '+width);
   await page.screenshot({path:path.join(out,'media-library-'+width+'.png'),fullPage:true});
   const old=await page.locator('#wme-rows').innerHTML();fail=true;await page.locator('[data-load]').last().click();await page.waitForFunction(()=>document.querySelector('#wme-message').dataset.error==='true');
   assert.equal(await page.locator('#wme-rows').innerHTML(),old,'Load error retains previous page');fail=false;
   await page.locator('[data-tab="settings"]').click();await page.locator('#wme-quality').fill('88');
   assert.equal(await page.locator('#wme-quality-output').textContent(),'88%');
   assert.equal(await page.locator('[name="disabled_sizes[]"][value="scaled"]').isChecked(),true,'Scaled setting visible and preserved');
   assert.equal(await page.locator('[name="replace_original"]').count(),1,'Original deletion setting preserved');
   assert.equal(await page.locator('[name="max_height"]').inputValue(),'0','Unlimited dimension retained');
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'Settings RWD '+width);
   await page.screenshot({path:path.join(out,'media-settings-'+width+'.png'),fullPage:true});
  }
  await page.locator('[data-tab="library"]').click();await page.locator('#wme-batch').click();
  await page.locator('#wme-job').waitFor({state:'hidden'});
  assert.equal(converted,1,'Only supported pending PNG converted; GIF and WebP unchanged');
  assert.match(await page.locator('#wme-message').textContent(),/原始檔與舊縮圖均保留/);
  // Reload and stop an in-flight single request, then start a new round.
  await page.locator('[data-load]').last().click();await page.waitForFunction(()=>document.querySelector('#wme-rows').textContent.includes('brand-story.png'));
  hold=true;await page.locator('#wme-batch').click();await page.locator('#wme-pause').click();await page.locator('#wme-job').waitFor({state:'hidden'});
  assert.match(await page.locator('#wme-message').textContent(),/停止/);
  await page.locator('[data-load]').last().click();await page.waitForFunction(()=>document.querySelector('#wme-rows').textContent.includes('brand-story.png'));
  await page.locator('#wme-batch').click();assert.equal(await page.locator('#wme-pause').isEnabled(),true,'Stop button resets for next round');
  await page.locator('#wme-job').waitFor({state:'hidden'});
  const finished=calls.length;await page.waitForTimeout(800);assert.equal(calls.length,finished,'No recurring polling after completion');
  assert.ok(calls.every(c=>c.action==='wutm_media_admin'&&c.nonce==='fixture-nonce'));
  assert.deepEqual(errors,[]);
  console.log('Media Chromium passed: scoped loading, real layout at four widths, original settings, bounded pagination, filters, errors, batch, stop/restart and no polling.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
