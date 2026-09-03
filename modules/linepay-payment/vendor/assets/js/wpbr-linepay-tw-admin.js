jQuery(function ($) {
	'use strict';

	const { __, _x, _n, _nx } = wp.i18n;
	/**
	 * Object to handle LINE Pay admin functions.
	 */
	var linepay_admin = {
		/**
		 * Initialize.
		 */
		init: function () {
			$(document.body).on('change', '#linepay_tw_sandboxmode_enabled', function () {
				var sandbox_channel_id = $('#linepay_tw_sandbox_channel_id').parents('tr').eq(0),
					sandbox_channel_secret = $('#linepay_tw_sandbox_channel_secret').parents('tr').eq(0),

					channel_id = $('#linepay_tw_channel_id').parents('tr').eq(0),
					channel_secret = $('#linepay_tw_channel_secret').parents('tr').eq(0);


				if ($(this).is(':checked')) {
					sandbox_channel_id.show();
					sandbox_channel_secret.show();

					channel_id.hide();
					channel_secret.hide();

				} else {
					sandbox_channel_id.hide();
					sandbox_channel_secret.hide();

					channel_id.show();
					channel_secret.show();

				}
			});

			$('#linepay_tw_sandboxmode_enabled').trigger('change');

			$( document ).on( 'click', '.linepay-confirm-btn', function( event ){
				event.preventDefault();
				var post_id = $(this).data('id');
				$('.linepay-notice').remove();
				if ($.blockUI) {
					$('#woocommerce-linepay-meta-boxes').block({
						message: null,
					});
				}
			$.ajax({
				url: linepay_object.ajax_url,
				data: {
					action: 'linepay_confirm',
					post_id: post_id,
					security: linepay_object.confirm_nonce,
				},
				dataType: "json",
				type: 'post',
				success: function (data) {
					console.log(data);

					if (data.success) {
						alert(data.message);
						window.location.reload();
					} else {
						alert(data.message);
					}
					if ($.blockUI) {
						$('#woocommerce-linepay-meta-boxes').unblock();
					}
				},
				always: function () {
					if ($.blockUI) {
						$('#woocommerce-linepay-meta-boxes').unblock();
					}
				}
			});

	});

		}
	};

	linepay_admin.init();
});
