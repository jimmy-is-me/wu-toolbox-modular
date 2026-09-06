<?php

/**
 * Admin settings and user profile meta
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
// Include required files from new admin folder structure
require_once SPAR_PLUGIN_PATH . 'includes/admin/pages/settings-page.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/tables/reward-vouchers-table.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/tables/customer-points-table.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/tables/referrals-table.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/pages/referrals-page.php';
require_once SPAR_PLUGIN_PATH . 'includes/functions-coupons.php';
require_once SPAR_PLUGIN_PATH . 'includes/functions-email.php';
require_once SPAR_PLUGIN_PATH . 'includes/functions-levels.php';
// Include referral link functionality
require_once SPAR_PLUGIN_PATH . 'includes/links/functions-referral-links.php';
require_once SPAR_PLUGIN_PATH . 'includes/links/functions-referral-tracking.php';
// Load core admin pages
require_once SPAR_PLUGIN_PATH . 'includes/admin/pages/log-page.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/pages/spins-log-page.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/pages/reports-page.php';
require_once SPAR_PLUGIN_PATH . 'includes/admin/pages/customer-detail-page.php';
require_once SPAR_PLUGIN_PATH . 'includes/migration/migration.php';
// Lightweight analytics helpers for admin header snapshot
require_once SPAR_PLUGIN_PATH . 'includes/admin/functions-analytics.php';
// CSV export handlers (customer balances, points activity log)
require_once SPAR_PLUGIN_PATH . 'includes/admin/export.php';
add_action( 'admin_menu', 'spar_register_admin_menus' );
function spar_register_admin_menus() {
    $options = get_option( 'spar_options', spar_settings_default() );
    $rewards_label = $options['rewards_label'] ?? 'Rewards';
    /* translators: %s: Rewards label */
    $vouchers_title = sprintf( esc_html__( '%s Vouchers', 'simple-points-and-rewards' ), esc_html( $rewards_label ) );
    add_menu_page(
        'Simple Points & Rewards',
        'Points & Rewards',
        'manage_woocommerce',
        'spar-settings',
        'spar_settings_page',
        'dashicons-tickets',
        58
    );
    add_submenu_page(
        'spar-settings',
        'Settings',
        'Settings',
        'manage_woocommerce',
        'spar-settings',
        'spar_settings_page',
        0
    );
    add_submenu_page(
        'spar-settings',
        esc_html__( 'Customer Points', 'simple-points-and-rewards' ),
        esc_html__( 'Customer Points', 'simple-points-and-rewards' ),
        'manage_woocommerce',
        'spar-customer-points',
        'spar_customer_points_admin_page_callback',
        25
    );
    add_submenu_page(
        null,
        esc_html__( 'Customer Detail', 'simple-points-and-rewards' ),
        esc_html__( 'Customer Detail', 'simple-points-and-rewards' ),
        'manage_woocommerce',
        'spar-customer',
        'spar_customer_detail_admin_page_callback'
    );
    add_submenu_page(
        null,
        esc_html__( 'Bulk Points Update', 'simple-points-and-rewards' ),
        esc_html__( 'Bulk Points Update', 'simple-points-and-rewards' ),
        'manage_woocommerce',
        'spar-customer-points-bulk',
        'spar_customer_points_bulk_admin_page_callback'
    );
    if ( spar_fs()->can_use_premium_code__premium_only() && function_exists( 'spar_is_guest_tracking_enabled' ) && spar_is_guest_tracking_enabled() ) {
        add_submenu_page(
            'spar-settings',
            esc_html__( 'Guest Points', 'simple-points-and-rewards' ),
            esc_html__( 'Guest Points', 'simple-points-and-rewards' ),
            'manage_woocommerce',
            'spar-guest-points',
            'spar_guest_points_admin_page_callback',
            26
        );
    }
    // Visual submenu separator (non-clickable) between management pages and reporting
    add_submenu_page(
        'spar-settings',
        esc_html__( 'Separator', 'simple-points-and-rewards' ),
        '──────────',
        'manage_woocommerce',
        'spar-separator-1',
        '__return_null',
        35
    );
    add_submenu_page(
        'spar-settings',
        $vouchers_title . esc_html__( ' Coupons', 'simple-points-and-rewards' ),
        $vouchers_title,
        'manage_woocommerce',
        'spar-reward-vouchers',
        'spar_reward_vouchers_admin_page_callback',
        20
    );
    // Visual submenu separator (non-clickable) between management pages and reporting
    add_submenu_page(
        'spar-settings',
        esc_html__( 'Separator', 'simple-points-and-rewards' ),
        '──────────',
        'manage_woocommerce',
        'spar-separator-1',
        '__return_null',
        35
    );
    // Activity Log moved lower and retitled
    add_submenu_page(
        'spar-settings',
        esc_html__( 'Points Activity Log', 'simple-points-and-rewards' ),
        esc_html__( 'Points Activity Log', 'simple-points-and-rewards' ),
        'manage_woocommerce',
        'spar-log',
        'spar_points_log_page',
        40
    );
    add_submenu_page(
        'spar-settings',
        esc_html__( 'Reports & Analytics', 'simple-points-and-rewards' ),
        esc_html__( 'Reports & Analytics', 'simple-points-and-rewards' ),
        'manage_woocommerce',
        'spar-reports',
        'spar_reports_admin_page',
        50
    );
    add_submenu_page(
        'spar-settings',
        esc_html__( 'Referral Link Clicks', 'simple-points-and-rewards' ),
        esc_html__( 'Referral Link Clicks', 'simple-points-and-rewards' ),
        'manage_woocommerce',
        'spar-referrals',
        'spar_referrals_admin_page',
        45
    );
    // Visual submenu separator (non-clickable) between management pages and reporting
    add_submenu_page(
        'spar-settings',
        esc_html__( 'Separator', 'simple-points-and-rewards' ),
        '──────────',
        'manage_woocommerce',
        'spar-separator-1',
        '__return_null',
        35
    );
}

add_action( 'admin_enqueue_scripts', 'spar_enqueue_admin_assets' );
// Enqueue frontend assets late so plugin styles override theme styles.
add_action( 'wp_enqueue_scripts', 'spar_enqueue_frontend_assets', 120 );
// Redirect legacy (non-prefixed) admin page slugs to new prefixed ones.
add_action( 'admin_init', 'spar_legacy_admin_slug_redirects' );
function spar_legacy_admin_slug_redirects() {
    $page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
    if ( !$page ) {
        return;
    }
    // Legacy rewards options page now lives under Settings > Rewards tab.
    if ( $page === 'reward-options' ) {
        wp_safe_redirect( admin_url( 'admin.php?page=spar-settings&tab=rewards' ) );
        exit;
    }
    $map = array(
        'customer-points'  => 'spar-customer-points',
        'reward-vouchers'  => 'spar-reward-vouchers',
        'referral-coupons' => 'spar-referral-coupons',
    );
    if ( isset( $map[$page] ) ) {
        wp_safe_redirect( admin_url( 'admin.php?page=' . $map[$page] ) );
        exit;
    }
}

