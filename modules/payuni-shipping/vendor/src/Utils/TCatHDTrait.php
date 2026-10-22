<?php
/**
 * Singleton trait to implements Singleton pattern in any classes where this trait is used.
 */

namespace WuDigital\Payuni\Shipping\Utils;

trait TCatHDTrait {

    
    private $min_amount = 30;
    private $max_amount = 20000;

	private $package_spec;
    
	/**
	 * Caculate shipping fee.
	 *
	 * @param array $package The shipping package.
	 * @return void
	 */
	public function calculate_shipping( $package = array() ) {
		$rate = array(
			'id'      => $this->get_rate_id(),
			'label'   => $this->title,
			'cost'    => $this->get_cost(),
			'taxes'   => true,
			'package' => $package,
		);
		$this->add_rate( $rate );
		do_action( 'woocommerce_' . $this->id . '_shipping_add_rate', $this, $rate );
	}

	/**
	 * Check if this shipping method available or not.
	 *
	 * @param array $package The shipping package array.
	 * @return boolean
	 */
	public function is_available( $package ) {

		$is_available = $this->is_enabled();

		$total = WC()->cart->get_cart_contents_total();

		if ( $total < $this->min_amount || $total > $this->max_amount ) {
			$is_available = false;
		}

		return apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', $is_available, $package, $this );
	}

	/**
	 * Initialize shipping method
	 *
	 * @return void
	 */
	public function init() {
		$this->init_settings();

		$this->instance_form_fields = include PAYUNI_SHIPPING_PLUGIN_DIR . 'src/Settings/TCat/HDFrozenFields.php';

		$this->title                    = $this->get_option( 'title' );
		$this->cost                     = $this->get_option( 'cost', 0 );
		$this->free_shipping_requires   = $this->get_option( 'free_shipping_requires' );
		$this->free_shipping_min_amount = $this->get_option( 'free_shipping_min_amount', 0 );
	}

}
