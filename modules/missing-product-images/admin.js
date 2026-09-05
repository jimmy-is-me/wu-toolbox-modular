(function () {
    'use strict';
    const config = window.WUTMMissingImages;
    const scan = document.getElementById('wutm-mir-scan');
    if (!config || !scan) return;
    const repair = document.getElementById('wutm-mir-repair');
    const stop = document.getElementById('wutm-mir-stop');
    const progress = document.getElementById('wutm-mir-progress');
    const summary = document.getElementById('wutm-mir-summary');
    const log = document.getElementById('wutm-mir-log');
    let running = false, stopped = false;
    stop.addEventListener('click', function () {
        stopped = true;
        stop.disabled = true;
        progress.textContent = '停止中，等待目前這張圖片處理完成…';
    });
    async function run(mode) {
        if (running) return;
        if (mode === 'repair' && !window.confirm('確定將 A 站圖片補回本站缺少的路徑？建議先備份；不會覆蓋已存在檔案。')) return;
        running = true; stopped = false; scan.disabled = repair.disabled = true; stop.disabled = false;
        let last = 0, index = 0, products = 0, lines = [];
        const counts = {exists: 0, available: 0, missing: 0, repaired: 0, failed: 0};
        log.textContent = ''; summary.textContent = ''; progress.textContent = '開始處理…';
        try {
            while (!stopped) {
                const response = await fetch(config.ajaxurl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({action: 'wutm_missing_images_batch', nonce: config.nonce, mode, last: String(last), index: String(index)})
                });
                let json;
                try { json = await response.json(); } catch (_) { throw new Error('伺服器未回傳有效結果，請檢查 PHP 錯誤紀錄後重試。'); }
                if (!response.ok || !json.success) throw new Error(json.data?.message || '操作失敗');
                const data = json.data;
                if (data.done) break;
                if (Number(data.last) === last && Number(data.index) === index) throw new Error('處理進度未前進，已停止。');
                last = Number(data.last); index = Number(data.index); products += Number(data.completed || 0);
                if (Object.hasOwnProperty.call(counts, data.status)) counts[data.status]++;
                lines.push(data.message || ''); if (lines.length > 200) lines.shift();
                log.textContent = lines.join('\n');
                summary.textContent = '已存在：' + counts.exists + '；來源可存取：' + counts.available + '；來源無法存取：' + counts.missing + '；已修復：' + counts.repaired + '；失敗：' + counts.failed;
                progress.textContent = (mode === 'scan' ? '掃描中' : '修復中') + '，已處理 ' + products + ' 個商品（記錄顯示最近 200 筆）';
            }
            progress.textContent = (stopped ? '已停止' : '處理完成') + '，已處理 ' + products + ' 個商品。' + (counts.failed || counts.missing ? '仍有失敗或缺圖，請查看記錄。' : '');
        } catch (error) { progress.textContent = '錯誤：' + error.message; }
        finally { running = false; scan.disabled = repair.disabled = false; stop.disabled = true; }
    }
    scan.addEventListener('click', () => run('scan'));
    repair.addEventListener('click', () => run('repair'));
})();

