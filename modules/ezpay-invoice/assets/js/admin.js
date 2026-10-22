jQuery(function ($) {

	let invoiceBusy = false;
	const uncertainMessage = "發票操作未能確認完成，請先至服務商查詢是否已開立／作廢，確認後再操作，避免重複送出。";
	function invoiceMessage(response) {
		const data = response?.data;
		if (typeof data === "string") return data;
		if (typeof data?.message === "string") return data.message;
		if (data && typeof data === "object") return Object.keys(data)[0] || uncertainMessage;
		return uncertainMessage;
	}
	function sendInvoice(data) {
		if (invoiceBusy) return;
		invoiceBusy = true;
		$.ajax({url: ajaxurl, method: "POST", data, dataType: "json", timeout: 45000})
			.done(function (response) {
				alert(invoiceMessage(response));
				if (response?.success === true) location.reload();
			})
			.fail(function (xhr) { alert(xhr?.responseJSON ? invoiceMessage(xhr.responseJSON) : uncertainMessage); })
			.always(function () { invoiceBusy = false; $.unblockUI(); });
	}
	function fieldDisplay(fieldControlName, fieldControlNameValue, fieldDisplay) {
		function condition(fieldControlName) {
			if (fieldControlNameValue.includes(fieldControlName.val())) {
				fieldDisplay.show();
			} else {
				fieldDisplay.hide();
			}
		}
		condition(fieldControlName);
		fieldControlName.on("change", function () {
			condition(fieldControlName);
		});
	}

	$(document).ready(function () {
		$(".btnGenerateInvoiceEzPay").click(function () {
			$.blockUI({ message: "<p>處理中...</p>" });

			const getInvoicePayload = () => {
				const _ezpay_invoice_type = $(
					'select[name="_ezpay_invoice_type"]'
				).val();
				const defaultPayload = {
					action: "gen_invoice_ezpay",
					nonce: wu_ezpay_invoice_params.ajax_nonce,
					orderId: $(this).val(),
					_ezpay_invoice_type,
				};

				switch (_ezpay_invoice_type) {
					case "individual":
						return {
							...defaultPayload,
							_ezpay_invoice_individual: $(
								'select[name="_ezpay_invoice_individual"]'
							).val(),
							_ezpay_invoice_carrier: $(
								'input[name="_ezpay_invoice_carrier"]'
							).val(),
						};
					case "company":
						return {
							...defaultPayload,
							_ezpay_invoice_company_name: $(
								'input[name="_ezpay_invoice_company_name"]'
							).val(),
							_ezpay_invoice_tax_id: $(
								'input[name="_ezpay_invoice_tax_id"]'
							).val(),
						};
					case "donate":
						return {
							...defaultPayload,
							_ezpay_invoice_donate: $(
								'input[name="_ezpay_invoice_donate"]'
							).val(),
						};
					default:
						return defaultPayload;
				}
			};

			const data = getInvoicePayload();

			sendInvoice(data);
		});

		$(".btnInvalidInvoiceEzPay").click(function () {
			if (confirm("確定要刪除此筆發票")) {
				$.blockUI({ message: "<p>處理中...</p>" });

				const data = {
					action: "invalid_invoice_ezpay",
					nonce: wu_ezpay_invoice_params.ajax_nonce,
					orderId: $(this).val(),
				};

				sendInvoice(data);
			}
		});


		$("#btnUpdateInvoiceDataEzPay").click(function () {
			$.blockUI({ message: "<p>處理中...</p>" });

			const getInvoicePayload = () => {
				const _ezpay_invoice_type = $(
					'select[name="_ezpay_invoice_type"]'
				).val();
				const defaultPayload = {
					action: "update_invoice_ezpay",
					nonce: wu_ezpay_invoice_params.ajax_nonce,
					orderId: $(this).val(),
					_ezpay_invoice_type,
				};

				switch (_ezpay_invoice_type) {
					case "individual":
						return {
							...defaultPayload,
							_ezpay_invoice_individual: $(
								'select[name="_ezpay_invoice_individual"]'
							).val(),
							_ezpay_invoice_carrier: $(
								'input[name="_ezpay_invoice_carrier"]'
							).val(),
						};
					case "company":
						return {
							...defaultPayload,
							_ezpay_invoice_company_name: $(
								'input[name="_ezpay_invoice_company_name"]'
							).val(),
							_ezpay_invoice_tax_id: $(
								'input[name="_ezpay_invoice_tax_id"]'
							).val(),
						};
					case "donate":
						return {
							...defaultPayload,
							_ezpay_invoice_donate: $(
								'input[name="_ezpay_invoice_donate"]'
							).val(),
						};
					default:
						return defaultPayload;
				}
			};

			const data = getInvoicePayload();

			sendInvoice(data);
		});

		fieldDisplay(
			$('select[name="wc_woomp_ezpay_invoice_issue_mode"]'),
			"auto",
			$("#wc_woomp_ezpay_invoice_issue_at").parent().parent()
		);

		fieldDisplay(
			$('select[name="wc_woomp_ezpay_invoice_invalid_mode"]'),
			"auto",
			$("#wc_woomp_ezpay_invoice_invalid_at").parent().parent()
		);

		// 訂單電子發票欄位顯示判斷
		fieldDisplay(
			$('select[name="_ezpay_invoice_type"]'),
			"individual",
			$('select[name="_ezpay_invoice_individual"]')
		);
		fieldDisplay(
			$('select[name="_ezpay_invoice_type"]'),
			"individual",
			$("#ezPayInvoiceIndividual")
		);
		fieldDisplay(
			$('select[name="_ezpay_invoice_type"]'),
			"individual",
			$("#ezPayInvoiceCarrier")
		);
		fieldDisplay(
			$('select[name="_ezpay_invoice_type"]'),
			"company",
			$("#ezPayInvoiceCompanyName")
		);
		fieldDisplay(
			$('select[name="_ezpay_invoice_type"]'),
			"company",
			$("#ezPayInvoiceTaxId")
		);
		fieldDisplay(
			$('select[name="_ezpay_invoice_type"]'),
			"donate",
			$("#ezPayInvoiceDonate")
		);
		fieldDisplay(
			$('select[name="_ezpay_invoice_individual"]'),
			["自然人憑證", "手機條碼"],
			$("#ezPayInvoiceCarrier")
		);

		// 觸發變更發票資料按鈕
		$('#ezpay_invoice select,#ezpay_invoice input[type="text"]').on(
			"click",
			function () {
				$("#btnUpdateInvoiceDataEzPay").prop("disabled", false);
				$(".btnGenerateInvoiceEzPay").prop("disabled", true);
			}
		);

		$('select[name="_ezpay_invoice_type"]').on("change", function () {
			$("#ezpay_invoice input").val("");
		});
	});
});
