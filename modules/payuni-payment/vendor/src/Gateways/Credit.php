<?php
/**
 * Credit class file
 *
 * @package payuni
 */

namespace WPBrewer\Payuni\Payment\Gateways;

use WPBrewer\Payuni\Payment\Api\PaymentRequest;
use WPBrewer\Payuni\Payment\Utils\OrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Credit class for Credit Card payment
 */
class Credit extends GatewayBase {

	const GATEWAY_ID = 'payuni-upp-credit';

	/**
	 * Constructor
	 */
	public function __construct() {

		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi Credit Card Payment', 'wpbr-payuni-payment' );
		$this->method_description = __( 'PAYUNi Credit Card Payment', 'wpbr-payuni-payment' );
		$this->supports           = array(
			'products',
			'refunds',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title                      = $this->get_option( 'title' );
		$this->description                = $this->get_option( 'description' );
		$this->incomplete_payment_message = $this->get_option( 'incomplete_payment_message' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_filter( 'payuni_upp_transaction_args_' . $this->id, array( $this, 'payuni_payment_credit_transaction_arrgs' ), 10, 2 );
	}


	/**
	 * Setup form fields for payment
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = include WUTM_PAYUNI_PLUGIN_DIR . 'includes/settings/CreditSetting.php';
	}

	/**
	 * Add payment parameter for credit card payment.
	 *
	 * @param array    $args  The payment parameters.
	 * @param WC_ORDER $order The order object.
	 *
	 * @return array
	 */
	public function payuni_payment_credit_transaction_arrgs( $args, $order ) {
		return array_merge(
			$args,
			array(
				'Credit' => '1',
			)
		);
	}

	/**
	 * Process the refund and return the result.
	 *
	 * This method is called only when the administrator processes it.
	 * When the administrator requests a refund, the process_refund() method is called through WC_AJAX::refund_line_items().
	 *
	 * @see woocommerce::action - woocommerce_delete_shop_order_transients
	 *
	 * @param int    $order_id The order id.
	 * @param float  $amount   The ammount to be refund.
	 * @param string $reason   The reason why the refund is requested.
	 *
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$request = new PaymentRequest();
		return $request->refund( $order_id, $amount, $reason );
	}

	/**
	 * The order meta for the payment method.
	 */
	public static function get_payment_order_metas() {
		$order_metas =
		array(
			OrderMeta::CREDIT_AUTH_TYPE => __( 'Auth Type', 'wpbr-payuni-payment' ),
			OrderMeta::CREDIT_AUTH_DAY  => __( 'Auth Date', 'wpbr-payuni-payment' ),
			OrderMeta::CREDIT_AUTH_TIME => __( 'Auth Time', 'wpbr-payuni-payment' ),
		);

		return $order_metas;
	}
}
