(function () {
    'use strict';

    var panel = document.getElementById('wutm-notice-center');
    var content = document.getElementById('wpbody-content');
    if (!panel || !content) return;
    var noticeRoot = document.getElementById('wpbody') || content;

    var items = panel.querySelector('.wutm-notice-items');
    var selector = '.notice, div.updated, div.error, .update-nag, .e-notice, .trp-notice, .fs-notice, .woocommerce-message, .woocommerce-info, .woocommerce-error, .woocommerce-admin-notice, .wc-admin-notice';
    var excludedContainers = '.postbox, .stuffbox, table, .components-notice-list, .woocommerce-layout__activity-panel';
    var collecting = false;

    function visible(node) {
        if (node.closest('[hidden], [aria-hidden="true"], .hidden, .hide-if-js, #lost-connection-notice, #local-storage-notice')) return false;
        var style = window.getComputedStyle(node);
        return style.display !== 'none' && style.visibility !== 'hidden';
    }

    function eligible(node) {
        if (!(node instanceof Element) || !node.matches(selector)) return false;
        if (panel.contains(node) || node.matches('.inline, .wutm-notice-keep')) return false;
        // Core's hide-if-js notices are no-JavaScript fallbacks, not errors.
        // Moving them into the panel used to override their hidden display and
        // expose the media-grid warning even while the grid worked normally.
        if (node.closest('.hide-if-js')) return false;
        var form = node.closest('form');
        // Plugins such as Post SMTP and WPCode print page-level notices as
        // direct children of WooCommerce's main settings form. Those are safe
        // to move; nested field notices and anything with form controls are not.
        var contextualFormNotice = form && (node.parentElement !== form || node.querySelector('input, select, textarea, button[type="submit"]'));
        if (contextualFormNotice || node.closest(excludedContainers)) {
            node.classList.add('wutm-notice-keep');
            return false;
        }
        return true;
    }

    function move(node) {
        if (eligible(node)) items.appendChild(node);
    }

    function scan(root) {
        if (!(root instanceof Element)) return;
        if (root.matches(selector)) move(root);
        Array.prototype.forEach.call(root.querySelectorAll(selector), move);
    }

    function refresh() {
        var notices = Array.prototype.filter.call(items.children, visible);
        var errors = notices.filter(function (node) {
            return node.matches('.notice-error, div.error, .woocommerce-error');
        }).length;
        panel.querySelector('.wutm-notice-count').textContent = String(notices.length);
        panel.querySelector('.wutm-notice-important').textContent = errors ? '包含 ' + errors + ' 則重要通知' : '';
        panel.classList.toggle('is-visible', notices.length > 0);
    }

    function collect(root) {
        if (collecting) return;
        collecting = true;
        scan(root || content);
        collecting = false;
        refresh();
    }

    // This footer script can collect immediately. The stylesheet has already
    // hidden matching notices, so they are moved before their first paint.
    collect(noticeRoot);

    var contentObserver = new MutationObserver(function (mutations) {
        if (collecting) return;
        collecting = true;
        mutations.forEach(function (mutation) {
            Array.prototype.forEach.call(mutation.addedNodes, function (node) {
                if (node instanceof Element) scan(node);
            });
        });
        collecting = false;
        refresh();
    });
    contentObserver.observe(noticeRoot, {childList: true, subtree: true});

    var panelObserver = new MutationObserver(refresh);
    panelObserver.observe(items, {childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class', 'hidden']});

    // Core common.js and some plugins relocate notices during DOM ready/load.
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { collect(noticeRoot); }, {once: true});
    window.addEventListener('load', function () { collect(noticeRoot); }, {once: true});
})();
