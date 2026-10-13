(function(){
 'use strict';
 const root=document.getElementById('jmi-insights');if(!root||root.dataset.bound)return;root.dataset.bound='true';root.classList.add('jmi-js');
 const config=JSON.parse(document.getElementById('jmi-live-config').textContent);
 const panel=root.querySelector('#jmi-report'),status=root.querySelector('.jmi-load-status'),reload=root.querySelector('#jmi-reload'),stop=root.querySelector('#jmi-stop'),exportButton=root.querySelector('.jmi-export'),readAt=root.querySelector('#jmi-read-at');
 let busy=false,csv=config.cached?config.cached.csv:null,successfulRead=config.cached?config.cached.read_at:'',pending=null,controller=null,run=0,orderPage=1,orderPages=1,ordersBusy=false;
 if(config.cached){readAt.textContent='資料時間：'+successfulRead+' · '+config.cached.currency+'（完成報表快取）';exportButton.disabled=false;}
 async function request(fields,signal){
  const local=new AbortController();let timedOut=false;const abort=()=>local.abort();
  if(signal){if(signal.aborted)local.abort();else signal.addEventListener('abort',abort,{once:true});}
  const timer=setTimeout(()=>{timedOut=true;local.abort();},15000);
  try{
   const response=await fetch(config.url,{method:'POST',credentials:'same-origin',cache:'no-store',signal:local.signal,body:new URLSearchParams(Object.assign({action:'jmi_wci_live',nonce:config.nonce},fields))});
   let result;try{result=await response.json();}catch(error){if(local.signal.aborted)throw error;throw new Error('伺服器沒有回傳有效資料，請重新登入或檢查網站錯誤紀錄。');}
   if(!response.ok||!result.success){const error=new Error(result.data&&result.data.message?result.data.message:'請求失敗，請確認權限。');error.paused=!!(result.data&&result.data.paused);throw error;}
   return result.data;
  }catch(error){
   if(local.signal.aborted){const stopped=new Error(timedOut?'單批讀取逾時，已停止；請縮短期間或稍後重試。':'讀取已停止。');stopped.cancelled=!timedOut;throw stopped;}
   throw error;
  }finally{clearTimeout(timer);if(signal)signal.removeEventListener('abort',abort);}
 }
 async function load(){
  if(busy||ordersBusy)return;busy=true;const epoch=++run;controller=new AbortController();const signal=controller.signal,start=performance.now();
  reload.disabled=true;stop.hidden=false;exportButton.disabled=true;panel.setAttribute('aria-busy','true');status.dataset.error='false';status.textContent='正在啟動本輪更新…';
  try{
   let data=pending,resume=!!pending;if(!data)data=await request(Object.assign({},config.state,{operation:'report_start'}),signal);
   if(epoch!==run)return;
   while(data.pending){
    pending=data;
    if(document.hidden||performance.now()-start>=20000){status.textContent='已暫停：整理 '+data.done+' 張訂單。按繼續讀取可接續；目前保留完整的上次結果。';break;}
    status.textContent='分批整理中：'+data.done+' 張訂單'+(data.phase==='previous'?'（前期比較）':'')+'。單批最多 '+config.batch+' 張；完成後才更新統計。';
    await new Promise(resolve=>setTimeout(resolve,500));if(epoch!==run)return;
    if(document.hidden)continue;
    data=await request({operation:'report_step',token:data.token,seq:data.seq,resume:resume?'1':'0'},signal);resume=false;
    if(epoch!==run)return;
   }
   if(!data.pending){pending=null;panel.innerHTML=data.html;orderPage=1;orderPages=1;csv=data.csv;successfulRead=data.read_at;readAt.textContent='資料時間：'+successfulRead+' · '+data.currency;status.textContent='已完成。本報表可重用 5 分鐘，切換／重新開啟不會自動掃描訂單。';}
  }catch(error){if(epoch!==run)return;if(!error.paused)pending=null;status.dataset.error=error.paused?'false':'true';status.textContent=error.message+(successfulRead?' 保留 '+successfulRead+' 的完整結果。':' 未顯示不完整統計。');}
  finally{if(epoch===run){busy=false;controller=null;reload.disabled=false;reload.textContent=pending?'繼續讀取':'更新報表';stop.hidden=true;exportButton.disabled=!csv;panel.setAttribute('aria-busy','false');}}
 }
 function stopReading(){
  const token=pending&&pending.token;++run;if(controller)controller.abort();controller=null;pending=null;busy=false;
  reload.disabled=false;reload.textContent='更新報表';stop.hidden=true;exportButton.disabled=!csv;panel.setAttribute('aria-busy','false');status.textContent='已停止後續讀取，保留上次完整結果；已送出的單批請求可能仍在結束。';
  if(token)request({operation:'report_cancel',token}).catch(()=>{});
 }
 stop.addEventListener('click',stopReading);reload.addEventListener('click',load);
 window.addEventListener('pagehide',()=>{++run;if(controller)controller.abort();});
 function filterRecent(){
  const q=(panel.querySelector('#jmi-order-q')?.value||'').trim().toLocaleLowerCase(),selected=panel.querySelector('#jmi-order-status')?.value||'';
  let shown=0;panel.querySelectorAll('.jmi-order-table tbody tr[data-status]').forEach(row=>{const ok=(!selected||row.dataset.status===selected)&&(!q||(row.dataset.search||'').toLocaleLowerCase().includes(q));row.hidden=!ok;if(ok)shown++;});
  let empty=panel.querySelector('.jmi-search-empty');if(!empty){empty=document.createElement('tr');empty.className='jmi-search-empty';empty.innerHTML='<td colspan="7" class="jmi-empty">本頁沒有符合條件的訂單</td>';panel.querySelector('#jmi-order-rows')?.appendChild(empty);}empty.hidden=shown>0;
 }
 panel.addEventListener('click',e=>{const b=e.target.closest('#jmi-filter-orders');if(!b)return;const filters=panel.querySelector('.jmi-order-filterbox');if(!filters)return;const next=filters.hidden;filters.hidden=!next;b.setAttribute('aria-expanded',next?'true':'false');if(next)panel.querySelector('#jmi-order-q')?.focus();});
 panel.addEventListener('input',e=>{if(e.target.matches('#jmi-order-q'))filterRecent();});panel.addEventListener('change',e=>{if(e.target.matches('#jmi-order-status'))filterRecent();});
 async function showOrderPage(page){
  if(ordersBusy||busy)return;const rows=panel.querySelector('#jmi-order-rows'),err=panel.querySelector('#jmi-order-error');if(!rows)return;ordersBusy=true;
  const prev=panel.querySelector('#jmi-order-prev'),next=panel.querySelector('#jmi-order-next'),loadButton=panel.querySelector('#jmi-orders-load');prev.disabled=true;next.disabled=true;if(loadButton)loadButton.disabled=true;err.hidden=true;rows.setAttribute('aria-busy','true');
  try{const data=await request({operation:'orders_page',order_page:page});rows.innerHTML=data.html;orderPage=data.page;orderPages=data.pages;
   panel.querySelector('#jmi-order-count').textContent='第 '+orderPage+' 頁 · 每頁 15 筆 · '+(data.more?'尚有下一頁':'已到最後一頁')+' · 讀取 '+(data.read_at||'');
   const q=panel.querySelector('#jmi-order-q');if(q)q.value='';const state=panel.querySelector('#jmi-order-status');if(state)state.value='';
  }catch(error){err.textContent=error.message;err.hidden=false;}
  finally{ordersBusy=false;rows.removeAttribute('aria-busy');prev.disabled=orderPage<=1;next.disabled=orderPage>=orderPages;if(loadButton)loadButton.disabled=false;}
 }
 panel.addEventListener('click',e=>{if(e.target.closest('#jmi-order-prev'))showOrderPage(orderPage-1);else if(e.target.closest('#jmi-order-next'))showOrderPage(orderPage+1);else if(e.target.closest('#jmi-orders-load'))showOrderPage(1);});
 function trendDetail(e){const point=e.target.closest('.jmi-trend-point');if(point)point.closest('.jmi-pulse-chart').querySelector('.jmi-trend-detail').textContent=point.dataset.day+' · 當日營收 '+point.dataset.total;}
 panel.addEventListener('pointerover',trendDetail);panel.addEventListener('focusin',trendDetail);
 exportButton.addEventListener('click',()=>{if(!csv)return;function cell(v){let s=v==null?'':String(v);if(typeof v!=='number'&&(/^[\s\u0000-\u001f]*[=+@-]/.test(s)||/^[\t\r\n]/.test(s)))s="'"+s;return '"'+s.replace(/"/g,'""')+'"';}
  const output='\ufeff'+csv.map(r=>r.map(cell).join(',')).join('\r\n'),url=URL.createObjectURL(new Blob([output],{type:'text/csv;charset=utf-8;'})),a=document.createElement('a');a.href=url;a.download='wc-live-'+config.state.tab+'-'+config.state.from+'-'+config.state.to+'-page-'+config.state.pg+'.csv';document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
 });
 panel.addEventListener('click',async event=>{const button=event.target.closest('.jmi-lifetime');if(!button||busy)return;busy=true;button.disabled=true;reload.disabled=true;const result=button.parentNode.querySelector('.jmi-lifetime-result');result.textContent='讀取顧客歷史中…';
  try{const data=await request({operation:'lifetime',email:button.dataset.email,user_id:button.dataset.user});const h=data.lifetime,kind=!h.first_order?'無有效訂單':(h.first_order.slice(0,10)<config.state.from?'本期舊客':'本期新客');result.textContent=kind+' · 累積淨消費 '+data.currency+' '+Number(h.collected).toLocaleString(undefined,{maximumFractionDigits:2})+' · '+h.orders+' 張有效原單 · 第一單 '+(h.first_order||'—')+' · 讀取 '+data.read_at;}
  catch(error){result.textContent=error.message;}finally{busy=false;button.disabled=false;reload.disabled=false;}
 });
})();
