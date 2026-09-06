<?php
/** Bundled Simple Points and Rewards 2.1.0 integration. */
defined('ABSPATH') || exit;

// The normal module loader runs after all installed plugins have loaded.
if (!class_exists('WooCommerce') || defined('SPAR_VERSION') || function_exists('spar_fs')) return;
require_once __DIR__ . '/vendor/simple-points-and-rewards/simple-points-and-rewards.php';

add_action('init', static function (): void {
    if (get_option('wutm_points_rewards_initialized', false)) return;
    spar_create_points_log_table();
    spar_create_referral_clicks_table();
    spar_create_spins_log_table();
    // Register the account endpoint before rebuilding its rewrite rules.
    spar_register_account_endpoint();
    flush_rewrite_rules(false);
    update_option('wutm_points_rewards_initialized', 1, false);
}, 20);

add_action('admin_menu', static function (): void {
    global $menu;
    foreach ($menu as &$entry) {
        if (($entry[2] ?? '') === 'spar-settings') $entry[0] = '紅利點數';
    }
    unset($entry);
}, 99);

add_action('update_option_wutm_module_points_and_rewards', static function ($old, $new): void {
    if ($old && !$new && function_exists('spar_unschedule_all_plugin_events')) {
        spar_unschedule_all_plugin_events();
    }
}, 10, 2);
