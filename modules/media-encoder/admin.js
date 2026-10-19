(()=>{
 'use strict';
 const root=document.getElementById('wutm-media');if(!root||!window.wutmMediaAdmin)return;
 const cfg=window.wutmMediaAdmin,$=s=>root.querySelector(s);
 let rows=[],filter='all',q='',loading=false,job=false,stop=false,pause=false,queue=[],done=0,cursor=0,nextCursor=0,more=false,history=[],round=0,controller=null;
 let libraryScope='images',scanState=null,scanning=false,scanStop=false;
 const supported=root.dataset.supported==='1';
 const bytes=n=>n===null?'未知':n<1024?n+' B':n<1048576?(n/1024).toFixed(1)+' KB':(n/1048576).toFixed(2)+' MB';
 const el=(tag,text,cls)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;};
 function message(text,error=false){$('#wme-message').hidden=false;$('#wme-message').textContent=text;$('#wme-message').dataset.error=String(error);}
 function go(tab){root.querySelectorAll('[data-tab]').forEach(b=>b.setAttribute('aria-selected',String(b.dataset.tab===tab)));['overview','library','settings'].forEach(t=>$('#wme-'+t).hidden=t!==tab);}
 function totals(){return {count:0,images:0,webp:0,ready:0,bytes:0,known:0,recorded:0,saving:0};}
 function countRow(t,r,sign=1){t.count+=sign;if(r.mime.startsWith('image/'))t.images+=sign;if(r.mime==='image/webp')t.webp+=sign;if(r.convertible)t.ready+=sign;if(r.bytes!==null){t.bytes+=sign*r.bytes;t.known+=sign;}if(r.saving!==null){t.saving+=sign*r.saving;t.recorded+=sign;}}
 function controls(){
  root.querySelectorAll('[data-load]').forEach(b=>b.disabled=loading||job);
  $('#wme-prev').disabled=loading||job||!history.length;$('#wme-next').disabled=loading||job||!more;
  $('#wme-batch').disabled=loading||job||!!(scanState&&!scanState.complete)||!supported||!rows.some(r=>r.convertible);
  root.querySelectorAll('[data-convert]').forEach(b=>b.disabled=loading||job||!!(scanState&&!scanState.complete)||!supported);
  root.querySelectorAll('[data-scan]').forEach(b=>b.disabled=loading||job);
  $('#wme-scan-stop').disabled=!scanning||scanStop;
  $('#wme-scan-resume').hidden=!scanState||scanState.complete||scanning;
 }
 function draw(){
  const list=rows.filter(r=>(filter==='all'||(filter==='ready'?r.convertible:r.mime==='image/webp'))&&(r.name+' '+r.title).toLocaleLowerCase().includes(q.toLocaleLowerCase()));
  const body=$('#wme-rows');body.replaceChildren();
  list.forEach(r=>{
   const tr=el('tr'),file=el('td'),wrap=el('div',undefined,'wme-file');
   if(r.thumb){const img=el('img');img.src=r.thumb;img.alt='';img.loading='lazy';img.width=42;img.height=42;wrap.append(img);}
   const name=el('div');name.append(el('strong',r.name),el('small',r.width&&r.height?r.width+' × '+r.height:r.mime));wrap.append(name);file.append(wrap);
   const size=el('td',bytes(r.bytes)),state=el('td'),tag=el('span',r.mime==='image/webp'?'WebP':r.convertible?'可轉換':r.mime==='image/gif'?'GIF · 保留原格式':!r.mime.startsWith('image/')?'非圖片 · 僅檢視':'無本機檔案','wme-badge');
   size.append(el('small',' · '+r.mime.replace('image/','').toUpperCase()));state.append(tag);
   const action=el('td'),edit=el('a','編輯','wme-button');edit.href=r.edit;action.append(edit);
   if(r.convertible){const b=el('button','轉換 WebP','wme-button');b.type='button';b.dataset.convert=String(r.id);b.disabled=job||!supported;action.append(document.createTextNode(' '),b);}
   tr.append(file,size,state,action);body.append(tr);
  });
  if(!list.length){const tr=el('tr'),td=el('td','本頁沒有符合條件的檔案。','wme-empty');td.colSpan=4;tr.append(td);body.append(tr);}
  $('#wme-results').textContent='本頁 '+rows.length+' 筆 · 顯示 '+list.length+' 筆'+(more?' · 尚有下一頁':' · 已到最後一頁');
  const stats=scanState&&scanState.first?scanState.totals:totals();
  if(!(scanState&&scanState.first))rows.forEach(r=>countRow(stats,r));
  const percent=stats.images?Math.round(stats.webp/stats.images*100):0;
  $('#wme-stat-count').textContent=String(stats.count);$('#wme-stat-ready').textContent=String(stats.ready);
  $('#wme-stat-size').textContent=bytes(stats.bytes)+(stats.known<stats.count?'（部分已知）':'');
  $('#wme-stat-saving').textContent=stats.recorded?bytes(stats.saving):'尚無紀錄';
  $('#wme-percent').textContent=stats.images?percent+'%':'—';$('.wme-ring').style.setProperty('--wme-percent',percent);
  $('#wme-ratio-label').textContent=scanState&&scanState.first?'已讀取圖片 WebP 比例':'本頁 WebP 比例';
  $('.wme-ring').setAttribute('aria-label','已讀取圖片 WebP 比例 '+percent+'%');
  $('#wme-summary').textContent=scanState&&scanState.first?'本次已讀取 '+stats.count+' 個檔案（圖片 '+stats.images+' 張），'+stats.ready+' 張可轉換。'+(scanState.complete?'全庫讀取已完成；清單維持每頁 20 筆。':'讀取尚未完成，這是目前累計，不是全庫總數。'):'本頁 '+rows.length+' 張圖片，'+stats.ready+' 張可手動轉換為 WebP。這不是全站總數；GIF 不參與手動轉換。';
  controls();
 }
 async function request(fields){
  controller=new AbortController();const timer=setTimeout(()=>controller.abort(),45000);
  try{
   const response=await fetch(cfg.url,{method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,body:new URLSearchParams({action:'wutm_media_admin',nonce:cfg.nonce,...fields})});
   let data;try{data=await response.json();}catch(e){throw new Error('伺服器沒有回傳有效資料，請重新登入或查看主機錯誤紀錄。');}
   if(!response.ok||!data.success)throw new Error(data.data?.message||'請求失敗。');
   return data.data;
  }catch(e){if(e.name==='AbortError')throw new Error(fields.operation==='list'?'讀取逾時或已取消，保留已成功讀取的資料，可稍後繼續。':'請求逾時或頁面已離開；已停止後續作業。已送出的轉換可能仍在執行，請先重新讀取確認結果再重試。');throw e;}
  finally{clearTimeout(timer);controller=null;}
 }
 async function load(target,mode,requestedScope=libraryScope){
  if(loading||job)return;loading=true;controls();message('讀取本頁圖片中…');
  try{
   const data=await request({operation:'list',cursor:String(target),scope:requestedScope});
   if(mode==='reset'){scanState=null;libraryScope=requestedScope;$('#wme-scan').hidden=true;}
   if(mode==='next')history.push(cursor);else if(mode==='prev')history.pop();else history=[];
   cursor=target;nextCursor=data.cursor;more=data.more;rows=data.rows;
   $('#wme-read-at').textContent='資料時間：'+data.at+' · 每頁最多 20 筆';draw();message('已載入 '+rows.length+' 筆。清單僅代表當前頁面；全庫統計以本次手動讀取為準。');
  }catch(e){message(e.message+' 保留上次成功載入的資料。',true);}
  finally{loading=false;controls();}
 }
 async function scan(resume=false){
  if(loading||job||scanning)return;
  if(!resume)scanState={next:0,first:null,totals:totals(),complete:false};
  if(!scanState||scanState.complete)return;
  scanning=true;loading=true;scanStop=false;const started=performance.now();$('#wme-scan').hidden=false;controls();
  try{
   while(!scanStop){
    const target=scanState.next,data=await request({operation:'list',scope:'all',cursor:String(target)});
    const next=Number(data.cursor);
    if(!Array.isArray(data.rows)||data.rows.length>20||!Number.isInteger(next)||next<0||(data.more&&(next===0||(target>0&&next>=target))))throw new Error('讀取游標無效，已停止以避免重複讀取。');
    let previous=target||Infinity;
    const batchStats=totals();
    for(const row of data.rows){
     if(!Number.isSafeInteger(row.id)||row.id<=0||row.id>=previous||row.id<next||typeof row.mime!=='string'||(row.bytes!==null&&(!Number.isFinite(row.bytes)||row.bytes<0))||(row.saving!==null&&(!Number.isFinite(row.saving)||row.saving<0)))throw new Error('檔案資料無效，已停止讀取。');
     previous=row.id;countRow(batchStats,row);
    }
    if(!scanState.first){scanState.first=data;libraryScope='all';rows=data.rows;cursor=0;nextCursor=next;more=!!data.more;history=[];}
    Object.keys(batchStats).forEach(key=>scanState.totals[key]+=batchStats[key]);scanState.next=next;scanState.complete=!data.more;
    $('#wme-read-at').textContent='資料時間：'+data.at+' · 手動全庫讀取，清單每頁最多 20 筆';
    $('#wme-scan-label').textContent='已讀取 '+scanState.totals.count+' 個檔案'+(scanState.complete?' · 已完成':' · 分批讀取中…');draw();
    if(scanState.complete||scanStop||document.hidden||performance.now()-started>=20000)break;
    await new Promise(resolve=>setTimeout(resolve,250));
   }
   message(scanState.complete?'全庫讀取完成。非圖片僅供檢視，不會轉換。':'已暫停，保留目前累計；按「繼續讀取」才讀取下一批。');
  }catch(e){message(e.message+' 已保留成功讀取的統計；可繼續重試。',true);}
  finally{scanning=false;loading=false;$('#wme-scan-label').textContent='已讀取 '+scanState.totals.count+' 個檔案'+(scanState.complete?' · 已完成':' · 已暫停（未完成）');draw();}
 }
 async function step(){
  if(stop){job=false;$('#wme-job').hidden=true;draw();message('已停止後續轉換，原檔保留。');return;}
  if(!queue.length){job=false;$('#wme-job').hidden=true;draw();message('本次 '+done+' 張圖片已轉換完成；原始檔與舊縮圖均保留。');return;}
  if(document.hidden||performance.now()-round>20000){pause=true;$('#wme-job-label').textContent='已暫停：按繼續後才處理下一張。';$('#wme-resume').hidden=false;return;}
  const id=queue[0],r=rows.find(r=>r.id===id);$('#wme-job-label').textContent='正在處理 '+(r?.name||id)+' · 已完成 '+done+' 張';
  try{
   const data=await request({operation:'convert',id:String(id)});
   if(scanState&&scanState.complete){const previous=rows.find(r=>r.id===id);if(previous){countRow(scanState.totals,previous,-1);countRow(scanState.totals,data.row);}}
   rows=rows.map(r=>r.id===id?data.row:r);queue.shift();done++;
   $('#wme-progress').value=Math.round(done/(done+queue.length)*100);
   const item=el('div',undefined,'wme-activity');item.append(el('strong',data.record.name+' → WEBP'),el('p',bytes(data.record.before)+' → '+bytes(data.record.after)+' · '+data.record.at));
   const recent=$('#wme-recent');recent.querySelector('.wme-empty')?.remove();recent.prepend(item);while(recent.children.length>12)recent.lastElementChild.remove();draw();
   await new Promise(resolve=>setTimeout(resolve,250));await step();
  }catch(e){job=false;queue=[];$('#wme-job').hidden=true;draw();message(e.message+' 已完成的圖片不會重複轉換，未完成的原圖保留。',true);}
 }
 function start(ids){
  if(job||loading||(scanState&&!scanState.complete)||!supported||!ids.length)return;
  if(!window.confirm('將逐張轉換 '+ids.length+' 張圖片並更新媒體紀錄與縮圖；保留原始檔和舊縮圖，因此可能增加磁碟占用。確定開始？'))return;
  queue=ids;done=0;stop=false;pause=false;job=true;round=performance.now();$('#wme-job').hidden=false;$('#wme-progress').value=0;$('#wme-pause').disabled=false;$('#wme-resume').hidden=true;draw();step();
 }
 root.addEventListener('click',e=>{
  const tab=e.target.closest('[data-tab]');if(tab){go(tab.dataset.tab);return;}
  const target=e.target.closest('[data-go]');if(target){go(target.dataset.go);return;}
  if(e.target.closest('[data-load]')){go('library');load(0,'reset','images');return;}
  if(e.target.closest('[data-scan]')){go('library');scan();return;}
  const f=e.target.closest('[data-filter]');if(f){filter=f.dataset.filter;root.querySelectorAll('[data-filter]').forEach(b=>b.setAttribute('aria-pressed',String(b===f)));draw();return;}
  const convert=e.target.closest('[data-convert]');if(convert)start([Number(convert.dataset.convert)]);
 });
 $('#wme-scan-stop').addEventListener('click',()=>{scanStop=true;$('#wme-scan-stop').disabled=true;$('#wme-scan-label').textContent='正在停止；等待本批讀取完成…';});
 $('#wme-scan-resume').addEventListener('click',()=>scan(true));
 $('#wme-batch').addEventListener('click',()=>start(rows.filter(r=>r.convertible).map(r=>r.id)));
 $('#wme-next').addEventListener('click',()=>load(nextCursor,'next'));
 $('#wme-prev').addEventListener('click',()=>load(history[history.length-1]||0,'prev'));
 $('#wme-search').addEventListener('input',e=>{q=e.target.value;draw();});
 $('#wme-quality').addEventListener('input',e=>$('#wme-quality-output').textContent=e.target.value+'%');
 $('#wme-resume').addEventListener('click',()=>{if(pause&&job){pause=false;round=performance.now();$('#wme-resume').hidden=true;step();}});
 $('#wme-pause').addEventListener('click',()=>{stop=true;if(pause){pause=false;queue=[];job=false;$('#wme-job').hidden=true;draw();message('已停止後續轉換，尚未處理的圖片維持原狀。');}else{$('#wme-pause').disabled=true;$('#wme-job-label').textContent='正在停止；等待已送出的圖片完成…';}});
 root.querySelector('[role=tablist]').addEventListener('keydown',e=>{if(!['ArrowLeft','ArrowRight'].includes(e.key))return;const tabs=Array.from(root.querySelectorAll('[data-tab]')),i=tabs.indexOf(e.target);if(i<0)return;e.preventDefault();const b=tabs[(i+(e.key==='ArrowRight'?1:tabs.length-1))%tabs.length];b.focus();go(b.dataset.tab);});
 window.addEventListener('pagehide',()=>{stop=true;scanStop=true;if(controller)controller.abort();});
 root.querySelector('form').addEventListener('submit',e=>{if(job||scanning){e.preventDefault();message('請先停止讀取或轉換，再儲存設定。',true);}});
 if(new URLSearchParams(location.search).has('updated'))go('settings');
 controls();
})();
