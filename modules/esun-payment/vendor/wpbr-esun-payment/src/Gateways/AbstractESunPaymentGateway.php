<?php

namespace WPBrewer\ESun\Payment\Gateways;
/**
 * Abstract_ESUN_Payment_Gateway class file
 *
 * @package wpbr-esun-payment
 */

defined( 'ABSPATH' ) || exit;

/**
 * Abstract_ESUN_Payment_Gateway main class for handling all checkout related process.
 */
abstract class AbstractESunPaymentGateway extends \WC_Payment_Gateway {

	/**
	 * MID
	 *
	 * @var string
	 */
	protected $MID;

	/**
	 * MAC
	 *
	 * @var string
	 */
	protected $MAC;


	protected $IC;

	/**
	 * Test mode
	 *
	 * @var boolean
	 */
	protected $testmode;

	/**
	 * API url
	 *
	 * @var string
	 */
	protected $api_url;

	public $order_result_url;

	/**
	 * Constructor
	 */
	public function __construct() {

		$this->icon              = $this->get_icon();
		$this->has_fields        = false;
		$this->order_button_text = __( 'Proceed to ESUN', 'wpbr-esun-payment' );
		$this->supports          = array(
			'products',
		);

		$this->order_result_url   = WC()->api_request_url( 'esun_credit_payment' );

	}

	/**
	 * Payment method settings
	 *
	 * @return void
	 */
	public function admin_options() {
		echo '<h3>' . esc_html( $this->get_method_title() ) . '</h3>';
		echo '<p>' . sprintf(
			/* translators: 1: Payment method title 2: ESUN URL */
			esc_html__( '%1$s is a payment gateway provided by %2$s', 'wpbr-esun-payment' ),
			esc_html( $this->get_method_title() ),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( 'https://www.esun.com.tw/' ),
				esc_html__( 'E.SUN Bank', 'wpbr-esun-payment' )
			)
		) . '</p>';
		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}


	/**
	 * Process payment
	 *
	 * @param string $order_id The order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Payment gateway icon output
	 *
	 * @return string
	 */
	public function get_icon() {
		$icon_html = '';
		return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
	}

	/**
	 * Return payment gateway method title
	 *
	 * @return string
	 */
	public function get_method_title() {
		return $this->method_title;
	}

	/**
	 * Return PayNow payment url
	 *
	 * @return string
	 */
	public function get_api_url() {
		return $this->api_url;
	}

}
