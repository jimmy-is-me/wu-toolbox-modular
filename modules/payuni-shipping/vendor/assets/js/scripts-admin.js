(function( $ ) {
	'use strict';

	$(document).on('click', '.edit_address', function () {

		if ($('#_shipping_payuni_storeid').length) {
			$('a.load_customer_shipping').remove();
			$('a.billing-same-as-shipping').remove();

			$('._shipping_company_field').hide();
			$('._shipping_address_1_field').hide();
			$('._shipping_address_2_field').hide();
			$('._shipping_city_field').hide();
			$('._shipping_postcode_field').hide();
			$('._shipping_country_field').hide();
			$('._shipping_state_field').hide();
		}
	});

	$(document).on('click', '#payuni-shipping-info .update-delivery-status', function(event){
		event.preventDefault();
		var post_id = $(this).data('id');
		$.ajax({
			url: payuni_shipping.ajax_url,
			data: {
				action: 'update_delivery_status',
				post_id: post_id,
				security: payuni_shipping.security,
			},
			dataType: "json",
			type: 'post',
			success: function (data) {
				if (data.success) {
					window.location.reload();
				} else {
					alert(payuni_shipping.translations.shipping_status_update_failed + ' ' + data.result);
				}

				window.location.reload();

			},
			always: function () {
				// $('input[name=shipping_order_file]').prop('disabled', false);
				// $('#order-shipping-import-form input[type=submit]').prop('disabled', false).val('匯入');
			}
		});

	});

	$(document).on('click', '#payuni-shipping-info .cancel-shipping', function (event) {

		event.preventDefault();
		var post_id = $(this).data('id');
		$.ajax({
			url: payuni_shipping.ajax_url,
			data: {
				action: 'cancel_shipping_order',
				post_id: post_id,
				security: payuni_shipping.security,
			},
			dataType: "json",
			type: 'post',
			success: function ( data ) {
				if (data.success) {

				} else {
					alert( payuni_shipping.translations.cancel_shipping_failed + ' ' + data.result);
				}
				window.location.reload();

			},
			always: function () {
				// $('input[name=shipping_order_file]').prop('disabled', false);
				// $('#order-shipping-import-form input[type=submit]').prop('disabled', false).val('匯入');
			}
		});

	});


})( jQuery );
