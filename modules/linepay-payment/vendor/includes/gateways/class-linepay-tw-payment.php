<?php
/**
 * LINEPay_TW_Payment class file
 *
 * @package linepay_tw
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LINEPay Payment Gateway
 *
 * @class LINEPay_TW_Payment
 * @extends WC_Payment_Gateway
 *
 * @version 1.0.0
 */
class LINEPay_TW_Payment extends WC_Payment_Gateway {

	/**
	 * The payment type.
	 *
	 * @var string
	 */
	public $payment_type;

	/**
	 * The supported currencies.
	 * @var mixed
	 */
	public $supported_currencies;

	/**
	 * The constructor.
	 */
	public function __construct() {

		$this->id                 = WPBR_LINEPay_Const::ID;
		$this->icon               = $this->get_icon();
		$this->title              = $this->get_option( 'title' );
		$this->description        = $this->get_option( 'description' );
		$this->has_fields         = false;
		$this->order_button_text  = __( 'Pay with LINE Pay', 'wpbr-linepay-tw' );
		$this->method_title       = __( 'LINE Pay - General', 'wpbr-linepay-tw' );
		$this->method_description = __( 'Pay with LINE Pay', 'wpbr-linepay-tw' );

		$this->payment_type   = 'NORMAL';

		// Support refund function.
		$this->supports = array(
			'products',
			'refunds',
		);

		/**
		 * Allow to filter the support currency.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $array The support currency.
		 */
		$this->supported_currencies = apply_filters( 'linepay_tw_support_currencies', array( 'TWD' ) );

		// Define form field to show in admin setting.
		$this->init_form_fields();
		$this->init_settings();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'thankyou_order_on_hold_message' ), 10, 2 );
		add_action( 'woocommerce_order_details_before_order_table', array( $this, 'display_on_hold_message_on_order_details' ) );
	}

	/**
	 * Payment gateway icon output
	 *
	 * @return string
	 */
	public function get_icon() {

		$icon_html = '';
		if ( get_option( 'linepay_tw_display_logo_enabled' ) === 'yes' ) {
			$icon_html .= sprintf(
				'<img src="%s" alt="%s" />',
				esc_url( WUTM_LINEPAY_PLUGIN_URL . 'assets/images/linepay-logo.png' ),
				esc_attr__( 'LINE Pay Taiwan', 'wpbr-linepay-tw' )
			);
		}
		/**
		 * Allow to filter the payment gateway icon.
		 *
		 * @since 1.0.0
		 *
		 * @param string $icon_html The payment gateway icon HTML.
		 * @param string $this->id  The payment gateway id.
		 */
		return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
	}

	/**
	 * Form fields Initialize the information.
	 *
	 * @see WC_Settings_API::init_form_fields()
	 * @see WC_Gateway_LINEPay_Settings->get_form_fields()
	 */
	public function init_form_fields() {
		$this->form_fields = include WUTM_LINEPAY_PLUGIN_DIR . 'includes/settings/settings-linepay-tw-payment.php';
	}

	/**
	 * Process payments and return results.
	 * To pay with LINE Pay, request-api is first called.
	 * return the success and redirect in an array. e.g:
	 * return array(
	 *    'result'   => 'success',
	 *    'redirect' => $this->get_return_url( $order )
	 * );
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {

		WC()->cart->empty_cart();

		$linepay_request = new LINEPay_TW_Request( $this );
		return $linepay_request->request( $order_id );
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
	 * @param float  $amount The ammount to be refund.
	 * @param string $reason The reason why the refund is requested.
	 *
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$linepay_request = new LINEPay_TW_Request( $this );
		return $linepay_request->refund( $order_id, $amount, $reason );
	}

	/**
	 * Check if the gateway is available for use.
	 *
	 * @return bool
	 */
	public function is_available() {
		$is_available = ( 'yes' === $this->enabled );

		// Channel information by usage environment.
		$channel_info = LINEPay_TW::get_channel_info();
		if ( empty( $channel_info['channel_id'] ) || empty( $channel_info['channel_secret'] ) ) {
			$is_available = false;
		}

		// Accepted Currency.
		$cur_currency = get_woocommerce_currency();
		if ( ! in_array( $cur_currency, $this->supported_currencies, true ) ) {
			$is_available = false;
		}

		if ( WC()->cart && 0 < $this->get_order_total() && 0 < $this->max_amount && $this->max_amount < $this->get_order_total() ) {
			$is_available = false;
		}

		return $is_available;
	}

	/**
	 * Display message on thank you page when order status is on-hold or pending.
	 *
	 * @param string   $text Text display on thank you page.
	 * @param WC_Order $order The order object.
	 *
	 * @return string
	 */
	public function thankyou_order_on_hold_message( $text, $order ) {

		if ( $order ) {
			if ( $order->get_payment_method() !== $this->id ) {
				return $text;
			}

			if ( $order->get_status() === 'on-hold' ) {
				$text = esc_html__( 'We have received your order, but the payment status need to be confirmed. Please contact the support.', 'wpbr-linepay-tw' );
			}

			if ( $order->get_status() === 'pending' ) {
				$text = esc_html__( 'We have received your order, but the order is awaiting payment. Please pay again.', 'wpbr-linepay-tw' );
			}
		}

		return $text;
	}

	/**
	 * Display the message on the order details page when order status is on-hold or pending.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function display_on_hold_message_on_order_details( $order ) {

		if ( is_checkout() ) {
			return;
		}
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		if ( $order->get_status() === 'on-hold' ) {
			echo esc_html__( 'We have received your order, but the payment status need to be confirmed. Please contact the support.', 'wpbr-linepay-tw' );
		}

		if ( $order->get_status() === 'pending' ) {
			echo esc_html__( 'We have received your order, but the order is awaiting payment. Please pay again.', 'wpbr-linepay-tw' ) . '</div>';
		}
	}
}
