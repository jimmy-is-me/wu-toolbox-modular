<?php
/**
 * Aftee class file
 *
 * @package payuni
 */

namespace WPBrewer\Payuni\Payment\Gateways;

use WPBrewer\Payuni\Payment\Utils\OrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Aftee class for AFTEE payment
 */
class Aftee extends GatewayBase {

	const GATEWAY_ID = 'payuni-upp-aftee';

	/**
	 *  Order payment meta
	 *
	 * @var array
	 */
	public static $order_metas;

	/**
	 * Constructor
	 */
	public function __construct() {

		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi AFTEE Payment', 'wpbr-payuni-payment' );
		$this->method_description = __( 'PAYUNi AFTEE Payment', 'wpbr-payuni-payment' );
		$this->supports           = array(
			'products',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title                      = $this->get_option( 'title' );
		$this->description                = $this->get_option( 'description' );
		$this->min_amount                 = $this->get_option( 'min_amount' );
		$this->incomplete_payment_message = $this->get_option( 'incomplete_payment_message' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_filter( 'payuni_upp_transaction_args_' . $this->id, array( $this, 'payuni_payment_aftee_transaction_arrgs' ), 10, 2 );
	}

	/**
	 * Setup form fields for payment
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = include WUTM_PAYUNI_PLUGIN_DIR . 'includes/settings/AfteeSetting.php';
	}

	/**
	 * Set transaction args for AFTEE payment
	 *
	 * @param array    $args  transaction args.
	 * @param WC_Order $order order object.
	 *
	 * @return array
	 */
	public function payuni_payment_aftee_transaction_arrgs( $args, $order ) {
		return array_merge(
			$args,
			array(
				'Aftee' => '1',
			)
		);
	}

	/**
	 * The order meta for the payment method.
	 */
	public static function get_payment_order_metas() {
		$order_metas =
		array(
			OrderMeta::AFTEE_PAY_NO   => _x( 'Pay No', 'AFTEE', 'wpbr-payuni-payment' ),
			OrderMeta::AFTEE_PAY_TIME => __( 'Pay Time', 'wpbr-payuni-payment' ),
		);

		return $order_metas;
	}
}
