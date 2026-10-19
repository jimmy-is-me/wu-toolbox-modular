const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const legacy=new Set(JSON.parse(fs.readFileSync(path.join(__dirname,'admin-ui-legacy-modules.json'),'utf8')));
function hasSettings(source){return /add_(?:submenu|options|menu)_page\s*\(/.test(source);}
function usesContract(source){return /wutm_admin_page_open\s*\(|wutm-ui-root/.test(source);}
const registrations=[],violations=[];
for(const name of fs.readdirSync('modules')){
 const folder=path.join('modules',name);if(!fs.statSync(folder).isDirectory())continue;
 const files=fs.readdirSync(folder).filter(file=>file.endsWith('.php'));
 const source=files.map(file=>fs.readFileSync(path.join(folder,file),'utf8')).join('\n');
 if(!hasSettings(source))continue;registrations.push(name);
 if(!legacy.has(name)&&!usesContract(source))violations.push(name);
}
assert.ok(usesContract('wutm_admin_page_open("Settings");'));
assert.ok(!usesContract('echo "<div class=wrap>";'),'A legacy wrapper alone is not the new-module contract');
assert.deepEqual(violations,[],'New setting modules must use the shared admin page/component contract; do not extend the legacy baseline for new modules');
console.log('Admin UI contract checked: '+registrations.length+' modules register admin pages; future modules require the common root/helper.');
