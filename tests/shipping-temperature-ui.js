const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process'),{chromium}=require('playwright');
(async()=>{
 const html=execFileSync(process.env.WUTM_TEST_PHP||'php',['tests/shipping-temperature-preview.php'],{encoding:'utf8'});
 const jquery=fs.readFileSync(path.join(process.env.WUTM_TEST_WP||'C:/Users/jimin/Documents/Codex/2026-10-15/new-chat/work/integration/wordpress','wp-includes/js/jquery/jquery.js'),'utf8');
 const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME});
 try {for(const width of [1280,768,390,320]){
  const page=await browser.newPage({viewport:{width,height:900}}),errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.setContent('<meta name="viewport" content="width=device-width"><style>body{margin:0}*{box-sizing:border-box}body{font:14px sans-serif}main{padding:10px}table{width:100%}input,select{max-width:100%}</style><body class="wutm-admin-screen"><main>'+html+'</main></body>');
  await page.addStyleTag({content:fs.readFileSync('assets/css/admin.css','utf8')+fs.readFileSync('modules/shipping-temperature/admin.css','utf8')});
  await page.addScriptTag({content:jquery});await page.evaluate(()=>{window.editCalls=0;window.inlineEditPost={edit(){window.editCalls++;},getId(element){return element.id.replace('post-','');}};});
  await page.addScriptTag({content:fs.readFileSync('modules/shipping-temperature/admin.js','utf8')});await page.evaluate(()=>new Promise(resolve=>jQuery(resolve)));
  await page.evaluate(()=>inlineEditPost.edit(1));assert.equal(await page.locator('#edit-1 select').inputValue(),'cold');assert.equal(await page.locator('#edit-2 select').inputValue(),'all');
  await page.evaluate(()=>inlineEditPost.edit(document.getElementById('post-2')));assert.equal(await page.locator('#edit-2 select').inputValue(),'room');assert.equal(await page.evaluate(()=>editCalls),2);
  assert.equal(await page.locator('[name=_stt_bulk]').inputValue(),'');assert.equal(await page.locator('[name="room[]"]:checked').count(),1);assert.equal(await page.locator('[name="cold[]"]:checked').count(),1);
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));assert.deepEqual(errors,[]);await page.close();
 }console.log('Temperature browser passed: four widths, settings, quick-edit isolation, bulk no-change and original editor delegation.');}finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
