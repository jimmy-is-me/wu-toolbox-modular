(function ($) {
    'use strict';
    $(function () {
        var selector = '.wutm-wc-custom-cart-form', timer, busy = false, desired = null, failed = false;
        function form() { return $(selector).first(); }
        if (!form().length) return;
        function read() {
            var values = {};
            form().find('input[name^="cart["]').each(function () { values[this.name] = this.value; });
            return values;
        }
        var saved = read();
        function same(a, b) { return JSON.stringify(a) === JSON.stringify(b); }
        function status(message) { $('#wutm-onepage-status').text(message); }
        function lock(locked) {
            $('form.checkout').toggleClass('wutm-onepage-pending', locked);
            $('.wutm-wc-onepage-coupons :submit').prop('disabled', locked);
        }
        function schedule() {
            clearTimeout(timer);
            desired = read(); failed = false;
            lock(!same(desired, saved));
            timer = setTimeout(update, 450);
        }
        function update() {
            clearTimeout(timer);
            if (busy || failed || !desired || same(desired, saved)) return;
            var $form = form(), invalid = false;
            $form.find('input.qty').each(function () { if (!this.checkValidity()) invalid = true; });
            if (invalid) { failed = true; status('請確認商品數量是否符合可購買範圍。'); return; }
            // Do not overlap a native coupon request in the same WooCommerce session.
            if ($('form.checkout_coupon.processing').length) { timer = setTimeout(update, 250); return; }
            busy = true;
            var sent = $.extend({}, desired);
            var data = $.param(sent) + '&security=' + encodeURIComponent($form.attr('data-security'));
            status('正在更新購物車…');
            $form.attr('aria-busy', 'true'); lock(true);
            $.ajax({url: $form.attr('data-update-url'), type: 'POST', data: data, dataType: 'json', timeout: 20000})
                .done(function (response) {
                    if (!response || !response.success) { failed = true; status('更新失敗，請調整數量或按重試。'); return; }
                    if (response.data.empty) { window.location.assign(response.data.redirect); return; }
                    var pending = !same(desired, sent);
                    $('.wutm-wc-custom-onepage-cart-wrap').first().replaceWith(response.data.html);
                    form().addClass('wutm-onepage-auto'); saved = read();
                    if (pending) {
                        form().find('input[name^="cart["]').each(function () { if (Object.prototype.hasOwnProperty.call(desired, this.name)) this.value = desired[this.name]; });
                        desired = read();
                    } else desired = saved;
                    status('購物車已更新。');
                    $(document.body).trigger('wc_fragment_refresh');
                    if (!pending) $(document.body).trigger('update_checkout');
                })
                .fail(function (xhr) {
                    failed = true;
                    status(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message || '連線失敗，請按重試；尚未送出訂單。');
                })
                .always(function () {
                    busy = false; form().removeAttr('aria-busy');
                    $('#wutm-onepage-retry').remove();
                    if (failed) $('<button type="button" id="wutm-onepage-retry" class="button">重試更新購物車</button>').insertAfter('#wutm-onepage-status');
                    else if (!same(desired, saved)) { clearTimeout(timer); timer = setTimeout(update, 0); }
                    lock(failed || !same(desired, saved));
                });
        }
        form().addClass('wutm-onepage-auto');
        $(document).on('input change', selector + ' input.qty', schedule);
        $(document).on('click', selector + ' .plus, ' + selector + ' .minus', function () { setTimeout(schedule, 0); });
        $(document).on('submit', selector, function (event) { event.preventDefault(); schedule(); update(); });
        $(document).on('click', '#wutm-onepage-retry', function () { failed = false; update(); });
        // WooCommerce's checkout_place_order event must not race a pending cart write.
        $('form.checkout').on('checkout_place_order', function () {
            if (busy || failed || (desired && !same(desired, saved))) { status('請等待購物車更新完成後再下單。'); return false; }
        });
    });
})(jQuery);
