jQuery(document).ready(function($) {
    $('.esun-toggle-checkbox').on('change', function() {
        const $checkbox = $(this);
        const install = $checkbox.data('install');
        const nonce = $checkbox.data('nonce');
        const active = $checkbox.is(':checked');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'esun_toggle_installment',
                install: install,
                active: active,
                nonce: nonce
            },
            success: function(response) {
                if (!response.success) {
                    $checkbox.prop('checked', !active);
                    alert('Failed to update status');
                }
            },
            error: function() {
                $checkbox.prop('checked', !active);
                alert('Failed to update status');
            }
        });
    });
}); 