<?php

namespace WuDigital\Payuni\Shipping\Providers\TCat;

defined( 'ABSPATH' ) || exit;

/**
 * PAYUNi Shipping TCAT Refrigerated class file.
 *
 * @package wu-payuni-shipping
 */

use WuDigital\Payuni\Shipping\Providers\ShippingBase;
use WuDigital\Payuni\Shipping\Utils\ShipType;
use WuDigital\Payuni\Shipping\Utils\TCatHDTrait;

/**
 * HDRefrigerated class.
 */
class HDRefrigerated extends ShippingBase {

	use TCatHDTrait;

	/**
	 * Constructor
	 *
	 * @param integer $instance_id The instance id.
	 */
	public function __construct( $instance_id = 0 ) {

		parent::__construct();

		$this->instance_id        = absint( $instance_id );
		$this->id                 = 'payuni_shipping_tcat_refrigerated';
		$this->method_title       = __( 'PAYUNi Shipping Tcat Refrigerated ', 'wu-payuni-shipping' );
		$this->method_description = __( 'PAYUNi Shipping Tcat Refrigerated ', 'wu-payuni-shipping' );
		
        $this->goods_type         = 3;//1=常溫，2=冷凍，3=冷藏
        $this->lgs_type           = 'HOME';
		$this->ship_type 	      = ShipType::TCAT; // 2=黑貓.

		$this->init();

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Initialize shipping method
	 *
	 * @return void
	 */
	public function init() {
		
		$this->init_settings();
		$this->instance_form_fields = include PAYUNI_SHIPPING_PLUGIN_DIR . 'src/Settings/TCat/HDRefrigeratedFields.php';

		$this->title                    = $this->get_option( 'title' );
		$this->cost                     = $this->get_option( 'cost', 0 );
		$this->free_shipping_requires   = $this->get_option( 'free_shipping_requires' );
		$this->free_shipping_min_amount = $this->get_option( 'free_shipping_min_amount', 0 );
		$this->package_spec             = $this->get_option( 'package_spec', 1 );//default 1=60
	}

}
