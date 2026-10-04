'use strict';
// Deterministic event/timer regression: no DOM packages or browser dependency.
const vm=require('node:vm'),fs=require('node:fs'),assert=require('node:assert/strict');
const classes=new Set(),storage=new Map(),timers=new Map(),events={},windowEvents={};
let now=0,sequence=0,reduced=false;
const location={href:'https://example.test/current/',origin:'https://example.test',pathname:'/current/',search:''};
const root={classList:{add:c=>classes.add(c),remove:c=>classes.delete(c)}};
const document={documentElement:root,hidden:false,addEventListener:(name,fn)=>events[name]=fn};
const window={matchMedia:()=>({matches:reduced}),addEventListener:(name,fn)=>windowEvents[name]=fn,
  setTimeout:(fn,delay)=>{const id=++sequence;timers.set(id,{fn,time:now+delay});return id;}};
function clearTimeout(id){timers.delete(id);}
function advance(ms){const end=now+ms;while(true){const next=[...timers.entries()].filter(([,v])=>v.time<=end).sort((a,b)=>a[1].time-b[1].time)[0];if(!next)break;now=next[1].time;timers.delete(next[0]);next[1].fn();}now=end;}
const sessionStorage={setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../assets/js/page-transition.js'),'utf8'),{document,window,location,sessionStorage,URL,Date,clearTimeout});
function click(overrides={},linkOptions={}){
  const link={href:'https://example.test/next/',target:'',closest:()=>null,hasAttribute:()=>false,matches:()=>false,...linkOptions};
  const event={button:0,defaultPrevented:false,target:{closest:()=>link},...overrides};
  events.click(event);return event;
}
const first=click();assert.equal(first.defaultPrevented,false,'Native navigation is never intercepted');
advance(219);assert.equal(classes.has('wupt-show'),false,'Fast navigation skips animation');
windowEvents.pagehide();advance(1);assert.equal(classes.has('wupt-show'),false,'No late loader after a fast departure');assert.equal(storage.size,0);
click();advance(220);assert.equal(classes.has('wupt-show'),true,'Slower departure shows loader');
assert.equal(JSON.parse([...storage.values()][0]).url,'https://example.test/next/');
windowEvents.pagehide();assert.equal(classes.has('wupt-show'),false);assert.equal(storage.size,1,'Marker retained only for continued arrival');
windowEvents.pageshow();assert.equal(storage.size,0,'BFCache restoration clears marker');
const canceled=click();canceled.defaultPrevented=true;advance(220);assert.equal(classes.has('wupt-show'),false,'Later event cancellation respected');
for(const override of [{button:1},{ctrlKey:true},{metaKey:true},{shiftKey:true},{altKey:true},{defaultPrevented:true}]){click(override);advance(220);assert.equal(classes.has('wupt-show'),false);}
for(const link of [{href:'https://elsewhere.test/page/'},{href:'mailto:test@example.test'},{href:'https://example.test/current/#anchor'},{href:'https://example.test/wp-admin/edit.php'},{target:'_blank'},{hasAttribute:()=>true},{matches:()=>true}]){click({},link);advance(220);assert.equal(classes.has('wupt-show'),false);}
reduced=true;click();advance(220);assert.equal(classes.has('wupt-show'),false,'Reduced motion skips loader');
reduced=false;click();advance(220);advance(8000);assert.equal(classes.has('wupt-show'),false,'Failed/canceled navigation has bounded recovery');assert.equal(storage.size,0);
console.log('Page transition timer/event policy passed: immediate native navigation, fast skip, slow continuation, cancellation, modifiers, sensitive links, reduced motion and recovery.');

