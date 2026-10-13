const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const {execFileSync}=require('node:child_process');
const php=process.env.WUTM_TEST_PHP||'php';
const fixture=(mode,tab,cold)=>execFileSync(php,['tests/wc-live-insights.php',mode,...(tab?[tab]:[]),...(cold?['cold']:[])],{encoding:'utf8'});
const shell=fixture('shell'),settings=fixture('settings');
const scripts=[fs.readFileSync('modules/wc-live-insights/live.js','utf8')];
assert.equal(scripts.length,1);for(const script of scripts)new vm.Script(script);
if(!process.env.WUTM_TEST_CHROME){console.log('Live insights original interface script syntax passed.');process.exit(0);}
const {chromium}=require('playwright');
(async()=>{
 const overview=JSON.parse(fixture('overview')),orders=JSON.parse(fixture('orders')),customers=JSON.parse(fixture('customers'));
 const out=process.env.WUTM_VISUAL_OUTPUT;fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({executablePath:process.env.WUTM_TEST_CHROME,headless:true});
 try{
 const page=await browser.newPage(),errors=[],calls=[];page.on('pageerror',e=>errors.push(e.message));
 let payload=overview,fail=false,pause=false,hold=false;
 await page.route('https://example.test/plugins/wc-live-insights/live.js*',route=>route.fulfill({contentType:'application/javascript',body:scripts[0]}));
 await page.route('https://example.test/wp-admin/admin-ajax.php',async route=>{
  const params=new URLSearchParams(route.request().postData());calls.push(Object.fromEntries(params));
  let data;
  if(params.get('operation')==='report_cancel')return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data:{stopped:true}})});
  if(params.get('operation')==='report_step'&&pause){pause=false;return route.fulfill({status:400,contentType:'application/json',body:JSON.stringify({success:false,data:{paused:true,message:'本輪讀取已暫停'}})});}
  if(fail){return route.fulfill({status:500,contentType:'text/html',body:'<!DOCTYPE html>Server error',headers:{'access-control-allow-origin':'*'}});}
  if(params.get('operation')==='report_start')data={pending:true,token:'fixture-token-123',seq:0,done:0,phase:'current'};
  else if(params.get('operation')==='report_step'&&(params.get('seq')==='0'||hold))data={pending:true,token:'fixture-token-123',seq:Number(params.get('seq'))+1,done:500,phase:'current'};
  else if(params.get('operation')==='orders_page')data={...orders,page:Number(params.get('order_page'))};
  else if(params.get('operation')==='lifetime')data={lifetime:{first_order:'2020-01-01',collected:9999,orders:9},read_at:'fixture-time',currency:'TWD'};
  else data=payload;
  return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data}),headers:{'access-control-allow-origin':'*'}});
 });
 for(const width of [1440,390,320]){
  await page.setViewportSize({width,height:1000});
  const before=calls.length;await page.setContent(settings);await page.waitForTimeout(50);assert.equal(calls.length,before,'Toolbox settings never query reports');
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'Settings RWD '+width);
  await page.screenshot({path:path.join(out,'insights-settings-'+width+'.png'),fullPage:true});
  payload=overview;await page.setContent(shell);await page.waitForFunction(()=>document.querySelector('#jmi-insights').dataset.bound==='true');
  const idle=calls.length;await page.waitForTimeout(600);assert.equal(calls.length,idle,'Opening panel does not scan orders');
  await page.locator('#jmi-reload').click();await page.waitForFunction(()=>document.querySelector('.jmi-load-status').textContent.startsWith('已完成。'));
  assert.equal(await page.locator('.jmi-pulse-kpis .jmi-kpi').count(),4);
  assert.equal(await page.locator('#jmi-insights>.nav-tab-wrapper>a').count(),6);
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'Main panel RWD '+width);
  const after=calls.length;await page.waitForTimeout(450);assert.equal(calls.length,after,'No recurring polling after completion');
  await page.locator('.jmi-trend-point').first().focus();assert.match(await page.locator('.jmi-trend-detail').textContent(),/當日營收/);
  await page.locator('#jmi-orders-load').click();await page.waitForFunction(()=>document.querySelector('#jmi-order-count').textContent.includes('第 1'));
  await page.locator('#jmi-filter-orders').click();assert.equal(await page.locator('#jmi-filter-orders').getAttribute('aria-expanded'),'true');
  await page.locator('#jmi-order-q').fill('NO MATCH');assert.equal(await page.locator('.jmi-search-empty').isVisible(),true);
  await page.locator('#jmi-order-q').fill('');
  await page.screenshot({path:path.join(out,'insights-overview-'+width+'.png'),fullPage:true});
 }
 await page.locator('#jmi-order-next').click();await page.waitForFunction(()=>document.querySelector('#jmi-order-count').textContent.includes('第 2'));
 await page.locator('#jmi-reload').click();await page.waitForFunction(()=>document.querySelector('.jmi-load-status').textContent.startsWith('已完成。'));
 await page.locator('#jmi-orders-load').click();await page.waitForFunction(()=>document.querySelector('#jmi-order-count').textContent.includes('第 1'));
 await page.locator('#jmi-order-next').click();await page.waitForFunction(()=>document.querySelector('#jmi-order-count').textContent.includes('第 2'));
 assert.equal(calls.filter(c=>c.operation==='orders_page').at(-1).order_page,'2','Reload resets recent-order page cursor');
 const old=await page.locator('#jmi-report').innerHTML();fail=true;await page.locator('#jmi-reload').click();
 await page.waitForFunction(()=>document.querySelector('.jmi-load-status').dataset.error==='true');
 assert.equal(await page.locator('#jmi-report').innerHTML(),old,'Failed refresh retains complete previous report');
 assert.match(await page.locator('.jmi-load-status').textContent(),/沒有回傳有效資料/);fail=false;
 payload=customers;await page.locator('#jmi-reload').click();await page.locator('.jmi-lifetime').waitFor();
 const beforeHistory=calls.filter(c=>c.operation==='lifetime').length;assert.equal(beforeHistory,0,'Customer history loads only when clicked');
 await page.locator('.jmi-lifetime').click();await page.waitForFunction(()=>document.querySelector('.jmi-lifetime-result').textContent.includes('9999')||document.querySelector('.jmi-lifetime-result').textContent.includes('9,999'));
 const downloadPromise=page.waitForEvent('download');await page.locator('.jmi-export').click();const download=await downloadPromise;
 await download.saveAs(path.join(out,'insights.csv'));const csv=fs.readFileSync(path.join(out,'insights.csv'),'utf8');
 assert.match(csv,/'=2\+2/,'CSV formula injection protection preserved');
 for(const tab of ['products','stock','abc','finance']){
  payload=JSON.parse(fixture(tab));await page.setContent(fixture('shell',tab,true));
  await page.waitForFunction(()=>document.querySelector('#jmi-insights').dataset.bound==='true');await page.locator('#jmi-reload').click();
  await page.waitForFunction(()=>document.querySelector('.jmi-load-status').textContent.startsWith('已完成。'));
  assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'Report RWD '+tab);
  assert.equal(await page.locator('.jmi-export').isEnabled(),true,'Completed report CSV available '+tab);
  if(tab==='finance')assert.equal(await page.locator('input[name^="context["]').count(),16,'Every original financial input preserved');
  await page.screenshot({path:path.join(out,'insights-'+tab+'-320.png'),fullPage:true});
 }
 await page.setContent(fixture('shell','products'));await page.waitForFunction(()=>document.querySelector('#jmi-insights').dataset.bound==='true');
 const warmCalls=calls.length;await page.waitForTimeout(700);assert.equal(calls.length,warmCalls,'Warm SSR uses snapshot without AJAX');
 assert.match(await page.locator('#jmi-read-at').textContent(),/完成報表快取/);
 const complete=await page.locator('#jmi-report').innerHTML();hold=true;await page.locator('#jmi-reload').click();
 await page.waitForFunction(()=>document.querySelector('.jmi-load-status').textContent.includes('分批整理中'));
 await page.locator('#jmi-stop').click();await page.waitForTimeout(700);
 const stopped=calls.filter(c=>c.operation==='report_step').length;await page.waitForTimeout(1000);
 assert.equal(calls.filter(c=>c.operation==='report_step').length,stopped,'Stop prevents further batches');
 assert.equal(await page.locator('#jmi-report').innerHTML(),complete,'Stop preserves complete report');hold=false;
 pause=true;await page.locator('#jmi-reload').click();await page.waitForFunction(()=>document.querySelector('#jmi-reload').textContent==='繼續讀取');
 const pausedCount=calls.length;await page.waitForTimeout(1000);assert.equal(calls.length,pausedCount,'Paused server is not polled');
 await page.locator('#jmi-reload').click();await page.waitForFunction(()=>document.querySelector('.jmi-load-status').textContent.startsWith('已完成。'));
 assert.ok(calls.some(c=>c.operation==='report_step'&&c.resume==='1'),'Explicit resume resets server round budget');
 assert.deepEqual(errors,[]);console.log('Live insights Chromium passed: manual loading, warm zero-query view, stop/resume, RWD, batching, no polling, filters, pagination, failure preservation, history and safe CSV.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
