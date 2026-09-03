<?php
/**
 * WPBR_LINEPay_Const class file
 *
 * @package linepay_tw
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LINEPay Gateway Constant
 *
 * Define the constant used in LINE Pay.
 *
 * @version 1.0.0
 */
final class WPBR_LINEPay_Const {

	// data.
	const ID           = 'linepay-tw';
	const TITLE        = 'LINE Pay Gateway';
	const DESC         = 'Payments are received through the LINE Pay gateway.';
	const METHOD_TITLE = 'LINE Pay';
	const METHOD_DESC  = 'Payments are received through the LINE Pay gateway.';

	// uri.
	const URI_REQUEST = '/v3/payments/request';
	const URI_CONFIRM = '/v3/payments/{transaction_id}/confirm';
	const URI_DETAILS = '/v3/payments?transactionId={transaction_id}';
	const URI_CHECK   = '/v3/payments/requests/{transaction_id}/check';
	// const URI_DETAILS          = '/v3/payments?orderId={order_id}';
	const URI_REFUND = '/v3/payments/{transaction_id}/refund';

	const URI_CALLBACK_HANDLER = '/wc-api/linepay_payment';

	// host.
	const HOST_SANDBOX = 'https://sandbox-api-pay.line.me';
	const HOST_REAL    = 'https://api-pay.line.me';

	// request type.
	const REQUEST_TYPE_REQUEST = 'request';
	const REQUEST_TYPE_CONFIRM = 'confirm';
	const REQUEST_TYPE_DETAILS = 'details';
	const REQUEST_TYPE_CHECK   = 'check';
	const REQUEST_TYPE_CANCEL  = 'cancel';
	const REQUEST_TYPE_REFUND  = 'refund';

	// environment.
	const ENV_SANDBOX = 'sandbox';
	const ENV_REAL    = 'real';

	// payment status.
	const PAYMENT_STATUS_RESERVED  = 'reserved';
	const PAYMENT_STATUS_AUTHED    = 'authed'; // 已經 reserved 但是尚未 confirmed.
	const PAYMENT_STATUS_CONFIRMED = 'confirmed'; // captured.
	const PAYMENT_STATUS_CANCELLED = 'cancelled';
	const PAYMENT_STATUS_REFUNDED  = 'refunded';
	const PAYMENT_STATUS_FAILED    = 'failed';

	// payment type.
	const PAYMENT_TYPE_NORMAL      = 'NORMAL';
	const PAYMENT_TYPE_PREAPPROVED = 'PREAPPROVED';

	// log template.
	const LOG_TEMPLATE_REFUND_FAILURE_AFTER_CONFIRM          = '[order_id: %s][requested confirm amount: %s][confirmed amount: %s] - %s';
	const LOG_TEMPLATE_CONFIRM_FAILURE_MISMATCH_ORDER_AMOUNT = '[requested confirm amount: %s][reserved amount: %s] - unvalid amount';
	const LOG_TEMPLATE_PAYMENT_CANCEL                        = '[order_id: %s][reserved_transaction_id: %s] - payment cancel';
	const LOG_TEMPLATE_HANDLE_CALLBANK_NOT_FOUND_ORDER_ID    = '[order_id: %s] - %s';
	const LOG_TEMPLATE_HANDLE_CALLBANK_NOT_FOUND_REQUREST    = '[order_id: %s][payment_status: %s][req_type: %s] - %s';
	const LOG_TEMPLATE_RESERVE_UNVALID_CURRENCY_SCALE        = '[order_id: %s][std_amount: %s][base currency: %s][base currency scale: %d][amount precision: %d] - unvalied currency scale';

	const AUTH_ALGRO             = 'sha256';
	const REQUEST_TIME_FORMAT    = 'YmdHis';
	const CONFIRM_URLTYPE_CLIENT = 'CLIENT';
}
