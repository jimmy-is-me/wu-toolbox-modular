<?php

namespace WPBrewer\ESun\Payment\Utils;
/**
 * InstallmentableTrait trait file
 *
 * @package chailease
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Installmentable trait
 */
trait InstallmentTrait {

	/**
	 * Number of installments
	 *
	 * @var int
	 */
	public $installs;

	/**
	 * Minimum order amount to use installment payment
	 *
	 * @var integer
	 */
	public $min_amount = 0;

	/**
	 * Setup the installment number and add hook
	 *
	 * @param int $installs The number of installments.
	 * @param int $min_amount Minimum amount to use this installment payment.
	 * @return void
	 */
	public function init_installment( $installs, $min_amount ) {
		$this->set_installs( $installs );
		$this->min_amount = $min_amount;
	}
	/**
	 * Set payment installs
	 *
	 * @param int $installs The number of installments.
	 * @return void
	 */
	private function set_installs( $installs ) {
		$this->installs = $installs;
	}

	/**
	 * Set minimum amount
	 *
	 * @param int $amount The minimum amount to use this installment payment.
	 * @return void
	 */
	public function set_min_amount( $amount ) {
		$this->min_amount = $amount;
	}

	/**
	 * Check If The Gateway Is Available For Use.
	 *
	 * @return bool
	 */
	public function is_available() {
		$is_available = ( 'yes' === $this->enabled );

		if ( WC()->cart && 0 < $this->get_order_total() && 0 < $this->min_amount && $this->min_amount > $this->get_order_total() ) {
			$is_available = false;
		}

		return $is_available;
	}

}