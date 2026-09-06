(function(){
    'use strict';

    function onReady(run){
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', run);
        } else {
            run();
        }
    }

    onReady(function(){
        var cfg = window.sparVouchers || {};
        var ajaxUrl = cfg.ajaxUrl || (window.ajaxurl || '');
        var nonce = cfg.nonce || '';
        var cartUrl = cfg.cartUrl || '';
        var i18n = cfg.i18n || {};

        function copyText(text){
            if (navigator.clipboard && navigator.clipboard.writeText) {
                return navigator.clipboard.writeText(text);
            }
            return new Promise(function(resolve, reject){
                try {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    ta.setAttribute('readonly', '');
                    ta.style.position = 'absolute';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.select();
                    var ok = document.execCommand('copy');
                    document.body.removeChild(ta);
                    ok ? resolve() : reject();
                } catch (err) {
                    reject(err);
                }
            });
        }

        document.addEventListener('click', function(e){
            var copyBtn = e.target && e.target.closest && e.target.closest('.spar-copy-voucher');
            if (copyBtn) {
                var code = copyBtn.getAttribute('data-code');
                if (!code) return;
                if (copyBtn._sparResetTimer) {
                    clearTimeout(copyBtn._sparResetTimer);
                }
                var label = copyBtn.querySelector('.spar-copy-voucher-code');
                copyText(code).then(function(){
                    copyBtn.classList.add('spar-copied');
                    if (label) {
                        if (!copyBtn._sparOriginal) {
                            copyBtn._sparOriginal = label.textContent;
                        }
                        label.textContent = i18n.copied || 'Copied!';
                    }
                    copyBtn._sparResetTimer = setTimeout(function(){
                        copyBtn.classList.remove('spar-copied');
                        if (label && copyBtn._sparOriginal) {
                            label.textContent = copyBtn._sparOriginal;
                            copyBtn._sparOriginal = null;
                        }
                    }, 1500);
                }).catch(function(){
                    // eslint-disable-next-line no-alert
                    alert(i18n.copyFailed || 'Could not copy the code. Please copy it manually: ' + code);
                });
                return;
            }

            var btn = e.target && e.target.closest && e.target.closest('.spar-apply-to-cart-btn');
            if (!btn) return;
            var code = btn.getAttribute('data-voucher');
            if (!code || !ajaxUrl) return;

            var original = btn.textContent;
            btn.disabled = true;
            btn.textContent = i18n.applying || 'Applying...';

            var body = new URLSearchParams();
            body.set('action', 'spar_apply_voucher_to_cart');
            body.set('voucher_code', code);
            if (nonce) body.set('nonce', nonce);

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function(r){ return r.json(); }).then(function(resp){
                if (resp && resp.success) {
                    // If server indicates deferred application (empty cart), do not redirect
                    if (resp.data && resp.data.deferred) {
                        btn.textContent = i18n.saved || 'Saved for later';
                        btn.classList.add('spar-apply-success');
                        btn.disabled = true;
                        // eslint-disable-next-line no-alert
                        alert((resp.data && resp.data.message) || i18n.deferredMsg || "We'll apply your voucher as soon as you add products to your cart.");
                        return;
                    }

                    btn.textContent = i18n.applied || 'Applied!';
                    btn.classList.add('spar-apply-success');
                    setTimeout(function(){
                        var url = (resp.data && resp.data.cart_url) ? resp.data.cart_url : cartUrl;
                        if (url) window.location.href = url;
                    }, 800);
                } else {
                    btn.disabled = false;
                    btn.textContent = original;
                    var msg = (resp && resp.data) ? resp.data : (i18n.error || 'Could not apply voucher. Please try again.');
                    // eslint-disable-next-line no-alert
                    alert(msg);
                }
            }).catch(function(){
                btn.disabled = false;
                btn.textContent = original;
                // eslint-disable-next-line no-alert
                alert(i18n.error || 'Could not apply voucher. Please try again.');
            });
        });
    });
})();
