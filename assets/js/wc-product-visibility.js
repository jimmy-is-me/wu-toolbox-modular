(function ($) {
    'use strict';
    function sync() {
        var type = document.getElementById('product-type');
        if (type) {
            var grouped = type.querySelector('option[value="grouped"]');
            // CSS is emitted only for the enabled option. Never alter the selected type.
            if (grouped) {
                grouped.hidden = false;
                grouped.hidden = !grouped.selected && getComputedStyle(grouped).display === 'none';
            }
        }
        var tabs = $('#woocommerce-product-data .product_data_tabs li');
        var active = tabs.filter('.active');
        if (!active.length || !active.is(':visible')) tabs.filter(':visible').first().find('a').trigger('click');
    }
    $(function () {
        sync();
        $(document.body).on('woocommerce-product-type-change', sync);
        $(document).on('postbox-toggled', sync);
        $('#product-type').on('change.wutmVisibility', function () { window.requestAnimationFrame(sync); });
    });
})(jQuery);
