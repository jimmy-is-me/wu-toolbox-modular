<?php
/**
 *
 * @since             1.0.0
 * @package           wpbr-esun-payment
 *
 * @wordpress-plugin
 * Plugin Name:       ESUN Payment for WooCommerce
 * Plugin URI:        https://wpbrewer.com/product/wpbr-esun-payment/
 * Description:       ESUN Payment for WooCommerce
 * Version:           2.0.0
 * Author:            WPBrewer
 * Author URI:        https://wpbrewer.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wpbr-esun-payment
 * Domain Path:       /languages
 */

use WPBrewer\ESun\Payment\ESunPayment;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {   
	die;
}

define( 'ESUN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ESUN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ESUN_BASENAME', plugin_basename( __FILE__ ) );
define( 'ESUN_INCLUDES_DIR', plugin_dir_path( __FILE__ ) . '/includes/' );
define( 'WPBR_ESUN_PAYMENT_VERSION', '2.0.0' );

// autoload
require_once ESUN_PLUGIN_DIR . 'vendor/autoload.php';

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

function esun_payment_needs_woocommerce() {

	echo '<div id="message" class="error">';
	echo '  <p>'.__('ESUN Payment needs WooCommerce, please intall and activate WooCommerce first!', 'wpbr-esun-payment').'</p>';
	echo '</div>';

}

function run_esun_payment() {

    if ( !in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
        require_once( ABSPATH . 'wp-admin/includes/plugin.php');
        if ( is_plugin_active('wpbr-esun-payment/wpbr-esun-payment.php') ) {
            deactivate_plugins( ESUN_BASENAME );
            add_action( 'admin_notices', 'esun_payment_needs_woocommerce');
            return;
        }
    }

	ESunPayment::init();

}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\run_esun_payment');
