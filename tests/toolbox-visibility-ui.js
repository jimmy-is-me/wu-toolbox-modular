const assert=require('node:assert/strict'),path=require('node:path'),{execFileSync}=require('node:child_process'),{chromium}=require('playwright');
(async()=>{
    const browser=await chromium.launch({headless:true,executablePath:process.env.WUTM_TEST_CHROME});
    try {
        for(const mode of ['default','active'])for(const width of [1280,768,390,320]){
            const html=execFileSync(process.env.WUTM_TEST_PHP||'php',['tests/toolbox-visibility-preview.php',mode],{cwd:path.join(__dirname,'..'),encoding:'utf8'});
            const page=await browser.newPage({viewport:{width,height:900}});let posts=[];
            await page.route('https://fixture.test/**',async route=>{posts.push(route.request().postData());await route.fulfill({status:200,body:'saved'});});
            await page.setContent('<meta name="viewport" content="width=device-width"><style>body{font:16px sans-serif;margin:16px}button{padding:10px}.description{line-height:1.7}label{display:block}form{max-width:100%}</style>'+html);
            const checkbox=page.locator('[name=hide_toolbox]');assert.equal(await checkbox.isChecked(),mode==='active');
            await checkbox.click();assert.equal(posts.length,0,'Changing checkbox cannot save or claim owner');
            await page.getByRole('button',{name:'取消',exact:true}).click();assert.equal(await checkbox.isChecked(),mode==='active');assert.equal(posts.length,0,'Cancel never submits');
            if(mode==='active')assert((await page.locator('body').textContent()).includes('ID：42'));
            else assert(!(await page.locator('body').textContent()).includes('ID：1'));
            assert.equal(await page.locator('[name=owner_id]').count(),0,'No client-supplied administrator ID');
            assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
            await checkbox.click();await page.getByRole('button',{name:'儲存',exact:true}).click();await page.waitForURL('https://fixture.test/**');
            assert.equal(posts.length,1);assert(posts[0].includes('action=wutm_toolbox_visibility')&&posts[0].includes('_wpnonce=fixture'));
            await page.close();
        }
        console.log('Toolbox visibility browser passed: manual checkbox, no automatic save, reset cancellation, explicit owner, save and four widths.');
    } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
