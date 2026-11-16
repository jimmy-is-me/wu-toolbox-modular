(function () {
    function init() {
        document.querySelectorAll('.wutm-member-card-qr[data-qr]').forEach(function (element) {
            if (element.dataset.ready) return;
            const status = element.parentElement.querySelector('.wutm-card-status');
            if (!window.QRCode || typeof window.QRCode.toCanvas !== 'function') {
                if (status) status.textContent = 'QR 載入失敗，請重新產生會員卡或聯絡客服。';
                return;
            }
            element.dataset.ready = '1';
            const canvas = document.createElement('canvas'); element.appendChild(canvas);
            window.QRCode.toCanvas(canvas, element.dataset.qr, {width:180,margin:4,errorCorrectionLevel:'M'}, function(error) {
                if (error) { canvas.remove(); delete element.dataset.ready; if(status) status.textContent='QR 產生失敗，請重新產生會員卡。'; return; }
                if(status) status.textContent='QR 已就緒，請交由已登入的授權人員掃描。';
                const tick = function() {
                    const left = Math.max(0, Math.ceil(Number(element.dataset.cardExpires) - Date.now()/1000));
                    if(status) status.textContent=left ? 'QR 有效時間剩 '+Math.floor(left/60)+' 分 '+left%60+' 秒' : 'QR 已過期，請按「重新產生會員卡」。';
                    if(!left) { canvas.hidden=true; clearInterval(timer); }
                };
                const timer=setInterval(tick,1000); tick();
            });
        });
    }
    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
