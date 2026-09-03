<?php
/**
 * TraitCreditInstallment trait file
 *
 * @package payuni
 */

namespace WPBrewer\Payuni\Payment\Gateways;

use WPBrewer\Payuni\Payment\Api\PaymentRequest;
use WPBrewer\Payuni\Payment\Utils\OrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Installmentable trait
 */
trait TraitCreditInstallment {

	/**
	 * Number of installments
	 *
	 * @var int
	 */
	public $installs;

	/**
	 * Setup the installment number and add hook
	 *
	 * @param  int $installs   The number of installments.
	 * @param  int $min_amount Minimum amount to use this installment payment.
	 * @return void
	 */
	public function init_installment( $installs, $min_amount ) {

		$this->supports = array(
			'products',
			'refunds',
		);

		$this->set_installs( $installs );
		$this->min_amount = $min_amount;
	}
	/**
	 * Set payment installs
	 *
	 * @param  int $installs The number of installments.
	 * @return void
	 */
	private function set_installs( $installs ) {
		$this->installs = $installs;
	}

	/**
	 * Set minimum amount
	 *
	 * @param  int $amount The minimum amount to use this installment payment.
	 * @return void
	 */
	public function set_min_amount( $amount ) {
		$this->min_amount = $amount;
	}

	/**
	 * Init the form fields
	 *
	 * @return void
	 */
	public function init_from_fields() {
		$this->form_fields = include WUTM_PAYUNI_PLUGIN_DIR . 'includes/settings/CreditInstallmentSetting.php';
		/* translators: %s: number of installments */
		$this->form_fields['title']['default'] = sprintf( __( 'PAYUNi Installment Payment (%s Installments)', 'wpbr-payuni-payment' ), $this->installs );
	}

	/**
	 * Check If The Gateway Is Available For Use.
	 *
	 * @return bool
	 */
	public function is_available() {
		$is_available = ( 'yes' === $this->enabled );

		if ( WC()->cart && 0 < $this->get_order_total() && 0 < $this->max_amount && $this->max_amount < $this->get_order_total() ) {
			$is_available = false;
		}

		if ( WC()->cart && 0 < $this->get_order_total() && 0 < $this->min_amount && $this->min_amount > $this->get_order_total() ) {
			$is_available = false;
		}

		return $is_available;
	}

	/**
	 * Set the transaction args for installment payment
	 *
	 * @param array     $args  The transaction args.
	 * @param \WC_Order $order The order object.
	 *
	 * @return array
	 */
	public function payuni_payment_installment_transaction_arrgs( $args, $order ) {
		return array_merge(
			$args,
			array(
				'CreditInst' => $this->installs,
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
			OrderMeta::CREDIT_CARD_4NO  => __( 'Card Last 4 No', 'wpbr-payuni-payment' ),
			OrderMeta::CREDIT_INSTALL   => __( 'Installments', 'wpbr-payuni-payment' ),
			OrderMeta::CREDIT_FIRST_AMT => __( 'First Amount', 'wpbr-payuni-payment' ),
			OrderMeta::CREDIT_EACH_AMT  => __( 'Each Amount', 'wpbr-payuni-payment' ),
			OrderMeta::CREDIT_AUTH_DAY  => __( 'Auth Date', 'wpbr-payuni-payment' ),
			OrderMeta::CREDIT_AUTH_TIME => __( 'Auth Time', 'wpbr-payuni-payment' ),
		);

		return $order_metas;
	}
}
