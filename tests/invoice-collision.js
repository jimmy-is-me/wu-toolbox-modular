const assert=require('node:assert/strict'),{spawnSync}=require('node:child_process');
const php=process.env.WUTM_TEST_PHP||'php',args=JSON.parse(process.env.WUTM_TEST_PHP_ARGS||'[]');
if(!process.env.WUTM_FOREIGN_AUTOLOAD)throw Error('WUTM_FOREIGN_AUTOLOAD required');
for(const provider of ['ezpay'])for(const order of ['before','after']){
 const result=spawnSync(php,[...args,'tests/invoice-isolation.php',order,provider],{encoding:'utf8'});
 assert.equal(result.status,0,result.stdout+result.stderr);
 assert.match(result.stdout,/Invoice dependency isolation passed/);
}
console.log('EZPAY WordPress transport coexists with real Guzzle 7/PSR 2 in both load orders.');
