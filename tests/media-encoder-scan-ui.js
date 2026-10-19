const assert=require('node:assert/strict'),fs=require('node:fs'),{execFileSync}=require('node:child_process'),{chromium}=require('playwright');
const args=JSON.parse(process.env.WUTM_TEST_PHP_ARGS||'[]'),php=process.env.WUTM_TEST_PHP||'php';
const shell=execFileSync(php,[...args,'tests/media-encoder.php','shell'],{encoding:'utf8'}),script=fs.readFileSync('modules/media-encoder/admin.js','utf8');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME});
 try{
  const page=await browser.newPage(),calls=[],errors=[];let failing=false,stalled=false;
  const files=Array.from({length:70},(_,i)=>({id:1000-i,name:'file-'+i,title:'媒體 '+i,mime:['image/png','image/webp','application/pdf','video/mp4'][i%4],width:i%4<2?300:0,height:i%4<2?200:0,bytes:100+i,thumb:'',edit:'#edit',convertible:i%4===0,saving:null}));
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://example.test/wp-admin/admin-ajax.php',async route=>{
   const fields=Object.fromEntries(new URLSearchParams(route.request().postData()));calls.push(fields);
   const cursor=Number(fields.cursor);
   if(failing&&cursor>0)return route.fulfill({status:500,contentType:'application/json',headers:{'access-control-allow-origin':'*'},body:JSON.stringify({success:false,data:{message:'fixture read failure'}})});
   const eligible=files.filter(r=>(fields.scope==='all'||r.mime.startsWith('image/'))&&(!cursor||r.id<cursor)),rows=eligible.slice(0,20);
   const data={rows,cursor:stalled&&cursor?cursor:rows.at(-1)?.id||0,more:eligible.length>20,at:'fixture time'};
   await route.fulfill({contentType:'application/json',headers:{'access-control-allow-origin':'*'},body:JSON.stringify({success:true,data})});
  });
  async function reset(){await page.setContent(shell);await page.addScriptTag({content:script});await page.waitForTimeout(50);}
  await reset();assert.equal(calls.length,0,'Opening never scans');
  await page.locator('[data-scan]').first().click();await page.waitForFunction(()=>document.querySelector('#wme-scan-label').textContent.includes('已完成'));
  assert.equal(await page.locator('#wme-stat-count').textContent(),'70','All file types counted');
  assert.equal(await page.locator('#wme-rows tr').count(),20,'Do not render the entire library into the DOM');
  assert.ok((await page.locator('#wme-summary').textContent()).includes('圖片 36 張'));
  assert.equal(await page.locator('#wme-rows tr').filter({hasText:'非圖片'}).locator('[data-convert]').count(),0,'PDF/video are read only');
  assert.ok(calls.every(c=>c.scope==='all'&&c.operation==='list'),'No conversion is started by read-all');
  const completeCalls=calls.length;await page.waitForTimeout(500);assert.equal(calls.length,completeCalls,'No post-completion polling');
  await page.locator('[data-load]').last().click();await page.waitForFunction(()=>document.querySelector('#wme-scan').hidden);
  assert.equal(await page.locator('#wme-stat-count').textContent(),'20','Regular recent-images read resets cumulative scope');
  await reset();const stopStart=calls.length;
  await page.locator('[data-scan]').first().click();await page.waitForFunction(()=>Number(document.querySelector('#wme-stat-count').textContent)>=20);
  await page.locator('#wme-scan-stop').click();await page.locator('#wme-scan-resume').waitFor();
  const pausedCalls=calls.length;await page.waitForTimeout(500);assert.equal(calls.length,pausedCalls,'Stop prevents following page requests');
  assert.ok(pausedCalls>stopStart);await page.locator('#wme-scan-resume').click();await page.waitForFunction(()=>document.querySelector('#wme-scan-label').textContent.includes('已完成'));
  assert.equal(await page.locator('#wme-stat-count').textContent(),'70','Resume does not count successful pages twice');
  await reset();failing=true;await page.locator('[data-scan]').first().click();await page.waitForFunction(()=>document.querySelector('#wme-message').dataset.error==='true');
  assert.equal(await page.locator('#wme-stat-count').textContent(),'20','Failure retains successful accumulated data');
  failing=false;await page.locator('#wme-scan-resume').click();await page.waitForFunction(()=>document.querySelector('#wme-scan-label').textContent.includes('已完成'));
  assert.equal(await page.locator('#wme-stat-count').textContent(),'70','Retry starts at last successful cursor');
  await reset();stalled=true;await page.locator('[data-scan]').first().click();await page.waitForFunction(()=>document.querySelector('#wme-message').textContent.includes('游標無效'));
  const invalidCalls=calls.length;await page.waitForTimeout(500);assert.equal(calls.length,invalidCalls,'Non-decreasing cursor cannot create an infinite scan');
  assert.deepEqual(errors,[]);assert.ok(calls.every(c=>c.nonce==='fixture-nonce'&&c.action==='wutm_media_admin'));
  console.log('Manual full-library read passed: all media types, 20-row DOM, stop/resume, no double counts, failure retry, cursor guard, original mode and no polling/conversions.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
