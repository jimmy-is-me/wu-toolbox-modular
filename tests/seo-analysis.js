const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { parseHTML } = require('linkedom');
const { document } = parseHTML('<html><body></body></html>');
const context = { window: {}, document, URL };
vm.runInNewContext(fs.readFileSync('assets/js/seo-analysis.js', 'utf8'), context);
const analyze = context.window.WUTMSEOAnalysis.analyze;
const result = analyze('<script>fake topic</script><h2>冷氣保養</h2><p>冷氣保養實際經驗</p><a href="/related/">相關內容</a><a href="#same">頁內</a><a href="https://developers.google.com/">參考資料</a><a href="mailto:hello@example.test">Email</a><img alt=""><img src="photo.jpg">', '冷氣保養方法', '實用摘要', '冷氣保養', 'https://example.test/article/');
assert.equal(result.checks.length, 8);
assert.equal(result.checks[2].ok, true);
assert.equal(result.checks[3].text.includes('1 個'), true, 'Only related pages count as internal links');
assert.equal(result.checks[4].ok, false, 'Missing alt flagged; empty decorative alt allowed');
assert.equal(result.checks[5].text.includes('1 個'), true, 'Only HTTP reference links count');
assert.equal(result.checks[6].ok, true);
assert.equal(result.checks[7].ok, true);
assert.equal(analyze('<p>內容</p>', '', '', '', 'https://example.test/').checks.length, 6, 'No forced keyword checks when no topic is configured');
assert.equal(analyze('<script>主題</script><p>其他文字</p>', 'title', 'desc', '主題', 'https://example.test/').checks[7].ok, false, 'Scripts do not count as visible content');
const php = fs.readFileSync('modules/seo-core/module.php', 'utf8');
for (const match of php.matchAll(/<<<'JS'\r?\n([\s\S]*?)\r?\nJS;/g)) {
    new vm.Script(match[1].replace('__WU_CONFIG__', '{}'));
}
console.log('SEO editor analysis and embedded script checks passed.');
