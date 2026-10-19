const assert=require('node:assert/strict'),fs=require('node:fs'),{execFileSync}=require('node:child_process'),{chromium}=require('playwright');
const php=process.env.WUTM_TEST_PHP||'php',args=JSON.parse(process.env.WUTM_TEST_PHP_ARGS||'[]');
const media=execFileSync(php,[...args,'tests/media-encoder.php','shell'],{encoding:'utf8'}).replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,'').replace(/<link[^>]*>/gi,'');
const common=fs.readFileSync('assets/css/admin.css','utf8'),components=fs.readFileSync('assets/css/admin-components.css','utf8'),adapter=fs.readFileSync('assets/js/admin-design.js','utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME});
 try{
  for(const width of [1440,768,390,320]){
   const snapshots=[];
   for(const type of ['standard','media','future']){
    const page=await browser.newPage({viewport:{width,height:1000}});
    const standard='<div class="wrap"><h1>SEO 核心</h1><p>內容與網站設定</p><form action="/save" method="post"><div class="example-panel"><input name="original" value="保留" required><button type="submit" class="button button-primary">儲存</button></div></form><nav class="nav-tab-wrapper"><a class="nav-tab nav-tab-active" href="#first">設定</a><a class="nav-tab" href="#second">紀錄</a></nav><div data-wutm-ui="preserve"><div class="preview-panel"><button class="button">預覽按鈕</button></div></div></div>';
    await page.setContent(type==='media'?media:standard.replace('example-panel',type==='future'?'new-feature-panel':'example-panel'));
    await page.evaluate(()=>{document.body.className='wutm-admin-screen';if(!document.getElementById('wpbody-content')){const main=document.createElement('main');main.id='wpbody-content';while(document.body.firstChild)main.appendChild(document.body.firstChild);document.body.appendChild(main);}window.nativeClicks=0;document.querySelector('button')?.addEventListener('click',e=>{e.preventDefault();window.nativeClicks++;});});
    await page.addStyleTag({content:'body{margin:0;background:#f0f0f1;font:14px system-ui}#wpbody-content{padding-left:10px}'});
    if(type==='media')await page.addStyleTag({path:'modules/media-encoder/admin.css'});
    const before=await page.locator('input,select,textarea').evaluateAll(nodes=>nodes.map(n=>[n.name,n.value,n.form?.action,n.required,n.disabled,n.type]));
    await page.addStyleTag({content:common+components});await page.addScriptTag({content:adapter});
    assert.deepEqual(await page.locator('input,select,textarea').evaluateAll(nodes=>nodes.map(n=>[n.name,n.value,n.form?.action,n.required,n.disabled,n.type])),before,'All form state and owners preserved');
    snapshots.push(await page.locator('.wutm-ui-header,.wutm-ui-title').first().evaluate(e=>{const s=getComputedStyle(e);return [s.backgroundImage,s.borderRadius,s.paddingTop,s.borderTopColor];}));
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,type+' overflow '+width);
    if(type!=='media'){
     assert.equal(await page.locator('.preview-panel.wutm-ui-panel').count(),0,'Preview is not restyled');
     await page.locator('button[type=submit]').click();assert.equal(await page.evaluate(()=>nativeClicks),1,'Original listener retained');
     await page.evaluate(()=>{const node=document.createElement('section');node.className='later-panel';document.querySelector('.wrap').appendChild(node);document.dispatchEvent(new Event('wutm-admin-ui-updated'));});
     assert.equal(await page.locator('.later-panel.wutm-ui-panel').count(),1,'Explicit dynamic adaptation without polling');
    }
    if(process.env.WUTM_VISUAL_OUTPUT){fs.mkdirSync(process.env.WUTM_VISUAL_OUTPUT,{recursive:true});await page.screenshot({path:process.env.WUTM_VISUAL_OUTPUT+'/consistent-'+type+'-'+width+'.png'});}
    await page.close();
   }
   assert.deepEqual(snapshots[0],snapshots[1],'Standard and media headers share computed design');assert.deepEqual(snapshots[0],snapshots[2],'Future modules inherit design');
  }
  console.log('Common components: 12 responsive cases, equal header styling, form state/listener preservation, preview isolation and dynamic opt-in passed.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
