'use strict';
// Optional local headless browser QA using actual PHP-rendered module markup.
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), http = require('node:http');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '..');
const html = mode => execFileSync(process.env.PHP_BINARY || 'php', ['tests/interface-fixture.php', mode], { cwd:root }).toString();
(async () => {
  const fixtures = Object.fromEntries(['faq','cursor','transition'].map(mode => [mode,html(mode)]));
  const server = http.createServer((req,res) => {
    if (/^\/assets\/(?:js|css)\/[a-z-]+\.(?:js|css)$/.test(req.url)) {
      res.setHeader('Content-Type', req.url.endsWith('.js') ? 'text/javascript' : 'text/css');
      return res.end(fs.readFileSync(path.join(root, req.url)));
    }
    const mode = req.url.startsWith('/faq') ? 'faq' : req.url.startsWith('/cursor') ? 'cursor' : 'transition';
    res.setHeader('Content-Type','text/html; charset=utf-8');
    setTimeout(() => res.end(fixtures[mode]), req.url === '/slow/' ? 700 : 0);
  });
  await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
  const origin = 'http://127.0.0.1:' + server.address().port;
  const browser = await chromium.launch({ headless:true, executablePath:process.env.WUTM_QA_BROWSER });
  const errors = [];
  try {
    for (const width of [1280,768,390,320]) {
      const page = await browser.newPage({ viewport:{width,height:900} });
      page.on('pageerror',e=>errors.push(e.message));
      await page.goto(origin+'/faq/');
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'FAQ no page overflow '+width);
      await page.locator('#wutm-product-faq-add-row').click();
      assert.equal(await page.locator('.wutm-faq-row').count(),3);
      await page.locator('input[name="faq_question[]"]').last().fill('新增問題');
      await page.locator('.wutm-faq-editable').last().fill('新增答案');
      const fields = await page.locator('#post').evaluate(form=>({q:new FormData(form).getAll('faq_question[]'),a:new FormData(form).getAll('faq_answer[]')}));
      assert.deepEqual(fields.q,['需要多久出貨？','可以退換貨嗎？','新增問題']);
      assert.ok(fields.a[0].includes('<strong>3～5 個工作天</strong>'));
      assert.equal(fields.a[2],'新增答案');
      page.once('dialog',dialog=>dialog.dismiss());
      await page.locator('.wutm-product-faq-remove-row').last().click();
      assert.equal(await page.locator('.wutm-faq-row').count(),3,'Cancel delete preserves data');
      page.once('dialog',dialog=>dialog.accept());
      await page.locator('.wutm-product-faq-remove-row').last().click();
      assert.equal(await page.locator('.wutm-faq-row').count(),2);
      assert.equal(await page.locator('.wutm-faq-editable').first().getAttribute('aria-label'),'答案');
      if(process.argv[2]){fs.mkdirSync(process.argv[2],{recursive:true});await page.screenshot({path:path.join(process.argv[2],'faq-'+width+'.png'),fullPage:true});}
      await page.close();
    }
    const page = await browser.newPage({viewport:{width:1280,height:900}});
    page.on('pageerror',e=>errors.push(e.message));
    await page.goto(origin+'/cursor/');
    async function position(x,y) {
      await page.mouse.move(x,y);
      await page.waitForTimeout(40);
      const rect=await page.locator('#wutm-minimal-cursor').boundingBox();
      assert.ok(Math.abs(rect.x+rect.width/2-x)<1 && Math.abs(rect.y+rect.height/2-y)<1,JSON.stringify({rect,x,y}));
      assert.equal(await page.evaluate(()=>document.documentElement.classList.contains('wutm-cursor-visible')),true);
    }
    await position(600,450);
    await page.evaluate(()=>{document.body.style.transform='translate(60px,35px) scale(.9)';document.documentElement.style.transform='translate(12px,8px)';});
    await position(640,460);
    await page.evaluate(()=>{document.documentElement.style.transform='';document.body.style.transform='';document.documentElement.style.zoom='1.2';});
    await position(660,480);
    await page.evaluate(()=>{document.documentElement.style.zoom='';scrollTo(0,500);});
    await position(700,420);
    await page.evaluate(()=>{scrollTo(0,0);document.querySelector('#text').dispatchEvent(new PointerEvent('pointerover',{pointerType:'mouse',bubbles:true}));});
    assert.equal(await page.evaluate(()=>document.documentElement.classList.contains('wutm-cursor-enabled')),false,'Text inputs retain native cursor');
    await position(600,400);
    await page.evaluate(()=>document.querySelector('#frame').dispatchEvent(new PointerEvent('pointerover',{pointerType:'mouse',bubbles:true})));
    assert.equal(await page.evaluate(()=>document.documentElement.classList.contains('wutm-cursor-enabled')),false,'Iframe does not leave a frozen custom cursor');
    await position(600,400);
    await page.evaluate(()=>document.body.dispatchEvent(new PointerEvent('pointermove',{pointerType:'touch',bubbles:true})));
    assert.equal(await page.evaluate(()=>document.documentElement.classList.contains('wutm-cursor-visible')),false,'Touch hides mouse cursor');
    await position(620,410);
    await page.evaluate(()=>window.dispatchEvent(new Event('blur')));
    assert.equal(await page.evaluate(()=>document.documentElement.classList.contains('wutm-cursor-enabled')),false);
    await page.evaluate(()=>{
      window.framesRequested=0;const raf=window.requestAnimationFrame;window.requestAnimationFrame=function(fn){framesRequested++;return raf(fn);};
      for(let i=0;i<100;i++)document.body.dispatchEvent(new PointerEvent('pointermove',{pointerType:'mouse',clientX:500+i,clientY:300,bubbles:true}));
    });
    assert.equal(await page.evaluate(()=>framesRequested),1,'100 pointer events schedule one animation frame');
    await page.waitForTimeout(40);
    await page.goto(origin+'/transition/');
    const shows=[];
    page.on('console',message=>{if(message.text().startsWith('SHOW:'))shows.push(message.text());});
    await page.addInitScript(()=>{new MutationObserver(()=>{if(document.documentElement && document.documentElement.classList.contains('wupt-show'))console.log('SHOW:'+location.pathname);}).observe(document,{subtree:true,attributes:true,attributeFilter:['class']});});
    const start=Date.now();await page.evaluate(()=>document.querySelector('#fast').click());await page.waitForURL('**/fast/');
    assert.ok(Date.now()-start<350,'Fast navigation has no 380ms artificial delay');
    assert.equal(await page.evaluate(()=>sessionStorage.getItem('wumetax_page_transition_v220')),null,'No animation marker for a fast page');
    await page.goto(origin+'/transition/');
    shows.length=0;
    await page.evaluate(()=>document.querySelector('#slow').click());
    await page.waitForURL('**/slow/');
    assert.ok(shows.includes('SHOW:/transition/'),'Only slow navigation shows the departing loader');
    assert.equal(await page.evaluate(()=>document.documentElement.classList.contains('wupt-show')),false,'Arrival has no mandatory hold');
    for(const id of ['cancel','anchor','ajax']){
      await page.goto(origin+'/transition/');await page.evaluate(id=>document.querySelector('#'+id).click(),id);await page.waitForTimeout(300);
      assert.equal(await page.evaluate(()=>document.documentElement.classList.contains('wupt-show')),false,id+' skipped');
    }
    await page.emulateMedia({reducedMotion:'reduce'});
    shows.length=0;
    await page.goto(origin+'/transition/');await page.evaluate(()=>document.querySelector('#slow').click());await page.waitForURL('**/slow/');
    assert.deepEqual(shows,[],'Reduced motion skips loader');
    assert.deepEqual(errors,[]);
    await page.close();
    console.log('Interface browser QA passed: FAQ CRUD/RWD, cursor transforms/zoom/scroll/iframes/touch/frame budget, native fast/slow navigation and reduced motion.');
  } finally {await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(e=>{console.error(e);process.exitCode=1;});

