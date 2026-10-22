<?php
/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @since             1.0.0
 * @package           payuni
 *
 * @wordpress-plugin
 * Plugin Name:       PAYUNi Shipping for WooCommerce
 * Description:       PAYUNi Shipping for WooCommerce
 * Version:           1.1.1
 * Author:            Wu Digital Technology
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wu-payuni-shipping
 * Domain Path:       /languages
 */

namespace WuDigital\Payuni\Shipping;

use WuDigital\Payuni\Shipping\Admin\OrderEdit;
use WuDigital\Payuni\Shipping\Admin\OrderListTable;
use WuDigital\Payuni\Shipping\Api\ShippingRequest;
use WuDigital\Payuni\Shipping\Api\ShippingResponse;
use WuDigital\Payuni\Shipping\Settings\SettingsTab;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'PAYUNI_SHIPPING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PAYUNI_SHIPPING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PAYUNI_SHIPPING_BASENAME', plugin_basename( __FILE__ ) );
define( 'PAYUNI_SHIPPING_TEMPLATE_DIR', plugin_dir_path( __FILE__ ) . '/templates/' );
define( 'PAYUNI_SHIPPING_VERSION', '1.1.1' );

require_once PAYUNI_SHIPPING_PLUGIN_DIR . "vendor/autoload.php";

/**
 * Add PAYUNi shipping methods.
 *
 * @param array $methods Payment methods.
 * @return array
 */
function payuni_add_shipping_methods( $methods ) {
	$methods['payuni_shipping_711_c2c_normal']   = 'WuDigital\Payuni\Shipping\Providers\SevenEleven\C2CNormal';
	$methods['payuni_shipping_711_c2c_frozen']    = 'WuDigital\Payuni\Shipping\Providers\SevenEleven\C2CFrozen';
	$methods['payuni_shipping_tcat_normal']      = 'WuDigital\Payuni\Shipping\Providers\TCat\HDNormal';
	$methods['payuni_shipping_tcat_frozen']      = 'WuDigital\Payuni\Shipping\Providers\TCat\HDFrozen';
	$methods['payuni_shipping_tcat_refrigerated'] = 'WuDigital\Payuni\Shipping\Providers\TCat\HDRefrigerated';
	return $methods;
}

/**
 * Display warning when WooCommerce is not installed and activated.
 *
 * @return void
 */
function payuni_shipping_needs_woocommerce() {

	echo '<div id="message" class="error">';
	echo '  <p>' . esc_html__( 'PAYUNi Shipping needs WooCommerce, please intall and activate WooCommerce first!', 'wu-payuni-shipping' ) . '</p>';
	echo '</div>';

}

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * PAYUNi shipping settings.
 *
 * @return Settings_Tab
 */
function payuni_add_shipping_settings( $settings ) {
	$settings[] = new SettingsTab();
	return $settings;
}

function run_payuni_shipping() {

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\payuni_shipping_needs_woocommerce' );
		return;
	}

	add_filter( 'woocommerce_shipping_methods', __NAMESPACE__ . '\\payuni_add_shipping_methods' );
	add_filter( 'woocommerce_get_settings_pages', __NAMESPACE__ . '\\payuni_add_shipping_settings', 15 );

	PayuniShipping::init();
	ShippingRequest::init();
	ShippingResponse::init();
	OrderEdit::init();
	OrderListTable::init();

}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\run_payuni_shipping' );

