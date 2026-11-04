(function ($) {
    'use strict';
    $(function () {
        if (!window.inlineEditPost || !window.inlineEditPost.edit) return;
        var original = window.inlineEditPost.edit;
        window.inlineEditPost.edit = function (id) {
            original.apply(this, arguments);
            var productId = typeof id === 'object' ? parseInt(this.getId(id), 10) : parseInt(id, 10);
            if (!productId) return;
            var value = $('#post-' + productId + ' .wutm-stt-data').attr('data-temp') || 'all';
            $('#edit-' + productId + ' select[name="_shipping_temperature"]').val(value);
        };
    });
})(jQuery);