function spar_enqueue_admin_assets(  $hook  ) {
    // Check if we're on any of our admin pages
    $our_pages = array(
        'spar-settings',
        'spar-log',
        'spar-reports',
        'spar-reward-vouchers',
        'spar-customer-points',
        'spar-customer-points-bulk',
        'spar-customer',
        'reward-options',
        'spar-referral-coupons',
        'spar-referrals',
        'spar-migration'
    );
    // Include Spins Activity Log page only when the Prize Wheel is enabled.
    $spar_enqueue_all_opts = get_option( 'spar_options', [] );
    if ( !empty( $spar_enqueue_all_opts['earn']['spin_wheel']['enabled'] ) ) {
        $our_pages[] = 'spar-spins-log';
    }
    // Include Guest Points page when guest tracking is available and enabled (PRO).
    if ( function_exists( 'spar_is_guest_tracking_enabled' ) && spar_is_guest_tracking_enabled() ) {
        $our_pages[] = 'spar-guest-points';
    }
    $is_our_page = false;
    // Read-only page param for UI decisions
    $current_page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
    foreach ( $our_pages as $page ) {
        if ( strpos( $hook, $page ) !== false || $current_page && $current_page === $page ) {
            $is_our_page = true;
            break;
        }
    }
    // Enqueue plugin styles only on our admin pages.
    if ( $is_our_page ) {
        wp_enqueue_style(
            'spar-styles',
            SPAR_PLUGIN_URL . 'assets/css/reward-points.css',
            [],
            SPAR_VERSION
        );
        wp_enqueue_style(
            'spar-admin-header',
            SPAR_PLUGIN_URL . 'assets/css/admin-header.css',
            ['spar-styles'],
            SPAR_VERSION
        );
    }
    $asset_version = ( defined( 'SPAR_VERSION' ) ? SPAR_VERSION : '1.0' );
    $admin_settings_css_path = SPAR_PLUGIN_PATH . 'assets/css/admin-settings.css';
    $admin_settings_css_version = ( file_exists( $admin_settings_css_path ) ? filemtime( $admin_settings_css_path ) : $asset_version );
    // Settings page specific assets
    if ( strpos( $hook, 'spar-settings' ) !== false || $current_page && $current_page === 'spar-settings' ) {
        // Enqueue admin settings CSS and JS
        wp_enqueue_style(
            'spar-font-awesome',
            SPAR_PLUGIN_URL . 'assets/fonts/font-awesome/css/all.min.css',
            [],
            $asset_version
        );
        wp_enqueue_style(
            'spar-admin-settings',
            SPAR_PLUGIN_URL . 'assets/css/admin-settings.css',
            ['spar-font-awesome'],
            $admin_settings_css_version
        );
        wp_enqueue_style(
            'spar-spin-wheel-admin',
            SPAR_PLUGIN_URL . 'premium-files/assets/css/spin-wheel.css',
            ['spar-admin-settings'],
            $asset_version
        );
        wp_enqueue_script(
            'spar-admin-settings',
            SPAR_PLUGIN_URL . 'assets/js/admin-settings.js',
            ['jquery'],
            $asset_version,
            true
        );
        // Sortable for Dashboard Customisation tab and Earn tab display order.
        wp_enqueue_script( 'jquery-ui-sortable' );
        wp_enqueue_script(
            'spar-admin-settings-dashboard-tab',
            SPAR_PLUGIN_URL . 'assets/js/admin-settings-dashboard-tab.js',
            ['jquery', 'jquery-ui-sortable'],
            $asset_version,
            true
        );
        wp_enqueue_script(
            'spar-admin-settings-earn-tab',
            SPAR_PLUGIN_URL . 'assets/js/admin-settings-earn-tab.js',
            ['jquery', 'jquery-ui-sortable', 'spar-admin-settings'],
            $asset_version,
            true
        );
        wp_enqueue_script(
            'spar-admin-settings-points-delay-tab',
            SPAR_PLUGIN_URL . 'assets/js/admin-settings-points-delay-tab.js',
            ['jquery', 'spar-admin-settings'],
            $asset_version,
            true
        );
        // Visual icon picker enhancement for the Badge/Reward Icon dropdowns.
        $icon_picker_js_path = SPAR_PLUGIN_PATH . 'assets/js/admin-settings-icon-picker.js';
        $icon_picker_js_version = ( file_exists( $icon_picker_js_path ) ? filemtime( $icon_picker_js_path ) : $asset_version );
        wp_enqueue_script(
            'spar-admin-settings-icon-picker',
            SPAR_PLUGIN_URL . 'assets/js/admin-settings-icon-picker.js',
            [],
            $icon_picker_js_version,
            true
        );
        $database_supports_emoji = !function_exists( 'spar_options_table_supports_emoji' ) || spar_options_table_supports_emoji();
        $reward_badge_icons = ( function_exists( 'spar_get_reward_badge_icons' ) ? spar_get_reward_badge_icons() : array() );
        $level_badge_icons = ( function_exists( 'spar_get_badge_icons' ) ? spar_get_badge_icons() : array() );
        $font_awesome_badge_icons = ( function_exists( 'spar_get_font_awesome_icon_options' ) ? spar_get_font_awesome_icon_options() : array() );
        $reward_type_defaults = array(
            'voucher'        => ( function_exists( 'spar_get_reward_type_default_icon' ) ? spar_get_reward_type_default_icon( 'voucher' ) : 'fa-solid fa-ticket' ),
            'product'        => ( function_exists( 'spar_get_reward_type_default_icon' ) ? spar_get_reward_type_default_icon( 'product' ) : 'fa-solid fa-gift' ),
            'product_bundle' => ( function_exists( 'spar_get_reward_type_default_icon' ) ? spar_get_reward_type_default_icon( 'product_bundle' ) : 'fa-solid fa-gifts' ),
            'custom'         => ( function_exists( 'spar_get_reward_type_default_icon' ) ? spar_get_reward_type_default_icon( 'custom' ) : 'fa-solid fa-gear' ),
        );
        $level_default_icon = ( function_exists( 'spar_get_level_default_badge_icon' ) ? spar_get_level_default_badge_icon( array(
            'id' => 'level_1',
        ) ) : 'fa-solid fa-trophy' );
        $custom_icon_text_placeholder = esc_html__( 'e.g. 🎁 or VIP', 'simple-points-and-rewards' );
        $custom_badge_text_placeholder = esc_html__( 'e.g. VIP or 😎', 'simple-points-and-rewards' );
        if ( !$database_supports_emoji ) {
            $reward_badge_icons = $font_awesome_badge_icons + array(
                'Voucher' => esc_html__( 'Voucher', 'simple-points-and-rewards' ),
                'Gift'    => esc_html__( 'Gift', 'simple-points-and-rewards' ),
                'Custom'  => esc_html__( 'Custom', 'simple-points-and-rewards' ),
                'Star'    => esc_html__( 'Star', 'simple-points-and-rewards' ),
                'Trophy'  => esc_html__( 'Trophy', 'simple-points-and-rewards' ),
            );
            $level_badge_icons = $font_awesome_badge_icons + array(
                'Bronze' => esc_html__( 'Bronze', 'simple-points-and-rewards' ),
                'Silver' => esc_html__( 'Silver', 'simple-points-and-rewards' ),
                'Gold'   => esc_html__( 'Gold', 'simple-points-and-rewards' ),
                'Star'   => esc_html__( 'Star', 'simple-points-and-rewards' ),
                'Trophy' => esc_html__( 'Trophy', 'simple-points-and-rewards' ),
            );
            $level_default_icon = 'fa-solid fa-trophy';
            $custom_icon_text_placeholder = esc_html__( 'e.g. Gift or VIP', 'simple-points-and-rewards' );
            $custom_badge_text_placeholder = esc_html__( 'e.g. VIP or Badge', 'simple-points-and-rewards' );
        }
        // Ensure SelectWoo (Select2) and styles are available locally via WooCommerce (no external CDNs)
        if ( function_exists( 'WC' ) ) {
            $wc_url = trailingslashit( WC()->plugin_url() );
            if ( !wp_script_is( 'selectWoo', 'registered' ) ) {
                wp_register_script(
                    'selectWoo',
                    $wc_url . 'assets/js/selectWoo/selectWoo.full.min.js',
                    array('jquery'),
                    ( defined( 'WC_VERSION' ) ? WC_VERSION : '1.0' ),
                    true
                );
            }
            if ( !wp_style_is( 'select2', 'registered' ) ) {
                wp_register_style(
                    'select2',
                    $wc_url . 'assets/css/select2.css',
                    array(),
                    ( defined( 'WC_VERSION' ) ? WC_VERSION : '1.0' )
                );
            }
            // WooCommerce-style tooltips.
            if ( !wp_script_is( 'jquery-tiptip', 'registered' ) ) {
                wp_register_script(
                    'jquery-tiptip',
                    $wc_url . 'assets/js/jquery-tiptip/jquery.tipTip.min.js',
                    array('jquery'),
                    ( defined( 'WC_VERSION' ) ? WC_VERSION : '1.0' ),
                    true
                );
            }
            if ( wp_script_is( 'selectWoo', 'registered' ) ) {
                wp_enqueue_script( 'selectWoo' );
            }
            if ( wp_style_is( 'select2', 'registered' ) ) {
                wp_enqueue_style( 'select2' );
            }
            if ( wp_script_is( 'jquery-tiptip', 'registered' ) ) {
                wp_enqueue_script( 'jquery-tiptip' );
            }
            if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
                wp_enqueue_style( 'woocommerce_admin_styles' );
            }
        }
        // Localize script data for premium status and ajax nonces
        wp_localize_script( 'spar-admin-settings', 'sparSettings', [
            'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
            'nonce'               => wp_create_nonce( 'spar_admin_nonce' ),
            'settingsNonce'       => wp_create_nonce( 'spar_settings_nonce' ),
            'pageLinkNonce'       => wp_create_nonce( 'spar_retrieve_page_link' ),
            'createPageNonce'     => wp_create_nonce( 'spar_generate_rewards_page' ),
            'listPagesNonce'      => wp_create_nonce( 'spar_list_rewards_pages' ),
            'couponEditLinkNonce' => wp_create_nonce( 'spar_get_coupon_edit_link' ),
            'generateTermsNonce'  => wp_create_nonce( 'spar_generate_terms' ),
            'strings'             => [
                'saving'             => esc_html__( 'Saving...', 'simple-points-and-rewards' ),
                'saved'              => esc_html__( 'Saved!', 'simple-points-and-rewards' ),
                'error'              => esc_html__( 'Error saving option', 'simple-points-and-rewards' ),
                'missingNonce'       => esc_html__( 'Security token missing', 'simple-points-and-rewards' ),
                'generatingTerms'    => esc_html__( 'Generating...', 'simple-points-and-rewards' ),
                'generateTerms'      => esc_html__( 'Generate', 'simple-points-and-rewards' ),
                'generateTermsError' => esc_html__( 'Failed to generate terms. Please try again.', 'simple-points-and-rewards' ),
            ],
        ] );
        // Enqueue tab-specific scripts (file-based, replacing previous inline blocks)
        $settings_dep = 'spar-admin-settings';
        if ( !wp_script_is( 'spar-admin-settings', 'registered' ) ) {
            $settings_dep = ( wp_script_is( 'spar-admin-settings-fallback', 'registered' ) ? 'spar-admin-settings-fallback' : 'jquery' );
        }
        // Build deps for rewards tab script, making selectWoo optional to avoid blocking enqueue when not registered
        $rewards_deps = ['jquery', $settings_dep];
        if ( wp_script_is( 'selectWoo', 'registered' ) ) {
            $rewards_deps[] = 'selectWoo';
        }
        wp_enqueue_script(
            'spar-admin-settings-rewards-tab',
            SPAR_PLUGIN_URL . 'assets/js/admin-settings-rewards-tab.js',
            $rewards_deps,
            $asset_version,
            true
        );
        $spar_max_rewards = 3;
        wp_localize_script( 'spar-admin-settings-rewards-tab', 'sparRewardsTab', [
            'ajaxUrl'                   => admin_url( 'admin-ajax.php' ),
            'searchProductsNonce'       => wp_create_nonce( 'spar_search_products' ),
            'searchCategoriesNonce'     => wp_create_nonce( 'spar_cr_search_categories' ),
            'couponEditLinkNonce'       => wp_create_nonce( 'spar_get_coupon_edit_link' ),
            'createTemplateCouponNonce' => wp_create_nonce( 'spar_create_template_coupon' ),
            'badgeIcons'                => $reward_badge_icons,
            'rewardTypeDefaults'        => $reward_type_defaults,
            'isPro'                     => spar_fs()->can_use_premium_code__premium_only(),
            'maxRewards'                => $spar_max_rewards,
            'i18n'                      => [
                'newReward'                 => esc_html__( 'New Reward', 'simple-points-and-rewards' ),
                'maxRewardsReached'         => esc_html__( 'Upgrade to PRO to add more rewards.', 'simple-points-and-rewards' ),
                'edit'                      => esc_html__( 'Edit', 'simple-points-and-rewards' ),
                'close'                     => esc_html__( 'Close', 'simple-points-and-rewards' ),
                'delete'                    => esc_html__( 'Delete', 'simple-points-and-rewards' ),
                'duplicate'                 => esc_html__( 'Duplicate', 'simple-points-and-rewards' ),
                'copySuffix'                => esc_html__( ' (Copy)', 'simple-points-and-rewards' ),
                'confirmDelete'             => esc_html__( 'Are you sure you want to delete this reward?', 'simple-points-and-rewards' ),
                'untitledReward'            => esc_html__( 'Untitled Reward', 'simple-points-and-rewards' ),
                'rewardName'                => esc_html__( 'Reward Name:', 'simple-points-and-rewards' ),
                'rewardNamePlaceholder'     => esc_html__( '$10 Off Voucher', 'simple-points-and-rewards' ),
                'pointsRequired'            => esc_html__( 'Points Required:', 'simple-points-and-rewards' ),
                'rewardType'                => esc_html__( 'Reward Type:', 'simple-points-and-rewards' ),
                'voucher'                   => esc_html__( 'Discount Voucher/Coupon', 'simple-points-and-rewards' ),
                'product'                   => esc_html__( 'Free Product', 'simple-points-and-rewards' ),
                'status'                    => esc_html__( 'Status:', 'simple-points-and-rewards' ),
                'active'                    => esc_html__( 'Active', 'simple-points-and-rewards' ),
                'inactive'                  => esc_html__( 'Inactive', 'simple-points-and-rewards' ),
                'voucherSettings'           => esc_html__( 'Voucher Settings', 'simple-points-and-rewards' ),
                'voucherAmount'             => esc_html__( 'Voucher Amount:', 'simple-points-and-rewards' ),
                'discountType'              => esc_html__( 'Discount Type:', 'simple-points-and-rewards' ),
                'fixedAmount'               => esc_html__( 'Fixed Amount', 'simple-points-and-rewards' ),
                'percentage'                => esc_html__( 'Percentage', 'simple-points-and-rewards' ),
                'freeShipping'              => esc_html__( 'Free Shipping', 'simple-points-and-rewards' ),
                'productSettings'           => esc_html__( 'Product Settings', 'simple-points-and-rewards' ),
                'productLabel'              => esc_html__( 'Product:', 'simple-points-and-rewards' ),
                'productHelp'               => esc_html__( 'Search and select a WooCommerce product for the free product reward', 'simple-points-and-rewards' ),
                'expiryDate'                => esc_html__( 'Expiry Date:', 'simple-points-and-rewards' ),
                'searchProduct'             => esc_html__( 'Search for a product...', 'simple-points-and-rewards' ),
                'templateCoupon'            => esc_html__( 'Template Coupon (optional):', 'simple-points-and-rewards' ),
                'templateCouponNone'        => esc_html__( 'No template – use basic settings above', 'simple-points-and-rewards' ),
                'templateCouponHelp'        => esc_html__( 'Select an existing coupon to use as a template. All coupon settings (minimum spend, product restrictions, usage limits, etc) will be copied to the generated voucher. Discount type, amount, shipping, expiry, and customer email are always set by the reward.', 'simple-points-and-rewards' ),
                'templateCouponNoSelected'  => esc_html__( 'No template selected.', 'simple-points-and-rewards' ),
                'templateCouponLoading'     => esc_html__( 'Loading…', 'simple-points-and-rewards' ),
                'templateCouponError'       => esc_html__( 'Error.', 'simple-points-and-rewards' ),
                'createNewTemplate'         => esc_html__( 'Create New Template', 'simple-points-and-rewards' ),
                'creatingTemplate'          => esc_html__( 'Creating…', 'simple-points-and-rewards' ),
                'enableTemplateCoupon'      => esc_html__( 'Enable Template Coupon', 'simple-points-and-rewards' ),
                'rewardIcon'                => esc_html__( 'Reward Icon:', 'simple-points-and-rewards' ),
                'rewardIconColor'           => esc_html__( 'Icon Color:', 'simple-points-and-rewards' ),
                'colorModeDefault'          => esc_html__( 'Default (Theme Colors)', 'simple-points-and-rewards' ),
                'colorModeCustom'           => esc_html__( 'Custom', 'simple-points-and-rewards' ),
                'selectDefaultIcon'         => esc_html__( 'Default (based on type)', 'simple-points-and-rewards' ),
                'customOptionUrl'           => esc_html__( 'Custom (Image URL)', 'simple-points-and-rewards' ),
                'customOptionText'          => esc_html__( 'Custom (Text/Emoji)', 'simple-points-and-rewards' ),
                'customIconUrl'             => esc_html__( 'Custom Icon (Image URL):', 'simple-points-and-rewards' ),
                'customIconUrlHelp'         => esc_html__( 'Provide an image URL to use as the reward icon.', 'simple-points-and-rewards' ),
                'uploadSelect'              => esc_html__( 'Upload / Select', 'simple-points-and-rewards' ),
                'customIconText'            => esc_html__( 'Custom Icon (Text/Emoji):', 'simple-points-and-rewards' ),
                'customIconTextHelp'        => esc_html__( 'Shown instead of the preset icon when Custom (Text/Emoji) is selected.', 'simple-points-and-rewards' ),
                'customIconTextPlaceholder' => $custom_icon_text_placeholder,
                'productImage'              => esc_html__( 'Product Image', 'simple-points-and-rewards' ),
                'productBundle'             => esc_html__( 'Free Product Bundle', 'simple-points-and-rewards' ),
                'bundleProducts'            => esc_html__( 'Bundle Products:', 'simple-points-and-rewards' ),
                'bundleHelp'                => esc_html__( 'Search and select two or more products. All of them are added to the cart for free when the bundle is redeemed.', 'simple-points-and-rewards' ),
                'showProductValue'          => esc_html__( 'Show total value', 'simple-points-and-rewards' ),
                'showProductValueHelp'      => esc_html__( 'Display the total value of the product(s) under the name on the rewards/claim display.', 'simple-points-and-rewards' ),
            ],
        ] );
        $levels_tab_js_path = SPAR_PLUGIN_PATH . 'assets/js/admin-settings-levels-tab.js';
        $levels_tab_js_version = ( file_exists( $levels_tab_js_path ) ? filemtime( $levels_tab_js_path ) : $asset_version );
        wp_enqueue_script(
            'spar-admin-settings-levels-tab',
            SPAR_PLUGIN_URL . 'assets/js/admin-settings-levels-tab.js',
            ['jquery', 'spar-admin-settings', 'jquery-ui-sortable'],
            $levels_tab_js_version,
            true
        );
        $starter_currency_code = ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : get_option( 'woocommerce_currency', 'USD' ) );
        $starter_currency_symbol = ( function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol( $starter_currency_code ), ENT_QUOTES, 'UTF-8' ) : '$' );
        $spar_max_levels = 1;
        wp_localize_script( 'spar-admin-settings-levels-tab', 'sparLevelsTab', [
            'isPro'           => spar_fs()->can_use_premium_code__premium_only(),
            'maxLevels'       => $spar_max_levels,
            'badgeIcons'      => $level_badge_icons,
            'defaultIcon'     => $level_default_icon,
            'earnTypeLabels'  => ( function_exists( 'spar_get_earn_type_labels' ) ? spar_get_earn_type_labels() : [] ),
            'starterBenefits' => [
                'currencySymbol' => $starter_currency_symbol,
                'priceDecimals'  => ( function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2 ),
                'decimalSep'     => ( function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : '.' ),
                'thousandSep'    => ( function_exists( 'wc_get_price_thousand_separator' ) ? (string) wc_get_price_thousand_separator() : ',' ),
                'perSpend'       => __( '%1$s points per %2$s%3$s spent', 'simple-points-and-rewards' ),
                'perReferral'    => __( '%s points for each referral', 'simple-points-and-rewards' ),
            ],
            'defaults'        => [
                'subject' => spar_get_default_level_email_subject(),
                'body'    => spar_get_default_level_email_body(),
            ],
            'i18n'            => [
                'newLevel'                     => esc_html__( 'New Level', 'simple-points-and-rewards' ),
                'maxLevelsReached'             => esc_html__( 'Upgrade to PRO to add more levels.', 'simple-points-and-rewards' ),
                'edit'                         => esc_html__( 'Edit', 'simple-points-and-rewards' ),
                'close'                        => esc_html__( 'Close', 'simple-points-and-rewards' ),
                'delete'                       => esc_html__( 'Delete', 'simple-points-and-rewards' ),
                'duplicate'                    => esc_html__( 'Duplicate', 'simple-points-and-rewards' ),
                'copySuffix'                   => esc_html__( ' (Copy)', 'simple-points-and-rewards' ),
                'confirmDelete'                => esc_html__( 'Are you sure you want to delete this level?', 'simple-points-and-rewards' ),
                'untitledLevel'                => esc_html__( 'Untitled Level', 'simple-points-and-rewards' ),
                'levelName'                    => esc_html__( 'Level Name:', 'simple-points-and-rewards' ),
                'levelNamePlaceholder'         => esc_html__( 'Bronze Member', 'simple-points-and-rewards' ),
                'requiredPoints'               => esc_html__( 'Required Points:', 'simple-points-and-rewards' ),
                'badgeIcon'                    => esc_html__( 'Badge Icon:', 'simple-points-and-rewards' ),
                'selectIcon'                   => esc_html__( 'Select an icon', 'simple-points-and-rewards' ),
                'iconColor'                    => esc_html__( 'Icon Color:', 'simple-points-and-rewards' ),
                'colorDefault'                 => esc_html__( 'Default (Theme Color)', 'simple-points-and-rewards' ),
                'colorCustom'                  => esc_html__( 'Custom', 'simple-points-and-rewards' ),
                'customBadgeUrl'               => esc_html__( 'Custom Badge (URL):', 'simple-points-and-rewards' ),
                'customBadgeHelp'              => esc_html__( 'Provide an image URL that will be used when Custom (Image URL) is selected.', 'simple-points-and-rewards' ),
                'customBadgeUrlHelp'           => esc_html__( 'Provide an image URL that will be used when Custom (Image URL) is selected.', 'simple-points-and-rewards' ),
                'customOptionUrl'              => esc_html__( 'Custom (Image URL)', 'simple-points-and-rewards' ),
                'customOptionText'             => esc_html__( 'Custom (Text/Emoji)', 'simple-points-and-rewards' ),
                'customBadgeText'              => esc_html__( 'Custom Badge (Text/Emoji):', 'simple-points-and-rewards' ),
                'customBadgeTextHelp'          => esc_html__( 'Shown instead of the preset icon when Custom (Text/Emoji) is selected.', 'simple-points-and-rewards' ),
                'customBadgeTextPlaceholder'   => $custom_badge_text_placeholder,
                'levelBenefits'                => esc_html__( 'Level Benefits', 'simple-points-and-rewards' ),
                'pointsMultiplier'             => esc_html__( 'Points Multiplier:', 'simple-points-and-rewards' ),
                'pointsMultiplierHelp'         => esc_html__( 'Multiply all earned points by this amount (e.g., 1.5 = 50% bonus on all activities)', 'simple-points-and-rewards' ),
                'customBenefits'               => esc_html__( 'Custom Benefits:', 'simple-points-and-rewards' ),
                'customBenefitsPlaceholder'    => esc_html__( 'Enter one benefit per line...', 'simple-points-and-rewards' ),
                'customBenefitsHelp'           => esc_html__( 'Enter one benefit per line. These will be displayed to customers alongside the automatic benefits.', 'simple-points-and-rewards' ),
                'sendLevelEmail'               => esc_html__( 'Send email to customer on level up', 'simple-points-and-rewards' ),
                'levelEmailHelp'               => esc_html__( 'Email is sent as soon as the customer reaches this level.', 'simple-points-and-rewards' ),
                'customiseEmail'               => esc_html__( 'Customise Email', 'simple-points-and-rewards' ),
                'emailSubject'                 => esc_html__( 'Subject:', 'simple-points-and-rewards' ),
                'emailBody'                    => esc_html__( 'Email Body:', 'simple-points-and-rewards' ),
                'levelEmailSubjectPlaceholder' => esc_html__( 'Congratulations! You reached {level_name}', 'simple-points-and-rewards' ),
                'levelEmailPlaceholders'       => esc_html__( 'Available placeholders: {user_name}, {user_email}, {level_name}, {previous_level_name}, {points_label}, {total_points}, {site_name}, {site_url}, {rewards_url}', 'simple-points-and-rewards' ),
                'customMultipliers'            => esc_html__( 'Use different multipliers per earn type', 'simple-points-and-rewards' ),
                'customMultipliersHelp'        => esc_html__( 'Override the global multiplier above with a unique multiplier for each way to earn points.', 'simple-points-and-rewards' ),
                'earnMultipliersBlankHelp'     => esc_html__( 'Leave blank to use the global multiplier for that earn type.', 'simple-points-and-rewards' ),
                'pointsMultiplierTitle'        => esc_html__( 'Points Multiplier', 'simple-points-and-rewards' ),
                'customBenefitsTitle'          => esc_html__( 'Custom Benefits', 'simple-points-and-rewards' ),
                'levelUpEmailTitle'            => esc_html__( 'Level Up Email', 'simple-points-and-rewards' ),
                'userCapabilityTitle'          => esc_html__( 'User Capability', 'simple-points-and-rewards' ),
                'capabilityName'               => esc_html__( 'Capability name:', 'simple-points-and-rewards' ),
                'capabilityHelp'               => esc_html__( 'Customers at this level are automatically granted this WordPress capability. Use it with a membership/restriction plugin or current_user_can() in your own code to give this level access to specific content.', 'simple-points-and-rewards' ),
            ],
        ] );
        // Conditional Rules (PRO) under Ways to Earn.
        if ( function_exists( 'spar_fs' ) && spar_fs()->can_use_premium_code__premium_only() ) {
            $cr_deps = ['jquery', 'spar-admin-settings'];
            if ( wp_script_is( 'selectWoo', 'registered' ) ) {
                $cr_deps[] = 'selectWoo';
            }
            if ( wp_script_is( 'jquery-tiptip', 'registered' ) ) {
                $cr_deps[] = 'jquery-tiptip';
            }
            wp_enqueue_script(
                'spar-admin-conditional-rules',
                SPAR_PLUGIN_URL . 'assets/js/admin-conditional-rules.js',
                $cr_deps,
                $asset_version,
                true
            );
            wp_localize_script( 'spar-admin-conditional-rules', 'sparConditionalRules', [
                'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
                'searchProductsNonce'   => wp_create_nonce( 'spar_search_products' ),
                'searchCategoriesNonce' => wp_create_nonce( 'spar_cr_search_categories' ),
                'searchCustomersNonce'  => wp_create_nonce( 'spar_cr_search_customers' ),
                'i18n'                  => [
                    'ruleTitle'     => esc_html__( 'Conditional Rule', 'simple-points-and-rewards' ),
                    'edit'          => esc_html__( 'Edit', 'simple-points-and-rewards' ),
                    'close'         => esc_html__( 'Close', 'simple-points-and-rewards' ),
                    'delete'        => esc_html__( 'Delete', 'simple-points-and-rewards' ),
                    'confirmDelete' => esc_html__( 'Are you sure you want to delete this rule?', 'simple-points-and-rewards' ),
                ],
            ] );
        }
        // Email preview functionality
        wp_enqueue_script(
            'spar-admin-email',
            SPAR_PLUGIN_URL . 'assets/js/admin-email.js',
            ['jquery'],
            $asset_version,
            true
        );
        wp_localize_script( 'spar-admin-email', 'sparEmail', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'spar_preview_email' ),
        ] );
        // Also enqueue the admin styles and scripts that were being loaded separately
        wp_enqueue_script(
            'spar-admin-scripts',
            SPAR_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            $asset_version,
            true
        );
    }
    // Points Activity Log and Spins Activity Log page specific assets
    $spar_spins_log_enqueue_pages = ['spar-log'];
    if ( !empty( $spar_enqueue_all_opts['earn']['spin_wheel']['enabled'] ) ) {
        $spar_spins_log_enqueue_pages[] = 'spar-spins-log';
    }
    if ( strpos( $hook, 'spar-log' ) !== false || $current_page && in_array( $current_page, $spar_spins_log_enqueue_pages, true ) ) {
        wp_enqueue_style(
            'spar-log-styles',
            SPAR_PLUGIN_URL . 'assets/css/admin-log.css',
            ['spar-styles'],
            $asset_version
        );
    }
    // Reports page specific assets
    if ( strpos( $hook, 'spar-reports' ) !== false || $current_page && $current_page === 'spar-reports' ) {
        // Font Awesome for icons
        wp_enqueue_style(
            'spar-font-awesome',
            SPAR_PLUGIN_URL . 'assets/fonts/font-awesome/css/all.min.css',
            [],
            '6.6.0'
        );
        wp_enqueue_style(
            'spar-admin-reports',
            SPAR_PLUGIN_URL . 'assets/css/admin-reports.css',
            ['spar-styles'],
            $asset_version
        );
        // Hover tooltips for the Points Trend chart.
        wp_enqueue_script(
            'spar-admin-reports',
            SPAR_PLUGIN_URL . 'assets/js/admin-reports.js',
            [],
            $asset_version,
            true
        );
    }
    // Reward options page specific assets
    if ( strpos( $hook, 'reward-options' ) !== false || $current_page && $current_page === 'reward-options' ) {
        wp_enqueue_style(
            'spar-admin-tables',
            SPAR_PLUGIN_URL . 'assets/css/admin-tables.css',
            ['spar-styles'],
            $asset_version
        );
        wp_enqueue_script(
            'spar-admin-tables',
            SPAR_PLUGIN_URL . 'assets/js/admin-tables.js',
            ['jquery'],
            $asset_version,
            true
        );
    }
    // Customer points page specific assets
    if ( strpos( $hook, 'spar-customer-points' ) !== false || $current_page && in_array( $current_page, array('spar-customer-points', 'spar-customer-points-bulk'), true ) ) {
        wp_enqueue_style(
            'spar-admin-tables',
            SPAR_PLUGIN_URL . 'assets/css/admin-tables.css',
            ['spar-styles'],
            $asset_version
        );
        wp_enqueue_script(
            'spar-admin-tables',
            SPAR_PLUGIN_URL . 'assets/js/admin-tables.js',
            ['jquery'],
            $asset_version,
            true
        );
    }
    // Guest points page specific assets.
    if ( strpos( $hook, 'spar-guest-points' ) !== false || $current_page && $current_page === 'spar-guest-points' ) {
        wp_enqueue_style(
            'spar-admin-tables',
            SPAR_PLUGIN_URL . 'assets/css/admin-tables.css',
            array('spar-styles'),
            $asset_version
        );
        wp_enqueue_script(
            'spar-admin-guest-points',
            SPAR_PLUGIN_URL . 'assets/js/admin-guest-points.js',
            array('jquery'),
            $asset_version,
            true
        );
        wp_localize_script( 'spar-admin-guest-points', 'sparGuestPointsAdmin', array(
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'syncNonce'     => wp_create_nonce( 'spar_sync_guest_points' ),
            'editNonce'     => wp_create_nonce( 'spar_update_guest_points_total' ),
            'addGuestNonce' => wp_create_nonce( 'spar_add_guest_customer' ),
            'logPageUrl'    => esc_url( admin_url( 'admin.php?page=spar-log' ) ),
            'batchSize'     => 25,
            'strings'       => array(
                'confirmSync'                => esc_html__( 'Sync past guest orders now? This may also award points to registered customers whose email matches older guest orders.', 'simple-points-and-rewards' ),
                'confirmOverrideSync'        => esc_html__( 'Override existing guest points tracking now? This will reset current guest records and rebuild tracking for all guest orders.', 'simple-points-and-rewards' ),
                'syncStarting'               => esc_html__( 'Starting guest points sync...', 'simple-points-and-rewards' ),
                'syncProgress'               => esc_html__( 'Checked %1$d of %2$d orders. Created %3$d new guest customers, added %4$d new guest order point entries, rebuilt %5$d existing guest order point entries, credited %6$d registered customers, skipped %7$d orders.', 'simple-points-and-rewards' ),
                'syncProgressNoTotal'        => esc_html__( 'Checked %1$d orders. Created %2$d new guest customers, added %3$d new guest order point entries, rebuilt %4$d existing guest order point entries, credited %5$d registered customers, skipped %6$d orders.', 'simple-points-and-rewards' ),
                'syncComplete'               => esc_html__( 'Sync complete.', 'simple-points-and-rewards' ),
                'syncCompleteGuestsAdded'    => esc_html__( '%1$d new guest customers created', 'simple-points-and-rewards' ),
                'syncCompleteAdded'          => esc_html__( '%1$d new guest order point entries added', 'simple-points-and-rewards' ),
                'syncCompleteUpdated'        => esc_html__( '%1$d existing guest order point entries rebuilt', 'simple-points-and-rewards' ),
                'syncCompleteCredited'       => esc_html__( '%1$d registered customers credited', 'simple-points-and-rewards' ),
                'syncCompleteSkipped'        => esc_html__( '%1$d orders skipped with no guest point changes', 'simple-points-and-rewards' ),
                'syncSkipReasonPrefix'       => esc_html__( 'Skipped breakdown:', 'simple-points-and-rewards' ),
                'syncSkipReasonLabels'       => array(
                    'registered_order'         => esc_html__( 'orders already linked to a registered customer', 'simple-points-and-rewards' ),
                    'ineligible_status'        => esc_html__( 'orders with an ineligible status', 'simple-points-and-rewards' ),
                    'terminal_status'          => esc_html__( 'cancelled, failed, refunded, or trashed orders', 'simple-points-and-rewards' ),
                    'missing_email'            => esc_html__( 'orders missing a billing email', 'simple-points-and-rewards' ),
                    'email_mismatch'           => esc_html__( 'orders outside the selected email filter', 'simple-points-and-rewards' ),
                    'already_tracked'          => esc_html__( 'orders already tracked', 'simple-points-and-rewards' ),
                    'existing_log'             => esc_html__( 'orders already present in the guest points log', 'simple-points-and-rewards' ),
                    'registered_user_excluded' => esc_html__( 'orders for now-registered customers excluded by the checkbox', 'simple-points-and-rewards' ),
                    'no_points'                => esc_html__( 'orders worth 0 points', 'simple-points-and-rewards' ),
                    'existing_user_log'        => esc_html__( 'registered customers already credited', 'simple-points-and-rewards' ),
                    'unchanged_existing_user'  => esc_html__( 'registered customer credits already correct', 'simple-points-and-rewards' ),
                ),
                'syncCompleteViewLog'        => esc_html__( 'View Points Activity Log', 'simple-points-and-rewards' ),
                'syncError'                  => esc_html__( 'Guest points sync failed. Please try again.', 'simple-points-and-rewards' ),
                'invalidEmail'               => esc_html__( 'Please enter a valid email address.', 'simple-points-and-rewards' ),
                'invalidDateRange'           => esc_html__( 'The start date must be before the end date.', 'simple-points-and-rewards' ),
                'invalidPoints'              => esc_html__( 'Please enter a valid points total.', 'simple-points-and-rewards' ),
                'fillMissingModeDescription' => esc_html__( 'Fill Missing Only adds points for historical guest orders that have not already been tracked. It keeps existing guest records, order tracking, and manual point edits intact.', 'simple-points-and-rewards' ),
                'overrideModeDescription'    => esc_html__( 'Override Existing resets the current guest points log and summary totals, checks all guest orders, clears stale guest tracking from ineligible orders, and rebuilds eligible guest points from the latest rules. Other filters are locked while this mode is selected.', 'simple-points-and-rewards' ),
                'saving'                     => esc_html__( 'Saving...', 'simple-points-and-rewards' ),
                'saved'                      => esc_html__( 'Saved.', 'simple-points-and-rewards' ),
                'saveError'                  => esc_html__( 'Could not save guest points.', 'simple-points-and-rewards' ),
                'addGuestSaving'             => esc_html__( 'Creating guest customer...', 'simple-points-and-rewards' ),
                'addGuestSuccess'            => esc_html__( 'Guest customer added successfully. Reloading...', 'simple-points-and-rewards' ),
                'addGuestError'              => esc_html__( 'Could not create guest customer. Please try again.', 'simple-points-and-rewards' ),
                'addGuestInvalidPoints'      => esc_html__( 'Points to grant must be at least 1.', 'simple-points-and-rewards' ),
            ),
        ) );
    }
    // Reward vouchers page specific assets
    if ( strpos( $hook, 'spar-reward-vouchers' ) !== false || $current_page && $current_page === 'spar-reward-vouchers' ) {
        wp_enqueue_style(
            'spar-admin-tables',
            SPAR_PLUGIN_URL . 'assets/css/admin-tables.css',
            ['spar-styles'],
            $asset_version
        );
        wp_enqueue_script(
            'spar-admin-tables',
            SPAR_PLUGIN_URL . 'assets/js/admin-tables.js',
            ['jquery'],
            $asset_version,
            true
        );
    }
    if ( strpos( $hook, 'spar-referral-coupons' ) !== false || $current_page && $current_page === 'spar-referral-coupons' ) {
        wp_enqueue_style(
            'spar-admin-tables',
            SPAR_PLUGIN_URL . 'assets/css/admin-tables.css',
            ['spar-styles'],
            $asset_version
        );
        wp_enqueue_script(
            'spar-admin-tables',
            SPAR_PLUGIN_URL . 'assets/js/admin-tables.js',
            ['jquery'],
            $asset_version,
            true
        );
    }
    if ( strpos( $hook, 'spar-migration' ) !== false || $current_page && $current_page === 'spar-migration' ) {
        wp_enqueue_style(
            'spar-admin-migration',
            SPAR_PLUGIN_URL . 'assets/css/admin-migration.css',
            array('spar-styles'),
            SPAR_VERSION
        );
        wp_enqueue_script(
            'spar-admin-migration',
            SPAR_PLUGIN_URL . 'assets/js/admin-migration.js',
            ['jquery'],
            SPAR_VERSION,
            true
        );
        $active_plugins = ( function_exists( 'spar_migration_get_active_plugins' ) ? spar_migration_get_active_plugins() : array() );
        $plugin_labels = array();
        foreach ( $active_plugins as $key => $data ) {
            $label = ( isset( $data['label'] ) ? $data['label'] : $key );
            $plugin_labels[$key] = $label;
        }
        wp_localize_script( 'spar-admin-migration', 'sparMigration', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'spar_migration_nonce' ),
            'batchSize' => ( function_exists( 'spar_migration_get_batch_size' ) ? spar_migration_get_batch_size() : 10 ),
            'plugins'   => $plugin_labels,
            'supports'  => array(
                'userStatus' => ( function_exists( 'spar_migration_get_user_status_supported_plugins' ) ? array_values( spar_migration_get_user_status_supported_plugins() ) : array() ),
            ),
            'strings'   => array(
                'selectPlugin' => esc_html__( 'Please select a plugin before starting the migration.', 'simple-points-and-rewards' ),
                'starting'     => esc_html__( 'Starting migration from %1$s using %2$s mode.', 'simple-points-and-rewards' ),
                'completed'    => esc_html__( 'Migration completed. Please review and verify the amounts.', 'simple-points-and-rewards' ),
                'failed'       => esc_html__( 'Migration request failed: %s', 'simple-points-and-rewards' ),
                'modeAdd'      => esc_html__( 'add', 'simple-points-and-rewards' ),
                'modeOverride' => esc_html__( 'override', 'simple-points-and-rewards' ),
                'genericError' => esc_html__( 'Unexpected response from the server.', 'simple-points-and-rewards' ),
            ),
        ) );
    }
}

