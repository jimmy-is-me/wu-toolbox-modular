const fs=require('node:fs'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process'),{chromium}=require('playwright');
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME});try{
 for(const width of [1440,768,390,320]){const page=await browser.newPage({viewport:{width,height:900}});
 await page.setContent('<meta name="viewport" content="width=device-width"><body class="wutm-admin-screen"><style>body{margin:0}*{box-sizing:border-box}.wutm-ui-root{padding:10px}table{width:100%}</style>'+execFileSync(process.env.WUTM_TEST_PHP||'php',['tests/v395-preview.php'],{encoding:'utf8'}));
 await page.addStyleTag({content:fs.readFileSync('assets/css/admin.css','utf8')});
 await page.locator('.wutm-order-layout-rows tr').nth(1).locator('.wutm-order-up').click();
 assert.deepEqual(await page.locator('input[name="order[]"]').evaluateAll(ns=>ns.map(n=>n.value)),['order_date','order_number','order_status','order_total','tracking']);
 await page.locator('input[name="labels[order_total]"]').fill('訂單金額');await page.locator('input[name="hide[tracking]"]').check();
 const values=await page.locator('form').evaluate(f=>Array.from(new FormData(f).entries()));assert(values.some(([k,v])=>k==='labels[order_total]'&&v==='訂單金額'));assert(values.some(([k])=>k==='hide[tracking]'));
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await page.close();
 }console.log('v3.9.5 orders browser passed: reorder, labels, hidden form values and four viewport widths.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
