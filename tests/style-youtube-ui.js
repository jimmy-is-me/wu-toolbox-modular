const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {execFileSync}=require('node:child_process'),{chromium}=require('playwright');
const php=process.env.WUTM_TEST_PHP||'php',wp=process.env.WUTM_TEST_WP;
const render=(...args)=>execFileSync(php,['tests/style-youtube.php',...args],{encoding:'utf8'});
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME});
 try{
  for(const theme of ['native','newsprint','botanical','flat','bauhaus'])for(const width of [1440,390]){
   const page=await browser.newPage({viewport:{width,height:1000}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.setContent(render('style',theme));
   if(wp)await page.addStyleTag({content:fs.readFileSync(path.join(wp,'wp-admin/css/common.css'),'utf8')+fs.readFileSync(path.join(wp,'wp-admin/css/forms.css'),'utf8')});
   await page.addStyleTag({content:fs.readFileSync('assets/css/admin.css','utf8')});await page.addScriptTag({path:'assets/js/admin-design.js'});
   assert.equal(await page.locator('input[type=radio]').count(),5,'Five theme choices');
   assert.equal(await page.locator('input[type=checkbox]').count(),8,'All original toggles');
   assert.equal(await page.locator('[name="np_admin_opts[theme]"]:checked').inputValue(),theme);
   assert.equal(await page.locator('form').getAttribute('action'),'options.php');
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'Style RWD '+theme+' '+width);
   assert.deepEqual(errors,[]);
   if(process.env.WUTM_VISUAL_OUTPUT){fs.mkdirSync(process.env.WUTM_VISUAL_OUTPUT,{recursive:true});await page.screenshot({path:path.join(process.env.WUTM_VISUAL_OUTPUT,'style-'+theme+'-'+width+'.png')});}
   await page.close();
  }
  const page=await browser.newPage();await page.setContent(render('youtube'));assert.equal(await page.locator('input[type=checkbox]').count(),10);await page.close();
  const nojs=await browser.newPage({javaScriptEnabled:false});await nojs.route('**youtube**',r=>r.abort());await nojs.setContent(render('facade'));
  const iframe=await nojs.locator('noscript iframe').boundingBox(),facade=await nojs.locator('.cyp-facade').boundingBox();assert.ok(iframe.width>200&&iframe.height>100&&iframe.width>=facade.width-1,'No-JS iframe occupies the facade area');await nojs.close();
  for(const lazy of [1,0]){
   const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));let loads=0,fail=true;
   const mock=`window.players=[];window.Plyr=class {constructor(el,options){this.el=el;this.options=options;this.handlers={};this.playing=false;players.push(this);}on(n,fn){this.handlers[n]=fn;}once(n,fn){if(n==='ready')setTimeout(fn,0);}play(){this.playing=true;this.handlers.play?.();return Promise.resolve();}pause(){this.playing=false;}destroy(fn){this.el.remove();fn();}};`;
   await page.route('https://assets.test/plyr.css',r=>r.fulfill({contentType:'text/css',body:'.cyp-video{min-height:50px}'}));
   await page.route('https://assets.test/plyr.js',r=>{loads++;if(fail)return r.abort();return r.fulfill({contentType:'text/javascript',body:mock});});
   await page.setContent('<div class="cyp-video" data-id="abcdefghijk"><button class="cyp-facade">Play first</button></div><div class="cyp-video" data-id="lmnopqrstuv"><button class="cyp-facade">Play second</button></div>');
   await page.evaluate(lazy=>window.cypCfg={lazy,js:'https://assets.test/plyr.js',css:'https://assets.test/plyr.css',bigplay:1,controls:1,fs:1,nocookie:1,rel:1},lazy);
   await page.addScriptTag({content:render('loader')});await page.waitForTimeout(100);assert.equal(loads,0,'No dependency request before interaction');
   await page.locator('.cyp-facade').first().click();await page.waitForFunction(()=>!document.querySelector('.cyp-video').__busy);
   fail=false;await page.locator('.cyp-facade').first().click();await page.waitForFunction(()=>window.players?.length===1&&players[0].playing);
   assert.ok((await page.evaluate(()=>players[0].options.controls)).includes('fullscreen'));
   await page.locator('.cyp-facade').click();await page.waitForFunction(()=>players.length===2&&players[1].playing);
   assert.equal(await page.evaluate(()=>players[0].playing),false,'Only one player plays');
   await page.evaluate(()=>players[1].handlers.ended());await page.waitForSelector('.cyp-video:nth-child(2) .cyp-facade');
   assert.deepEqual(errors,[],'Failed dependency loads retry without unhandled errors');await page.close();
  }
  console.log('Style/YouTube browser checks passed: five themes, mobile fields, lazy/eager recovery, controls, exclusive playback and ended facade restore.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
