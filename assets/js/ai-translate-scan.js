(function () {
    'use strict';
    let running = false;
    let paused = false;
    let job = null;
    async function request(operation) {
        const body = new FormData();
        body.append('action', 'wu_ait_scan_sitewide');
        body.append('_ajax_nonce', wuAitScanConfig.nonce);
        body.append('operation', operation);
        if (job) {
            body.append('token', job.token);
            body.append('revision', String(job.revision));
        }
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 60000);
        try {
            const response = await fetch(wuAitScanConfig.url, { method: 'POST', body, credentials: 'same-origin', signal: controller.signal });
            const text = await response.text();
            let json;
            try { json = JSON.parse(text); } catch (_) {
                if (response.redirected || /wp-login|loginform/i.test(text)) {
                    throw new Error('登入已失效，請重新登入後續掃。');
                }
                if (response.status === 403) throw new Error('HTTP 403：請求被拒絕，請檢查防火牆或登入驗證。');
                if (response.status === 502 || response.status === 504) throw new Error('HTTP ' + response.status + '：主機回應逾時，請稍後續掃。');
                throw new Error('HTTP ' + response.status + '：主機未回傳有效 JSON，請檢查伺服器錯誤紀錄後續掃。');
            }
            if (!response.ok || !json || json.success !== true || !json.data) {
                throw new Error(json && json.data && json.data.message || ('HTTP ' + response.status + '：掃描請求失敗。'));
            }
            return json.data;
        } catch (error) {
            if (error.name === 'AbortError') throw new Error('請求等待超過 60 秒，請稍後續掃。');
            throw error;
        } finally { clearTimeout(timer); }
    }
    window.wuAitRunBatchedScan = async function () {
        if (running) return;
        const button = document.getElementById('wu-ait-scan-btn');
        const result = document.getElementById('wu-ait-scan-result');
        const count = document.getElementById('wu-ait-discovered-count');
        let progress = document.getElementById('wu-ait-scan-progress');
        if (!progress) {
            progress = document.createElement('progress');
            progress.id = 'wu-ait-scan-progress';
            progress.setAttribute('aria-label', '全站掃描進度');
            progress.style.cssText = 'display:block;width:100%;max-width:640px;height:16px;margin:12px 0;';
            result.after(progress);
        }
        let pause = document.getElementById('wu-ait-scan-pause');
        if (!pause) {
            pause = document.createElement('button');
            pause.type = 'button';
            pause.id = 'wu-ait-scan-pause';
            pause.className = 'button';
            pause.textContent = '暫停掃描';
            pause.addEventListener('click', () => { paused = true; pause.disabled = true; pause.textContent = '處理完目前頁面後暫停…'; });
            button.after(pause);
        }
        let cancel = document.getElementById('wu-ait-scan-cancel');
        if (!cancel) {
            cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.id = 'wu-ait-scan-cancel';
            cancel.className = 'button';
            cancel.textContent = '取消未完成的掃描';
            cancel.addEventListener('click', async () => {
                if (running || !job || !window.confirm('取消本次掃描？已完成的清單會保留。')) return;
                cancel.disabled = true;
                try {
                    await request('cancel');
                    job = null;
                    cancel.hidden = true;
                    button.textContent = '立即掃描全站';
                    result.textContent = '已取消未完成的掃描，原清單未變更。';
                } catch (error) { result.textContent = error.message; }
                finally { cancel.disabled = false; }
            });
            pause.after(cancel);
        }
        cancel.hidden = true;
        running = true;
        paused = false;
        button.disabled = true;
        button.textContent = '分批掃描中…';
        pause.hidden = false;
        pause.disabled = false;
        pause.textContent = '暫停掃描';
        result.setAttribute('role', 'status');
        result.style.color = '#50575e';
        result.textContent = '正在建立或恢復掃描工作；完成前保留舊清單。';
        try {
            job = await request(job ? 'status' : 'start');
            while (true) {
                progress.max = Math.max(1, job.total);
                progress.value = job.done ? progress.max : job.processed;
                result.textContent = job.message;
                if (job.done) {
                    if (count) count.textContent = String(job.count);
                    result.style.color = '#1a7e28';
                    job = null;
                    break;
                }
                if (paused) { result.textContent += ' 已暫停，可在 24 小時內續掃。'; break; }
                // Only one page/request at a time, yield between requests; no idle polling.
                await new Promise(resolve => setTimeout(resolve, 150));
                job = await request('step');
            }
        } catch (error) {
            if (error.message.includes('掃描工作已過期')) job = null;
            result.style.color = '#b32d2e';
            result.textContent = '掃描未完成：' + error.message + ' 已完成的掃描清單仍保留，請按續掃。';
        } finally {
            running = false;
            button.disabled = false;
            button.textContent = job ? '繼續掃描全站' : '立即掃描全站';
            pause.hidden = true;
            cancel.hidden = !job;
        }
    };
}());
