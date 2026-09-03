<?php
/**
 * Cvs class file
 *
 * @package payuni
 */

namespace WPBrewer\Payuni\Payment\Gateways;

use WPBrewer\Payuni\Payment\Utils\OrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * CVS class for CVS payment
 */
class Cvs extends GatewayBase {

	const GATEWAY_ID = 'payuni-upp-cvs';

	/**
	 * The expire days for ATM virtual account.
	 *
	 * @var string
	 */
	public $expire_days;

	/**
	 * Constructor
	 */
	public function __construct() {

		parent::__construct();

		$this->id                 = self::GATEWAY_ID;
		$this->method_title       = __( 'PAYUNi CVS Payment', 'wpbr-payuni-payment' );
		$this->method_description = __( 'PAYUNi CVS Payment', 'wpbr-payuni-payment' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title                      = $this->get_option( 'title' );
		$this->description                = $this->get_option( 'description' );
		$this->expire_days                = $this->get_option( 'expire_days', 7 );
		$this->incomplete_payment_message = $this->get_option( 'incomplete_payment_message' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
		add_filter( 'payuni_upp_transaction_args_' . $this->id, array( $this, 'payuni_payment_cvs_transaction_arrgs' ), 10, 2 );
	}

	/**
	 * Setup form fields for payment
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = include WUTM_PAYUNI_PLUGIN_DIR . 'includes/settings/CvsSetting.php';
	}

	/**
	 * Set cvs payment transaction args
	 *
	 * @param array    $args  The transaction args.
	 * @param WC_Order $order The order object.
	 *
	 * @return array
	 */
	public function payuni_payment_cvs_transaction_arrgs( $args, $order ) {

		return array_merge(
			$args,
			array(
				'ExpireDate' => gmdate( 'Y-m-d', strtotime( '+' . $this->expire_days . ' days' ) ),
				'CVS'        => '1',
			)
		);
	}

	/**
	 * The order meta for the payment method.
	 */
	public static function get_payment_order_metas() {
		$order_metas =
		array(
			OrderMeta::CVS_PAY_NO      => _x( 'Pay No', 'CVS', 'wpbr-payuni-payment' ),
			OrderMeta::CVS_STORE       => __( 'CVS Store', 'wpbr-payuni-payment' ),
			OrderMeta::CVS_EXPIRE_DATE => __( 'Expire Date', 'wpbr-payuni-payment' ),
			OrderMeta::CVS_PAY_TIME    => __( 'Pay Time', 'wpbr-payuni-payment' ),
		);

		return $order_metas;
	}
}
