(() => {
    'use strict';
    const root = document.getElementById('wutm-chunk-upload');
    const form = root?.querySelector('[data-upload-form]');
    if (!form) return;

    const input = form.querySelector('input[type=file]');
    const start = form.querySelector('[data-start]');
    const pause = form.querySelector('[data-pause]');
    const cancel = form.querySelector('[data-cancel]');
    const status = root.querySelector('[data-status]');
    const result = root.querySelector('[data-result]');
    const progress = root.querySelector('progress');
    const bar = progress.parentElement;
    const config = root.dataset;
    const storageKey = 'wutm-chunk:' + config.ajax + ':' + config.scope;

    let file = null;
    let job = null;
    let controller;
    let phase = 'idle';

    function message(text, error = false) {
        status.textContent = text;
        status.dataset.error = String(error);
    }

    function render(next) {
        phase = next;
        const transferring = next === 'uploading';
        const busy = transferring || next === 'merging' || next === 'cancelling';
        input.disabled = busy;
        start.disabled = busy || next === 'success';
        start.textContent = next === 'paused' ? '繼續上傳' : '開始上傳';
        pause.disabled = !transferring;
        cancel.disabled = !job?.id || next === 'merging' || next === 'cancelling' || next === 'success';
    }

    function showProgress(value) {
        bar.hidden = false;
        progress.value = value;
        bar.querySelector('[data-percent]').textContent = value + '%';
    }

    function hex(bytes) {
        return Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    }

    function readJob(digest) {
        try {
            const saved = JSON.parse(localStorage.getItem(storageKey));
            if (digest && saved?.digest === digest && Date.now() - saved.savedAt < 24 * 60 * 60 * 1000) return saved;
        } catch (_) {
            // Upload still works when browser storage is unavailable.
        }
        return null;
    }

    function remember() {
        if (!job?.digest) return;
        try { localStorage.setItem(storageKey, JSON.stringify({...job,savedAt:Date.now()})); } catch (_) {}
    }

    function forget() {
        try {
            const saved = JSON.parse(localStorage.getItem(storageKey));
            if (saved?.key === job?.key) localStorage.removeItem(storageKey);
        } catch (_) {}
        job = null;
    }

    async function request(op, fields, signal) {
        const abort = new AbortController();
        const relay = () => abort.abort();
        signal?.addEventListener('abort', relay, {once:true});
        if (signal?.aborted) abort.abort();
        const timer = setTimeout(relay, op === 'finish' ? 75000 : 30000);
        try {
            const data = new FormData();
            data.append('action', config.action);
            data.append('nonce', config.nonce);
            data.append('op', op);
            for (const [key,value] of Object.entries(fields)) data.append(key, value);
            const response = await fetch(config.ajax, {method:'POST', credentials:'same-origin', cache:'no-store',
                body:data, signal:abort.signal});
            const payload = await response.json().catch(() => null);
            if (!response.ok || !payload?.success) {
                const text = response.status === 413 ? '單一分段超過主機或代理服務限制。' :
                    payload?.data?.message || ('伺服器回應異常（HTTP ' + response.status + '）');
                throw Object.assign(new Error(text), {status:response.status});
            }
            return payload.data;
        } finally {
            clearTimeout(timer);
            signal?.removeEventListener('abort', relay);
        }
    }

    async function retry(op, fields, signal) {
        for (let attempt = 0; ; attempt++) {
            if (signal?.aborted) throw new Error('已暫停');
            try {
                return await request(op, fields, signal);
            } catch (error) {
                const transient = !error.status || [408,409,500,502,503,504].includes(error.status);
                if (signal?.aborted || !transient || attempt >= 3) throw error;
                message('連線或主機暫時忙碌，正在重試…');
                await new Promise(resolve => setTimeout(resolve, 500 * (2 ** attempt)));
            }
        }
    }

    function showResult(upload) {
        showProgress(100);
        result.replaceChildren();
        for (const [key,label] of [['url','開啟影片'], ['edit_url','在媒體庫編輯']]) {
            if (!upload[key]) continue;
            const url = new URL(upload[key], location.href);
            if (!['http:','https:'].includes(url.protocol)) continue;
            const link = document.createElement('a');
            link.href = url.href;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = label;
            link.className = 'button';
            result.append(link);
        }
        result.hidden = false;
        forget();
        message('影片已加入媒體庫。');
        render('success');
    }

    input.addEventListener('change', () => {
        const selected = input.files[0] || null;
        if (selected === file) return;
        file = selected;
        job = null;
        bar.hidden = true;
        result.hidden = true;
        message('');
        render('idle');
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (start.disabled || !file) return;
        if (!/\.mp4$/i.test(file.name) || file.size === 0 || file.size > Number(config.max)) {
            message('請選擇未超過本站上限的 MP4 影片。', true);
            return;
        }

        controller = new AbortController();
        const {signal} = controller;
        render('uploading');
        result.hidden = true;
        try {
            if (!job) {
                message('正在確認檔案…');
                // A whole-file digest prevents a similarly named file from being joined to an old job.
                const digest = globalThis.crypto.subtle ? hex(new Uint8Array(await crypto.subtle.digest('SHA-256', await file.arrayBuffer()))) : '';
                if (signal.aborted) return;
                job = readJob(digest) || {key:hex(crypto.getRandomValues(new Uint8Array(16))),digest};
                remember();
            }
            const session = await retry('start', {key:job.key,name:file.name,size:file.size,digest:job.digest}, signal);
            job.id = session.id;
            remember();
            render('uploading');

            if (!session.attachment) {
                const received = new Set(session.received);
                let sent = [...received].reduce((total, i) => total + Math.min(session.part_size, file.size - i * session.part_size), 0);
                showProgress(Math.floor(sent / file.size * 100));
                for (let i = 0; i < session.total; i++) {
                    if (received.has(i)) continue;
                    const offset = i * session.part_size;
                    const chunk = file.slice(offset, offset + session.part_size);
                    message('正在傳送第 ' + (i + 1) + '／' + session.total + ' 段…');
                    await retry('part', {id:job.id,index:i,chunk}, signal);
                    sent += chunk.size;
                    showProgress(Math.floor(sent / file.size * 100));
                }
            }
            if (signal.aborted) return;
            render('merging');
            message('正在合併、驗證影片並加入媒體庫…');
            showResult(await retry('finish', {id:job.id}, signal));
        } catch (error) {
            if (signal.aborted) return;
            if ([404,410].includes(error.status)) forget();
            message(error.message + ' 可按開始或繼續上傳重試。', true);
        } finally {
            controller = null;
            if (!['success','cancelling'].includes(phase)) render(job ? 'paused' : 'idle');
        }
    });

    pause.addEventListener('click', () => {
        pause.disabled = true;
        controller?.abort();
        message('已暫停。可以用同一個檔案繼續上傳。');
    });

    cancel.addEventListener('click', async () => {
        if (!job?.id || !window.confirm('取消後會清除這次尚未完成的分段，確定取消？')) return;
        const id = job.id;
        controller?.abort();
        render('cancelling');
        message('正在取消上傳…');
        try {
            const cancelled = await retry('cancel', {id});
            if (cancelled.attachment) {
                showResult(cancelled);
                return;
            }
            forget();
            bar.hidden = true;
            message('已取消上傳。');
        } catch (error) {
            if ([404,410].includes(error.status)) {
                forget();
                bar.hidden = true;
                message('這筆上傳工作已結束。');
            } else {
                message(error.message, true);
            }
        } finally {
            if (phase !== 'success') render(job ? 'paused' : 'idle');
        }
    });
})();