function spar_enqueue_frontend_assets() {
    // Determine if the current page needs plugin frontend assets.
    $on_rewards_page = is_account_page() || function_exists( 'spar_page_has_rewards_shortcode' ) && spar_page_has_rewards_shortcode();
    $on_shop_page = function_exists( 'is_shop' ) && is_shop() || function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() || is_post_type_archive( 'product' ) || is_search() && isset( $_GET['post_type'] ) && 'product' === sanitize_text_field( wp_unslash( $_GET['post_type'] ) );
    $needs_assets = $on_rewards_page || is_product() || is_cart() || is_checkout() || $on_shop_page;
    if ( !$needs_assets ) {
        return;
    }
    // Settings are only needed once we know assets will be enqueued (spar_options is not autoloaded).
    $options = get_option( 'spar_options', [] );
    // General plugin styles — only load on pages that use them.
    wp_enqueue_style(
        'spar-styles',
        SPAR_PLUGIN_URL . 'assets/css/reward-points.css',
        [],
        SPAR_VERSION
    );
    // Add dynamic CSS variables for theme colors (sanitize to valid hex colors).
    $theme_color_1_raw = ( isset( $options['rewards_theme_color_1'] ) && is_string( $options['rewards_theme_color_1'] ) && $options['rewards_theme_color_1'] !== '' ? $options['rewards_theme_color_1'] : '#667eea' );
    $theme_color_2_raw = ( isset( $options['rewards_theme_color_2'] ) && is_string( $options['rewards_theme_color_2'] ) && $options['rewards_theme_color_2'] !== '' ? $options['rewards_theme_color_2'] : '#764ba2' );
    $theme_accent_color_raw = ( isset( $options['rewards_theme_accent_color'] ) && is_string( $options['rewards_theme_accent_color'] ) && $options['rewards_theme_accent_color'] !== '' ? $options['rewards_theme_accent_color'] : '#2ca58d' );
    $theme_color_1 = ( sanitize_hex_color( $theme_color_1_raw ) ?: '#667eea' );
    $theme_color_2 = ( sanitize_hex_color( $theme_color_2_raw ) ?: '#764ba2' );
    $theme_accent_color = ( sanitize_hex_color( $theme_accent_color_raw ) ?: '#2ca58d' );
    $vars_css = ':root{--spar-theme-color-1:' . $theme_color_1 . ';--spar-theme-color-2:' . $theme_color_2 . ';--spar-theme-accent-color:' . $theme_accent_color . ';}';
    wp_add_inline_style( 'spar-styles', $vars_css );
    // Redeemed vouchers — only needed on account/shortcode pages, cart, and checkout.
    if ( $on_rewards_page || is_cart() || is_checkout() ) {
        wp_register_script(
            'spar-redeemed-vouchers',
            SPAR_PLUGIN_URL . 'assets/js/redeemed-vouchers.js',
            [],
            SPAR_VERSION,
            true
        );
        wp_register_style(
            'spar-redeemed-vouchers',
            SPAR_PLUGIN_URL . 'assets/css/redeemed-vouchers.css',
            [],
            SPAR_VERSION
        );
        wp_localize_script( 'spar-redeemed-vouchers', 'sparVouchers', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'spar_apply_voucher' ),
            'cartUrl' => ( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '' ),
            'i18n'    => [
                'applying'    => esc_html__( 'Applying...', 'simple-points-and-rewards' ),
                'applied'     => esc_html__( 'Applied!', 'simple-points-and-rewards' ),
                'saved'       => esc_html__( 'Saved for later', 'simple-points-and-rewards' ),
                'deferredMsg' => esc_html__( "We'll apply your voucher as soon as you add products to your cart.", 'simple-points-and-rewards' ),
                'error'       => esc_html__( 'Could not apply voucher. Please try again.', 'simple-points-and-rewards' ),
                'copied'      => esc_html__( 'Copied!', 'simple-points-and-rewards' ),
                'copyFailed'  => esc_html__( 'Could not copy the code. Please copy it manually.', 'simple-points-and-rewards' ),
            ],
        ] );
        wp_enqueue_style( 'spar-redeemed-vouchers' );
        wp_enqueue_script( 'spar-redeemed-vouchers' );
    }
    // Enqueue My Account / shortcode page assets.
    $should_enqueue = $on_rewards_page;
    // Register scripts and styles so they can be enqueued late if needed.
    wp_register_style(
        'spar-account-rewards',
        SPAR_PLUGIN_URL . 'assets/css/account-rewards.css',
        ['spar-styles'],
        SPAR_VERSION
    );
    wp_register_style(
        'spar-dashboard-dark-mode',
        SPAR_PLUGIN_URL . 'assets/css/dashboard-dark-mode.css',
        ['spar-account-rewards'],
        SPAR_VERSION
    );
    wp_register_style(
        'spar-referral-system-css',
        SPAR_PLUGIN_URL . 'assets/css/referral-system.css',
        ['spar-styles', 'spar-font-awesome'],
        SPAR_VERSION
    );
    wp_register_script(
        'spar-referral-system',
        SPAR_PLUGIN_URL . 'assets/js/referral-system.js',
        ['jquery'],
        SPAR_VERSION,
        true
    );
    wp_register_script(
        'spar-rewards-tabs',
        SPAR_PLUGIN_URL . 'assets/js/rewards-tabs.js',
        [],
        SPAR_VERSION,
        true
    );
    if ( $should_enqueue ) {
        // Localize script for referral system — only needed on account/shortcode pages.
        $referral_options = ( isset( $options['earn']['referral'] ) && is_array( $options['earn']['referral'] ) ? $options['earn']['referral'] : [] );
        $social_sharing = ( isset( $options['earn']['social_sharing'] ) && is_array( $options['earn']['social_sharing'] ) ? $options['earn']['social_sharing'] : [] );
        $tracking_mode = ( isset( $referral_options['coupon_tracking_mode'] ) ? $referral_options['coupon_tracking_mode'] : 'always_track' );
        $offer_enabled = !empty( $referral_options['offer_enabled'] );
        $referral_offer_enabled = $offer_enabled && in_array( $tracking_mode, ['always_track', 'flexible'], true );
        $social_points_enabled = !empty( $social_sharing['referral_social_points_enabled'] );
        $social_points = ( isset( $social_sharing['social_points'] ) ? (int) $social_sharing['social_points'] : 10 );
        $social_limit_mode = ( isset( $social_sharing['social_limit_mode'] ) ? (string) $social_sharing['social_limit_mode'] : 'per_social' );
        wp_localize_script( 'spar-referral-system', 'sparReferralSystem', [
            'strings'              => [
                'copy'      => esc_html__( 'Copy', 'simple-points-and-rewards' ),
                'copied'    => esc_html__( 'Copied!', 'simple-points-and-rewards' ),
                'copyError' => esc_html__( 'Copy Failed', 'simple-points-and-rewards' ),
            ],
            'ajaxurl'              => admin_url( 'admin-ajax.php' ),
            'referralNonce'        => wp_create_nonce( 'spar_referral_nonce' ),
            'referralOfferEnabled' => (bool) $referral_offer_enabled,
            'socialShare'          => [
                'enabled'   => (bool) $social_points_enabled,
                'points'    => $social_points,
                'limitMode' => $social_limit_mode,
                'nonce'     => wp_create_nonce( 'spar_social_share_nonce' ),
            ],
        ] );
        // Enqueue Font Awesome for better social icons
        wp_enqueue_style(
            'spar-font-awesome',
            SPAR_PLUGIN_URL . 'assets/fonts/font-awesome/css/all.min.css',
            [],
            '6.6.0'
        );
        // Account rewards styles (static file)
        wp_enqueue_style( 'spar-account-rewards' );
        wp_enqueue_style( 'spar-dashboard-dark-mode' );
        wp_enqueue_style( 'spar-referral-system-css' );
        wp_enqueue_script( 'spar-referral-system' );
        // Rewards tabs behavior
        wp_enqueue_script( 'spar-rewards-tabs' );
        // Pending points countdown (shared between dashboard and widget).
        wp_register_script(
            'spar-pending-countdown',
            SPAR_PLUGIN_URL . 'assets/js/pending-countdown.js',
            [],
            SPAR_VERSION,
            true
        );
        wp_enqueue_script( 'spar-pending-countdown' );
        // Localize dark mode settings for dashboard
        $dashboard_dark_mode_toggle = !empty( $options['dashboard_dark_mode_toggle'] );
        $dashboard_dark_mode_default = !empty( $options['dashboard_dark_mode_default'] );
        $dashboard_dark_mode_header = !empty( $options['dashboard_dark_mode_header'] );
        $dashboard_dark_mode_hide_toggle_when_default = !empty( $options['dashboard_dark_mode_hide_toggle_when_default'] );
        wp_localize_script( 'spar-rewards-tabs', 'sparDashboard', [
            'darkModeToggle'                => $dashboard_dark_mode_toggle,
            'darkModeDefault'               => $dashboard_dark_mode_default,
            'darkModeHeader'                => $dashboard_dark_mode_header,
            'darkModeHideToggleWhenDefault' => $dashboard_dark_mode_hide_toggle_when_default,
            'strings'                       => [
                'darkMode'  => esc_html__( 'Enable dark mode', 'simple-points-and-rewards' ),
                'lightMode' => esc_html__( 'Enable light mode', 'simple-points-and-rewards' ),
            ],
        ] );
    }
    // My Account: Confetti and voucher success assets after redeem
    $redeemed_param = filter_input( INPUT_GET, 'redeemed', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
    $redeemed_nonce = filter_input( INPUT_GET, 'redeemed_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
    if ( $on_rewards_page && !empty( $redeemed_param ) && $redeemed_nonce && wp_verify_nonce( $redeemed_nonce, 'spar_redeemed_notice' ) ) {
        wp_enqueue_script(
            'spar-confetti',
            SPAR_PLUGIN_URL . 'assets/js/confetti/confetti.browser.js',
            [],
            '1.9.3',
            true
        );
        wp_register_script(
            'spar-confetti-runner',
            SPAR_PLUGIN_URL . 'assets/js/confetti-runner.js',
            ['spar-confetti'],
            SPAR_VERSION,
            true
        );
        wp_enqueue_script( 'spar-confetti-runner' );
        // Voucher success notice styles and script
        wp_enqueue_style(
            'spar-voucher-success',
            SPAR_PLUGIN_URL . 'assets/css/voucher-success.css',
            [],
            SPAR_VERSION
        );
        wp_enqueue_script(
            'spar-voucher-success',
            SPAR_PLUGIN_URL . 'assets/js/voucher-success.js',
            ['jquery'],
            SPAR_VERSION,
            true
        );
    }
    // My Account: Points history AJAX pagination
    if ( $on_rewards_page ) {
        wp_register_script(
            'spar-points-history',
            SPAR_PLUGIN_URL . 'assets/js/points-history.js',
            ['jquery'],
            SPAR_VERSION,
            true
        );
        wp_localize_script( 'spar-points-history', 'sparPointsHistory', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'spar_points_history_nonce' ),
            'i18n'    => [
                'error' => esc_html__( 'Error loading history. Please try again.', 'simple-points-and-rewards' ),
            ],
        ] );
        wp_enqueue_script( 'spar-points-history' );
    }
    // My Account: Referral clicks AJAX pagination
    $show_referral_clicks_log = !empty( $referral_options['show_clicks_log'] );
    if ( $show_referral_clicks_log ) {
        wp_register_script(
            'spar-referral-clicks',
            SPAR_PLUGIN_URL . 'assets/js/referral-clicks.js',
            ['jquery'],
            SPAR_VERSION,
            true
        );
        wp_localize_script( 'spar-referral-clicks', 'sparReferralClicks', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'spar_referral_clicks_nonce' ),
            'i18n'    => [
                'error' => esc_html__( 'Error loading referral clicks. Please try again.', 'simple-points-and-rewards' ),
            ],
        ] );
        wp_enqueue_script( 'spar-referral-clicks' );
    }
}

/**
 * Render admin header for plugin pages
 */
function spar_render_admin_header(  $page_title  ) {
    // Include the header template
    $header_template = SPAR_PLUGIN_PATH . 'includes/admin/templates/header.php';
    if ( file_exists( $header_template ) ) {
        include $header_template;
    } else {
        // Fallback if template doesn't exist
        echo '<div class="wrap">';
        echo '<h1>' . esc_html( $page_title ) . '</h1>';
    }
}

add_action( 'show_user_profile', 'spar_user_profile_points_field' );
add_action( 'edit_user_profile', 'spar_user_profile_points_field' );
function spar_user_profile_points_field(  $user  ) {
    if ( !current_user_can( 'edit_users' ) ) {
        return;
    }
    $points = spar_get_user_points( $user->ID );
    $status = get_user_meta( $user->ID, 'spar_user_status', true );
    $allowed_statuses = ['active', 'banned'];
    if ( !in_array( $status, $allowed_statuses, true ) ) {
        $status = 'active';
    }
    wp_nonce_field( 'spar_update_user_points', 'spar_points_nonce' );
    ?>
	<h3><?php 
    esc_html_e( 'Reward Points', 'simple-points-and-rewards' );
    ?></h3>
	<table class="form-table">
		<tr>
			<th><label for="spar_points"><?php 
    esc_html_e( 'Points', 'simple-points-and-rewards' );
    ?></label></th>
			<td><input type="number" name="spar_points" id="spar_points" value="<?php 
    echo esc_attr( $points );
    ?>" /></td>
		</tr>
		<tr>
			<th><label for="spar_total_earned_points"><?php 
    esc_html_e( 'Total Earned Points', 'simple-points-and-rewards' );
    ?></label></th>
			<td>
				<?php 
    $total_earned = ( function_exists( 'spar_get_user_total_points_earned' ) ? spar_get_user_total_points_earned( $user->ID ) : 0 );
    ?>
				<input type="number" name="spar_total_earned_points" id="spar_total_earned_points" min="0" value="<?php 
    echo esc_attr( (int) $total_earned );
    ?>" />
				<button type="button" class="button button-secondary" id="spar-reset-total-earned">
					<?php 
    esc_html_e( 'Reset', 'simple-points-and-rewards' );
    ?>
				</button>
				<p class="description"><?php 
    esc_html_e( 'Lifetime points earned. Adjust manually or use Reset to set back to 0.', 'simple-points-and-rewards' );
    ?></p>
				<input type="hidden" name="spar_reset_total_earned" id="spar_reset_total_earned" value="0" />
				<script>
				(function(){
					var btn = document.getElementById('spar-reset-total-earned');
					if(!btn){ return; }
					var confirmMsg = <?php 
    echo wp_json_encode( wp_kses_decode_entities( esc_html__( 'Are you sure you want to reset this user\'s Total Earned points to 0? This action cannot be undone.', 'simple-points-and-rewards' ) ) );
    ?>;
					btn.addEventListener('click', function(e){
						if(window.confirm(confirmMsg)){
							var input = document.getElementById('spar_reset_total_earned');
							if(input){ input.value = '1'; }
							var totalField = document.getElementById('spar_total_earned_points');
							if(totalField){ totalField.value = '0'; }
							var form = btn.closest('form');
							if(form){ form.submit(); }
						}
					});
				})();
				</script>
			</td>
		</tr>
		<tr>
			<th><label for="spar_user_status"><?php 
    esc_html_e( 'Status', 'simple-points-and-rewards' );
    ?></label></th>
			<td>
				<select name="spar_user_status" id="spar_user_status">
					<option value="active" <?php 
    selected( 'active', $status );
    ?>><?php 
    esc_html_e( 'Active', 'simple-points-and-rewards' );
    ?></option>
					<option value="banned" <?php 
    selected( 'banned', $status );
    ?>><?php 
    esc_html_e( 'Banned', 'simple-points-and-rewards' );
    ?></option>
				</select>
				<p class="description">
					<?php 
    esc_html_e( 'Banned users cannot earn points or see rewards interfaces.', 'simple-points-and-rewards' );
    ?>
				</p>
			</td>
		</tr>
	</table>
	<?php 
}

add_action( 'personal_options_update', 'spar_save_user_profile_points' );
add_action( 'edit_user_profile_update', 'spar_save_user_profile_points' );
function spar_save_user_profile_points(  $user_id  ) {
    // Verify nonce and permissions
    if ( !isset( $_POST['spar_points_nonce'] ) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['spar_points_nonce'] ) ), 'spar_update_user_points' ) ) {
        return;
    }
    if ( !current_user_can( 'edit_users', $user_id ) ) {
        return;
    }
    // Handle reset of Total Earned Points (lifetime) when requested
    $reset_flag = ( isset( $_POST['spar_reset_total_earned'] ) ? sanitize_text_field( wp_unslash( $_POST['spar_reset_total_earned'] ) ) : '0' );
    if ( '1' === $reset_flag ) {
        update_user_meta( $user_id, '_spar_total_earned_points', 0 );
    } elseif ( isset( $_POST['spar_total_earned_points'] ) ) {
        $total_earned_points = absint( wp_unslash( $_POST['spar_total_earned_points'] ) );
        update_user_meta( $user_id, '_spar_total_earned_points', $total_earned_points );
    }
    if ( isset( $_POST['spar_points'] ) ) {
        $points = absint( $_POST['spar_points'] );
        // Route through the canonical writer so the change is atomic, logged,
        // and level-change/integration hooks fire. Uses the default
        // 'admin_set_balance' action id (an absolute override that does not count
        // toward lifetime "Total Earned"), keeping it distinct from the relative
        // 'admin_adjustment' add tools.
        spar_set_user_points_balance( $user_id, $points, esc_html__( 'Points balance set by admin (user profile)', 'simple-points-and-rewards' ) );
    }
    if ( isset( $_POST['spar_user_status'] ) ) {
        $status = sanitize_text_field( wp_unslash( $_POST['spar_user_status'] ) );
        $allowed_statuses = ['active', 'banned'];
        if ( !in_array( $status, $allowed_statuses, true ) ) {
            $status = 'active';
        }
        update_user_meta( $user_id, 'spar_user_status', $status );
    }
}

// Add meta box to WooCommerce order edit pages (supports both traditional and HPOS)
add_action( 'add_meta_boxes', 'spar_add_order_points_meta_box' );
function spar_add_order_points_meta_box(  $post_type = null  ) {
    // For traditional post-based orders
    if ( $post_type === 'shop_order' || is_admin() && function_exists( 'get_current_screen' ) && get_current_screen() && get_current_screen()->id === 'shop_order' ) {
        add_meta_box(
            'spar_order_points',
            esc_html__( 'Reward Points', 'simple-points-and-rewards' ),
            'spar_order_points_meta_box_callback',
            'shop_order',
            'side',
            'high'
        );
        // Separate meta box for Referral details
        add_meta_box(
            'spar_order_referral',
            esc_html__( 'Referral Order', 'simple-points-and-rewards' ),
            'spar_order_referral_meta_box_callback',
            'shop_order',
            'side',
            'high'
        );
    }
    // For HPOS orders
    if ( function_exists( 'wc_get_container' ) && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ) {
        add_meta_box(
            'spar_order_points',
            esc_html__( 'Reward Points', 'simple-points-and-rewards' ),
            'spar_order_points_meta_box_callback_hpos',
            wc_get_page_screen_id( 'shop-order' ),
            'side',
            'high'
        );
        // Referral meta box for HPOS
        add_meta_box(
            'spar_order_referral',
            esc_html__( 'Points Referral', 'simple-points-and-rewards' ),
            'spar_order_referral_meta_box_callback_hpos',
            wc_get_page_screen_id( 'shop-order' ),
            'side',
            'high'
        );
    }
}

function spar_order_points_meta_box_callback(  $post  ) {
    $order = wc_get_order( $post->ID );
    spar_render_order_points_content( $order );
}

function spar_order_points_meta_box_callback_hpos(  $order  ) {
    spar_render_order_points_content( $order );
}

function spar_order_referral_meta_box_callback(  $post  ) {
    $order = wc_get_order( $post->ID );
    spar_render_order_referral_content( $order );
}

function spar_order_referral_meta_box_callback_hpos(  $order  ) {
    spar_render_order_referral_content( $order );
}

