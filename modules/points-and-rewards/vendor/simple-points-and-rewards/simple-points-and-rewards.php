<?php

/**
* Plugin Name: Simple Points and Rewards for WooCommerce
* Description: Add a powerful loyalty points and rewards program to your WooCommerce store. Reward purchases, referrals, and more.
* Plugin URI: https://relywp.com/plugins/simple-points-and-rewards/
* License: GPLv3 or later.
* License URI: https://www.gnu.org/licenses/gpl-3.0.html
* Version: 2.1.0
* Author: Elliot Sowersby, RelyWP
* Author URI: https://relywp.com/plugins/simple-points-and-rewards/
* Text Domain: simple-points-and-rewards
* Domain Path: /languages
* Requires Plugins: woocommerce
*
* Requires at least: 6.2
* WC requires at least: 3.7
* WC tested up to: 10.9
*
*/
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
// Define constants
define( 'SPAR_VERSION', '2.1.0' );
define( 'SPAR_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPAR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
/**
 * Register the plugin languages directory for bundled translation files.
 */
if ( !function_exists( 'spar_pro_load_plugin_textdomain' ) ) {
    function spar_pro_load_plugin_textdomain() {
        load_plugin_textdomain( 'simple-points-and-rewards', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

}
add_action( 'init', 'spar_pro_load_plugin_textdomain', 0 );
// Include vendor autoload if available
if ( file_exists( SPAR_PLUGIN_PATH . 'vendor/autoload.php' ) ) {
    require_once SPAR_PLUGIN_PATH . 'vendor/autoload.php';
}
if ( function_exists( 'spar_fs' ) ) {
    spar_fs()->set_basename( false, __FILE__ );
} else {
    if ( !function_exists( 'spar_fs' ) ) {
        // Create a helper function for easy SDK access.
        function spar_fs() {
            global $spar_fs;
            if ( !isset( $spar_fs ) ) {
                // Include Freemius SDK.
                // SDK is auto-loaded through composer
                $spar_fs = fs_dynamic_init( array(
                    'id'               => '19510',
                    'slug'             => 'simple-points-and-rewards',
                    'premium_slug'     => 'simple-points-and-rewards-pro',
                    'type'             => 'plugin',
                    'public_key'       => 'pk_46a2680f08e8e89ec0ab938e5409d',
                    'is_premium'       => false,
                    'premium_suffix'   => '(PRO)',
                    'has_addons'       => false,
                    'has_paid_plans'   => true,
                    'is_org_compliant' => true,
                    'trial'            => array(
                        'days'               => 7,
                        'is_require_payment' => true,
                    ),
                    'menu'             => array(
                        'slug'       => 'spar-settings',
                        'first-path' => 'admin.php?page=spar-settings',
                        'support'    => false,
                    ),
                    'is_live'          => true,
                ) );
            }
            return $spar_fs;
        }

        // Init Freemius.
        spar_fs();
        // Signal that SDK was initiated.
        do_action( 'spar_fs_loaded' );
    }
    // Include core files
    require_once SPAR_PLUGIN_PATH . 'includes/functions-core.php';
    require_once SPAR_PLUGIN_PATH . 'includes/functions-scheduler.php';
    require_once SPAR_PLUGIN_PATH . 'includes/functions-shortcodes.php';
    require_once SPAR_PLUGIN_PATH . 'includes/functions-myaccount.php';
    require_once SPAR_PLUGIN_PATH . 'includes/functions-admin.php';
    require_once SPAR_PLUGIN_PATH . 'includes/functions-points.php';
    require_once SPAR_PLUGIN_PATH . 'includes/functions-gift-redeem.php';
    require_once SPAR_PLUGIN_PATH . 'includes/functions-redeem.php';
    require_once SPAR_PLUGIN_PATH . 'includes/functions-redeem-action.php';
    // Include referral system files
    require_once SPAR_PLUGIN_PATH . 'includes/links/functions-referral-links.php';
    require_once SPAR_PLUGIN_PATH . 'includes/links/functions-referral-tracking.php';
    require_once SPAR_PLUGIN_PATH . 'includes/links/functions-referral-offers.php';
    // Include frontend files
    require_once SPAR_PLUGIN_PATH . 'includes/frontend/cart-checkout.php';
    // Include integrations
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    // FunnelKit Side Cart Integration
    $funnelkit_active = is_plugin_active( 'cart-for-woocommerce/plugin.php' );
    $funnelkit_active = apply_filters( 'spar_funnelkit_side_cart_active', $funnelkit_active );
    if ( $funnelkit_active ) {
        require_once SPAR_PLUGIN_PATH . 'includes/integrations/funnelkit/funnelkit-side-cart.php';
    }
    // WooCommerce Subscriptions Integration (all hooks no-op unless WCS is active)
    require_once SPAR_PLUGIN_PATH . 'includes/integrations/subscriptions.php';
    // Include settings utilities
    require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/settings-utilities.php';
    // Activation hook
    register_activation_hook( __FILE__, 'spar_activate_plugin' );
    register_deactivation_hook( __FILE__, 'spar_unschedule_all_plugin_events' );
    register_activation_hook( __FILE__, 'spar_create_points_log_table' );
    register_activation_hook( __FILE__, 'spar_create_referral_clicks_table' );
    register_activation_hook( __FILE__, 'spar_create_spins_log_table' );
    /**
     * Plugin activation tasks
     */
    function spar_activate_plugin() {
        spar_register_account_endpoint();
        if ( function_exists( 'spar_maybe_seed_example_content' ) ) {
            spar_maybe_seed_example_content();
        }
        flush_rewrite_rules();
    }

    /**
     * Clear every scheduled event the plugin owns, from both Action Scheduler
     * and WP-Cron, on deactivation.
     */
    function spar_unschedule_all_plugin_events() {
        $hooks = array(
            'spar_delayed_points_cron',
            'spar_delayed_points_process_batch',
            'spar_points_inactivity_expiry_cron',
            'spar_points_expiry_process_batch',
            'spar_daily_birthday_award',
            'spar_guest_reminder_cron',
            'spar_guest_reminder_process_batch',
            'spar_cleanup_expired_coupons',
            'spar_cleanup_expired_coupons_batch',
            'spar_cleanup_expired_rewards',
            'spar_cleanup_expired_reward_vouchers',
            'spar_cleanup_expired_reward_vouchers_batch'
        );
        foreach ( $hooks as $hook ) {
            if ( function_exists( 'spar_unschedule_action' ) ) {
                spar_unschedule_action( $hook, true );
            } else {
                wp_clear_scheduled_hook( $hook );
            }
        }
        delete_option( 'spar_scheduler_jobs' );
    }

    // Admin assets are enqueued in includes/functions-admin.php
}
/**
 * Compatible with WooCommerce HP
 *
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );