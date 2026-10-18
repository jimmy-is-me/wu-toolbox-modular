const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {execFileSync}=require('node:child_process'),{chromium}=require('playwright');
const wp=process.env.WUTM_TEST_WP;
if(!wp) throw Error('WUTM_TEST_WP must point to an isolated WordPress source tree');
const render=mode=>execFileSync(process.env.WUTM_TEST_PHP||'php',['tests/admin-enhancements.php',mode],{encoding:'utf8'});
const script=render('media-js').replace(/^\s*<script[^>]*>/,'').replace(/<\/script>\s*$/,'');
const mediaCSS=render('media-css').replace(/^\s*<style[^>]*>/,'').replace(/<\/style>\s*$/,'');
const readWP=file=>fs.readFileSync(path.join(wp,file),'utf8');
async function jquery(page,sortable=false) {
 await page.addScriptTag({content:readWP('wp-includes/js/jquery/jquery.js')});
 if(sortable) for(const file of ['core','mouse','sortable']) await page.addScriptTag({content:readWP('wp-includes/js/jquery/ui/'+file+'.js')});
}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME});
 try {
  for(const width of [1440,768,390]) for(const modal of [true,false]) for(const wrapped of [true,false]) {
   const page=await browser.newPage({viewport:{width,height:950}}),errors=[];
   page.on('pageerror',e=>errors.push(e.message));
   const grid='<ul class="attachments"><li class="attachment" style="width:100px;height:100px;background:#dbeafe">商品圖片</li></ul>';
   await page.setContent('<body class="wp-core-ui upload-php"><div class="'+(modal?'media-modal':'')+'"><div class="media-frame '+(modal?'':'mode-grid')+'"><div class="media-frame-content"><div class="attachments-browser hide-sidebar '+(wrapped?'has-load-more':'')+'"><div class="media-toolbar '+(modal?'':'wp-filter')+'"><button>篩選媒體</button></div>'+(wrapped?'<div class="attachments-wrapper">'+grid+'</div>':grid)+'</div></div></div></div>');
   await page.addStyleTag({content:readWP('wp-admin/css/common.css')+readWP('wp-includes/css/media-views.css')+readWP('wp-admin/css/media.css')});
   await page.addStyleTag({content:'body{margin:0}.media-modal{inset:10px}.media-frame{height:800px}.media-frame-content{left:0;top:0;bottom:0}.media-toolbar{height:72px;box-sizing:border-box}'});
   await page.addStyleTag({content:mediaCSS});await jquery(page);
   await page.evaluate(()=>{window.ajaxurl='/admin-ajax.php';jQuery.ajax=()=>jQuery.Deferred().resolve({success:true,data:{folders:[{id:1,parent:0,name:'測試資料夾',count:1}],all_count:1,uncategorized_count:0}}).promise();});
   await page.addScriptTag({content:script});
   await page.waitForSelector('.wumf-panel');await page.waitForTimeout(650);
   const boxes=await page.evaluate(()=>{
    const browser=document.querySelector('.attachments-browser');
    const rect=selector=>{const r=browser.querySelector(selector).getBoundingClientRect();return {x:r.x,y:r.y,right:r.right,bottom:r.bottom,width:r.width,height:r.height};};
    return {panel:rect('.wumf-panel'),grid:rect('.attachments'),toolbar:rect('.media-toolbar'),compact:browser.classList.contains('wumf-compact')};
   });
   assert.ok(boxes.panel.height>30,'Folder pane visible');
   assert.ok(boxes.panel.y>=boxes.toolbar.bottom-2,'Folders below toolbar '+JSON.stringify({width,modal,wrapped,boxes}));
   if(boxes.compact) assert.ok(boxes.grid.y>=boxes.panel.bottom-2,'Mobile grid below drawer '+JSON.stringify({width,modal,wrapped,boxes}));
   else assert.ok(boxes.grid.x>=boxes.panel.right-2,'Grid beside pane '+JSON.stringify({width,modal,wrapped,boxes}));
   assert.ok(boxes.grid.width>60,'Grid has usable width');assert.deepEqual(errors,[]);
   if(modal && width===1440){
    await page.evaluate(()=>document.querySelector('.attachments-browser').classList.remove('hide-sidebar'));
    const right=await page.evaluate(()=>{const b=document.querySelector('.attachments-browser').getBoundingClientRect();const g=document.querySelector('.attachments-wrapper,.attachments').getBoundingClientRect();return b.right-g.right;});
    assert.ok(right>=298,'Core attachment-details sidebar inset retained');
   }
   if(boxes.compact){await page.locator('.wumf-mobile-toggle').click();await page.waitForTimeout(50);assert.equal(await page.locator('.wumf-mobile-toggle').getAttribute('aria-expanded'),'true');const clear=await page.evaluate(()=>document.querySelector('.attachments').getBoundingClientRect().top>=document.querySelector('.wumf-panel').getBoundingClientRect().bottom-2);assert.ok(clear,'Expanded mobile folder drawer must not cover grid');}
   if(process.env.WUTM_VISUAL_OUTPUT){fs.mkdirSync(process.env.WUTM_VISUAL_OUTPUT,{recursive:true});await page.screenshot({path:path.join(process.env.WUTM_VISUAL_OUTPUT,`folders-${width}-${modal}-${wrapped}.png`)});}
   await page.close();
  }
  const page=await browser.newPage({viewport:{width:390,height:950}});
  await page.setContent('<div id="wutm-co-tree" data-object="category"><input type="search"><button data-tree-expand="true">全部展開</button><button data-tree-expand="false">全部收合</button>'+render('tree')+'<span role="status"></span><button id="wutm-tree-save" disabled>儲存</button></div>');
  await page.addStyleTag({content:fs.readFileSync('assets/css/ordering-tree.css','utf8')});await jquery(page,true);
  await page.evaluate(()=>{window.ajaxurl='/ajax';window.WUTMNativeOrdering={nonce:'fixture'};window.saved=[];window.fail=false;jQuery.ajax=options=>{saved.push(options.data);return jQuery.Deferred().resolve({success:!window.fail}).promise();};});
  await page.addScriptTag({content:fs.readFileSync('assets/js/ordering-tree.js','utf8')});
  await page.waitForTimeout(100);
  await page.locator('[data-tree-expand=false]').click();assert.equal(await page.locator('li[data-id="3"]').isVisible(),false);
  await page.locator('input').fill('深層');assert.ok(await page.locator('li[data-id="5"]').isVisible());assert.equal(await page.locator('li[data-id="2"]').isVisible(),false);
  assert.equal(await page.locator('li[data-id="5"] [data-move=up]').isEnabled(),false,'Disable reordering filtered subset');
  await page.locator('[data-tree-expand=true]').click();
  await page.locator('li[data-id="4"] [data-move=up]').click();
  assert.equal(await page.locator('ul[data-parent="1"] > li').first().getAttribute('data-id'),'4');
  await page.evaluate(()=>window.fail=true);await page.locator('#wutm-tree-save').click();await page.waitForFunction(()=>document.querySelector('[role=status]').textContent.includes('尚未儲存'));
  await page.evaluate(()=>window.fail=false);await page.locator('#wutm-tree-save').click();
  await page.waitForFunction(()=>document.querySelector('[role=status]').textContent==='分類順序已儲存');
  const requests=await page.evaluate(()=>saved);assert.equal(requests[1].parent,'1');assert.deepEqual(requests[1].ids,['4','3']);
  assert.equal(await page.locator('#wutm-tree-save').isEnabled(),false);
  console.log('Native media layout (12 cases) and nested tree search, controls, persistence and retry passed.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