function spar_render_order_referral_content(  $order  ) {
    if ( !$order ) {
        echo '<p>' . esc_html__( 'Invalid order.', 'simple-points-and-rewards' ) . '</p>';
        return;
    }
    $options = get_option( 'spar_options', [] );
    $points_label = ( isset( $options['points_label'] ) ? sanitize_text_field( $options['points_label'] ) : esc_html__( 'Points', 'simple-points-and-rewards' ) );
    $referrer_code = $order->get_meta( 'referrer_code' );
    $referral_points_awarded = (int) $order->get_meta( 'referral_points_awarded' );
    if ( !$referrer_code ) {
        echo '<p>' . esc_html__( 'No referral data for this order.', 'simple-points-and-rewards' ) . '</p>';
        return;
    }
    // Referred by
    echo '<p><strong>' . esc_html__( 'Referrer code:', 'simple-points-and-rewards' ) . '</strong> ' . esc_html( $referrer_code ) . '</p>';
    // Get the referrer user (if any)
    $referrer_val = spar_get_user_by_referral_code( $referrer_code );
    $referrer_id = ( is_object( $referrer_val ) ? (int) $referrer_val->ID : (int) $referrer_val );
    if ( $referrer_id > 0 ) {
        $referrer_user = get_userdata( $referrer_id );
        if ( $referrer_user ) {
            $ref_name = ( $referrer_user->display_name ? $referrer_user->display_name : $referrer_user->user_login );
            echo '<p><strong>' . esc_html__( 'Referrer User:', 'simple-points-and-rewards' ) . '</strong> ' . esc_html( $ref_name ) . '</p>';
        }
    }
    // Awarded points (if any)
    if ( $referral_points_awarded > 0 ) {
        echo '<p><strong>' . esc_html__( 'Referral points:', 'simple-points-and-rewards' ) . '</strong> ' . esc_html( (int) $referral_points_awarded ) . ' ' . esc_html( strtolower( $points_label ) ) . '</p>';
    }
}

function spar_render_order_points_content(  $order  ) {
    if ( !$order ) {
        echo '<p>' . esc_html__( 'Invalid order.', 'simple-points-and-rewards' ) . '</p>';
        return;
    }
    $options = get_option( 'spar_options', [] );
    $points_label = ( isset( $options['points_label'] ) ? sanitize_text_field( $options['points_label'] ) : esc_html__( 'Points', 'simple-points-and-rewards' ) );
    // Check if order points earning is enabled
    if ( empty( $options['earn']['order']['enabled'] ) ) {
        echo '<p>' . sprintf( 
            /* translators: %s: points label (lowercase) */
            esc_html__( 'Order %s earning is currently disabled.', 'simple-points-and-rewards' ),
            esc_html( strtolower( $points_label ) )
         ) . '</p>';
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=spar-settings' ) ) . '">' . esc_html__( 'Enable in Settings', 'simple-points-and-rewards' ) . '</a></p>';
        return;
    }
    // Check user has permission to view this information
    if ( !current_user_can( 'edit_shop_orders' ) ) {
        return;
    }
    $award_timing = ( isset( $options['earn']['order']['award_timing'] ) ? sanitize_text_field( $options['earn']['order']['award_timing'] ) : 'thankyou' );
    $completed_status = ( isset( $options['earn']['order']['completed_status'] ) ? sanitize_key( $options['earn']['order']['completed_status'] ) : 'completed' );
    if ( empty( $completed_status ) ) {
        $completed_status = 'completed';
    }
    $user_id = $order->get_user_id();
    $order_status = $order->get_status();
    $is_terminal_order = in_array( $order_status, ['refunded', 'cancelled', 'failed'], true );
    // Check if points have been awarded or deducted.
    $points_awarded = $order->get_meta( 'points_earned' );
    $points_deducted = $order->get_meta( 'points_deducted' );
    $show_potential_points = !$points_awarded && !$points_deducted && !$is_terminal_order;
    $potential_points = 0;
    if ( $show_potential_points ) {
        // Calculate potential points using helper (respects multi-currency via premium filter).
        $potential_points = ( function_exists( 'spar_calculate_order_base_points' ) ? spar_calculate_order_base_points( $order ) : 0 );
        // Include first-order bonus from order meta if it exists, otherwise check if user qualifies.
        $first_order_bonus_meta = (int) $order->get_meta( 'points_first_order_bonus' );
        if ( $first_order_bonus_meta > 0 ) {
            $potential_points += $first_order_bonus_meta;
        } else {
            if ( $user_id && function_exists( 'spar_user_qualifies_for_first_order_bonus' ) ) {
                if ( spar_user_qualifies_for_first_order_bonus( $user_id ) ) {
                    $first_order_points = (int) ($options['earn']['first_order']['points'] ?? 0);
                    if ( $first_order_points > 0 ) {
                        $potential_points += $first_order_points;
                    }
                }
            }
        }
        /**
         * Filter the potential points shown in the order meta box, so integrations
         * that adjust the awarded amount (e.g. subscription renewal rates) can keep
         * the displayed value in line with what will actually be awarded.
         *
         * @param int      $potential_points Potential points to display.
         * @param WC_Order $order            Order object.
         */
        $potential_points = max( 0, (int) apply_filters( 'spar_order_potential_points_display', $potential_points, $order ) );
    }
    echo '<div class="spar-order-points-info">';
    // Order total and calculation
    if ( $show_potential_points ) {
        echo '<p><strong>' . esc_html__( 'Potential Points:', 'simple-points-and-rewards' ) . '</strong> <span id="spar-potential-points-display">' . esc_html( $potential_points ) . '</span>';
        // Add Modify button
        echo ' &nbsp; ' . '<button type="button" class="button button-small" id="spar-modify-potential-points">' . esc_html__( 'Modify', 'simple-points-and-rewards' ) . '</button>';
        echo '</p>';
        // Custom points input (initially hidden and disabled)
        echo '<div id="spar-custom-points-container" style="display:none; margin-bottom: 10px;">';
        echo '<input type="number" name="spar_custom_potential_points" id="spar_custom_potential_points" value="' . esc_attr( $potential_points ) . '" style="width: 80px;" />';
        wp_nonce_field( 'spar_save_order_points_meta', 'spar_order_points_meta_nonce' );
        echo ' <button type="button" class="button button-primary button-small" id="spar-save-potential-points" data-order-id="' . esc_attr( $order->get_id() ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'spar_save_potential_points' ) ) . '">' . esc_html__( 'Save', 'simple-points-and-rewards' ) . '</button>';
        echo ' <button type="button" class="button button-small" id="spar-cancel-modify-points">' . esc_html__( 'Cancel', 'simple-points-and-rewards' ) . '</button>';
        echo '<span class="spinner" style="float:none; margin-top: 0;"></span>';
        echo '</div>';
    }
    // Award status
    if ( $points_deducted ) {
        echo '<p>';
        echo '<strong>↩️ ' . esc_html__( 'Points Deducted:', 'simple-points-and-rewards' ) . '</strong> ' . esc_html( $points_deducted ) . ' ' . esc_html( strtolower( $points_label ) );
        echo '<br /><small>' . esc_html__( 'Points were deducted due to order refund/cancellation', 'simple-points-and-rewards' ) . '</small>';
        echo '</p>';
    } elseif ( $points_awarded ) {
        echo '<p>';
        echo '<strong>' . esc_html__( 'Points Awarded:', 'simple-points-and-rewards' ) . '</strong> ' . esc_html( $points_awarded ) . ' ' . esc_html( strtolower( $points_label ) );
        echo '</p>';
    } else {
        if ( $user_id ) {
            $status_icon = '⏳';
            $status_message = esc_html__( 'Points not yet awarded', 'simple-points-and-rewards' );
            $status_class = 'notice-warning';
            $show_award_button = !$is_terminal_order;
            // Check order status to determine if points should have been awarded
            if ( $is_terminal_order ) {
                $status_icon = '';
                $status_message = esc_html__( 'Order is refunded/cancelled/failed; points cannot be awarded.', 'simple-points-and-rewards' );
                $status_class = 'notice-warning';
                $show_award_button = false;
            } elseif ( $award_timing === 'thankyou' ) {
                // Points should be awarded immediately, so if not awarded something might be wrong
                if ( in_array( $order_status, ['processing', 'completed', 'on-hold'], true ) ) {
                    $status_icon = '❌';
                    $status_message = esc_html__( 'Points should have been awarded but were not', 'simple-points-and-rewards' );
                    $status_class = 'notice-error';
                    $show_award_button = true;
                }
            } elseif ( $award_timing === 'completed' && $order_status === $completed_status ) {
                // Order is completed but points not awarded. This branch only runs
                // when nothing has been awarded yet, so gate the remediation button
                // on whether the order actually has points to grant ($potential_points),
                // not $points_awarded (which is always empty here).
                if ( $potential_points > 0 ) {
                    $status_icon = '❌';
                    $status_message = esc_html__( 'Order completed but points not awarded', 'simple-points-and-rewards' );
                    $status_class = 'notice-error';
                    $show_award_button = true;
                } else {
                    $status_icon = '';
                    $status_message = esc_html__( 'No points earned for this order', 'simple-points-and-rewards' );
                    $status_class = 'notice-warning';
                    $show_award_button = false;
                }
            }
            echo '<p style="background-color: #fff3cd; padding: 10px; border-left: 4px solid #ffeeba;" class="' . esc_attr( $status_class ) . '">';
            echo '<strong>' . esc_html( trim( $status_icon . ' ' . $status_message ) ) . '</strong>';
            if ( $show_award_button ) {
                $award_nonce = wp_create_nonce( 'spar_award_order_points' );
                echo '<br /><br />';
                echo '<button type="button" class="button button-small spar-award-order-points" data-order-id="' . esc_attr( $order->get_id() ) . '" data-nonce="' . esc_attr( $award_nonce ) . '">' . esc_html__( 'Grant points now', 'simple-points-and-rewards' ) . '</button>';
                echo '<span class="spinner" style="float:none; margin-left: 6px;"></span>';
            }
            echo '</p>';
        }
    }
    // Show customer info if logged in
    if ( $user_id ) {
        $user = get_userdata( $user_id );
        if ( $user ) {
            $current_points = spar_get_user_points( $user_id );
            echo '<p><strong>' . esc_html__( 'Customer:', 'simple-points-and-rewards' ) . '</strong> ' . esc_html( $user->display_name ) . '</p>';
            echo '<p style="margin-bottom: 0;"><strong>' . esc_html__( 'Current Points:', 'simple-points-and-rewards' ) . '</strong> ' . esc_html( $current_points ) . '</p>';
        }
    }
    /**
     * Fires after the order points meta box content is rendered.
     *
     * @param WC_Order $order The order object.
     */
    do_action( 'spar_after_order_points_meta_box', $order );
    echo '</div>';
}

function spar_enqueue_admin_scripts(  $hook  ) {
    // Enqueue on order edit pages
    $screen = ( function_exists( 'get_current_screen' ) ? get_current_screen() : null );
    if ( $screen && ('shop_order' === $screen->id || 'woocommerce_page_wc-orders' === $screen->id) ) {
        wp_enqueue_script(
            'spar-admin-order',
            plugin_dir_url( __DIR__ ) . 'assets/js/admin-order.js',
            ['jquery'],
            SPAR_VERSION,
            true
        );
    }
    // Only load on our settings page
    if ( $hook !== 'toplevel_page_spar-settings' ) {
        return;
    }
    $asset_version = ( defined( 'SPAR_VERSION' ) ? SPAR_VERSION : '1.1' );
    $admin_settings_css_path = plugin_dir_path( __DIR__ ) . 'assets/css/admin-settings.css';
    $admin_settings_css_version = ( file_exists( $admin_settings_css_path ) ? filemtime( $admin_settings_css_path ) : $asset_version );
    wp_enqueue_style(
        'spar-admin-settings',
        plugin_dir_url( __DIR__ ) . 'assets/css/admin-settings.css',
        [],
        $admin_settings_css_version
    );
    wp_enqueue_script(
        'spar-admin-settings',
        plugin_dir_url( __DIR__ ) . 'assets/js/admin-settings.js',
        ['jquery'],
        $asset_version,
        true
    );
    // Do NOT re-localize 'sparSettings' here; primary localization occurs in spar_enqueue_admin_assets.
    // Re-localizing was overwriting settingsNonce causing AJAX saves (e.g. levels_enabled) to fail and revert.
    // If for some reason the primary localization did not run, provide a minimal fallback without nonce (read-only UI).
    wp_add_inline_script( 'spar-admin-settings', "if(!window.sparSettings){window.sparSettings={ajaxUrl:'" . esc_js( admin_url( 'admin-ajax.php' ) ) . "'};}" );
}

add_action( 'admin_enqueue_scripts', 'spar_enqueue_admin_scripts' );
// AJAX handler for updating customer points
add_action( 'wp_ajax_spar_update_customer_points', 'spar_handle_update_customer_points_ajax' );
// AJAX handler to update user status (active/banned)
add_action( 'wp_ajax_spar_update_user_status', 'spar_handle_update_user_status_ajax' );
// Add AJAX handler for points history pagination
add_action( 'wp_ajax_spar_load_points_history', 'spar_handle_load_points_history_ajax' );
// Add AJAX handler for referral clicks pagination
add_action( 'wp_ajax_spar_load_referral_clicks', 'spar_handle_load_referral_clicks_ajax' );
add_action( 'wp_ajax_spar_admin_load_points_history', 'spar_handle_admin_load_points_history_ajax' );
add_action( 'wp_ajax_spar_admin_load_referral_clicks', 'spar_handle_admin_load_referral_clicks_ajax' );
add_action( 'wp_ajax_spar_admin_load_customer_orders', 'spar_handle_admin_load_customer_orders_ajax' );
// AJAX: Search products for the Rewards settings "Free Product" select
add_action( 'wp_ajax_spar_search_products', 'spar_ajax_search_products' );
function spar_ajax_search_products() {
    // Verify nonce from request (supports GET or POST)
    $nonce = ( isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '' );
    if ( !$nonce || !wp_verify_nonce( $nonce, 'spar_search_products' ) ) {
        wp_send_json_error( [
            'message' => esc_html__( 'Invalid request', 'simple-points-and-rewards' ),
        ], 403 );
    }
    // Capability check: limit to store managers/admins
    if ( !current_user_can( 'manage_woocommerce' ) && !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => esc_html__( 'Not allowed', 'simple-points-and-rewards' ),
        ], 403 );
    }
    // Search term from SelectWoo (sent as `q`)
    $term = ( isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '' );
    $results = [];
    if ( $term !== '' ) {
        // Prefer WooCommerce products; basic title search via WP_Query for reliability
        $args = [
            'post_type'           => ['product'],
            's'                   => $term,
            'post_status'         => ['publish'],
            'posts_per_page'      => 20,
            'orderby'             => 'title',
            'order'               => 'ASC',
            'ignore_sticky_posts' => true,
            'fields'              => 'ids',
            'suppress_filters'    => false,
        ];
        $query = new WP_Query($args);
        if ( $query->have_posts() ) {
            foreach ( $query->posts as $product_id ) {
                $product = ( function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null );
                if ( !$product ) {
                    continue;
                }
                $name = $product->get_name();
                $results[] = [
                    'id'   => (int) $product_id,
                    'text' => sprintf( '%1$s (#%2$d)', $name, (int) $product_id ),
                ];
            }
        }
        wp_reset_postdata();
    }
    // Shape for Select2/SelectWoo: [{id, text}, ...]
    wp_send_json_success( $results );
}

add_action( 'wp_ajax_spar_get_reward_preview_image', 'spar_ajax_get_reward_preview_image' );
/**
 * Return the product image (or bundle carousel) markup for the reward icon preview
 * shown in the rewards settings accordion. Mirrors the rewards dashboard output.
 */
function spar_ajax_get_reward_preview_image() {
    $nonce = ( isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '' );
    if ( !$nonce || !wp_verify_nonce( $nonce, 'spar_search_products' ) ) {
        wp_send_json_error( [
            'message' => esc_html__( 'Invalid request', 'simple-points-and-rewards' ),
        ], 403 );
    }
    if ( !current_user_can( 'manage_woocommerce' ) && !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [
            'message' => esc_html__( 'Not allowed', 'simple-points-and-rewards' ),
        ], 403 );
    }
    if ( !function_exists( 'wc_get_product' ) ) {
        wp_send_json_error( [
            'message' => esc_html__( 'WooCommerce is not available', 'simple-points-and-rewards' ),
        ], 400 );
    }
    $type = ( isset( $_REQUEST['type'] ) ? sanitize_key( wp_unslash( $_REQUEST['type'] ) ) : '' );
    $raw_ids = ( isset( $_REQUEST['product_ids'] ) ? (array) wp_unslash( $_REQUEST['product_ids'] ) : array() );
    $ids = array_values( array_filter( array_map( 'absint', $raw_ids ) ) );
    $html = '';
    if ( 'product' === $type && !empty( $ids ) ) {
        $product = wc_get_product( $ids[0] );
        if ( $product ) {
            $html = $product->get_image( 'thumbnail', array(
                'style' => 'max-height: 50px; width: auto; margin: 0 auto; border-radius: 5px;',
            ) );
        }
    } elseif ( 'product_bundle' === $type && !empty( $ids ) && function_exists( 'spar_render_bundle_image_carousel' ) ) {
        $products = array();
        foreach ( $ids as $id ) {
            $product = wc_get_product( $id );
            if ( $product ) {
                $products[] = $product;
            }
        }
        $html = spar_render_bundle_image_carousel( $products );
    }
    wp_send_json_success( [
        'html' => $html,
    ] );
}

function spar_handle_load_points_history_ajax() {
    // Verify nonce
    check_ajax_referer( 'spar_points_history_nonce', 'nonce', true );
    $user_id = get_current_user_id();
    if ( !$user_id ) {
        wp_send_json_error( esc_html__( 'User not logged in.', 'simple-points-and-rewards' ) );
    }
    $page = ( isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1 );
    $per_page = 10;
    $status_filter = ( isset( $_POST['status_filter'] ) ? sanitize_key( wp_unslash( $_POST['status_filter'] ) ) : '' );
    if ( !in_array( $status_filter, array(
        '',
        'earned',
        'spent',
        'pending'
    ), true ) ) {
        $status_filter = '';
    }
    // Get points label
    $options = get_option( 'spar_options', [] );
    $points_label = $options['points_label'] ?? esc_html__( 'Points', 'simple-points-and-rewards' );
    $pending_handled = false;
    if ( !$pending_handled ) {
        if ( 'pending' === $status_filter ) {
            $status_filter = '';
        }
        $type_filter_map = array(
            'earned' => 'add',
            'spent'  => 'remove',
        );
        $type_filter = ( isset( $type_filter_map[$status_filter] ) ? $type_filter_map[$status_filter] : '' );
        $history_data = spar_get_user_points_history(
            $user_id,
            $page,
            $per_page,
            array(
                'type_filter' => $type_filter,
            )
        );
        $logs = $history_data['logs'];
        $pagination = $history_data['pagination'];
    }
    // Generate timeline HTML
    $table_html = '';
    if ( !empty( $logs ) ) {
        $current_date_group = '';
        foreach ( $logs as $log ) {
            $date_group = date_i18n( 'Y-m-d', strtotime( $log['date'] ) );
            if ( $date_group !== $current_date_group ) {
                $current_date_group = $date_group;
                $table_html .= sprintf( '<div class="spar-tl-date-sep"><span>%s</span></div>', esc_html( date_i18n( 'M j', strtotime( $log['date'] ) ) ) );
            }
            $table_html .= spar_build_history_timeline_item( $log, $points_label );
        }
    } else {
        $empty_text = ( 'pending' === $status_filter ? esc_html__( 'No pending points at the moment.', 'simple-points-and-rewards' ) : sprintf( 
            /* translators: %s: points label (lowercase) */
            esc_html__( 'No %s history yet. Start earning points by making purchases!', 'simple-points-and-rewards' ),
            esc_html( strtolower( $points_label ) )
         ) );
        $table_html = sprintf( '<div class="spar-tl-empty"><span class="spar-tl-empty-icon">📋</span><span class="spar-tl-empty-text">%s</span></div>', $empty_text );
    }
    // Generate pagination HTML
    $pagination_html = '';
    if ( $pagination['total_pages'] > 1 ) {
        $pagination_html .= '<div class="spar-pagination-controls">';
        // Previous button
        $prev_disabled = ( !$pagination['has_previous'] ? 'disabled' : '' );
        $pagination_html .= sprintf(
            '<button class="spar-pagination-btn spar-pagination-prev" data-page="%d" %s>%s</button>',
            $pagination['current_page'] - 1,
            $prev_disabled,
            esc_html__( '← Previous', 'simple-points-and-rewards' )
        );
        // Page number buttons
        $current_page = (int) $pagination['current_page'];
        $total_pages = (int) $pagination['total_pages'];
        $start_page = max( 1, $current_page - 2 );
        $end_page = min( $total_pages, $current_page + 2 );
        if ( $start_page === 1 ) {
            $end_page = min( $total_pages, 5 );
        } elseif ( $end_page === $total_pages ) {
            $start_page = max( 1, $total_pages - 4 );
        }
        for ($i = $start_page; $i <= $end_page; $i++) {
            $is_current = $i === $current_page;
            $btn_class = ( $is_current ? 'spar-pagination-btn spar-pagination-current' : 'spar-pagination-btn spar-pagination-page' );
            $btn_disabled = ( $is_current ? 'disabled' : '' );
            $pagination_html .= sprintf(
                '<button class="%s" data-page="%d" %s>%d</button>',
                esc_attr( $btn_class ),
                $i,
                $btn_disabled,
                $i
            );
        }
        // Next button
        $next_disabled = ( !$pagination['has_next'] ? 'disabled' : '' );
        $pagination_html .= sprintf(
            '<button class="spar-pagination-btn spar-pagination-next" data-page="%d" %s>%s</button>',
            $pagination['current_page'] + 1,
            $next_disabled,
            esc_html__( 'Next →', 'simple-points-and-rewards' )
        );
        $pagination_html .= '</div>';
        $pagination_html .= '<div class="spar-pagination-loading"><span>' . esc_html__( 'Loading...', 'simple-points-and-rewards' ) . '</span></div>';
    }
    wp_send_json_success( array(
        'table_html'      => $table_html,
        'pagination_html' => $pagination_html,
        'current_page'    => $pagination['current_page'],
        'total_pages'     => $pagination['total_pages'],
        'status_filter'   => $status_filter,
    ) );
}

function spar_handle_load_referral_clicks_ajax() {
    // Verify nonce
    check_ajax_referer( 'spar_referral_clicks_nonce', 'nonce', true );
    $user_id = get_current_user_id();
    if ( !$user_id ) {
        wp_send_json_error( esc_html__( 'User not logged in.', 'simple-points-and-rewards' ) );
    }
    $options = get_option( 'spar_options', [] );
    $show_clicks_log = !empty( $options['earn']['referral']['show_clicks_log'] );
    if ( !$show_clicks_log ) {
        wp_send_json_error( esc_html__( 'Referral clicks log is disabled.', 'simple-points-and-rewards' ) );
    }
    $page = max( 1, intval( $_POST['page'] ?? 1 ) );
    $context = ( isset( $_POST['context'] ) ? sanitize_text_field( wp_unslash( $_POST['context'] ) ) : 'dashboard' );
    if ( !in_array( $context, ['dashboard', 'widget'], true ) ) {
        $context = 'dashboard';
    }
    $per_page = 5;
    if ( !function_exists( 'spar_get_user_referral_clicks_history' ) ) {
        wp_send_json_error( esc_html__( 'Referral clicks unavailable.', 'simple-points-and-rewards' ) );
    }
    $history_data = spar_get_user_referral_clicks_history( $user_id, $page, $per_page );
    $logs = $history_data['logs'];
    $pagination = $history_data['pagination'];
    $table_html = ( function_exists( 'spar_build_referral_clicks_table_rows' ) ? spar_build_referral_clicks_table_rows( $logs, $context ) : '' );
    $pagination_html = ( function_exists( 'spar_build_referral_clicks_pagination_html' ) ? spar_build_referral_clicks_pagination_html( $pagination, $context ) : '' );
    wp_send_json_success( [
        'table_html'      => $table_html,
        'pagination_html' => $pagination_html,
    ] );
}

