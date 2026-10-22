var payuni_shipping_info;

(function ($) {
	'use strict';

	if (typeof payuni_shipping_object !== 'undefined') {
       	payuni_shipping_info = payuni_shipping_object;
    }

	// console.log(payuni_shipping_info);

	$(document).on('click', '#choose-cvs-btn', function () {

		var html = '<form id="payuni-choose-cvs-form" action="'+ payuni_shipping_info.ajax_url + '" method="post">';

		for (const [key, value] of Object.entries(payuni_shipping_info)) {
			html += '<input type="hidden" name="' + key + '" value="' + value + '">';
		}
		html += '</form>';

		document.body.innerHTML += html;
		document.getElementById('payuni-choose-cvs-form').submit();

	});

	$(document.body).on('updated_checkout', function (e, data) {

		//if the shipping method is cvs, always check ship to different address
		console.log(data.fragments.payuni_shipping_info.shipping_data.ShipType);
		if (typeof data.fragments.payuni_shipping_info.shipping_data.ShipType !== 'undefined') {

			if (data.fragments.payuni_shipping_info.shipping_data.is_payuni_cvs) {
				payuni_shipping_info = data.fragments.payuni_shipping_info.shipping_data;
				console.log(payuni_shipping_info);
				$('.woocommerce-shipping-fields__field-wrapper p:not(.payuni-shipping-field)').hide();

				$('.woocommerce-shipping-fields__field-wrapper p#shipping_first_name_field').show();
				$('.woocommerce-shipping-fields__field-wrapper p#shipping_last_name_field').show();

				if (data.fragments.payuni_shipping_info.shipping_data.ShipType != $('#payuni_shiptype').val()) {
					// $('#payuni_shiptype').val('');
					// $('#payuni_storeid').val('');
					// $('#payuni_storename').val('');
					// $('#payuni_storeaddress').val('');
					// $('#payuni_reservedno').val('');
					// $('#payuni_shipdate').val('');
				}

				$('.woocommerce-shipping-fields__field-wrapper p.payuni-shipping-field').show();

				if ($('#payuni_storename').val() != '') {
					$('#choose-cvs-btn').show();
					$('#choose-cvs-btn').html('選擇超商:' + $('input#payuni_storename').val());
				}

				//超取要強制勾選 ship to different address
				if ($('#ship-to-different-address-checkbox').prop('checked') === false) {
					$('#ship-to-different-address-checkbox').click();
				}
			} else {
				//宅配不需要超商資訊，但需要手機
				$('.woocommerce-shipping-fields__field-wrapper p:not(.payuni-shipping-field)').show();
				$('.woocommerce-shipping-fields__field-wrapper p.payuni-shipping-field').hide();
				$('.woocommerce-shipping-fields__field-wrapper p.payuni-shipping-field#shipping_phone_field').show();
			}

		} else {
			$('.woocommerce-shipping-fields__field-wrapper p.payuni-shipping-field').hide();
			$('.woocommerce-shipping-fields__field-wrapper p:not(.payuni-shipping-field)').show();
		}

	});

})(jQuery);
