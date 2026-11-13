const fs = require('node:fs'), assert = require('node:assert/strict'), {execFileSync} = require('node:child_process'), {chromium} = require('playwright');
(async () => {
 const browser = await chromium.launch({headless: true, executablePath: process.env.WUTM_TEST_CHROME});
 try {
  const html = execFileSync(process.env.WUTM_TEST_PHP || 'php', ['tests/seo-management-preview.php'], {encoding:'utf8'});
  const wp = process.env.WUTM_TEST_WP;
  const jquery = fs.readFileSync(wp + '/wp-includes/js/jquery/jquery.min.js', 'utf8');
  for (const width of [1280, 768, 390, 320]) {
   const page = await browser.newPage({viewport:{width,height:900}}); const errors=[]; page.on('pageerror', e => errors.push(e.message));
   await page.setContent('<meta name="viewport" content="width=device-width"><style>body{margin:0}*{box-sizing:border-box}#wpbody-content{padding:8px}pre{overflow-wrap:anywhere}</style><body class="wutm-admin-screen"><div id="wpbody-content">' + html + '<button class="wu-seo-open" data-id="1">編輯 SEO</button></div></body>');
   await page.addStyleTag({content:fs.readFileSync('assets/css/admin-components.css','utf8')});
   assert.equal(await page.locator('details').first().evaluate(e => e.open), false);
   assert(await page.getByRole('button', {name:'一鍵檢查與安全優化'}).isVisible());
   await page.addScriptTag({content:jquery});
   await page.evaluate(() => {
    window.inlineEditPost = {edit(){},getId(){return 1;}};
    window.wuSeoManagement = {url:'/ajax',nonce:'fixture'}; window.requests = [];
    window.jQuery.post = async (url, args) => { window.requests.push(args); return {success:true,data:{data:Object.assign({title:'原標題',description:'原摘要',topic:'one, two',canonical:'',noindex:0,nofollow:0,og_title:'',og_desc:'',og_image:0},args.data || {}),title:'自動範本標題',description:'自動摘要',image:'',history:[{time:'2026-11-13',user:1,field:'title',before:'舊值',after:'原標題'}]}}; };
   });
   await page.evaluate(() => {
    const table = document.createElement('table');
    table.innerHTML = '<tbody><tr id="post-1"><td class="column-wu_seo">舊狀態<span hidden class="wu-seo-row-data"></span></td><td class="column-wu_seo_title">舊標題</td><td class="column-wu_seo_noindex"></td></tr></tbody>';
    document.body.appendChild(table);
   });
   await page.addScriptTag({content:fs.readFileSync('assets/js/seo-management.js','utf8')});
   assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Management controls fit viewport '+width);
   await page.locator('.wu-seo-open').click(); await page.waitForFunction(() => document.getElementById('wu-seo-save-status').textContent === '已讀取。');
   await page.locator('#wu-seo-editor [name=title]').fill('更新搜尋標題');
   assert.equal(await page.locator('#wu-seo-search-title').textContent(), '更新搜尋標題');
   await page.locator('#wu-seo-editor [name=noindex]').check();
   await page.locator('#wu-seo-editor [type=submit]').click();
   await page.waitForFunction(() => document.getElementById('wu-seo-save-status').textContent.includes('已儲存'));
   const sent = await page.evaluate(() => window.requests.at(-1)); assert.equal(sent.data.noindex,1); assert.equal(sent.data.nofollow,0); assert.equal(sent.data.title,'更新搜尋標題');
   assert.equal(await page.locator('#post-1 .column-wu_seo_title').textContent(), '自動範本標題');
   assert.equal(await page.locator('#post-1 .column-wu_seo_noindex').textContent(), 'noindex');
   assert.equal(await page.locator('.wu-seo-row-data').evaluate(e => JSON.parse(e.dataset.seo).title), '更新搜尋標題');
   assert((await page.locator('#wu-seo-length-title').textContent()).includes('6 字'));
   await page.locator('#wu-seo-close').click(); assert.equal(await page.locator('dialog').evaluate(e => e.open),false);
   assert.deepEqual(errors,[]); await page.close();
  }
  console.log('SEO management UI passed: four widths, real rendered controls, modal loading, search preview, explicit robots state and save.');
 } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
