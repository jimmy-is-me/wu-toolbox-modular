(function () {
    'use strict';
    function sync(root) {
        var edit = root.closest('.wutm-sp-edit');
        if (!edit) return;
        var fieldset = edit.querySelector('fieldset');
        if (fieldset) fieldset.disabled = root.matches('.wutm-sp-toggle') ? !root.checked : root.value !== 'custom';
    }
    document.addEventListener('change', function (event) {
        if (event.target.matches('.wutm-sp-toggle, .wutm-sp-mode')) sync(event.target);
        if (event.target.matches('input[type="date"][data-part="from"]')) {
            var group = event.target.closest('.wutm-sp-date-range');
            var end = group && group.querySelector('input[data-part="to"]');
            if (end) end.min = event.target.value;
        }
    });
    document.querySelectorAll('.wutm-sp-toggle, .wutm-sp-mode').forEach(sync);
    // Variation panels are loaded on demand; no global MutationObserver or polling.
    if (window.jQuery) window.jQuery(document.body).on('woocommerce_variations_loaded woocommerce_variations_added', function () {
        document.querySelectorAll('.wutm-sp-variation .wutm-sp-toggle').forEach(sync);
    });
})();
