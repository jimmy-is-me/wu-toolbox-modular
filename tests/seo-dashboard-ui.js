const assert = require('node:assert/strict');
const fs = require('node:fs');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const asset = name => fs.readFileSync('modules/seo-core/assets/dashboard.' + name, 'utf8');
const html = execFileSync(process.env.WUTM_TEST_PHP || 'php', ['tests/seo-management-preview.php','dashboard'], {encoding:'utf8'});
const site = 'https://seo.example.test/wp-admin/admin.php?page=wu-seo-tools';
const metric = (key, im, click, ctr, pos) => ({key,impressions:im,clicks:click,ctr,position:pos});

(async () => {
    const browser = await chromium.launch({executablePath:process.env.WUTM_TEST_CHROME,headless:true});
    try {
        const page = await browser.newPage({viewport:{width:1440,height:900}});
        const requests = [];
        let mode = 'ok', last;
        await page.route('https://seo.example.test/**', route => {
            if (route.request().method() !== 'POST') return route.fulfill({contentType:'text/html',body:html});
            const input = JSON.parse(new URLSearchParams(route.request().postData()).get('payload'));
            requests.push(input);
            const stamp = 1700000000;
            const posts = [
                {id:7,title:'首頁',url:'https://seo.example.test/',google:{state:'indexed',time:stamp,detail:{lastCrawlTime:'2026-03-01T08:00:00Z'}},local:{noindex:false}},
                {id:9,title:'服務',url:'https://seo.example.test/service/',google:{state:'excluded',time:stamp,detail:{coverageState:'Not indexed'}},local:{noindex:true}},
                {id:11,title:'文章',url:'https://seo.example.test/article/',google:null,local:{noindex:false}},
                {id:12,title:'案例',url:'https://seo.example.test/case/',google:{state:'failed',time:stamp,detail:{code:'quota'}},local:{noindex:false}}
            ];
            let output;
            if (input.op === 'snapshot') output = {connected:true,posts,keywords:[],sitemap:{enabled:true,http:null},sitemap_url:'https://seo.example.test/wp-sitemap.xml'};
            else if (input.op === 'performance') {last=input; output='fixture';}
            else if (input.op === 'job') {
                if (mode === 'auth') output={state:'failed',time:stamp,data:{},errors:{total:{message:'Google 授權已失效或設定不完整，請重新連線。'}}};
                else if (mode === 'empty') output={state:'ready',time:stamp,data:{total:[],daily:[]}};
                else output={state:'ready',time:stamp,data:{
                    total:[metric('',2300,191,.083,4.5)], prior_total:[metric('',2000,150,.075,7)],
                    daily:[metric(last.start,80,8,.1,4.5),metric(last.end,140,7,.05,2)],
                    queries:[metric('高雄網頁設計',200,17,.085,4.5)], prior_queries:[metric('高雄網頁設計',50,6,.12,7)],
                    pages:[metric(posts[0].url,100,8,.08,5),metric(posts[1].url,40,5,.125,3)]
                }};
            }
            return route.fulfill({json:{success:true,data:output}});
        });
        await page.goto(site);
        await page.locator('.wus-tabs a[data-tab=indexnow]').waitFor();
        await page.addStyleTag({content:asset('css')});
        await page.addScriptTag({content:asset('js')});
        await page.getByText('2,300',{exact:true}).first().waitFor();
        const grid = page.locator('.wus-metrics');
        assert.equal(await grid.evaluate(e=>getComputedStyle(e).gridTemplateColumns.split(' ').length),4);
        assert.match(await grid.innerText(),/\+0\.8 個百分點/);
        assert.match(await grid.innerText(),/\+2\.5 名/);
        assert.equal(await page.locator('.wus-point').count(),2);
        await page.locator('[data-chart] circle[tabindex="0"]').focus();
        await page.keyboard.press('ArrowRight');
        assert.match(await page.locator('[data-point]').innerText(),/140/);
        await page.locator('input[name=country]').fill('TWN');
        await page.locator('select[name=device]').selectOption('MOBILE');
        await page.locator('[data-filters] button[type=submit]').click();
        await page.getByText('2,300',{exact:true}).first().waitFor();
        assert.equal(last.country,'TWN');
        assert.equal(last.device,'MOBILE');
        assert.equal(last.compare,true);
        const lookup = page.waitForRequest(request => request.method() === 'POST' && (request.postData() || '').includes('performance'));
        await page.locator('[data-tables]').getByRole('button',{name:'高雄網頁設計',exact:true}).click();
        await lookup;
        await page.getByText('目前範圍 · 高雄網頁設計',{exact:true}).waitFor();
        assert.equal(last.value,'高雄網頁設計');
        await page.getByRole('button',{name:/未收錄\s+1/}).click();
        assert.equal(await page.locator('[data-index-filter]').inputValue(),'excluded');
        assert.equal(await page.locator('[data-index-table] tbody tr').count(),1);
        assert.match(await page.locator('[data-donut]').innerText(),/查詢失敗\s+1/);
        await page.setViewportSize({width:390,height:844});
        assert.equal(await grid.evaluate(e=>getComputedStyle(e).gridTemplateColumns.split(' ').length),1);
        assert(await page.locator('[data-index-table] .wus-scroll').evaluate(e=>e.scrollWidth>e.clientWidth));
        mode='empty'; await page.locator('[data-filters] button[type=submit]').click();
        await page.getByText('此期間或篩選沒有搜尋資料',{exact:true}).waitFor();
        assert(!((await grid.innerText()).includes('2,300')));
        mode='auth'; await page.locator('[data-filters] button[type=submit]').click();
        await page.locator('[data-state]').filter({hasText:'Google 授權已失效'}).waitFor();
        console.log('SEO dashboard desktop/mobile, chart keyboard, deltas, filters, index counts, no-data and OAuth error passed.');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
