(function (window, document, $, undefined) {

	const ckeckoutForm = {};

	ckeckoutForm.init = function () {
		ckeckoutForm.checkStorage()
	}

	const ignoreFields = ['payuni_shiptype', 'payuni_storename', 'payuni_storeid', 'payuni_storeaddress'];

	ckeckoutForm.checkStorage = function () {

		let formValues = window.localStorage.getItem('payuni_woo_form');

		if (formValues && JSON.parse(formValues)) {
			formValues = JSON.parse(formValues);

			for (var i in formValues) {

				if (ignoreFields.includes(formValues[i].name)) {
					continue;
				}

				var $item = jQuery('[name="' + formValues[i].name + '"]');

				switch ($item.prop('tagName')) {
					case 'INPUT':
						if ( $item.attr('type') == 'checkbox' ) {
							//support multiple checkbox
							$item.filter('[value="' + formValues[i].value + '"]').attr('checked', true);
							break;
						} else if ($item.attr('type') == 'radio') {
							$item.filter('[value="' + formValues[i].value + '"]').attr('checked', true);
							break;
						} else {
							$item.val(formValues[i].value);
							break;
						}
					case 'TEXTAREA':
						$item.val(formValues[i].value);
						break;
					case 'SELECT':
						var oldVal = $item.val();
						if (Array.isArray(oldVal)) {
							//multi select
							oldVal.push(formValues[i].value);
							$item.val(oldVal).change();
						} else {
							//single select
							$item.val(formValues[i].value).change();
						}
						break;
					default:
						break;
				}

			}
			$('body').trigger('update_checkout');
		}
		ckeckoutForm.startListener();

	}

	ckeckoutForm.startListener = function () {
		const $form = $('form.woocommerce-checkout');
		$form.change(event => {
			let wooForm = $form.serializeArray().filter(val => ignoreFields.indexOf(val.name) === -1);
			window.localStorage.setItem('payuni_woo_form', JSON.stringify(wooForm));
		});
	}

	ckeckoutForm.init();

})(window, document, jQuery);
