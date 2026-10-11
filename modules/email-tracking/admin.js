(() => {
  'use strict';
  const dialog = document.getElementById('wu-mail-dialog');
  if (!dialog) return;
  const state = dialog.querySelector('.wu-mail-detail-state');
  const meta = dialog.querySelector('.wu-mail-detail-meta');
  const frame = dialog.querySelector('iframe');
  let active = null, opener = null;
  function close() { if (active) active.abort(); dialog.close(); frame.srcdoc = ''; meta.replaceChildren(); if (opener) opener.focus(); }
  dialog.querySelector('.wu-mail-close').addEventListener('click', close);
  dialog.addEventListener('cancel', e => { e.preventDefault(); close(); });
  dialog.addEventListener('click', e => { if(e.target===dialog) {const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)close();} });
  document.querySelectorAll('.wu-mail-view').forEach(button => button.addEventListener('click', async () => {
    if (active) active.abort(); active = new AbortController(); const request=active;
    opener=button; state.textContent='正在讀取信件…'; meta.replaceChildren();frame.srcdoc='';dialog.showModal();
    try {
      const response=await fetch(wuEmailAdmin.url,{method:'POST',credentials:'same-origin',signal:request.signal,
        body:new URLSearchParams({action:'wu_email_log_detail',nonce:wuEmailAdmin.nonce,id:button.dataset.id})});
      const text=await response.text();let result;
      try {result=JSON.parse(text);}catch {throw new Error('伺服器未回傳有效資料，請重新登入或稍後再試。');}
      if(!response.ok||!result.success)throw new Error(result.data?.message||'無法讀取紀錄。');
      if(active!==request||!dialog.open)return;
      const d=result.data;
      for(const [label,key] of [['主旨','subject'],['收件者','to'],['時間','time'],['方式','method'],['狀態','status'],['備註','error']]){
        const term=document.createElement('dt'),value=document.createElement('dd');term.textContent=label;value.textContent=d[key]||'—';meta.append(term,value);
      }
      state.textContent=!d.has_content?'此紀錄未保存內容（舊紀錄或已關閉保存）。':d.truncated?'內容超過 256 KiB，顯示已保存部分。':'安全預覽：外部圖片、追蹤像素、腳本與連結均停用。';
      if(d.has_content)frame.srcdoc=d.preview;
    } catch(error) {if(error.name!=='AbortError'&&active===request&&dialog.open)state.textContent=error.message;}
  }));
})();
