(function () {
    'use strict';

    var panel = document.getElementById('wutm-notice-center');
    var content = document.getElementById('wpbody-content');
    if (!panel || !content) return;

    var items = panel.querySelector('.wutm-notice-items');
    var selector = '.notice, div.updated, div.error, .update-nag, .e-notice, .trp-notice';
    var excludedContainers = '.postbox, .stuffbox, table, form, .components-notice-list, .woocommerce-layout__activity-panel';
    var collecting = false;

    function visible(node) {
        if (node.closest('[hidden], [aria-hidden="true"], .hidden, .hide-if-js, #lost-connection-notice, #local-storage-notice')) return false;
        var style = window.getComputedStyle(node);
        return style.display !== 'none' && style.visibility !== 'hidden';
    }

    function eligible(node) {
        if (!(node instanceof Element) || !node.matches(selector)) return false;
        if (panel.contains(node) || node.matches('.inline, .wutm-notice-keep')) return false;
        if (node.closest(excludedContainers)) {
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
            return node.matches('.notice-error, div.error');
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
    collect(content);

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
    contentObserver.observe(content, {childList: true, subtree: true});

    var panelObserver = new MutationObserver(refresh);
    panelObserver.observe(items, {childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class', 'hidden']});

    // Core common.js and some plugins relocate notices during DOM ready/load.
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { collect(content); }, {once: true});
    window.addEventListener('load', function () { collect(content); }, {once: true});
})();