function spar_handle_update_customer_points_ajax() {
    // Verify nonce
    check_ajax_referer( 'spar_update_points_nonce', 'nonce', true );
    // Check permissions
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( esc_html__( 'Insufficient permissions.', 'simple-points-and-rewards' ) );
    }
    $user_id = ( isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0 );
    $points_amount = ( isset( $_POST['points'] ) ? absint( $_POST['points'] ) : 0 );
    $action = ( isset( $_POST['points_action'] ) ? sanitize_key( wp_unslash( $_POST['points_action'] ) ) : 'set' );
    // Reason: accept custom text when provided; fallback to default when missing/blank
    $reason_raw = ( isset( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : '' );
    $reason_trimmed = ( is_string( $reason_raw ) ? trim( $reason_raw ) : '' );
    $reason = ( $reason_trimmed !== '' ? sanitize_text_field( $reason_trimmed ) : esc_html__( 'Admin adjustment', 'simple-points-and-rewards' ) );
    if ( !$user_id || !get_userdata( $user_id ) ) {
        wp_send_json_error( esc_html__( 'Invalid user ID.', 'simple-points-and-rewards' ) );
    }
    // Perform update using core helper which also logs the change
    if ( 'add' === $action || 'remove' === $action ) {
        spar_update_user_points(
            $user_id,
            $points_amount,
            ( 'add' === $action ? 'add' : 'remove' ),
            $reason,
            'admin_adjustment'
        );
    } else {
        // Fallback: set absolute by applying the delta via helper
        $current_points = spar_get_user_points( $user_id );
        $delta = (int) $points_amount - (int) $current_points;
        if ( 0 !== $delta ) {
            $delta_action = ( $delta > 0 ? 'add' : 'remove' );
            spar_update_user_points(
                $user_id,
                abs( $delta ),
                $delta_action,
                $reason,
                'admin_adjustment'
            );
        }
    }
    // Fetch new balance to return
    $new_points = spar_get_user_points( $user_id );
    $total_earned = ( function_exists( 'spar_get_user_total_points_earned' ) ? spar_get_user_total_points_earned( $user_id ) : 0 );
    wp_send_json_success( [
        'message'      => sprintf( 
            /* translators: %d: updated points balance */
            esc_html__( 'Points updated successfully. User now has %d points.', 'simple-points-and-rewards' ),
            $new_points
         ),
        'new_points'   => $new_points,
        'total_earned' => $total_earned,
    ] );
}

/**
 * AJAX: Update user status (Active/Banned)
 */
function spar_handle_update_user_status_ajax() {
    check_ajax_referer( 'spar_update_user_status', 'nonce', true );
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( esc_html__( 'Insufficient permissions.', 'simple-points-and-rewards' ) );
    }
    $user_id = ( isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0 );
    $status = ( isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'active' );
    if ( !$user_id || !get_userdata( $user_id ) ) {
        wp_send_json_error( esc_html__( 'Invalid user ID.', 'simple-points-and-rewards' ) );
    }
    if ( !in_array( $status, array('active', 'banned'), true ) ) {
        wp_send_json_error( esc_html__( 'Invalid status.', 'simple-points-and-rewards' ) );
    }
    update_user_meta( $user_id, 'spar_user_status', $status );
    wp_send_json_success( array(
        'status' => $status,
    ) );
}

/**
 * AJAX: Create a new Rewards Dashboard page with the shortcode.
 */
function spar_ajax_generate_rewards_page() {
    // Allow Admins (manage_options) and Shop Managers (manage_woocommerce)
    if ( !current_user_can( 'manage_woocommerce' ) && !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Insufficient permissions', 'simple-points-and-rewards' ) );
    }
    check_ajax_referer( 'spar_generate_rewards_page', 'nonce', true );
    $title = ( isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : esc_html__( 'Rewards', 'simple-points-and-rewards' ) );
    // Choose a safe status depending on capabilities
    $status = 'draft';
    if ( current_user_can( 'publish_pages' ) ) {
        $status = 'publish';
    } elseif ( !current_user_can( 'edit_pages' ) ) {
        wp_send_json_error( esc_html__( 'Your role cannot create pages. Please create a page manually and add the shortcode.', 'simple-points-and-rewards' ) );
    }
    $page_id = wp_insert_post( [
        'post_title'   => $title,
        'post_content' => '[spar_points_rewards]',
        'post_status'  => $status,
        'post_type'    => 'page',
    ] );
    if ( is_wp_error( $page_id ) || !$page_id ) {
        wp_send_json_error( esc_html__( 'Failed to create page', 'simple-points-and-rewards' ) );
    }
    // Save option
    spar_update_single_option( 'rewards_page_id', absint( $page_id ) );
    wp_send_json_success( [
        'id'     => $page_id,
        'url'    => get_permalink( $page_id ),
        'title'  => get_the_title( $page_id ),
        'status' => $status,
    ] );
}

add_action( 'wp_ajax_spar_generate_rewards_page', 'spar_ajax_generate_rewards_page' );
/**
 * AJAX: List all pages that contain the [spar_points_rewards] shortcode.
 */
function spar_ajax_list_rewards_pages() {
    // Allow Admins (manage_options) and Shop Managers (manage_woocommerce)
    if ( !current_user_can( 'manage_woocommerce' ) && !current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Insufficient permissions', 'simple-points-and-rewards' ) );
    }
    check_ajax_referer( 'spar_list_rewards_pages', 'nonce', true );
    $pages = get_pages( array(
        'sort_column' => 'post_title',
        'post_status' => array('publish', 'draft'),
    ) );
    $result = array();
    if ( !empty( $pages ) ) {
        foreach ( $pages as $page ) {
            if ( function_exists( 'has_shortcode' ) && has_shortcode( $page->post_content, 'spar_points_rewards' ) ) {
                $result[] = array(
                    'id'    => $page->ID,
                    'title' => $page->post_title,
                );
            }
        }
    }
    wp_send_json_success( array(
        'pages' => $result,
    ) );
}

add_action( 'wp_ajax_spar_list_rewards_pages', 'spar_ajax_list_rewards_pages' );
/**
 * AJAX: Get page link for selected page ID.
 */
function spar_ajax_get_page_link() {
    // Match plugin menu capability so Shop Managers can use this tool
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( esc_html__( 'Insufficient permissions', 'simple-points-and-rewards' ) );
    }
    check_ajax_referer( 'spar_retrieve_page_link', 'nonce', true );
    $page_id = ( isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0 );
    if ( !$page_id ) {
        wp_send_json_success( [
            'url' => '',
        ] );
    }
    $url = get_permalink( $page_id );
    if ( !$url ) {
        wp_send_json_error( esc_html__( 'Invalid page', 'simple-points-and-rewards' ) );
    }
    wp_send_json_success( [
        'url' => $url,
    ] );
}

add_action( 'wp_ajax_spar_get_page_link', 'spar_ajax_get_page_link' );
/**
 * AJAX: Get admin edit link for a coupon ID (used by Template Coupon select).
 */
function spar_ajax_get_coupon_edit_link() {
    // Capability: match settings page capability (Shop Managers usually have manage_woocommerce)
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( esc_html__( 'Insufficient permissions', 'simple-points-and-rewards' ) );
    }
    check_ajax_referer( 'spar_get_coupon_edit_link', 'nonce', true );
    $coupon_id = ( isset( $_POST['coupon_id'] ) ? absint( $_POST['coupon_id'] ) : 0 );
    if ( !$coupon_id ) {
        wp_send_json_success( [
            'url'  => '',
            'text' => esc_html__( 'No template selected.', 'simple-points-and-rewards' ),
        ] );
    }
    $post_type = get_post_type( $coupon_id );
    if ( 'shop_coupon' !== $post_type ) {
        wp_send_json_error( esc_html__( 'Invalid coupon.', 'simple-points-and-rewards' ) );
    }
    $edit_url = admin_url( 'post.php?post=' . $coupon_id . '&action=edit' );
    $title = get_the_title( $coupon_id );
    $text = sprintf( esc_html__( 'Edit coupon: %1$s (#%2$d)', 'simple-points-and-rewards' ), $title, $coupon_id );
    wp_send_json_success( [
        'url'  => $edit_url,
        'text' => $text,
    ] );
}

add_action( 'wp_ajax_spar_get_coupon_edit_link', 'spar_ajax_get_coupon_edit_link' );
/**
 * AJAX: Create a new blank template coupon for use as a reward template.
 */
add_action( 'wp_ajax_spar_create_template_coupon', 'spar_ajax_create_template_coupon' );
function spar_ajax_create_template_coupon() {
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( esc_html__( 'Insufficient permissions', 'simple-points-and-rewards' ) );
    }
    check_ajax_referer( 'spar_create_template_coupon', 'nonce', true );
    $random = strtolower( wp_generate_password( 8, false, false ) );
    $coupon_code = 'template_' . $random;
    $coupon_id = wp_insert_post( [
        'post_title'   => $coupon_code,
        'post_content' => '',
        'post_status'  => 'publish',
        'post_author'  => get_current_user_id(),
        'post_type'    => 'shop_coupon',
    ] );
    if ( is_wp_error( $coupon_id ) ) {
        wp_send_json_error( $coupon_id->get_error_message() );
    }
    // Set sensible defaults so it shows up properly in WooCommerce.
    update_post_meta( $coupon_id, 'discount_type', 'fixed_cart' );
    update_post_meta( $coupon_id, 'coupon_amount', '0' );
    update_post_meta( $coupon_id, 'individual_use', 'no' );
    update_post_meta( $coupon_id, 'usage_limit', '' );
    update_post_meta( $coupon_id, 'usage_limit_per_user', '' );
    update_post_meta( $coupon_id, 'usage_count', '0' );
    update_post_meta( $coupon_id, 'free_shipping', 'no' );
    $edit_url = get_edit_post_link( $coupon_id, 'raw' );
    wp_send_json_success( [
        'coupon_id'    => $coupon_id,
        'coupon_title' => $coupon_code,
        'edit_url'     => ( $edit_url ? esc_url( $edit_url ) : '' ),
    ] );
}

// Save custom potential points meta
add_action(
    'woocommerce_process_shop_order_meta',
    'spar_save_order_points_meta',
    10,
    2
);
function spar_save_order_points_meta(  $post_id, $post  ) {
    // Verify nonce
    if ( !isset( $_POST['spar_order_points_meta_nonce'] ) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['spar_order_points_meta_nonce'] ) ), 'spar_save_order_points_meta' ) ) {
        return;
    }
    if ( isset( $_POST['spar_custom_potential_points'] ) ) {
        $custom_points = absint( $_POST['spar_custom_potential_points'] );
        update_post_meta( $post_id, 'custom_potential_points', $custom_points );
    }
}

