(function ($) {
    'use strict';
    $(function () {
        // Core common.js relocates notices during jQuery ready. Run after it.
        function collect() {
            var panel = document.getElementById('wutm-notice-center');
            if (!panel) return;
            var items = panel.querySelector('.wutm-notice-items');
            var roots = document.querySelectorAll('#wpbody-content, #wpbody-content > .wrap, #wpbody-content > .wrap > .wutm-notice-slot, #wpbody-content > .wrap > .wutm-header');
            var selector = '.notice, .updated, .error, .update-nag';
            function visible(node) {
                if (node.closest('[hidden], [aria-hidden="true"], .hidden, .hide-if-js, #lost-connection-notice, #local-storage-notice')) return false;
                var style = window.getComputedStyle(node);
                return style.display !== 'none' && style.visibility !== 'hidden';
            }
            Array.prototype.forEach.call(roots, function (root) {
                Array.prototype.slice.call(root.children).forEach(function (node) {
                    if (!node.matches(selector) || node.matches('.inline') || panel.contains(node) || !visible(node)) return;
                    items.appendChild(node);
                });
            });
            function refresh() {
                var notices = Array.prototype.filter.call(items.children, visible);
                var errors = notices.filter(function (node) {
                    return node.matches('.notice-error, .error');
                }).length;
                panel.querySelector('.wutm-notice-count').textContent = String(notices.length);
                panel.querySelector('.wutm-notice-important').textContent = errors ? '包含 ' + errors + ' 則重要通知' : '';
                if (errors && panel.dataset.openImportant === '1') panel.querySelector('details').open = true;
                panel.classList.toggle('is-visible', notices.length > 0);
            }
            refresh();
            if (!panel.wutmObserver) {
                // Observe only collected notices for dismissals; never the editor DOM.
                panel.wutmObserver = new MutationObserver(refresh);
                panel.wutmObserver.observe(items, {childList: true});
            }
        }
        setTimeout(collect, 0);
        if (document.readyState !== 'complete') window.addEventListener('load', collect, {once: true});
    });
})(jQuery);
