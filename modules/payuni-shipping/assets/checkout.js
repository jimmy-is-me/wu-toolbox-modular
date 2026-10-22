/* Preserve map submission while isolating PAYUNi's UI from other providers. */
var payuni_shipping_info;
(function ($) {
    'use strict';
    payuni_shipping_info = window.payuni_shipping_object || {};
    function selected() {
        var input = $('input[name^="shipping_method"]:checked').first();
        if (!input.length) input = $('input[name^="shipping_method"][type="hidden"]').first();
        return String(input.val() || '').split(':')[0];
    }
    function refresh(data) {
        var info = data && data.fragments && data.fragments.payuni_shipping_info;
        if (info && info.shipping_data) payuni_shipping_info = info.shipping_data;
        var method = selected(), cvs = method.indexOf('payuni_shipping_711_') === 0;
        var hd = method.indexOf('payuni_shipping_tcat_') === 0;
        $(document.body).toggleClass('wutm-payuni-cvs', cvs).toggleClass('wutm-payuni-hd', hd);
        $('#wutm-payuni-choose-cvs').toggle(cvs).text($('#payuni_storename').val() ? '選擇超商：' + $('#payuni_storename').val() : '選擇超商');
        if (cvs && !$('#ship-to-different-address-checkbox').prop('checked')) $('#ship-to-different-address-checkbox').prop('checked', true).trigger('change');
    }
    $(document).on('click', '#wutm-payuni-choose-cvs', function (event) {
        event.preventDefault();
        if (selected().indexOf('payuni_shipping_711_') !== 0 || !payuni_shipping_info.EncryptInfo || !payuni_shipping_info.HashInfo) return;
        if (!/^https:\/\/(?:sandbox-api|api)\.payuni\.com\.tw\/api\/logistics\/ship_map$/.test(payuni_shipping_info.ajax_url || '')) return;
        $('#payuni-choose-cvs-form').remove();
        var form = $('<form>', {id:'payuni-choose-cvs-form', action:payuni_shipping_info.ajax_url, method:'post'});
        Object.entries(payuni_shipping_info).forEach(function (pair) { if (typeof pair[1] !== 'object') $('<input>', {type:'hidden', name:pair[0], value:pair[1]}).appendTo(form); });
        form.appendTo(document.body); form[0].submit();
    });
    $(document.body).on('updated_checkout', function (event, data) { refresh(data); });
    $(function () { refresh(); });
})(jQuery);
