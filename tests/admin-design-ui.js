const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const php = process.env.WUTM_TEST_PHP || 'php';
const phpArgs=JSON.parse(process.env.WUTM_TEST_PHP_ARGS || '[]');
const fontDir=process.env.WUTM_TEST_FONT_DIR;
const fontCSS=fontDir ? '@font-face{font-family:"Noto Sans TC";src:url(data:font/woff2;base64,'+fs.readFileSync(path.join(fontDir,'noto-sans-tc-chinese-traditional-400-normal.woff2')).toString('base64')+')}body{font-family:"Noto Sans TC",sans-serif!important}' : '';
const render = mode => execFileSync(php, [...phpArgs, 'tests/admin-design.php', mode], {cwd:root,encoding:'utf8'});
(async () => {
 const dashboard=render('dashboard'), settings=render('settings');
 const server=http.createServer((req,res)=>{
  if(req.url==='/dashboard'||req.url==='/settings') {res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(req.url==='/dashboard'?dashboard:settings);}
  if(req.url.startsWith('/assets/')) {res.setHeader('Content-Type',req.url.endsWith('.css')?'text/css':'application/javascript');return res.end(fs.readFileSync(path.join(root,req.url)));}
  res.writeHead(404).end();
 });
 await new Promise(r=>server.listen(0,'127.0.0.1',r));
 server.unref();
 const origin='http://127.0.0.1:'+server.address().port;
 const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME,args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 try {
  for(const width of [1440,1024,768,390,320]) {
   const page=await browser.newPage({viewport:{width,height:1000}}),errors=[];
   page.on('pageerror',e=>errors.push(e.message));
   await page.route('**/wp-admin/admin-ajax.php',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({success:true})}));
   await page.goto(origin+'/dashboard');
   if(fontCSS){await page.addStyleTag({content:fontCSS});await page.evaluate(()=>document.fonts.ready);}
   assert.ok(await page.locator('.wutm-card').count()>100,'All registry modules present');
   assert.equal(await page.locator('.wutm-ui-header').count(),1);
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Dashboard overflow '+width);
   const geometry=await page.evaluate(()=>{const box=s=>document.querySelector(s).getBoundingClientRect();return {search:box('#wutm-module-search-input').height,tabs:box('.wutm-module-tabs').height,rows:[...document.querySelectorAll('.wutm-module-tab')].map(e=>Math.round(e.getBoundingClientRect().top))};});
   assert.equal(geometry.search,geometry.tabs,'Search and tabs equal height '+width);
   assert.equal(new Set(geometry.rows).size,1,'All tabs remain on one row '+width);
   assert.equal(await page.locator('.wutm-signal-bars').count(),1,'Reference signal detail present');
   const sharing=page.locator('.wutm-share-toggle:not(:disabled)').first(),oldShare=await sharing.isChecked();
   await page.route('**/wp-admin/admin-ajax.php',async route=>{const params=new URLSearchParams(route.request().postData());await route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data:{enabled:params.get('enabled')==='1'}})});});
   await sharing.setChecked(!oldShare);await page.waitForFunction(()=>!!document.querySelector('.wutm-share-control[title]'));
   assert.equal(await sharing.isChecked(),!oldShare,'Separate sharing saved');
   await page.route('**/wp-admin/admin-ajax.php',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({success:false,data:{message:'fixture rejected'}})}));
   await sharing.setChecked(oldShare);await page.waitForFunction(()=>document.querySelector('.wutm-notice-slot').textContent.includes('fixture rejected'));
   assert.equal(await sharing.isChecked(),!oldShare,'Failed sharing save restores previous state');
   await page.route('**/wp-admin/admin-ajax.php',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({success:true})}));
   const checkbox=page.locator('.wutm-switch input:not(:disabled)').first();
   const was=await checkbox.isChecked(), count=Number(await page.locator('.wutm-enabled-count').textContent());
   await checkbox.setChecked(!was);
   await page.waitForFunction(n=>Number(document.querySelector('.wutm-enabled-count').textContent)!==n,count);
   assert.equal(Number(await page.locator('.wutm-enabled-count').textContent()),count+(was?-1:1));
   await page.locator('[data-filter="enabled"]').click();
   assert.equal(await page.locator('.wutm-card:not(.on):visible').count(),0);
   await page.locator('[data-filter="disabled"]').click();
   assert.equal(await page.locator('.wutm-card.on:visible').count(),0);
   await page.locator('[data-filter="all"]').click();
   await page.locator('#wutm-module-search-input').fill('鼠標');
   await page.locator('.wutm-module-search button[type=submit]').click();
   assert.ok((await page.locator('.wutm-search-status').textContent()).includes('鼠標'));
   await page.locator('.wutm-search-result').first().click();
   assert.ok(await page.locator('.wutm-card.is-search-match').count());
   await page.emulateMedia({reducedMotion:'reduce'});
   assert.equal(await page.locator('.wutm-ui-header').evaluate(e=>getComputedStyle(e,'::after').animationName),'none');
   if(process.env.WUTM_VISUAL_OUTPUT) {fs.mkdirSync(process.env.WUTM_VISUAL_OUTPUT,{recursive:true});await page.screenshot({path:path.join(process.env.WUTM_VISUAL_OUTPUT,'dashboard-'+width+'.png')});}
   await page.goto(origin+'/settings');
   if(fontCSS){await page.addStyleTag({content:fontCSS});await page.evaluate(()=>document.fonts.ready);}
   assert.equal(await page.locator('.wutm-ui-title').count(),1);
   assert.equal(await page.locator('.wutm-ui-panel').count(),1);
   const fields=await page.locator('form input').evaluateAll(nodes=>nodes.map(n=>[n.name,n.value]));
   assert.ok(fields.some(([name])=>name==='wutm_custom_cursor_options[ring_color]'));
   assert.equal(await page.locator('form').getAttribute('action'),'options.php');
   await page.locator('#wutm-cursor-ring').fill('#112233');
   assert.equal(await page.locator('#wutm-cursor-ring + code').textContent(),'#112233');
   assert.equal(await page.locator('.wutm-cursor-preview').evaluate(e=>getComputedStyle(e).getPropertyValue('--cursor-ring')),'#112233');
   const previewButton=await page.locator('.wutm-cursor-preview button').boundingBox();
   await page.mouse.move(previewButton.x+10,previewButton.y+10);
   assert.ok(await page.locator('.wutm-cursor-preview.is-hover').count(),'Live hover preview');
   await page.mouse.down();assert.ok(await page.locator('.wutm-cursor-preview.is-down').count());await page.mouse.up();
   await page.mouse.move(0,0);assert.equal(await page.locator('.wutm-cursor-preview.is-hover').count(),0);
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Settings overflow '+width);
   const unchanged=await page.locator('#wutm-cursor-dot').inputValue();assert.equal(unchanged,'#4fa567');
   if(process.env.WUTM_VISUAL_OUTPUT) await page.screenshot({path:path.join(process.env.WUTM_VISUAL_OUTPUT,'settings-'+width+'.png')});
   assert.deepEqual(errors,[]);
   await page.close();
  }
  // Adapt structurally different real module templates without moving submitted controls.
  for (const [file,mode] of [['content-modules','overview'],['media-encoder','shell'],['email-tracking','visual']]) {
   const html=execFileSync(php,[...phpArgs,'tests/'+file+'.php',mode],{cwd:root,encoding:'utf8'}).replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi,'');
   for(const width of [1440,1024,768,390]) {
    const page=await browser.newPage({viewport:{width,height:1000}});
    await page.setContent(html.replace(/<link[^>]*>/gi,''));
    const nativeCSS={'media-encoder':'modules/media-encoder/admin.css','email-tracking':'modules/email-tracking/admin.css'}[file];
    if(nativeCSS) await page.addStyleTag({content:fs.readFileSync(path.join(root,nativeCSS),'utf8')});
    const before=await page.locator('input,select,textarea').evaluateAll(nodes=>nodes.map(n=>[n.name,n.value,n.form&&n.form.action]));
    await page.addStyleTag({content:fs.readFileSync(path.join(root,'assets/css/admin.css'),'utf8')+fs.readFileSync(path.join(root,'assets/css/admin-components.css'),'utf8')+fontCSS});
    await page.evaluate(()=>{document.body.classList.add('wutm-admin-screen');if(!document.querySelector('#wpbody-content')){const main=document.createElement('main');main.id='wpbody-content';while(document.body.firstChild)main.appendChild(document.body.firstChild);document.body.appendChild(main);}const content=document.querySelector('#wpbody-content');if(!content.querySelector(':scope > .wrap')){const wrap=document.createElement('div');wrap.className='wrap';const h=document.createElement('h1');h.textContent='郵件追蹤';wrap.appendChild(h);while(content.firstChild)wrap.appendChild(content.firstChild);content.appendChild(wrap);}});
    await page.addScriptTag({content:fs.readFileSync(path.join(root,'assets/js/admin-design.js'),'utf8')});
    await page.evaluate(()=>document.fonts.ready);
    assert.ok(await page.locator('.wutm-ui-root').count(),'Template root adapted: '+file);
    const after=await page.locator('input,select,textarea').evaluateAll(nodes=>nodes.map(n=>[n.name,n.value,n.form&&n.form.action]));
    assert.deepEqual(after,before,'Native field identities and form owners: '+file);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,file+' overflow '+width);
    if(process.env.WUTM_VISUAL_OUTPUT) await page.screenshot({path:path.join(process.env.WUTM_VISUAL_OUTPUT,file+'-'+width+'.png')});
    await page.close();
   }
  }
  // Contact widgets has a sibling hero and form root rather than a shared wrap.
  const composite=await browser.newPage({viewport:{width:390,height:900}});
  await composite.setContent('<body class="wutm-admin-screen"><main id="wpbody-content"><div class="ysch-hero"><div class="ysch-hero-title">聯絡按鈕</div></div><div class="ysch-admin-wrap"><form action="options.php"><input name="contact_setting" value="preserved"></form></div></main>');
  await composite.addStyleTag({content:fs.readFileSync(path.join(root,'assets/css/admin.css'),'utf8')});
  await composite.addScriptTag({content:fs.readFileSync(path.join(root,'assets/js/admin-design.js'),'utf8')});
  assert.equal(await composite.locator('.wutm-ui-root').count(),2);
  assert.ok((await composite.locator('.ysch-hero').evaluate(e=>getComputedStyle(e).backgroundImage)).includes('gradient'),'Top-level hero remains legible against server root styling');
  assert.equal(await composite.locator('[name=contact_setting]').inputValue(),'preserved');
  await composite.close();
  // Same stylesheet on an unowned screen cannot affect native markup.
  const page=await browser.newPage();await page.goto(origin+'/settings');
  const result=await page.evaluate(()=>{
   document.body.classList.remove('wutm-admin-screen');
   const h=document.querySelector('h1'), table=document.querySelector('table');
   return [getComputedStyle(h).backgroundColor,getComputedStyle(table).borderTopWidth];
  });
  assert.deepEqual(result,['rgba(0, 0, 0, 0)','0px']);
  console.log('Admin design browser checks passed: 5 dashboard widths, equal-height single-row tabs, separate sharing save/rollback, 100+ cards, toggles/counts, search, filters, original forms, reduced motion and foreign-screen isolation.');
 } finally {await browser.close();server.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