// AJAX handler for saving custom potential points
add_action( 'wp_ajax_spar_save_custom_potential_points', 'spar_save_custom_potential_points_ajax' );
function spar_save_custom_potential_points_ajax() {
    check_ajax_referer( 'spar_save_potential_points', 'nonce' );
    if ( !current_user_can( 'edit_shop_orders' ) && !current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    $order_id = ( isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0 );
    $points = ( isset( $_POST['points'] ) ? absint( $_POST['points'] ) : 0 );
    if ( !$order_id ) {
        wp_send_json_error( 'Invalid order ID' );
    }
    $order = wc_get_order( $order_id );
    if ( !$order ) {
        wp_send_json_error( 'Order not found' );
    }
    // Check if points already awarded
    if ( $order->get_meta( 'points_earned' ) ) {
        wp_send_json_error( 'Points already awarded for this order' );
    }
    $order->update_meta_data( 'custom_potential_points', $points );
    $order->save();
    wp_send_json_success();
}

// AJAX handler for manually awarding order points in admin.
add_action( 'wp_ajax_spar_award_order_points_admin', 'spar_award_order_points_admin_ajax' );
function spar_award_order_points_admin_ajax() {
    check_ajax_referer( 'spar_award_order_points', 'nonce' );
    if ( !current_user_can( 'edit_shop_orders' ) && !current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( esc_html__( 'Permission denied', 'simple-points-and-rewards' ), 403 );
    }
    $order_id = ( isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0 );
    if ( !$order_id ) {
        wp_send_json_error( esc_html__( 'Invalid order ID', 'simple-points-and-rewards' ) );
    }
    $order = wc_get_order( $order_id );
    if ( !$order ) {
        wp_send_json_error( esc_html__( 'Order not found', 'simple-points-and-rewards' ) );
    }
    $options = get_option( 'spar_options', [] );
    $completed_status = ( isset( $options['earn']['order']['completed_status'] ) ? sanitize_key( $options['earn']['order']['completed_status'] ) : 'completed' );
    if ( empty( $completed_status ) ) {
        $completed_status = 'completed';
    }
    $award_timing = ( isset( $options['earn']['order']['award_timing'] ) ? sanitize_key( $options['earn']['order']['award_timing'] ) : 'completed' );
    $order_status = $order->get_status();
    $allowed_statuses = [$completed_status];
    if ( 'thankyou' === $award_timing ) {
        // When award_timing is thankyou, also allow common post-payment statuses
        $allowed_statuses = array_unique( array_merge( $allowed_statuses, ['processing', 'on-hold', 'pending'] ) );
    }
    if ( $order->get_meta( 'points_earned' ) ) {
        wp_send_json_error( esc_html__( 'Points already awarded for this order.', 'simple-points-and-rewards' ) );
    }
    if ( !function_exists( 'spar_award_order_points' ) ) {
        wp_send_json_error( esc_html__( 'Internal error: Points function missing.', 'simple-points-and-rewards' ) );
    }
    spar_award_order_points( $order_id, true );
    // Also trigger the first-order bonus if applicable (not fired automatically when forcing via admin)
    if ( function_exists( 'spar_award_first_order_points' ) ) {
        $first_order_bonus = (int) $order->get_meta( 'points_first_order_bonus' );
        if ( $first_order_bonus > 0 ) {
            $user_id = $order->get_user_id();
            if ( $user_id ) {
                $user_rewards_earned = get_user_meta( $user_id, '_spar_rewards_earned', true );
                if ( !is_array( $user_rewards_earned ) || empty( $user_rewards_earned['first_order'] ) ) {
                    $options = get_option( 'spar_options', [] );
                    $action_name = $options['earn']['first_order']['name'] ?? esc_html__( 'First Order Bonus', 'simple-points-and-rewards' );
                    spar_update_user_points(
                        $user_id,
                        $first_order_bonus,
                        'add',
                        sanitize_text_field( $action_name ),
                        'first_order',
                        array(
                            'reference_type' => 'order',
                            'reference_id'   => $order_id,
                        )
                    );
                    if ( !is_array( $user_rewards_earned ) ) {
                        $user_rewards_earned = [];
                    }
                    $user_rewards_earned['first_order'] = true;
                    update_user_meta( $user_id, '_spar_rewards_earned', $user_rewards_earned );
                }
            }
        }
    }
    $order = wc_get_order( $order_id );
    $points_awarded = ( $order ? (int) $order->get_meta( 'points_earned' ) : 0 );
    if ( $points_awarded > 0 ) {
        wp_send_json_success( [
            'points' => $points_awarded,
        ] );
    }
    wp_send_json_error( esc_html__( 'Points could not be awarded. Please try again.', 'simple-points-and-rewards' ) );
}

/**
 * Parse a serialized settings form payload without PHP's parse_str().
 *
 * parse_str() is affected by max_input_vars, which can truncate this large
 * settings form on hosts with low limits. The AJAX request already arrives as
 * one POST value, so parsing it manually keeps the plugin from losing fields at
 * this layer.
 *
 * @param string $form_data URL-encoded form data from jQuery.serialize().
 * @return array Parsed form data.
 */
function spar_parse_settings_form_data(  $form_data  ) {
    $parsed_data = array();
    if ( !is_string( $form_data ) || '' === $form_data ) {
        return $parsed_data;
    }
    $form_pairs = explode( '&', $form_data );
    foreach ( $form_pairs as $form_pair ) {
        if ( '' === $form_pair ) {
            continue;
        }
        $pair_parts = explode( '=', $form_pair, 2 );
        $field_name = ( isset( $pair_parts[0] ) ? urldecode( $pair_parts[0] ) : '' );
        $field_value = ( isset( $pair_parts[1] ) ? wp_slash( urldecode( $pair_parts[1] ) ) : '' );
        if ( '' === $field_name ) {
            continue;
        }
        $field_keys = spar_parse_settings_field_name( $field_name );
        spar_assign_settings_form_value( $parsed_data, $field_keys, $field_value );
    }
    return $parsed_data;
}

/**
 * Recover a native settings form POST from the raw request body when needed.
 *
 * Native PHP parsing is limited by max_input_vars. The settings form can be
 * large, and the manual AJAX fallback can still use a normal form submit on
 * hosts that block admin-ajax requests. Parsing php://input with the same
 * parser used by AJAX avoids saving a truncated settings payload.
 *
 * @param array $post_data Parsed POST data from PHP/WordPress.
 * @return array Parsed settings POST data.
 */
function spar_get_settings_post_data(  $post_data  ) {
    $post_data = ( is_array( $post_data ) ? $post_data : array() );
    $request_method = ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' );
    if ( 'POST' !== $request_method ) {
        return $post_data;
    }
    $content_type = ( isset( $_SERVER['CONTENT_TYPE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) : '' );
    if ( '' !== $content_type && false === stripos( $content_type, 'application/x-www-form-urlencoded' ) ) {
        return $post_data;
    }
    static $raw_input = null;
    if ( null === $raw_input ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- php://input is required to recover the raw form body when PHP truncates $_POST.
        $raw_input = file_get_contents( 'php://input' );
    }
    if ( !is_string( $raw_input ) || '' === $raw_input ) {
        return $post_data;
    }
    if ( false === strpos( $raw_input, 'spar_save_settings' ) && false === strpos( $raw_input, 'spar_form_end' ) ) {
        return $post_data;
    }
    $raw_post_data = spar_parse_settings_form_data( $raw_input );
    if ( empty( $raw_post_data ) || !is_array( $raw_post_data ) || empty( $raw_post_data['spar_save_settings'] ) ) {
        return $post_data;
    }
    if ( !empty( $raw_post_data['spar_form_end'] ) && (empty( $post_data['spar_form_end'] ) || count( $raw_post_data ) >= count( $post_data )) ) {
        return $raw_post_data;
    }
    return $post_data;
}

/**
 * Convert a form field name into nested array keys.
 *
 * @param string $field_name Field name, such as rewards[0][name].
 * @return array Field keys.
 */
function spar_parse_settings_field_name(  $field_name  ) {
    $field_keys = array();
    if ( preg_match_all(
        '/([^\\[\\]]+)|\\[([^\\]]*)\\]/',
        $field_name,
        $field_matches,
        PREG_SET_ORDER
    ) ) {
        foreach ( $field_matches as $field_match ) {
            if ( isset( $field_match[1] ) && '' !== $field_match[1] ) {
                $field_keys[] = $field_match[1];
            } else {
                $field_keys[] = ( isset( $field_match[2] ) ? $field_match[2] : '' );
            }
        }
    }
    return ( !empty( $field_keys ) ? $field_keys : array($field_name) );
}

/**
 * Assign a parsed form value into a nested array.
 *
 * @param array $target Data being populated.
 * @param array $field_keys Nested field keys.
 * @param mixed $field_value Field value.
 */
function spar_assign_settings_form_value(  &$target, $field_keys, $field_value  ) {
    $current_key = array_shift( $field_keys );
    if ( null === $current_key ) {
        return;
    }
    if ( '' === $current_key ) {
        if ( !is_array( $target ) ) {
            $target = array();
        }
        if ( empty( $field_keys ) ) {
            $target[] = $field_value;
            return;
        }
        $target[] = array();
        $new_index = array_key_last( $target );
        spar_assign_settings_form_value( $target[$new_index], $field_keys, $field_value );
        return;
    }
    if ( empty( $field_keys ) ) {
        $target[$current_key] = $field_value;
        return;
    }
    if ( !isset( $target[$current_key] ) || !is_array( $target[$current_key] ) ) {
        $target[$current_key] = array();
    }
    spar_assign_settings_form_value( $target[$current_key], $field_keys, $field_value );
}

/**
 * Validate that a settings save payload looks complete enough to process.
 *
 * @param array $post_data Data to validate.
 * @return true|WP_Error True when valid, otherwise an error.
 */
function spar_validate_settings_payload(  $post_data  ) {
    if ( empty( $post_data ) || !is_array( $post_data ) ) {
        return new WP_Error('spar_settings_payload_empty', esc_html__( 'Settings payload was empty and was not saved. This can happen when the request exceeds the server post_max_size limit or is blocked by a security rule.', 'simple-points-and-rewards' ));
    }
    if ( empty( $post_data['spar_form_end'] ) ) {
        return new WP_Error('spar_settings_form_truncated', esc_html__( 'Settings form was truncated and was not saved. Please increase PHP max_input_vars or try saving again after refreshing the page.', 'simple-points-and-rewards' ));
    }
    $required_fields = apply_filters( 'spar_settings_required_payload_fields', array(
        'spar_save_settings',
        '_wpnonce',
        'points_label',
        'rewards_label',
        'rewards_page_id',
        'rewards_box_theme'
    ) );
    $missing_fields = array();
    foreach ( $required_fields as $required_field ) {
        if ( !array_key_exists( $required_field, $post_data ) ) {
            $missing_fields[] = sanitize_key( $required_field );
        }
    }
    if ( !empty( $missing_fields ) ) {
        return new WP_Error('spar_settings_payload_incomplete', sprintf( 
            /* translators: %s: comma-separated list of missing settings fields. */
            esc_html__( 'Settings payload was incomplete and was not saved. Missing field(s): %s. Please refresh the page and try again, or ask your host to increase PHP request limits.', 'simple-points-and-rewards' ),
            esc_html( implode( ', ', $missing_fields ) )
         ));
    }
    return true;
}

function spar_clean_settings_option_cache() {
    if ( function_exists( 'clean_option_cache' ) ) {
        clean_option_cache( 'spar_options' );
    } else {
        wp_cache_delete( 'spar_options', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }
    if ( function_exists( 'wp_cache_flush_runtime' ) ) {
        wp_cache_flush_runtime();
    }
}

function spar_get_settings_option_from_database() {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional cache-bypassing read to verify settings persistence after save.
    $option_value = $wpdb->get_var( $wpdb->prepare( 
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->options is a trusted internal table name.
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        'spar_options'
     ) );
    if ( null === $option_value ) {
        return null;
    }
    return maybe_unserialize( $option_value );
}

/**
 * Determines whether the options table can store 4-byte Unicode characters.
 *
 * @return bool
 */
function spar_options_table_supports_emoji() {
    static $supports_emoji = null;
    if ( null !== $supports_emoji ) {
        return $supports_emoji;
    }
    global $wpdb;
    $emoji = html_entity_decode( '&#x1F600;', ENT_QUOTES, 'UTF-8' );
    if ( '' === $emoji ) {
        $supports_emoji = true;
        return $supports_emoji;
    }
    if ( method_exists( $wpdb, 'strip_invalid_text_for_column' ) ) {
        $stripped = $wpdb->strip_invalid_text_for_column( $wpdb->options, 'option_value', $emoji );
        $supports_emoji = is_string( $stripped ) && $emoji === $stripped;
        return $supports_emoji;
    }
    $supports_emoji = 'utf8mb4' === strtolower( (string) $wpdb->charset );
    return $supports_emoji;
}

/**
 * Prepares the full settings option for storage in wp_options.
 *
 * @param array $options       Settings array.
 * @param array $cleaned_paths Field paths changed during cleanup.
 * @return array
 */
function spar_prepare_settings_options_for_database(  $options, &$cleaned_paths = array()  ) {
    if ( !is_array( $options ) ) {
        return array();
    }
    $options = spar_prepare_settings_option_for_database( $options, $cleaned_paths );
    return spar_apply_database_safe_icon_fallbacks( $options, $cleaned_paths );
}

/**
 * Applies DB-safe icon defaults when emoji-only icon fields cannot be stored.
 *
 * @param array $options       Settings array.
 * @param array $cleaned_paths Field paths changed during cleanup.
 * @return array
 */
function spar_apply_database_safe_icon_fallbacks(  $options, &$cleaned_paths  ) {
    if ( empty( $cleaned_paths ) ) {
        return $options;
    }
    $cleaned_lookup = array_flip( array_unique( $cleaned_paths ) );
    $default_level_icon = ( function_exists( 'spar_get_level_default_badge_icon' ) ? spar_get_level_default_badge_icon( array(
        'id' => 'level_1',
    ) ) : 'fa-solid fa-trophy' );
    $default_starter_icon = ( function_exists( 'spar_get_level_default_badge_icon' ) ? spar_get_level_default_badge_icon( array(
        'id'         => 'level_0',
        'is_default' => true,
    ) ) : 'fa-solid fa-star' );
    $default_widget_icon = 'fa-solid fa-star';
    $starter_icon_cleaned = isset( $cleaned_lookup['levels_starter_badge_icon'] ) && empty( $options['levels_starter_badge_icon'] );
    $starter_text_cleaned = isset( $cleaned_lookup['levels_starter_badge_text'] ) && 'custom_text' === ($options['levels_starter_badge_type'] ?? '') && empty( $options['levels_starter_badge_text'] );
    if ( $starter_icon_cleaned || $starter_text_cleaned ) {
        $options['levels_starter_badge_type'] = 'preset';
        $options['levels_starter_badge_icon'] = $default_starter_icon;
        $options['levels_starter_badge_text'] = '';
    }
    if ( isset( $cleaned_lookup['rewards_widget_icon'] ) && empty( $options['rewards_widget_icon'] ) ) {
        $options['rewards_widget_icon'] = $default_widget_icon;
    }
    $widget_text_cleaned = isset( $cleaned_lookup['rewards_widget_icon_custom_text'] ) && 'custom_text' === ($options['rewards_widget_icon'] ?? '') && empty( $options['rewards_widget_icon_custom_text'] );
    if ( $widget_text_cleaned ) {
        $options['rewards_widget_icon'] = $default_widget_icon;
        $options['rewards_widget_icon_custom_text'] = '';
    }
    if ( !empty( $options['levels'] ) && is_array( $options['levels'] ) ) {
        foreach ( $options['levels'] as $level_index => $level ) {
            if ( !is_array( $level ) ) {
                continue;
            }
            $icon_path = 'levels.' . (string) $level_index . '.badge_icon';
            $text_path = 'levels.' . (string) $level_index . '.badge_text';
            $level_icon_cleaned = isset( $cleaned_lookup[$icon_path] ) && empty( $level['badge_icon'] );
            $level_text_cleaned = isset( $cleaned_lookup[$text_path] ) && 'custom_text' === ($level['badge_type'] ?? '') && empty( $level['badge_text'] );
            if ( $level_icon_cleaned || $level_text_cleaned ) {
                $options['levels'][$level_index]['badge_type'] = 'preset';
                $options['levels'][$level_index]['badge_icon'] = $default_level_icon;
                $options['levels'][$level_index]['badge_text'] = '';
            }
        }
    }
    if ( !empty( $options['rewards'] ) && is_array( $options['rewards'] ) ) {
        foreach ( $options['rewards'] as $reward_index => $reward ) {
            if ( !is_array( $reward ) ) {
                continue;
            }
            $icon_path = 'rewards.' . (string) $reward_index . '.badge_icon';
            $text_path = 'rewards.' . (string) $reward_index . '.badge_text';
            $reward_type = ( isset( $reward['type'] ) ? sanitize_key( $reward['type'] ) : 'voucher' );
            $reward_default_icon = ( function_exists( 'spar_get_reward_type_default_icon' ) ? spar_get_reward_type_default_icon( $reward_type ) : 'fa-solid fa-ticket' );
            $reward_icon_cleaned = isset( $cleaned_lookup[$icon_path] ) && empty( $reward['badge_icon'] );
            $reward_text_cleaned = isset( $cleaned_lookup[$text_path] ) && 'custom_text' === ($reward['badge_type'] ?? '') && empty( $reward['badge_text'] );
            if ( $reward_icon_cleaned || $reward_text_cleaned ) {
                $options['rewards'][$reward_index]['badge_type'] = 'preset';
                $options['rewards'][$reward_index]['badge_icon'] = $reward_default_icon;
                $options['rewards'][$reward_index]['badge_text'] = '';
            }
        }
    }
    return $options;
}

/**
 * Removes characters that the current wp_options.option_value column cannot store.
 *
 * @param mixed  $value         Setting value or nested setting array.
 * @param array  $cleaned_paths Field paths changed during cleanup.
 * @param string $path          Dot-notated path for fallback handling.
 * @return mixed
 */
function spar_prepare_settings_option_for_database(  $value, &$cleaned_paths = array(), $path = ''  ) {
    global $wpdb;
    if ( is_array( $value ) ) {
        foreach ( $value as $setting_key => $setting_value ) {
            $setting_path = ( '' === $path ? (string) $setting_key : $path . '.' . (string) $setting_key );
            $value[$setting_key] = spar_prepare_settings_option_for_database( $setting_value, $cleaned_paths, $setting_path );
        }
        return $value;
    }
    if ( !is_string( $value ) || '' === $value ) {
        return $value;
    }
    $clean_value = $value;
    $used_column_stripper = false;
    if ( method_exists( $wpdb, 'strip_invalid_text_for_column' ) ) {
        $stripped_value = $wpdb->strip_invalid_text_for_column( $wpdb->options, 'option_value', $clean_value );
        if ( is_string( $stripped_value ) ) {
            $clean_value = $stripped_value;
            $used_column_stripper = true;
        }
    }
    if ( function_exists( 'wp_check_invalid_utf8' ) ) {
        $clean_value = wp_check_invalid_utf8( $clean_value, true );
    }
    if ( !$used_column_stripper ) {
        $without_four_byte_characters = preg_replace( '/[\\x{10000}-\\x{10FFFF}]/u', '', $clean_value );
        if ( is_string( $without_four_byte_characters ) ) {
            $clean_value = $without_four_byte_characters;
        }
    }
    if ( $clean_value !== $value ) {
        $cleaned_paths[] = ( '' === $path ? 'spar_options' : $path );
    }
    return $clean_value;
}

/**
 * Saves spar_options through WordPress APIs and verifies the stored value.
 *
 * @param array $options       Settings to save.
 * @return bool
 */
function spar_save_settings_option_value(  $options  ) {
    update_option( 'spar_options', $options, false );
    spar_clean_settings_option_cache();
    $database_options = spar_get_settings_option_from_database();
    if ( null !== $database_options && spar_settings_option_values_match( $options, $database_options ) ) {
        return true;
    }
    if ( null === $database_options ) {
        add_option(
            'spar_options',
            $options,
            '',
            false
        );
        spar_clean_settings_option_cache();
        $database_options = spar_get_settings_option_from_database();
        if ( null !== $database_options && spar_settings_option_values_match( $options, $database_options ) ) {
            return true;
        }
    }
    return false;
}

function spar_settings_option_values_match(  $expected_value, $actual_value  ) {
    return $expected_value === $actual_value || maybe_serialize( $expected_value ) === maybe_serialize( $actual_value ) || $expected_value == $actual_value;
}

/**
 * Process settings save
 *
 * @param array $post_data Data to save (usually $_POST)
 * @return true|WP_Error True on success, otherwise an error.
 */
function spar_process_settings_save(  $post_data  ) {
    if ( !function_exists( 'spar_get_option_fields' ) ) {
        require_once SPAR_PLUGIN_PATH . 'includes/admin/settings/settings-utilities.php';
    }
    $payload_validation = spar_validate_settings_payload( $post_data );
    if ( is_wp_error( $payload_validation ) ) {
        return $payload_validation;
    }
    $form_complete = !empty( $post_data['spar_form_end'] );
    $options = get_option( 'spar_options', spar_settings_default() );
    $defaults = spar_settings_default();
    // Ensure earn array exists
    if ( !isset( $options['earn'] ) || !is_array( $options['earn'] ) ) {
        $options['earn'] = $defaults['earn'];
    }
    $fields = spar_get_option_fields();
    foreach ( $fields as $key => $field_data ) {
        if ( $key === 'earn' ) {
            // Handle nested earn fields
            foreach ( $field_data as $earn_key => $earn_data ) {
                // Check if this earn type has ANY field submitted in the POST data.
                // If none were submitted, the form section was not rendered (e.g., PRO-only
                // section on a free install), so we should preserve existing DB values rather
                // than forcing checkboxes to false.
                $earn_type_in_form = false;
                foreach ( $earn_data as $ck => $cv ) {
                    if ( array_key_exists( $earn_key . '_' . $ck, $post_data ) ) {
                        $earn_type_in_form = true;
                        break;
                    }
                }
                foreach ( $earn_data as $subkey => $subfield ) {
                    $post_key = $earn_key . '_' . $subkey;
                    $has_post = array_key_exists( $post_key, $post_data );
                    if ( $has_post ) {
                        $value = wp_unslash( $post_data[$post_key] );
                        $options['earn'][$earn_key][$subkey] = spar_sanitize_field_value(
                            $value,
                            $subfield['type'],
                            $subfield['default'],
                            $post_key
                        );
                    } elseif ( 'array' === $subfield['type'] && $form_complete && $earn_type_in_form ) {
                        $options['earn'][$earn_key][$subkey] = spar_sanitize_settings_array_field( [], $post_key, $subfield['default'] );
                    } elseif ( 'checkbox' === $subfield['type'] && $form_complete && $earn_type_in_form ) {
                        $options['earn'][$earn_key][$subkey] = false;
                    } elseif ( !isset( $options['earn'][$earn_key][$subkey] ) ) {
                        $options['earn'][$earn_key][$subkey] = $subfield['default'];
                    }
                }
            }
        } elseif ( 'points_icon' === $key ) {
            // Points value icon: a bundled preset key, 'custom', or '' for none.
            if ( array_key_exists( $key, $post_data ) ) {
                $value = sanitize_text_field( wp_unslash( $post_data[$key] ) );
                $allowed = ( function_exists( 'spar_get_points_icon_presets' ) ? array_keys( spar_get_points_icon_presets() ) : array() );
                $allowed[] = 'custom';
                $options[$key] = ( in_array( $value, $allowed, true ) ? $value : '' );
            }
        } elseif ( 'points_icon_url' === $key ) {
            if ( array_key_exists( $key, $post_data ) ) {
                $options[$key] = esc_url_raw( wp_unslash( $post_data[$key] ) );
            }
        } elseif ( 'rewards_widget_icon' === $key ) {
            // Allow empty selection for "No Icon".
            if ( array_key_exists( $key, $post_data ) ) {
                $value = wp_unslash( $post_data[$key] );
                $options[$key] = sanitize_text_field( $value );
            }
        } elseif ( 'rewards_widget_icon_custom_url' === $key ) {
            if ( array_key_exists( $key, $post_data ) ) {
                $value = wp_unslash( $post_data[$key] );
                $options[$key] = esc_url_raw( $value );
            }
        } elseif ( 'rewards_widget_icon_custom_text' === $key ) {
            if ( array_key_exists( $key, $post_data ) ) {
                $value = wp_unslash( $post_data[$key] );
                $options[$key] = sanitize_text_field( $value );
            }
        } elseif ( 'levels_starter_badge_icon' === $key ) {
            $raw_icon = ( isset( $post_data['levels_starter_badge_icon'] ) ? sanitize_text_field( wp_unslash( $post_data['levels_starter_badge_icon'] ) ) : '' );
            $raw_url = ( isset( $post_data['levels_starter_badge_url'] ) ? esc_url_raw( wp_unslash( $post_data['levels_starter_badge_url'] ) ) : '' );
            $raw_text = ( isset( $post_data['levels_starter_badge_text'] ) ? sanitize_text_field( wp_unslash( $post_data['levels_starter_badge_text'] ) ) : '' );
            $raw_type = ( isset( $post_data['levels_starter_badge_type'] ) ? sanitize_text_field( wp_unslash( $post_data['levels_starter_badge_type'] ) ) : '' );
            $allowed_badge_types = ['preset', 'custom_url', 'custom_text'];
            $badge_type = ( in_array( $raw_type, $allowed_badge_types, true ) ? $raw_type : '' );
            if ( '' === $badge_type ) {
                if ( '' !== $raw_url ) {
                    $badge_type = 'custom_url';
                } elseif ( '' !== $raw_text ) {
                    $badge_type = 'custom_text';
                } elseif ( '' !== $raw_icon ) {
                    $badge_type = 'preset';
                } else {
                    $badge_type = 'preset';
                }
            }
            $badge_icon = $raw_icon;
            $badge_url = $raw_url;
            $badge_text = $raw_text;
            if ( 'custom_url' === $badge_type ) {
                $badge_icon = '';
                $badge_text = '';
            } elseif ( 'custom_text' === $badge_type ) {
                if ( '' === $badge_text && '' !== $badge_icon ) {
                    $badge_text = $badge_icon;
                }
                $badge_icon = '';
                $badge_url = '';
            } else {
                $badge_type = 'preset';
                if ( '' === $badge_icon && '' !== $badge_text ) {
                    $badge_icon = $badge_text;
                }
                $badge_url = '';
                $badge_text = '';
            }
            $raw_color = ( isset( $post_data['levels_starter_badge_color'] ) ? sanitize_hex_color( wp_unslash( $post_data['levels_starter_badge_color'] ) ) : '' );
            $raw_color = ( is_string( $raw_color ) ? $raw_color : '' );
            $raw_color_mode = ( isset( $post_data['levels_starter_badge_color_mode'] ) ? sanitize_key( wp_unslash( $post_data['levels_starter_badge_color_mode'] ) ) : 'default' );
            $badge_color_mode = ( in_array( $raw_color_mode, ['default', 'custom'], true ) ? $raw_color_mode : 'default' );
            $badge_color_supported = 'custom_text' === $badge_type || 'preset' === $badge_type && function_exists( 'spar_is_font_awesome_icon_value' ) && spar_is_font_awesome_icon_value( $badge_icon );
            $options['levels_starter_badge_type'] = $badge_type;
            $options['levels_starter_badge_icon'] = $badge_icon;
            $options['levels_starter_badge_url'] = $badge_url;
            $options['levels_starter_badge_text'] = $badge_text;
            $options['levels_starter_badge_color_mode'] = ( $badge_color_supported ? $badge_color_mode : 'default' );
            $options['levels_starter_badge_color'] = ( $badge_color_supported && 'custom' === $badge_color_mode ? $raw_color : '' );
            continue;
        } elseif ( in_array( $key, [
            'levels_starter_badge_type',
            'levels_starter_badge_text',
            'levels_starter_badge_url',
            'levels_starter_badge_color',
            'levels_starter_badge_color_mode'
        ], true ) ) {
            // Starter badge fields are processed together above.
            continue;
        } elseif ( 'buy_products' === $key ) {
            // Handle Product Offers & Bonuses config array (PRO).
            if ( !function_exists( 'sparp_bp_sanitize_config__premium_only' ) ) {
                continue;
            }
            $bp_present = !empty( $post_data['buy_products_present'] );
            $bp_raw = ( isset( $post_data['buy_products'] ) ? wp_unslash( $post_data['buy_products'] ) : [] );
            // If the section was not rendered in this payload, preserve existing DB values.
            if ( !$bp_present && empty( $bp_raw ) ) {
                continue;
            }
            $options['buy_products'] = sparp_bp_sanitize_config__premium_only( $bp_raw );
            continue;
        } elseif ( 'earn_conditional_rules' === $key ) {
            // Handle earn conditional rules array (PRO).
            $has_rules = isset( $post_data['earn_conditional_rules'] );
            $raw_rules = ( $has_rules ? wp_unslash( $post_data['earn_conditional_rules'] ) : [] );
            $deleted_ids = ( isset( $post_data['earn_conditional_rules_deleted_ids'] ) ? wp_unslash( $post_data['earn_conditional_rules_deleted_ids'] ) : [] );
            if ( !is_array( $deleted_ids ) ) {
                $deleted_ids = [$deleted_ids];
            }
            $deleted_ids = array_values( array_filter( array_map( 'sanitize_text_field', $deleted_ids ) ) );
            $has_deleted = !empty( $deleted_ids );
            // If neither rules nor deleted IDs were submitted, do not modify the saved rules.
            if ( !$has_rules && !$has_deleted ) {
                continue;
            }
            // If rules are missing but we have deleted IDs, perform a delete-only update
            // against the existing saved rules. This is resilient to truncated form payloads.
            if ( !$has_rules && $has_deleted ) {
                $existing = ( isset( $options['earn_conditional_rules'] ) && is_array( $options['earn_conditional_rules'] ) ? $options['earn_conditional_rules'] : [] );
                $existing = array_values( array_filter( $existing, function ( $rule ) use($deleted_ids) {
                    if ( !is_array( $rule ) ) {
                        return false;
                    }
                    $rid = ( isset( $rule['id'] ) ? sanitize_text_field( (string) $rule['id'] ) : '' );
                    if ( '' === $rid ) {
                        return true;
                    }
                    return !in_array( $rid, $deleted_ids, true );
                } ) );
                $options['earn_conditional_rules'] = $existing;
                continue;
            }
            $sanitized_rules = [];
            $allowed_types = [
                'product',
                'product_category',
                'product_item',
                'product_item_category',
                'cart_total',
                'cart_coupon',
                'customer',
                'user_role',
                'customer_total_points',
                'customer_available_points'
            ];
            $allowed_ways_order_only = ['order', 'order_fixed'];
            $allowed_ways_all = [
                'order',
                'order_fixed',
                'signup',
                'daily_login',
                'daily_login_streak',
                'birthday',
                'review',
                'referral',
                'social_share',
                'first_order',
                'nth_order'
            ];
            $allowed_in_cart_ops = ['in_cart', 'not_in_cart'];
            $allowed_cart_ops = ['above', 'below'];
            $all_roles = [];
            if ( function_exists( 'wp_roles' ) && wp_roles() ) {
                $all_roles = array_keys( wp_roles()->roles );
            }
            if ( is_array( $raw_rules ) ) {
                foreach ( $raw_rules as $rule ) {
                    if ( !is_array( $rule ) ) {
                        continue;
                    }
                    $rule_id = ( isset( $rule['id'] ) ? sanitize_text_field( (string) $rule['id'] ) : '' );
                    if ( '' !== $rule_id && !empty( $deleted_ids ) && in_array( $rule_id, $deleted_ids, true ) ) {
                        continue;
                    }
                    $condition_types = [];
                    if ( isset( $rule['conditions'] ) && is_array( $rule['conditions'] ) ) {
                        foreach ( $rule['conditions'] as $cond ) {
                            if ( !is_array( $cond ) ) {
                                continue;
                            }
                            $cond_type = ( isset( $cond['condition_type'] ) ? sanitize_text_field( (string) $cond['condition_type'] ) : '' );
                            if ( in_array( $cond_type, $allowed_types, true ) ) {
                                $condition_types[] = $cond_type;
                            }
                        }
                    }
                    if ( empty( $condition_types ) ) {
                        $condition_type = ( isset( $rule['condition_type'] ) ? sanitize_text_field( (string) $rule['condition_type'] ) : '' );
                        if ( !in_array( $condition_type, $allowed_types, true ) ) {
                            continue;
                        }
                        $condition_types[] = $condition_type;
                    }
                    $condition_types = array_values( array_unique( $condition_types ) );
                    $condition_type = $condition_types[0];
                    $all_non_order = empty( array_diff( $condition_types, [
                        'customer',
                        'user_role',
                        'customer_total_points',
                        'customer_available_points'
                    ] ) ) && !empty( $condition_types );
                    $ways = [];
                    $allowed_ways = ( $all_non_order ? $allowed_ways_all : $allowed_ways_order_only );
                    if ( isset( $rule['ways'] ) ) {
                        $raw_ways = $rule['ways'];
                        if ( !is_array( $raw_ways ) ) {
                            $raw_ways = [$raw_ways];
                        }
                        $raw_ways = array_map( 'sanitize_text_field', $raw_ways );
                        $ways = array_values( array_intersect( $raw_ways, $allowed_ways ) );
                    }
                    if ( empty( $ways ) ) {
                        $ways = $allowed_ways;
                    }
                    $multiplier = ( isset( $rule['multiplier'] ) ? (float) $rule['multiplier'] : 1.0 );
                    $multiplier = max( 0.0, (float) $multiplier );
                    $hide_disable_raw = $rule['hide_disable_way'] ?? '';
                    if ( is_array( $hide_disable_raw ) && !empty( $hide_disable_raw ) ) {
                        $hide_disable_raw = end( $hide_disable_raw );
                    }
                    $hide_disable = false;
                    if ( true === $hide_disable_raw || 1 === $hide_disable_raw ) {
                        $hide_disable = true;
                    } elseif ( is_string( $hide_disable_raw ) ) {
                        $hide_disable = in_array( strtolower( $hide_disable_raw ), [
                            '1',
                            'true',
                            'yes',
                            'on'
                        ], true );
                    }
                    $fixed_points_delta = ( isset( $rule['fixed_points_delta'] ) ? (int) $rule['fixed_points_delta'] : 0 );
                    $min_points = ( isset( $rule['min_points'] ) ? absint( $rule['min_points'] ) : 0 );
                    $max_points = ( isset( $rule['max_points'] ) ? absint( $rule['max_points'] ) : 0 );
                    if ( $min_points > 0 && $max_points > 0 && $max_points < $min_points ) {
                        $max_points = $min_points;
                    }
                    $san = [
                        'id'                 => $rule_id,
                        'label'              => ( isset( $rule['label'] ) ? sanitize_text_field( (string) $rule['label'] ) : '' ),
                        'condition_type'     => $condition_type,
                        'ways'               => $ways,
                        'multiplier'         => $multiplier,
                        'hide_disable_way'   => $hide_disable,
                        'fixed_points_delta' => $fixed_points_delta,
                        'min_points'         => $min_points,
                        'max_points'         => $max_points,
                    ];
                    if ( empty( $san['id'] ) && function_exists( 'wp_generate_uuid4' ) ) {
                        $san['id'] = wp_generate_uuid4();
                    }
                    $conditions = [];
                    $conditions_input = ( isset( $rule['conditions'] ) && is_array( $rule['conditions'] ) ? $rule['conditions'] : [] );
                    if ( empty( $conditions_input ) ) {
                        $conditions_input = [$rule];
                    }
                    foreach ( $conditions_input as $cond ) {
                        if ( !is_array( $cond ) ) {
                            continue;
                        }
                        $cond_type = ( isset( $cond['condition_type'] ) ? sanitize_text_field( (string) $cond['condition_type'] ) : '' );
                        if ( !in_array( $cond_type, $allowed_types, true ) ) {
                            continue;
                        }
                        $cond_valid = true;
                        $cond_san = [
                            'condition_type' => $cond_type,
                        ];
                        switch ( $cond_type ) {
                            case 'product_item':
                                $ids = ( isset( $cond['product_ids'] ) && is_array( $cond['product_ids'] ) ? array_map( 'absint', $cond['product_ids'] ) : [] );
                                $ids = array_values( array_unique( array_filter( $ids ) ) );
                                $cond_san['product_ids'] = $ids;
                                if ( empty( $ids ) ) {
                                    $cond_valid = false;
                                }
                                break;
                            case 'product_item_category':
                                $op = ( isset( $cond['category_item_operator'] ) ? sanitize_text_field( (string) $cond['category_item_operator'] ) : 'is' );
                                $op = ( in_array( $op, ['is', 'is_not'], true ) ? $op : 'is' );
                                $ids = ( isset( $cond['category_ids'] ) && is_array( $cond['category_ids'] ) ? array_map( 'absint', $cond['category_ids'] ) : [] );
                                $ids = array_values( array_unique( array_filter( $ids ) ) );
                                $cond_san['category_item_operator'] = $op;
                                $cond_san['category_ids'] = $ids;
                                if ( empty( $ids ) ) {
                                    $cond_valid = false;
                                }
                                break;
                            case 'product':
                                $op = ( isset( $cond['product_operator'] ) ? sanitize_text_field( (string) $cond['product_operator'] ) : 'in_cart' );
                                $op = ( in_array( $op, $allowed_in_cart_ops, true ) ? $op : 'in_cart' );
                                $ids = ( isset( $cond['product_ids'] ) && is_array( $cond['product_ids'] ) ? array_map( 'absint', $cond['product_ids'] ) : [] );
                                $ids = array_values( array_unique( array_filter( $ids ) ) );
                                $cond_san['product_operator'] = $op;
                                $cond_san['product_ids'] = $ids;
                                if ( empty( $ids ) ) {
                                    $cond_valid = false;
                                }
                                break;
                            case 'product_category':
                                $op = ( isset( $cond['category_operator'] ) ? sanitize_text_field( (string) $cond['category_operator'] ) : 'in_cart' );
                                $op = ( in_array( $op, $allowed_in_cart_ops, true ) ? $op : 'in_cart' );
                                $ids = ( isset( $cond['category_ids'] ) && is_array( $cond['category_ids'] ) ? array_map( 'absint', $cond['category_ids'] ) : [] );
                                $ids = array_values( array_unique( array_filter( $ids ) ) );
                                $cond_san['category_operator'] = $op;
                                $cond_san['category_ids'] = $ids;
                                if ( empty( $ids ) ) {
                                    $cond_valid = false;
                                }
                                break;
                            case 'cart_total':
                                $op = ( isset( $cond['cart_total_operator'] ) ? sanitize_text_field( (string) $cond['cart_total_operator'] ) : 'above' );
                                $op = ( in_array( $op, $allowed_cart_ops, true ) ? $op : 'above' );
                                $amt = ( isset( $cond['cart_total_amount'] ) ? (float) $cond['cart_total_amount'] : 0.0 );
                                $amt = max( 0.0, (float) $amt );
                                $cond_san['cart_total_operator'] = $op;
                                $cond_san['cart_total_amount'] = $amt;
                                if ( $amt <= 0 ) {
                                    $cond_valid = false;
                                }
                                break;
                            case 'cart_coupon':
                                $codes = [];
                                if ( isset( $cond['coupon_codes'] ) ) {
                                    $raw_codes = $cond['coupon_codes'];
                                    if ( !is_array( $raw_codes ) ) {
                                        $raw_codes = explode( ',', (string) $raw_codes );
                                    }
                                    foreach ( $raw_codes as $code ) {
                                        $code = sanitize_text_field( (string) $code );
                                        $code = trim( $code );
                                        if ( '' !== $code ) {
                                            if ( function_exists( 'wc_format_coupon_code' ) ) {
                                                $code = wc_format_coupon_code( $code );
                                            } else {
                                                $code = strtolower( $code );
                                            }
                                            $codes[] = $code;
                                        }
                                    }
                                }
                                $codes = array_values( array_unique( array_filter( $codes ) ) );
                                $cond_san['coupon_codes'] = $codes;
                                $allowed_coupon_types = ['regular', 'voucher', 'referral'];
                                $coupon_types = [];
                                if ( isset( $cond['coupon_types'] ) ) {
                                    $raw_coupon_types = $cond['coupon_types'];
                                    if ( !is_array( $raw_coupon_types ) ) {
                                        $raw_coupon_types = [$raw_coupon_types];
                                    }
                                    $coupon_types = array_values( array_intersect( $allowed_coupon_types, array_map( 'sanitize_text_field', $raw_coupon_types ) ) );
                                }
                                if ( empty( $coupon_types ) ) {
                                    // Not submitted (legacy payload) or all unchecked: all coupon types count.
                                    $coupon_types = $allowed_coupon_types;
                                }
                                $cond_san['coupon_types'] = $coupon_types;
                                // An empty code list is intentionally valid: it means
                                // "match when ANY coupon is applied".
                                break;
                            case 'customer':
                                $ids = ( isset( $cond['customer_ids'] ) && is_array( $cond['customer_ids'] ) ? array_map( 'absint', $cond['customer_ids'] ) : [] );
                                $ids = array_values( array_filter( $ids ) );
                                $cond_san['customer_ids'] = $ids;
                                if ( empty( $ids ) ) {
                                    $cond_valid = false;
                                }
                                break;
                            case 'user_role':
                                $roles = ( isset( $cond['user_roles'] ) && is_array( $cond['user_roles'] ) ? array_map( 'sanitize_text_field', $cond['user_roles'] ) : [] );
                                $roles = array_values( array_filter( $roles ) );
                                if ( !empty( $all_roles ) ) {
                                    $roles = array_values( array_intersect( $roles, $all_roles ) );
                                }
                                $cond_san['user_roles'] = $roles;
                                if ( empty( $roles ) ) {
                                    $cond_valid = false;
                                }
                                break;
                            case 'customer_total_points':
                                $op = ( isset( $cond['customer_total_points_operator'] ) ? sanitize_text_field( (string) $cond['customer_total_points_operator'] ) : 'above' );
                                $op = ( in_array( $op, $allowed_cart_ops, true ) ? $op : 'above' );
                                $amt = ( isset( $cond['customer_total_points_amount'] ) ? (int) $cond['customer_total_points_amount'] : 0 );
                                $amt = max( 0, (int) $amt );
                                $cond_san['customer_total_points_operator'] = $op;
                                $cond_san['customer_total_points_amount'] = $amt;
                                break;
                            case 'customer_available_points':
                                $op = ( isset( $cond['customer_available_points_operator'] ) ? sanitize_text_field( (string) $cond['customer_available_points_operator'] ) : 'above' );
                                $op = ( in_array( $op, $allowed_cart_ops, true ) ? $op : 'above' );
                                $amt = ( isset( $cond['customer_available_points_amount'] ) ? (int) $cond['customer_available_points_amount'] : 0 );
                                $amt = max( 0, (int) $amt );
                                $cond_san['customer_available_points_operator'] = $op;
                                $cond_san['customer_available_points_amount'] = $amt;
                                break;
                        }
                        if ( $cond_valid ) {
                            $conditions[] = $cond_san;
                        }
                    }
                    if ( !empty( $conditions ) ) {
                        $san['conditions'] = $conditions;
                        $first_condition = $conditions[0];
                        $san['condition_type'] = $first_condition['condition_type'];
                        foreach ( [
                            'product_operator',
                            'product_ids',
                            'category_operator',
                            'category_ids',
                            'category_item_operator',
                            'cart_total_operator',
                            'cart_total_amount',
                            'coupon_codes',
                            'coupon_types',
                            'customer_total_points_operator',
                            'customer_total_points_amount',
                            'customer_available_points_operator',
                            'customer_available_points_amount',
                            'customer_ids',
                            'user_roles'
                        ] as $cond_key ) {
                            if ( isset( $first_condition[$cond_key] ) ) {
                                $san[$cond_key] = $first_condition[$cond_key];
                            }
                        }
                    }
                    $valid = !empty( $conditions );
                    $san['draft'] = ( $valid ? 0 : 1 );
                    $sanitized_rules[] = $san;
                }
            }
            $options['earn_conditional_rules'] = array_values( $sanitized_rules );
            continue;
        } elseif ( $key === 'levels' ) {
            if ( !array_key_exists( 'levels', $post_data ) ) {
                continue;
            }
            // Handle levels array specially with detailed processing
            $levels = ( isset( $post_data['levels'] ) ? wp_unslash( $post_data['levels'] ) : [] );
            $sanitized_levels = [];
            if ( !empty( $levels ) && is_array( $levels ) ) {
                foreach ( $levels as $level_data ) {
                    if ( !is_array( $level_data ) ) {
                        continue;
                    }
                    $raw_badge_type = sanitize_text_field( $level_data['badge_type'] ?? '' );
                    $allowed_badge_types = ['preset', 'custom_url', 'custom_text'];
                    $badge_type = ( in_array( $raw_badge_type, $allowed_badge_types, true ) ? $raw_badge_type : '' );
                    $badge_icon = sanitize_text_field( $level_data['badge_icon'] ?? '' );
                    $badge_url = esc_url_raw( $level_data['badge_url'] ?? '' );
                    $badge_text = sanitize_text_field( $level_data['badge_text'] ?? '' );
                    if ( '' === $badge_type ) {
                        if ( '' !== $badge_url ) {
                            $badge_type = 'custom_url';
                        } elseif ( '' !== $badge_text ) {
                            $badge_type = 'custom_text';
                        } elseif ( '' !== $badge_icon ) {
                            $badge_type = 'preset';
                        } else {
                            $badge_type = 'preset';
                        }
                    }
                    if ( 'custom_url' === $badge_type ) {
                        $badge_icon = '';
                        $badge_text = '';
                    } elseif ( 'custom_text' === $badge_type ) {
                        if ( '' === $badge_text && '' !== $badge_icon ) {
                            $badge_text = $badge_icon;
                        }
                        $badge_icon = '';
                        $badge_url = '';
                    } else {
                        $badge_type = 'preset';
                        $badge_text = '';
                        $badge_url = '';
                    }
                    $enabled_raw = $level_data['level_up_email_enabled'] ?? '';
                    if ( is_array( $enabled_raw ) && !empty( $enabled_raw ) ) {
                        $enabled_raw = end( $enabled_raw );
                    }
                    $enabled_raw = ( is_string( $enabled_raw ) ? trim( $enabled_raw ) : $enabled_raw );
                    $level_email_enabled = false;
                    if ( true === $enabled_raw || 1 === $enabled_raw ) {
                        $level_email_enabled = true;
                    } elseif ( is_numeric( $enabled_raw ) ) {
                        $level_email_enabled = (int) $enabled_raw > 0;
                    } elseif ( is_string( $enabled_raw ) ) {
                        $level_email_enabled = in_array( strtolower( $enabled_raw ), array(
                            '1',
                            'true',
                            'yes',
                            'on'
                        ), true );
                    }
                    $email_subject = ( isset( $level_data['level_up_email_subject'] ) ? sanitize_text_field( $level_data['level_up_email_subject'] ) : '' );
                    $email_body = ( isset( $level_data['level_up_email_body'] ) ? wp_kses_post( $level_data['level_up_email_body'] ) : '' );
                    // Sanitize per-earn-type multipliers (PRO only).
                    $custom_multipliers_on = false;
                    $sanitized_earn_multipliers = [];
                    $sanitized_level = [
                        'id'                         => sanitize_text_field( $level_data['id'] ?? '' ),
                        'name'                       => sanitize_text_field( $level_data['name'] ?? '' ),
                        'required_points'            => absint( $level_data['required_points'] ?? 0 ),
                        'badge_type'                 => $badge_type,
                        'badge_icon'                 => $badge_icon,
                        'badge_url'                  => $badge_url,
                        'badge_text'                 => $badge_text,
                        'points_multiplier'          => max( 0.0, floatval( $level_data['points_multiplier'] ?? 1.0 ) ),
                        'custom_multipliers_enabled' => $custom_multipliers_on,
                        'earn_type_multipliers'      => $sanitized_earn_multipliers,
                        'custom_benefits'            => sanitize_textarea_field( $level_data['custom_benefits'] ?? '' ),
                        'level_up_email_enabled'     => $level_email_enabled,
                        'level_up_email_subject'     => $email_subject,
                        'level_up_email_body'        => $email_body,
                    ];
                    $badge_color = ( isset( $level_data['badge_color'] ) ? sanitize_hex_color( $level_data['badge_color'] ) : '' );
                    $badge_color = ( is_string( $badge_color ) ? $badge_color : '' );
                    $badge_color_mode_raw = ( isset( $level_data['badge_color_mode'] ) ? sanitize_key( $level_data['badge_color_mode'] ) : 'default' );
                    $badge_color_mode = ( in_array( $badge_color_mode_raw, ['default', 'custom'], true ) ? $badge_color_mode_raw : 'default' );
                    $badge_color_supported = 'custom_text' === $badge_type || 'preset' === $badge_type && function_exists( 'spar_is_font_awesome_icon_value' ) && spar_is_font_awesome_icon_value( $badge_icon );
                    if ( $badge_color_supported ) {
                        $sanitized_level['badge_color_mode'] = $badge_color_mode;
                        if ( 'custom' === $badge_color_mode && '' !== $badge_color ) {
                            $sanitized_level['badge_color'] = $badge_color;
                        }
                    }
                    if ( !empty( $sanitized_level['id'] ) ) {
                        $sanitized_levels[] = $sanitized_level;
                    }
                }
            }
            $options['levels'] = $sanitized_levels;
        } elseif ( $key === 'rewards' ) {
            if ( !array_key_exists( 'rewards', $post_data ) ) {
                continue;
            }
            $rewards = ( isset( $post_data['rewards'] ) ? wp_unslash( $post_data['rewards'] ) : [] );
            $sanitized_rewards = [];
            $seen_dev_ids = [];
            if ( !empty( $rewards ) && is_array( $rewards ) ) {
                foreach ( $rewards as $reward_data ) {
                    if ( !is_array( $reward_data ) ) {
                        continue;
                    }
                    $sanitized_reward = [
                        'id'                 => sanitize_text_field( $reward_data['id'] ?? '' ),
                        'name'               => sanitize_text_field( $reward_data['name'] ?? '' ),
                        'points'             => absint( $reward_data['points'] ?? 0 ),
                        'type'               => sanitize_text_field( $reward_data['type'] ?? 'voucher' ),
                        'status'             => sanitize_text_field( $reward_data['status'] ?? 'active' ),
                        'voucher_amount'     => floatval( $reward_data['voucher_amount'] ?? 0 ),
                        'discount_type'      => sanitize_text_field( $reward_data['discount_type'] ?? 'fixed_cart' ),
                        'free_shipping'      => !empty( $reward_data['free_shipping'] ),
                        'product_id'         => absint( $reward_data['product_id'] ?? 0 ),
                        'show_product_value' => !empty( $reward_data['show_product_value'] ),
                    ];
                    // Badge / icon fields.
                    $rw_raw_icon = sanitize_text_field( $reward_data['badge_icon'] ?? '' );
                    // "Product Image" is a UI-only icon option for product / bundle rewards.
                    // It maps to the use_product_image flag and leaves the badge at default.
                    $rw_is_product_like = in_array( $sanitized_reward['type'], array('product', 'product_bundle'), true );
                    $rw_use_product_image = $rw_is_product_like && 'product_image' === $rw_raw_icon;
                    if ( $rw_use_product_image ) {
                        $rw_raw_icon = '';
                    }
                    $sanitized_reward['use_product_image'] = $rw_use_product_image;
                    $rw_raw_url = esc_url_raw( $reward_data['badge_url'] ?? '' );
                    $rw_raw_text = sanitize_text_field( $reward_data['badge_text'] ?? '' );
                    $rw_raw_type = sanitize_text_field( $reward_data['badge_type'] ?? '' );
                    $rw_raw_color = ( isset( $reward_data['badge_color'] ) ? sanitize_hex_color( $reward_data['badge_color'] ) : '' );
                    $rw_raw_color = ( is_string( $rw_raw_color ) ? $rw_raw_color : '' );
                    $rw_allowed_badge_types = ['preset', 'custom_url', 'custom_text'];
                    $rw_badge_type = ( in_array( $rw_raw_type, $rw_allowed_badge_types, true ) ? $rw_raw_type : '' );
                    if ( '' === $rw_badge_type ) {
                        if ( '' !== $rw_raw_url ) {
                            $rw_badge_type = 'custom_url';
                        } elseif ( '' !== $rw_raw_text ) {
                            $rw_badge_type = 'custom_text';
                        } elseif ( '' !== $rw_raw_icon ) {
                            $rw_badge_type = 'preset';
                        }
                    }
                    if ( 'custom_url' === $rw_badge_type ) {
                        $rw_raw_icon = '';
                        $rw_raw_text = '';
                    } elseif ( 'custom_text' === $rw_badge_type ) {
                        if ( '' === $rw_raw_text && '' !== $rw_raw_icon ) {
                            $rw_raw_text = $rw_raw_icon;
                        }
                        $rw_raw_icon = '';
                        $rw_raw_url = '';
                    } else {
                        $rw_raw_url = '';
                        $rw_raw_text = '';
                    }
                    if ( '' !== $rw_badge_type ) {
                        $sanitized_reward['badge_type'] = $rw_badge_type;
                        $sanitized_reward['badge_icon'] = $rw_raw_icon;
                        $sanitized_reward['badge_url'] = $rw_raw_url;
                        $sanitized_reward['badge_text'] = $rw_raw_text;
                    }
                    $rw_effective_icon = ( '' !== $rw_raw_icon ? $rw_raw_icon : (( function_exists( 'spar_get_reward_type_default_icon' ) ? spar_get_reward_type_default_icon( $sanitized_reward['type'] ) : 'fa-solid fa-ticket' )) );
                    $rw_badge_color_supported = 'custom_text' === $rw_badge_type || in_array( $rw_badge_type, array('', 'preset'), true ) && function_exists( 'spar_is_font_awesome_icon_value' ) && spar_is_font_awesome_icon_value( $rw_effective_icon );
                    if ( $rw_badge_color_supported ) {
                        $sanitized_reward['badge_color_mode'] = $rw_badge_color_mode;
                        if ( 'custom' === $rw_badge_color_mode && '' !== $rw_raw_color ) {
                            $sanitized_reward['badge_color'] = $rw_raw_color;
                        }
                    }
                    if ( 'custom' === ($sanitized_reward['type'] ?? '') ) {
                        $custom_desc = sanitize_textarea_field( $reward_data['custom_description'] ?? '' );
                        if ( !empty( $custom_desc ) ) {
                            $sanitized_reward['custom_description'] = $custom_desc;
                        }
                        $developer_id = sanitize_key( $reward_data['developer_id'] ?? '' );
                        if ( !empty( $developer_id ) ) {
                            if ( in_array( $developer_id, $seen_dev_ids, true ) ) {
                                $dedupe_suffix = 2;
                                while ( in_array( $developer_id . '_' . $dedupe_suffix, $seen_dev_ids, true ) ) {
                                    $dedupe_suffix++;
                                }
                                $developer_id = $developer_id . '_' . $dedupe_suffix;
                            }
                            $sanitized_reward['developer_id'] = $developer_id;
                            $seen_dev_ids[] = $developer_id;
                        }
                    }
                    // Template coupon: validate it is a real published shop_coupon before saving.
                    $template_coupon_id = absint( $reward_data['template_coupon_id'] ?? 0 );
                    if ( $template_coupon_id > 0 && 'shop_coupon' === get_post_type( $template_coupon_id ) ) {
                        $sanitized_reward['template_coupon_id'] = $template_coupon_id;
                    }
                    if ( !empty( $sanitized_reward['id'] ) ) {
                        $sanitized_rewards[] = $sanitized_reward;
                    }
                }
            }
            $options['rewards'] = $sanitized_rewards;
        } else {
            $has_post = array_key_exists( $key, $post_data );
            if ( $has_post ) {
                $value = wp_unslash( $post_data[$key] );
                $options[$key] = spar_sanitize_field_value(
                    $value,
                    $field_data['type'],
                    $field_data['default'],
                    $key
                );
            } elseif ( in_array( $key, array('redeem_discount_fee_taxable', 'redeem_discount_fee_tax_class_mode', 'redeem_discount_fee_tax_class'), true ) && function_exists( 'spar_is_avalara_tax_plugin_active' ) && !spar_is_avalara_tax_plugin_active() ) {
                continue;
            } elseif ( 0 === strpos( $key, 'subscriptions_' ) && empty( $post_data['subscriptions_present'] ) ) {
                // The Subscriptions tab only renders when WooCommerce Subscriptions is
                // active — preserve saved values when its section was absent from the payload.
                continue;
            } elseif ( 'array' === $field_data['type'] && $form_complete && in_array( $key, [
                'dashboard_tabs_order',
                'redeem_currency_rates',
                'redeem_exclude_product_ids',
                'redeem_exclude_category_ids'
            ], true ) ) {
                $options[$key] = spar_sanitize_settings_array_field( [], $key, $field_data['default'] );
            } elseif ( 'checkbox' === $field_data['type'] && $form_complete ) {
                $options[$key] = false;
            }
        }
    }
    $db_cleaned_paths = array();
    $options = spar_prepare_settings_options_for_database( $options, $db_cleaned_paths );
    $saved = spar_save_settings_option_value( $options );
    if ( !$saved ) {
        global $wpdb;
        $db_error = ( isset( $wpdb->last_error ) ? trim( (string) $wpdb->last_error ) : '' );
        $message = esc_html__( 'Settings were submitted but could not be saved to the database.', 'simple-points-and-rewards' );
        if ( '' !== $db_error ) {
            $message .= ' ' . sprintf( 
                /* translators: %s: database error message. */
                esc_html__( 'Database error: %s', 'simple-points-and-rewards' ),
                esc_html( $db_error )
             );
        } elseif ( !empty( $db_cleaned_paths ) ) {
            $db_cleaned_paths = array_values( array_unique( $db_cleaned_paths ) );
            $message .= ' ' . sprintf( 
                /* translators: %d: number of fields cleaned before saving. */
                esc_html__( 'Unsupported characters were removed from %d field(s), but WordPress still could not verify the saved option.', 'simple-points-and-rewards' ),
                count( $db_cleaned_paths )
             );
        }
        return new WP_Error('spar_settings_not_persisted', $message);
    }
    if ( !get_option( 'spar_user_settings_first_saved', 0 ) ) {
        update_option( 'spar_user_settings_first_saved', current_time( 'timestamp' ), false );
    }
    return true;
}

add_action( 'wp_ajax_spar_generate_terms', 'spar_ajax_generate_terms' );
/**
 * AJAX: Generate Terms and Conditions content based on current plugin settings.
 */
function spar_ajax_generate_terms() {
    check_ajax_referer( 'spar_generate_terms', 'nonce' );
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( esc_html__( 'Permission denied', 'simple-points-and-rewards' ) );
    }
    $options = get_option( 'spar_options', [] );
    $site_name = get_bloginfo( 'name' );
    $points_label = ( isset( $options['points_label'] ) && '' !== $options['points_label'] ? esc_html( $options['points_label'] ) : esc_html__( 'Reward Points', 'simple-points-and-rewards' ) );
    $rewards_label = ( isset( $options['rewards_label'] ) && '' !== $options['rewards_label'] ? esc_html( $options['rewards_label'] ) : esc_html__( 'Rewards', 'simple-points-and-rewards' ) );
    $date_str = date_i18n( get_option( 'date_format' ) );
    // ---- Collect active earning methods ----
    $earn = ( isset( $options['earn'] ) && is_array( $options['earn'] ) ? $options['earn'] : [] );
    $earn_items = [];
    if ( !empty( $earn['signup']['enabled'] ) ) {
        $pts = ( isset( $earn['signup']['points'] ) ? (int) $earn['signup']['points'] : 0 );
        /* translators: %1$s: points amount, %2$s: points label */
        $earn_items[] = sprintf( esc_html__( 'Creating a new account (%1$s %2$s)', 'simple-points-and-rewards' ), number_format_i18n( $pts ), $points_label );
    }
    if ( !empty( $earn['order']['enabled'] ) || !empty( $earn['order_fixed']['enabled'] ) ) {
        $earn_items[] = esc_html__( 'Completing a qualifying purchase', 'simple-points-and-rewards' );
    }
    if ( !empty( $earn['first_order']['enabled'] ) ) {
        $pts = ( isset( $earn['first_order']['points'] ) ? (int) $earn['first_order']['points'] : 0 );
        /* translators: %1$s: points amount, %2$s: points label */
        $earn_items[] = sprintf( esc_html__( 'Placing your first order (%1$s %2$s bonus)', 'simple-points-and-rewards' ), number_format_i18n( $pts ), $points_label );
    }
    if ( !empty( $earn['nth_order']['enabled'] ) ) {
        $n = ( isset( $earn['nth_order']['order_count'] ) ? (int) $earn['nth_order']['order_count'] : 0 );
        $pts = ( isset( $earn['nth_order']['points'] ) ? (int) $earn['nth_order']['points'] : 0 );
        /* translators: %1$s: order number, %2$s: points amount, %3$s: points label */
        $earn_items[] = sprintf(
            esc_html__( 'Reaching your %1$s%2$s order (%3$s %4$s bonus)', 'simple-points-and-rewards' ),
            number_format_i18n( $n ),
            esc_html__( 'th', 'simple-points-and-rewards' ),
            number_format_i18n( $pts ),
            $points_label
        );
    }
    if ( !empty( $earn['referral']['enabled'] ) ) {
        $earn_items[] = esc_html__( 'Referring a friend who makes a purchase', 'simple-points-and-rewards' );
    }
    if ( !empty( $earn['review']['enabled'] ) ) {
        $earn_items[] = esc_html__( 'Leaving a verified product review', 'simple-points-and-rewards' );
    }
    if ( !empty( $earn['birthday']['enabled'] ) ) {
        $pts = ( isset( $earn['birthday']['points'] ) ? (int) $earn['birthday']['points'] : 0 );
        /* translators: %1$s: points amount, %2$s: points label */
        $earn_items[] = sprintf( esc_html__( 'Your birthday (%1$s %2$s gift)', 'simple-points-and-rewards' ), number_format_i18n( $pts ), $points_label );
    }
    if ( !empty( $earn['daily_login']['enabled'] ) ) {
        $pts = ( isset( $earn['daily_login']['points'] ) ? (int) $earn['daily_login']['points'] : 0 );
        /* translators: %1$s: points amount, %2$s: points label */
        $earn_items[] = sprintf( esc_html__( 'Logging in daily (%1$s %2$s per day)', 'simple-points-and-rewards' ), number_format_i18n( $pts ), $points_label );
    }
    if ( !empty( $earn['spin_wheel']['enabled'] ) ) {
        $name = ( isset( $earn['spin_wheel']['name'] ) && '' !== $earn['spin_wheel']['name'] ? esc_html( $earn['spin_wheel']['name'] ) : esc_html__( 'Prize Wheel', 'simple-points-and-rewards' ) );
        /* translators: %s: spin wheel name */
        $earn_items[] = sprintf( esc_html__( 'Spinning the %s', 'simple-points-and-rewards' ), $name );
    }
    // ---- Collect active rewards ----
    $rewards = ( isset( $options['rewards'] ) && is_array( $options['rewards'] ) ? $options['rewards'] : [] );
    $reward_items = [];
    foreach ( $rewards as $reward ) {
        if ( !is_array( $reward ) ) {
            continue;
        }
        $status = ( isset( $reward['status'] ) ? $reward['status'] : 'active' );
        if ( 'inactive' === $status ) {
            continue;
        }
        $name = ( isset( $reward['name'] ) && '' !== $reward['name'] ? esc_html( $reward['name'] ) : '' );
        $points = ( isset( $reward['points'] ) ? (int) $reward['points'] : 0 );
        if ( '' === $name || $points <= 0 ) {
            continue;
        }
        /* translators: %1$s: reward name, %2$s: points cost, %3$s: points label */
        $reward_items[] = sprintf(
            esc_html__( '%1$s (%2$s %3$s)', 'simple-points-and-rewards' ),
            $name,
            number_format_i18n( $points ),
            $points_label
        );
    }
    // ---- Collect active levels ----
    $levels_enabled = !empty( $options['levels_enabled'] );
    $level_names = [];
    if ( $levels_enabled ) {
        $starter_name = ( isset( $options['levels_starter_name'] ) && '' !== $options['levels_starter_name'] ? esc_html( $options['levels_starter_name'] ) : esc_html__( 'Starter', 'simple-points-and-rewards' ) );
        $level_names[] = $starter_name;
        $saved_levels = ( isset( $options['levels'] ) && is_array( $options['levels'] ) ? $options['levels'] : [] );
        foreach ( $saved_levels as $level ) {
            if ( is_array( $level ) && !empty( $level['name'] ) ) {
                $level_names[] = esc_html( $level['name'] );
            }
        }
    }
    // ---- Expiry ----
    $expiry_enabled = !empty( $options['points_inactivity_expiry_enabled'] );
    $expiry_days = ( isset( $options['points_inactivity_expiry_days'] ) ? (int) $options['points_inactivity_expiry_days'] : 0 );
    // ---- Voucher redemption ----
    $redeem_rate_points = ( isset( $options['redeem_points_per_points'] ) ? (float) $options['redeem_points_per_points'] : 100 );
    $redeem_rate_amount = ( isset( $options['redeem_points_per_amount'] ) ? (float) $options['redeem_points_per_amount'] : 1 );
    $currency_symbol = ( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$' );
    // ---- Build the HTML content ----
    $section_num = 0;
    $html = '<h3>' . sprintf( 
        /* translators: %1$s: rewards label, %2$s: site name */
        esc_html__( '%1$s Program – Terms and Conditions', 'simple-points-and-rewards' ),
        $rewards_label
     ) . '</h3>';
    $html .= '<p>' . sprintf( 
        /* translators: %1$s: site name, %2$s: rewards label */
        esc_html__( 'Welcome to the %1$s %2$s Program. By participating, you agree to the following terms and conditions. Please read them carefully.', 'simple-points-and-rewards' ),
        esc_html( $site_name ),
        $rewards_label
     ) . '</p>';
    // 1. Eligibility
    $section_num++;
    $html .= '<h4>' . $section_num . '. ' . esc_html__( 'Eligibility', 'simple-points-and-rewards' ) . '</h4>';
    $html .= '<p>' . esc_html__( 'The rewards program is open to registered account holders only. By creating an account and participating in the program, you confirm that you agree to these terms. We reserve the right to refuse or revoke participation at our discretion.', 'simple-points-and-rewards' ) . '</p>';
    // 2. Earning Points
    $section_num++;
    $html .= '<h4>' . $section_num . '. ' . sprintf( esc_html__( 'Earning %s', 'simple-points-and-rewards' ), $points_label ) . '</h4>';
    if ( !empty( $earn_items ) ) {
        $html .= '<p>' . sprintf( 
            /* translators: %s: points label */
            esc_html__( 'You can earn %s by completing the following actions:', 'simple-points-and-rewards' ),
            $points_label
         ) . '</p>';
        $html .= '<ul>';
        foreach ( $earn_items as $item ) {
            $html .= '<li>' . $item . '</li>';
        }
        $html .= '</ul>';
    } else {
        $html .= '<p>' . sprintf( 
            /* translators: %s: points label */
            esc_html__( '%s are awarded for qualifying actions as outlined in the rewards dashboard. Points are credited once the qualifying action is confirmed.', 'simple-points-and-rewards' ),
            $points_label
         ) . '</p>';
    }
    $html .= '<p>' . sprintf( 
        /* translators: %s: points label */
        esc_html__( '%s have no cash value and are non-transferable. They may not be combined with other offers unless stated otherwise.', 'simple-points-and-rewards' ),
        $points_label
     ) . '</p>';
    // 3. Redeeming / Rewards
    $section_num++;
    $html .= '<h4>' . $section_num . '. ' . sprintf( esc_html__( 'Redeeming %s', 'simple-points-and-rewards' ), $points_label ) . '</h4>';
    if ( !empty( $reward_items ) ) {
        $html .= '<p>' . sprintf( 
            /* translators: %s: points label */
            esc_html__( 'Accumulated %s may be exchanged for the following rewards:', 'simple-points-and-rewards' ),
            $points_label
         ) . '</p>';
        $html .= '<ul>';
        foreach ( $reward_items as $item ) {
            $html .= '<li>' . $item . '</li>';
        }
        $html .= '</ul>';
    } else {
        $html .= '<p>' . sprintf( 
            /* translators: %s: points label */
            esc_html__( 'Accumulated %s may be redeemed for rewards as listed in the rewards dashboard. Rewards are subject to availability and may change at any time.', 'simple-points-and-rewards' ),
            $points_label
         ) . '</p>';
    }
    // Exchange rate clause
    $rate_amount_fmt = ( function_exists( 'wc_price' ) ? wc_price( $redeem_rate_amount ) : $currency_symbol . number_format_i18n( $redeem_rate_amount, 2 ) );
    $html .= '<p>' . sprintf(
        /* translators: %1$s: points amount, %2$s: points label, %3$s: currency amount */
        esc_html__( 'The standard redemption rate is %1$s %2$s = %3$s discount, unless otherwise specified for a particular reward.', 'simple-points-and-rewards' ),
        number_format_i18n( $redeem_rate_points ),
        $points_label,
        $rate_amount_fmt
    ) . '</p>';
    // 4. Levels (conditional)
    if ( $levels_enabled && !empty( $level_names ) ) {
        $section_num++;
        $html .= '<h4>' . $section_num . '. ' . esc_html__( 'Levels & Badges', 'simple-points-and-rewards' ) . '</h4>';
        $html .= '<p>' . sprintf( 
            /* translators: %s: points label */
            esc_html__( 'The program features a tiered level system. Your level is determined by your total %s earned over time. Levels may unlock additional benefits such as bonus multipliers. Current levels include:', 'simple-points-and-rewards' ),
            $points_label
         ) . ' ' . implode( ', ', $level_names ) . '.</p>';
    }
    // 5. Validity / Expiry
    $section_num++;
    $html .= '<h4>' . $section_num . '. ' . sprintf( esc_html__( '%s Validity', 'simple-points-and-rewards' ), $points_label ) . '</h4>';
    if ( $expiry_enabled && $expiry_days > 0 ) {
        $html .= '<p>' . sprintf( 
            /* translators: %1$s: points label, %2$s: number of days */
            _n(
                '%1$s will expire after %2$s day of account inactivity (no new %1$s earned). Earning new points at any time resets the inactivity timer.',
                '%1$s will expire after %2$s days of account inactivity (no new %1$s earned). Earning new points at any time resets the inactivity timer.',
                $expiry_days,
                'simple-points-and-rewards'
            ),
            $points_label,
            number_format_i18n( $expiry_days )
         ) . '</p>';
    } else {
        $html .= '<p>' . sprintf( 
            /* translators: %s: points label */
            esc_html__( '%s remain valid while your account is in good standing. We reserve the right to introduce expiry policies with reasonable advance notice.', 'simple-points-and-rewards' ),
            $points_label
         ) . '</p>';
    }
    // 6. Account Conduct
    $section_num++;
    $html .= '<h4>' . $section_num . '. ' . esc_html__( 'Account Conduct & Fraud', 'simple-points-and-rewards' ) . '</h4>';
    $html .= '<p>' . sprintf( 
        /* translators: %s: points label */
        esc_html__( 'Any attempt to manipulate, abuse, or fraudulently earn %s — including but not limited to fake accounts, fraudulent orders, or automated activity — may result in the immediate suspension of your account, cancellation of all accumulated points, and exclusion from the program.', 'simple-points-and-rewards' ),
        $points_label
     ) . '</p>';
    // 7. Program Changes
    $section_num++;
    $html .= '<h4>' . $section_num . '. ' . esc_html__( 'Program Changes', 'simple-points-and-rewards' ) . '</h4>';
    $html .= '<p>' . sprintf( 
        /* translators: %s: site name */
        esc_html__( '%s reserves the right to modify, suspend, or discontinue any aspect of the rewards program at any time, including point values, reward availability, and earning rules. Where possible, significant changes will be communicated in advance.', 'simple-points-and-rewards' ),
        esc_html( $site_name )
     ) . '</p>';
    // 8. Governing Law
    $section_num++;
    $html .= '<h4>' . $section_num . '. ' . esc_html__( 'Governing Law', 'simple-points-and-rewards' ) . '</h4>';
    $html .= '<p>' . esc_html__( 'These terms are governed by applicable local laws. Continued participation in the program constitutes acceptance of these terms and any future amendments.', 'simple-points-and-rewards' ) . '</p>';
    $html .= '<p><em>' . esc_html__( 'Last updated: ', 'simple-points-and-rewards' ) . esc_html( $date_str ) . '</em></p>';
    wp_send_json_success( [
        'html' => $html,
    ] );
}

add_action( 'wp_ajax_spar_save_settings_ajax', 'spar_save_settings_ajax_handler' );
function spar_save_settings_ajax_handler() {
    check_ajax_referer( 'spar_save_settings_action', 'nonce' );
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( esc_html__( 'Permission denied', 'simple-points-and-rewards' ) );
    }
    // Parse the serialized form data
    if ( empty( $_POST['data'] ) || !is_string( $_POST['data'] ) ) {
        wp_send_json_error( esc_html__( 'Settings payload missing', 'simple-points-and-rewards' ) );
    }
    $params = spar_parse_settings_form_data( wp_unslash( $_POST['data'] ) );
    if ( empty( $params ) || !is_array( $params ) ) {
        wp_send_json_error( esc_html__( 'Settings payload invalid', 'simple-points-and-rewards' ) );
    }
    $payload_validation = spar_validate_settings_payload( $params );
    if ( is_wp_error( $payload_validation ) ) {
        wp_send_json_error( $payload_validation->get_error_message() );
    }
    $save_result = spar_process_settings_save( $params );
    if ( is_wp_error( $save_result ) ) {
        wp_send_json_error( $save_result->get_error_message() );
    }
    if ( $save_result ) {
        wp_send_json_success( esc_html__( 'Settings saved', 'simple-points-and-rewards' ) );
    } else {
        wp_send_json_error( esc_html__( 'Failed to save settings', 'simple-points-and-rewards' ) );
    }
}

function spar_handle_admin_load_points_history_ajax() {
    check_ajax_referer( 'spar_customer_detail_pagination', 'nonce', true );
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( esc_html__( 'Insufficient permissions.', 'simple-points-and-rewards' ) );
    }
    $user_id = ( isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0 );
    if ( !$user_id || !get_userdata( $user_id ) ) {
        wp_send_json_error( esc_html__( 'Invalid user ID.', 'simple-points-and-rewards' ) );
    }
    $page = max( 1, intval( $_POST['page'] ?? 1 ) );
    $per_page = 10;
    $history_data = ( function_exists( 'spar_get_user_points_history' ) ? spar_get_user_points_history( $user_id, $page, $per_page ) : array(
        'logs'       => array(),
        'pagination' => array(),
    ) );
    $logs = $history_data['logs'] ?? array();
    $pagination = $history_data['pagination'] ?? array();
    $table_html = ( function_exists( 'spar_build_customer_detail_points_rows' ) ? spar_build_customer_detail_points_rows( $logs ) : '' );
    $pagination_html = ( function_exists( 'spar_build_customer_detail_pagination_html' ) ? spar_build_customer_detail_pagination_html( $pagination, 'points' ) : '' );
    wp_send_json_success( array(
        'table_html'      => $table_html,
        'pagination_html' => $pagination_html,
    ) );
}

function spar_handle_admin_load_referral_clicks_ajax() {
    check_ajax_referer( 'spar_customer_detail_pagination', 'nonce', true );
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( esc_html__( 'Insufficient permissions.', 'simple-points-and-rewards' ) );
    }
    $user_id = ( isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0 );
    if ( !$user_id || !get_userdata( $user_id ) ) {
        wp_send_json_error( esc_html__( 'Invalid user ID.', 'simple-points-and-rewards' ) );
    }
    $page = max( 1, intval( $_POST['page'] ?? 1 ) );
    $per_page = 10;
    $history_data = ( function_exists( 'spar_get_user_referral_clicks_history_full' ) ? spar_get_user_referral_clicks_history_full( $user_id, $page, $per_page ) : array(
        'logs'       => array(),
        'pagination' => array(),
    ) );
    $logs = $history_data['logs'] ?? array();
    $pagination = $history_data['pagination'] ?? array();
    $table_html = ( function_exists( 'spar_build_customer_detail_referral_rows' ) ? spar_build_customer_detail_referral_rows( $logs ) : '' );
    $pagination_html = ( function_exists( 'spar_build_customer_detail_pagination_html' ) ? spar_build_customer_detail_pagination_html( $pagination, 'referrals' ) : '' );
    wp_send_json_success( array(
        'table_html'      => $table_html,
        'pagination_html' => $pagination_html,
    ) );
}

function spar_handle_admin_load_customer_orders_ajax() {
    check_ajax_referer( 'spar_customer_detail_pagination', 'nonce', true );
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( esc_html__( 'Insufficient permissions.', 'simple-points-and-rewards' ) );
    }
    $user_id = ( isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0 );
    if ( !$user_id || !get_userdata( $user_id ) ) {
        wp_send_json_error( esc_html__( 'Invalid user ID.', 'simple-points-and-rewards' ) );
    }
    if ( !function_exists( 'wc_get_orders' ) ) {
        wp_send_json_error( esc_html__( 'Orders unavailable.', 'simple-points-and-rewards' ) );
    }
    $page = max( 1, intval( $_POST['page'] ?? 1 ) );
    $per_page = 10;
    $orders_query = wc_get_orders( array(
        'customer_id' => $user_id,
        'limit'       => $per_page,
        'orderby'     => 'date',
        'order'       => 'DESC',
        'paginate'    => true,
        'paged'       => $page,
    ) );
    $orders = array();
    $pagination = array();
    if ( is_object( $orders_query ) ) {
        $orders = $orders_query->orders;
        $total_pages = (int) $orders_query->max_num_pages;
        $pagination = array(
            'current_page' => $page,
            'per_page'     => $per_page,
            'total_count'  => (int) $orders_query->total,
            'total_pages'  => $total_pages,
            'has_previous' => $page > 1,
            'has_next'     => $page < $total_pages,
        );
    }
    $points_label = ( function_exists( 'spar_get_option' ) ? spar_get_option( '', 'points_label' ) : '' );
    $points_label = ( $points_label ? $points_label : esc_html__( 'Points', 'simple-points-and-rewards' ) );
    $table_html = ( function_exists( 'spar_build_customer_detail_orders_rows' ) ? spar_build_customer_detail_orders_rows( $orders, $points_label, $user_id ) : '' );
    $pagination_html = ( function_exists( 'spar_build_customer_detail_pagination_html' ) ? spar_build_customer_detail_pagination_html( $pagination, 'orders' ) : '' );
    wp_send_json_success( array(
        'table_html'      => $table_html,
        'pagination_html' => $pagination_html,
    ) );
}

/**
 * AJAX handler to permanently hide the Quick Setup Guide for the current user.
 */
add_action( 'wp_ajax_spar_hide_setup_guide', 'spar_ajax_hide_setup_guide' );
function spar_ajax_hide_setup_guide() {
    check_ajax_referer( 'spar_admin_nonce', 'nonce' );
    if ( !current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( esc_html__( 'Permission denied.', 'simple-points-and-rewards' ) );
    }
    update_user_meta( get_current_user_id(), 'spar_hide_setup_guide', 1 );
    wp_send_json_success();
}
