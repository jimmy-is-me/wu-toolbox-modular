<?php

namespace WuDigital\Payuni\Shipping\Providers\SevenEleven;

defined( 'ABSPATH' ) || exit;

/**
 * PAYUNi Shipping C2C 7-11 class file.
 *
 * @package payuni
 */

use WuDigital\Payuni\Shipping\Providers\ShippingBase;
use WuDigital\Payuni\Shipping\Utils\GoodsType;
use WuDigital\Payuni\Shipping\Utils\LgsType;
use WuDigital\Payuni\Shipping\Utils\ShipType;

/**
 * C2CNormal class.
 */
class C2CNormal extends ShippingBase {

	/**
	 * Constructor
	 *
	 * @param integer $instance_id The instance id.
	 */
	public function __construct( $instance_id = 0 ) {

		parent::__construct();

		$this->instance_id        = absint( $instance_id );
		$this->id                 = 'payuni_shipping_711_c2c_normal';
		$this->method_title       = __( 'PAYUNi Shipping 7-11 C2C Normal', 'wu-payuni-shipping' );
		$this->method_description = __( 'PAYUNi Shipping 7-11 C2C Normal', 'wu-payuni-shipping' );
		
		$this->goods_type		  = GoodsType::NORMAL; //1: 常溫, 2:冷凍
		$this->lgs_type		      = LgsType::C2C;
		$this->ship_type          = ShipType::SEVEN;

		$this->init();

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

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

		$max_amount   = 20000;
		$is_available = $this->is_enabled();

		$total = WC()->cart->get_cart_contents_total();
		if ( $total >= $max_amount ) {
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

		$this->instance_form_fields = include PAYUNI_SHIPPING_PLUGIN_DIR . 'src/Settings/SevenEleven/C2CNormalFields.php';

		$this->title                    = $this->get_option( 'title' );
		$this->cost                     = $this->get_option( 'cost', 0 );
		$this->free_shipping_requires   = $this->get_option( 'free_shipping_requires' );
		$this->free_shipping_min_amount = $this->get_option( 'free_shipping_min_amount', 0 );
	}
}
