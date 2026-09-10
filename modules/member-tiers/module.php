<?php
/**
 * WU Toolbox Modular：會員階級系統。
 *
 * WooCommerce 會員分級功能。自訂無限階級（名稱、徽章、排序），支援單筆消費金額、
 *              期間累積消費金額、期間累積消費次數三種升級門檻（符合任一即升級），效期與續約金額，
 *              訂單需「已完成」+ 鑑賞期天數才正式計入資格（防退貨刷等級），每日自動滾動計算升降級並
 *              寄送 Email 通知，權益模組（天天折扣、點數倍率、生日禮券、每月免運券），CRM 會員搜尋／
 *              篩選／分頁／CSV 匯出／一鍵初始化，前台 VIP 尊榮看板、升級進度條、購物車動態提醒、結帳
 *              自動套用折扣與免運、VIP 隱密賣場。後台並提供手動測試排程與寄送測試信功能。HPOS 相容。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WC_Membership_Tiers' ) ) :

final class WC_Membership_Tiers {

    const OPTION_KEY        = 'wmt_settings';
    const LOG_OPT           = 'wmt_last_run_log';
    const CRON_HOOK_DAILY   = 'wmt_daily_recalculate';
    const CRON_HOOK_CONFIRM = 'wmt_confirm_single_order';
    const VERSION           = '1.2.0-wutm';
    const VERSION_OPT       = 'wmt_plugin_version';

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // WU Toolbox controls this module's lifecycle; initialization below
        // performs the one-time setup that a standalone activation hook used to do.
        add_action( 'before_woocommerce_init', array( $this, 'wmt_declare_hpos_compatibility' ) );
        add_action( 'plugins_loaded', array( $this, 'wmt_init' ) );
    }

    public function wmt_declare_hpos_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }

    /* =================================================================
     * 啟用 / 停用 / 資料表
     * ================================================================= */

    public function wmt_activate() {
        $this->wmt_create_tables();
        if ( false === get_option( self::OPTION_KEY ) ) {
            update_option( self::OPTION_KEY, $this->wmt_default_settings() );
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK_DAILY ) ) {
            wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', self::CRON_HOOK_DAILY );
        }
        $this->wmt_maybe_seed_default_tier();
        add_rewrite_endpoint( 'vip-tier', EP_ROOT | EP_PAGES );
        flush_rewrite_rules();
        update_option( self::VERSION_OPT, self::VERSION );
    }

    public function wmt_deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK_DAILY );
        flush_rewrite_rules();
    }

    private function wmt_create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tiers = $wpdb->prefix . 'wmt_tiers';
        dbDelta( "CREATE TABLE {$tiers} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            level INT NOT NULL DEFAULT 0,
            badge_url VARCHAR(500) NULL,
            is_default TINYINT NOT NULL DEFAULT 0,
            single_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            cum_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            cum_amount_days INT NOT NULL DEFAULT 365,
            cum_count INT NOT NULL DEFAULT 0,
            cum_count_days INT NOT NULL DEFAULT 180,
            valid_days INT NOT NULL DEFAULT 365,
            renewal_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
            points_multiplier DECIMAL(5,2) NOT NULL DEFAULT 1,
            birthday_coupon_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            free_shipping_per_month INT NOT NULL DEFAULT 0,
            free_shipping_always TINYINT NOT NULL DEFAULT 0,
            status TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY level (level)
        ) {$charset_collate};" );

        $user_tier = $wpdb->prefix . 'wmt_user_tier';
        dbDelta( "CREATE TABLE {$user_tier} (
            user_id BIGINT UNSIGNED NOT NULL,
            tier_id BIGINT UNSIGNED NOT NULL,
            assigned_at DATETIME NOT NULL,
            expires_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (user_id),
            KEY tier_id (tier_id)
        ) {$charset_collate};" );

        $orders = $wpdb->prefix . 'wmt_qualified_orders';
        dbDelta( "CREATE TABLE {$orders} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            order_date DATETIME NOT NULL,
            confirm_after DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            counted_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY order_id (order_id),
            KEY user_id (user_id),
            KEY status (status)
        ) {$charset_collate};" );

        $history = $wpdb->prefix . 'wmt_history_log';
        dbDelta( "CREATE TABLE {$history} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            from_tier_id BIGINT UNSIGNED NULL,
            to_tier_id BIGINT UNSIGNED NULL,
            reason VARCHAR(50) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) {$charset_collate};" );

        $coupons = $wpdb->prefix . 'wmt_coupons_issued';
        dbDelta( "CREATE TABLE {$coupons} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            tier_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(30) NOT NULL,
            coupon_code VARCHAR(100) NOT NULL,
            month_key VARCHAR(7) NOT NULL,
            issued_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_month (user_id, type, month_key)
        ) {$charset_collate};" );
    }

    public function wmt_ensure_tables_exist() {
        global $wpdb;
        $need = array( 'wmt_tiers', 'wmt_user_tier', 'wmt_qualified_orders', 'wmt_history_log', 'wmt_coupons_issued' );
        foreach ( $need as $t ) {
            $full = $wpdb->prefix . $t;
            if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) ) {
                $this->wmt_create_tables();
                break;
            }
        }
    }

    private function wmt_maybe_seed_default_tier() {
        global $wpdb;
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wmt_tiers" );
        if ( $count > 0 ) return;
        $wpdb->insert( $wpdb->prefix . 'wmt_tiers', array(
            'name' => '一般會員', 'slug' => 'general', 'level' => 0, 'is_default' => 1,
            'valid_days' => 0, 'discount_percent' => 0, 'points_multiplier' => 1,
            'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
        ) );
    }

    /* =================================================================
     * 初始化
     * ================================================================= */

    public function wmt_init() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-error"><p>「會員階級系統」外掛需要先安裝並啟用 WooCommerce。</p></div>';
            } );
            return;
        }

        if ( get_option( self::VERSION_OPT ) !== self::VERSION ) {
            $this->wmt_create_tables();
            $this->wmt_maybe_seed_default_tier();
        }
        if ( false === get_option( self::OPTION_KEY ) ) {
            update_option( self::OPTION_KEY, $this->wmt_default_settings() );
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK_DAILY ) ) {
            wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', self::CRON_HOOK_DAILY );
        }

        // 後台
        add_action( 'admin_menu', array( $this, 'wmt_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'wmt_admin_assets' ) );
        add_action( 'admin_post_wmt_save_tier', array( $this, 'wmt_handle_save_tier' ) );
        add_action( 'admin_post_wmt_delete_tier', array( $this, 'wmt_handle_delete_tier' ) );
        add_action( 'admin_post_wmt_save_settings', array( $this, 'wmt_handle_save_settings' ) );
        add_action( 'admin_post_wmt_export_members', array( $this, 'wmt_handle_export_members' ) );
        add_action( 'admin_post_wmt_manual_set_tier', array( $this, 'wmt_handle_manual_set_tier' ) );
        add_action( 'admin_post_wmt_run_manual_cycle', array( $this, 'wmt_handle_run_manual_cycle' ) );
        add_action( 'admin_post_wmt_send_test_email', array( $this, 'wmt_handle_send_test_email' ) );
        add_action( 'admin_post_wmt_init_all_members', array( $this, 'wmt_handle_init_all_members' ) );
        add_action( 'admin_post_wmt_save_birthday', array( $this, 'wmt_handle_save_birthday' ) );
        add_action( 'admin_post_wmt_reset_birthday', array( $this, 'wmt_handle_reset_birthday' ) );

        // Keep the module's own master switch functional. The WU Toolbox card
        // controls whether this file loads at all; this secondary switch lets an
        // administrator temporarily pause benefits without losing settings.
        $settings = $this->wmt_get_settings();
        if ( $settings['enable'] !== 'yes' ) return;

        // 商品「VIP 隱密賣場」設定
        add_action( 'add_meta_boxes', array( $this, 'wmt_add_product_meta_box' ) );
        add_action( 'save_post_product', array( $this, 'wmt_save_product_meta_box' ) );

        // Cron
        add_action( self::CRON_HOOK_DAILY, array( $this, 'wmt_run_daily_cycle' ) );
        add_action( self::CRON_HOOK_CONFIRM, array( $this, 'wmt_confirm_single_order' ), 10, 1 );

        // 訂單狀態掛勾（鑑賞期＋防退貨刷等級）
        add_action( 'woocommerce_order_status_completed', array( $this, 'wmt_on_order_completed' ) );
        add_action( 'woocommerce_order_status_cancelled', array( $this, 'wmt_on_order_reversed' ) );
        add_action( 'woocommerce_order_status_refunded', array( $this, 'wmt_on_order_reversed' ) );
        add_action( 'woocommerce_order_status_failed', array( $this, 'wmt_on_order_reversed' ) );

        // 新會員註冊立即給預設階級，CRM 名單不會一開始整片空白
        add_action( 'user_register', array( $this, 'wmt_on_user_register' ) );

        // 我的帳戶 endpoint
        add_filter( 'woocommerce_account_menu_items', array( $this, 'wmt_add_my_account_menu_item' ) );
        add_action( 'init', array( $this, 'wmt_add_my_account_endpoint' ) );
        add_action( 'wp_loaded', array( $this, 'wmt_maybe_flush_rewrite_rules' ) );
        add_action( 'woocommerce_account_vip-tier_endpoint', array( $this, 'wmt_myaccount_endpoint_content' ) );

        // Shortcodes
        add_shortcode( 'wmt_dashboard', array( $this, 'wmt_shortcode_dashboard' ) );
        add_shortcode( 'wmt_progress', array( $this, 'wmt_shortcode_progress' ) );

        // 前台：折扣 / 免運 / 動態價格 / 購物車提醒
        add_filter( 'woocommerce_cart_calculate_fees', array( $this, 'wmt_apply_tier_discount_fee' ) );
        add_filter( 'woocommerce_package_rates', array( $this, 'wmt_force_free_shipping' ), 100, 2 );
        add_filter( 'woocommerce_get_price_html', array( $this, 'wmt_show_vip_price_html' ), 20, 2 );
        add_action( 'woocommerce_before_cart', array( $this, 'wmt_cart_upgrade_notice' ) );
        add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'wmt_cart_notice_fragment' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'wmt_frontend_assets' ) );

        // 前台：VIP 隱密賣場（限時只對指定階級以上開放）
        add_filter( 'woocommerce_is_purchasable', array( $this, 'wmt_restrict_purchasable' ), 20, 2 );
        add_action( 'woocommerce_before_single_product_summary', array( $this, 'wmt_maybe_block_single_product' ), 5 );
        add_action( 'pre_get_posts', array( $this, 'wmt_exclude_locked_products_from_archive' ) );

        // 積分倍率 filter（供其他外掛，如購物點數外掛，讀取套用倍率）
        add_filter( 'wmt_points_multiplier', array( $this, 'wmt_filter_points_multiplier' ), 10, 2 );
    }

    public function wmt_maybe_flush_rewrite_rules() {
        if ( get_option( self::VERSION_OPT ) !== self::VERSION ) {
            add_rewrite_endpoint( 'vip-tier', EP_ROOT | EP_PAGES );
            flush_rewrite_rules();
            update_option( self::VERSION_OPT, self::VERSION );
        }
    }

    public function wmt_admin_assets( $hook ) {
        if ( strpos( $hook, 'wmt-' ) === false && strpos( $hook, 'page_wmt' ) === false ) return;
        wp_enqueue_media();
    }

    public function wmt_frontend_assets() {
        if ( ! ( is_cart() || is_product() || is_account_page() ) ) return;
        wp_add_inline_style( 'woocommerce-general', $this->wmt_frontend_css() );
    }

    private function wmt_frontend_css() {
        return '
            .wmt-dashboard{max-width:640px;border:1px solid #eadfc4;border-radius:14px;padding:26px 28px;background:linear-gradient(160deg,#fffdf7,#fff);box-shadow:0 2px 10px rgba(0,0,0,.05)}
            .wmt-dash-head{display:flex;align-items:center;gap:14px}
            .wmt-dash-badge-img{width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid #d4af37}
            .wmt-dash-title{font-size:20px;font-weight:800;color:#7a5b12;margin:0}
            .wmt-dash-sub{color:#8a8a8a;font-size:13px;margin-top:2px}
            .wmt-section-title{font-size:14px;font-weight:700;margin:20px 0 8px;color:#333;border-left:4px solid #d4af37;padding-left:8px}
            .wmt-perks-list{list-style:none;padding:0;margin:0;display:grid;gap:8px}
            .wmt-perks-list li{padding:8px 12px;background:#fbf7ec;border-radius:8px;font-size:14px;color:#555}
            .wmt-progress-wrap{margin:8px 0 4px;background:#eee;border-radius:999px;overflow:hidden;height:16px;position:relative}
            .wmt-progress-bar{height:100%;background:linear-gradient(90deg,#e9c869,#b8860b);transition:width .5s}
            .wmt-progress-label{font-size:13px;color:#666;margin-bottom:4px}
            .wmt-progress-target{font-weight:700;color:#b8860b}
            .wmt-notice-box{margin-top:18px;padding:12px 14px;background:#eef6ff;border:1px solid #cfe4fb;border-radius:8px;font-size:13px;color:#3a5a78;line-height:1.6}
            .wmt-max-box{margin-top:14px;padding:14px;background:#fff6e0;border-radius:8px;text-align:center;color:#7a5b12;font-weight:700}
            .wmt-cart-notice{background:#fff8e1;border:1px solid #d4af37;padding:10px 14px;border-radius:6px;margin-bottom:14px;font-size:14px}
            .wmt-vip-price{color:#b8860b;font-weight:700}
            .wmt-locked-notice{padding:20px;background:#f5f5f5;border:1px dashed #999;text-align:center;border-radius:6px}
            .wmt-member-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:18px}
            .wmt-member-box{padding:16px;background:#fff;border:1px solid #eadfc4;border-radius:10px}
            .wmt-member-box h3{margin:0 0 10px;font-size:15px;color:#7a5b12}
            .wmt-birthday-form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
            .wmt-birthday-form input[type=date]{min-height:38px;padding:4px 8px}
            .wmt-primary-btn{border:0;border-radius:6px;background:#7a5b12;color:#fff;padding:9px 14px;cursor:pointer}
            .wmt-coupon-list{list-style:none;margin:0;padding:0;display:grid;gap:9px}
            .wmt-coupon-item{padding:10px 12px;background:#fbf7ec;border-radius:7px}
            .wmt-coupon-code{display:inline-block;font-family:monospace;font-weight:700;letter-spacing:.4px;margin:3px 0;color:#7a5b12;word-break:break-all}
            .wmt-coupon-meta{display:block;color:#777;font-size:12px}
            .wmt-help-text{color:#777;font-size:12px;line-height:1.6;margin:8px 0 0}
            .wmt-status-message{padding:10px 12px;margin:0 0 14px;border-radius:7px;background:#eef8ee;color:#256029}
            .wmt-status-message.wmt-error{background:#fff0f0;color:#a02020}
            @media(max-width:640px){.wmt-member-grid{grid-template-columns:1fr}}
        ';
    }

    /* =================================================================
     * 設定值
     * ================================================================= */

    public function wmt_default_settings() {
        return array(
            'enable'          => 'yes',
            'guarantee_days'  => 7,
            'require_status'  => 'completed',
            'notify_email'    => 'yes',
            'downgrade_to_default' => 'yes',
        );
    }

    public function wmt_get_settings() {
        $s = get_option( self::OPTION_KEY, array() );
        return wp_parse_args( $s, $this->wmt_default_settings() );
    }

    /* =================================================================
     * 階級 CRUD
     * ================================================================= */

    private function wmt_table_tiers() { global $wpdb; return $wpdb->prefix . 'wmt_tiers'; }
    private function wmt_table_user_tier() { global $wpdb; return $wpdb->prefix . 'wmt_user_tier'; }
    private function wmt_table_orders() { global $wpdb; return $wpdb->prefix . 'wmt_qualified_orders'; }
    private function wmt_table_history() { global $wpdb; return $wpdb->prefix . 'wmt_history_log'; }
    private function wmt_table_coupons() { global $wpdb; return $wpdb->prefix . 'wmt_coupons_issued'; }

    // 安全包裝：只有在真的有參數（佔位符）時才呼叫 $wpdb->prepare()，
    // 避免「查詢引數必須有前導字元」的 Notice（沒有 %s/%d 卻呼叫 prepare 會觸發）
    private function wmt_prepare_query( $sql, $params = array() ) {
        global $wpdb;
        if ( empty( $params ) ) {
            return $sql;
        }
        return $wpdb->prepare( $sql, $params );
    }

    // 統一組出 CRM 篩選用的 WHERE 片段與對應參數，供列表頁與匯出共用，
    // 絕不在這裡提前呼叫 prepare()，避免巢狀 prepare 造成佔位符被吃掉
    private function wmt_build_member_filter( $keyword, $filter_tier ) {
        global $wpdb;
        $where  = array( '1=1' );
        $params = array();

        if ( $keyword !== '' ) {
            $where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
            $like = '%' . $wpdb->esc_like( $keyword ) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ( $filter_tier === 'none' ) {
            $where[] = 'ut.tier_id IS NULL';
        } elseif ( $filter_tier !== '' && $filter_tier !== null ) {
            $where[] = 'ut.tier_id = %d';
            $params[] = intval( $filter_tier );
        }

        return array( implode( ' AND ', $where ), $params );
    }

    public function wmt_get_all_tiers( $active_only = false ) {
        global $wpdb;
        $sql = "SELECT * FROM {$this->wmt_table_tiers()}";
        if ( $active_only ) $sql .= " WHERE status = 1";
        $sql .= " ORDER BY level ASC";
        return $wpdb->get_results( $sql );
    }

    public function wmt_get_tier( $tier_id ) {
        global $wpdb;
        if ( ! $tier_id ) return null;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->wmt_table_tiers()} WHERE id = %d", $tier_id ) );
    }

    private function wmt_get_default_tier() {
        global $wpdb;
        $row = $wpdb->get_row( "SELECT * FROM {$this->wmt_table_tiers()} WHERE is_default = 1 ORDER BY level ASC LIMIT 1" );
        if ( $row ) return $row;
        return $wpdb->get_row( "SELECT * FROM {$this->wmt_table_tiers()} ORDER BY level ASC LIMIT 1" );
    }

    public function wmt_handle_save_tier() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足' );
        check_admin_referer( 'wmt_save_tier' );
        global $wpdb;

        $id = isset( $_POST['tier_id'] ) ? intval( $_POST['tier_id'] ) : 0;
        $data = array(
            'name'                     => sanitize_text_field( $_POST['name'] ?? '' ),
            'slug'                     => sanitize_title( $_POST['slug'] ?? ( $_POST['name'] ?? '' ) ),
            'level'                    => intval( $_POST['level'] ?? 0 ),
            'badge_url'                => esc_url_raw( $_POST['badge_url'] ?? '' ),
            'is_default'               => isset( $_POST['is_default'] ) ? 1 : 0,
            'single_amount'            => floatval( $_POST['single_amount'] ?? 0 ),
            'cum_amount'               => floatval( $_POST['cum_amount'] ?? 0 ),
            'cum_amount_days'          => intval( $_POST['cum_amount_days'] ?? 365 ),
            'cum_count'                => intval( $_POST['cum_count'] ?? 0 ),
            'cum_count_days'           => intval( $_POST['cum_count_days'] ?? 180 ),
            'valid_days'               => intval( $_POST['valid_days'] ?? 365 ),
            'renewal_amount'           => floatval( $_POST['renewal_amount'] ?? 0 ),
            'discount_percent'         => floatval( $_POST['discount_percent'] ?? 0 ),
            'points_multiplier'        => floatval( $_POST['points_multiplier'] ?? 1 ),
            'birthday_coupon_amount'   => floatval( $_POST['birthday_coupon_amount'] ?? 0 ),
            'free_shipping_per_month'  => intval( $_POST['free_shipping_per_month'] ?? 0 ),
            'free_shipping_always'     => isset( $_POST['free_shipping_always'] ) ? 1 : 0,
            'status'                   => isset( $_POST['status'] ) ? 1 : 0,
            'updated_at'               => current_time( 'mysql' ),
        );

        if ( $data['is_default'] ) {
            $wpdb->update( $this->wmt_table_tiers(), array( 'is_default' => 0 ), array( 'is_default' => 1 ) );
        }

        if ( $id > 0 ) {
            $wpdb->update( $this->wmt_table_tiers(), $data, array( 'id' => $id ) );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $this->wmt_table_tiers(), $data );
            $id = $wpdb->insert_id;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=wmt-tiers&updated=1' ) );
        exit;
    }

    public function wmt_handle_delete_tier() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足' );
        check_admin_referer( 'wmt_delete_tier' );
        global $wpdb;
        $id = intval( $_GET['tier_id'] ?? 0 );
        $wpdb->delete( $this->wmt_table_tiers(), array( 'id' => $id ) );
        wp_safe_redirect( admin_url( 'admin.php?page=wmt-tiers&deleted=1' ) );
        exit;
    }

    public function wmt_handle_save_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足' );
        check_admin_referer( 'wmt_save_settings' );
        $s = array(
            'enable'                => isset( $_POST['enable'] ) ? 'yes' : 'no',
            'guarantee_days'        => max( 0, intval( $_POST['guarantee_days'] ?? 7 ) ),
            'require_status'        => 'completed',
            'notify_email'          => isset( $_POST['notify_email'] ) ? 'yes' : 'no',
            'downgrade_to_default'  => isset( $_POST['downgrade_to_default'] ) ? 'yes' : 'no',
        );
        update_option( self::OPTION_KEY, $s );
        wp_safe_redirect( admin_url( 'admin.php?page=wmt-settings&updated=1' ) );
        exit;
    }

    public function wmt_handle_manual_set_tier() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足' );
        check_admin_referer( 'wmt_manual_set_tier' );
        $user_id = intval( $_POST['user_id'] ?? 0 );
        $tier_id = intval( $_POST['tier_id'] ?? 0 );
        $days    = intval( $_POST['valid_days_override'] ?? 0 );
        if ( $user_id && $tier_id ) {
            $tier = $this->wmt_get_tier( $tier_id );
            $valid_days = $days > 0 ? $days : ( $tier ? intval( $tier->valid_days ) : 0 );
            $this->wmt_assign_tier( $user_id, $tier_id, $valid_days, 'manual', true );
        }
        $redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wmt-members' );
        wp_safe_redirect( add_query_arg( 'msg', 'manual_set', $redirect ) );
        exit;
    }

    /**
     * 會員只能設定一次生日。已設定後，前台不接受覆寫；只有具 WooCommerce
     * 管理權限的管理員能先清除，會員才可重新填寫。
     */
    public function wmt_handle_save_birthday() {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }

        check_admin_referer( 'wmt_save_birthday' );
        $user_id = get_current_user_id();
        $redirect = wc_get_account_endpoint_url( 'vip-tier' );

        if ( get_user_meta( $user_id, 'billing_birthday', true ) ) {
            wp_safe_redirect( add_query_arg( 'wmt_birthday', 'locked', $redirect ) );
            exit;
        }

        $birthday = isset( $_POST['birthday'] ) ? sanitize_text_field( wp_unslash( $_POST['birthday'] ) ) : '';
        $date = DateTime::createFromFormat( '!Y-m-d', $birthday );
        $errors = DateTime::getLastErrors();
        $valid = $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) )
            && $date->format( 'Y-m-d' ) === $birthday
            && $birthday <= current_time( 'Y-m-d' );

        if ( ! $valid ) {
            wp_safe_redirect( add_query_arg( 'wmt_birthday', 'invalid', $redirect ) );
            exit;
        }

        add_user_meta( $user_id, 'billing_birthday', $birthday, true );
        $this->wmt_issue_monthly_perks_for_user( $user_id, date_i18n( 'Y-m' ) );
        wp_safe_redirect( add_query_arg( 'wmt_birthday', 'saved', $redirect ) );
        exit;
    }

    public function wmt_handle_reset_birthday() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足' );
        $user_id = intval( $_GET['user_id'] ?? 0 );
        check_admin_referer( 'wmt_reset_birthday_' . $user_id );
        if ( $user_id > 0 ) delete_user_meta( $user_id, 'billing_birthday' );
        $redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wmt-members' );
        wp_safe_redirect( add_query_arg( 'msg', 'birthday_reset', $redirect ) );
        exit;
    }

    // 手動立即跑一次每日排程（測試用），跟凌晨 3 點自動排程效果完全相同
    public function wmt_handle_run_manual_cycle() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足' );
        check_admin_referer( 'wmt_run_manual_cycle' );
        $result = $this->wmt_run_daily_cycle( true );
        set_transient( 'wmt_manual_run_result', $result, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=wmt-settings&manual_run=1' ) );
        exit;
    }

    // 寄一封測試信到目前登入的管理員信箱，確認 wp_mail() 送達狀況
    public function wmt_handle_send_test_email() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足' );
        check_admin_referer( 'wmt_send_test_email' );
        $to = wp_get_current_user()->user_email;
        $sent = $this->wmt_send_html_mail(
            $to,
            '會員階級系統｜測試信件',
            '<p>這是一封測試信，如果您收到這封信，代表本外掛的升級通知信功能可以正常寄出。</p><p>若沒收到，請檢查主機的郵件寄送設定（SMTP）或安裝 WP Mail SMTP 等外掛改善送達率。</p>'
        );
        set_transient( 'wmt_test_mail_result', array( 'to' => $to, 'sent' => $sent ), 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=wmt-settings&test_mail=1' ) );
        exit;
    }

    // 讓所有現有會員立即取得階級紀錄（避免會員名單一開始整片「尚未分級」）
    public function wmt_handle_init_all_members() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足' );
        check_admin_referer( 'wmt_init_all_members' );
        $users = get_users( array( 'fields' => 'ID' ) );
        foreach ( $users as $uid ) {
            $this->wmt_recalculate_user( intval( $uid ) );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=wmt-members&init=1' ) );
        exit;
    }

    /* =================================================================
     * 核心：指派階級 / 升降級 / Email 通知
     * ================================================================= */

    public function wmt_get_user_tier_row( $user_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->wmt_table_user_tier()} WHERE user_id = %d", $user_id ) );
    }

    public function wmt_get_user_current_tier( $user_id ) {
        $row = $this->wmt_get_user_tier_row( $user_id );
        if ( $row ) {
            $tier = $this->wmt_get_tier( $row->tier_id );
            if ( $tier ) return $tier;
        }
        return $this->wmt_get_default_tier();
    }

    // 新會員註冊時立即給預設等級，讓 CRM 名單與「我的會員」頁不會是空的
    public function wmt_on_user_register( $user_id ) {
        $this->wmt_recalculate_user( intval( $user_id ) );
    }

    private function wmt_assign_tier( $user_id, $tier_id, $valid_days, $reason = 'auto', $is_manual = false ) {
        global $wpdb;
        $current = $this->wmt_get_user_tier_row( $user_id );
        $from_tier_id = $current ? $current->tier_id : null;

        $expires_at = null;
        if ( $valid_days > 0 ) {
            $expires_at = date( 'Y-m-d H:i:s', strtotime( "+{$valid_days} days", current_time( 'timestamp' ) ) );
        }

        $data = array(
            'user_id'     => $user_id,
            'tier_id'     => $tier_id,
            'assigned_at' => current_time( 'mysql' ),
            'expires_at'  => $expires_at,
            'updated_at'  => current_time( 'mysql' ),
        );

        if ( $current ) {
            $wpdb->update( $this->wmt_table_user_tier(), $data, array( 'user_id' => $user_id ) );
        } else {
            $wpdb->insert( $this->wmt_table_user_tier(), $data );
        }

        if ( $from_tier_id != $tier_id ) {
            $wpdb->insert( $this->wmt_table_history(), array(
                'user_id'      => $user_id,
                'from_tier_id' => $from_tier_id,
                'to_tier_id'   => $tier_id,
                'reason'       => $reason,
                'created_at'   => current_time( 'mysql' ),
            ) );

            $new_tier = $this->wmt_get_tier( $tier_id );
            $old_level = $from_tier_id ? ( $this->wmt_get_tier( $from_tier_id )->level ?? 0 ) : -1;
            if ( $new_tier && $new_tier->level > $old_level && ! $is_manual ) {
                $this->wmt_send_upgrade_email( $user_id, $new_tier );
            }
        }

        return true;
    }

    // 統一寄信函式：正確加上/移除 HTML content-type filter，避免殘留影響全站信件；回傳寄送是否成功
    private function wmt_send_html_mail( $to, $subject, $body_html ) {
        $set_html = function ( $type ) { return 'text/html'; };
        add_filter( 'wp_mail_content_type', $set_html );
        $wrapped = '<div style="font-family:sans-serif;font-size:15px;line-height:1.7;color:#333;">' . $body_html . '</div>';
        $sent = wp_mail( $to, $subject, $wrapped );
        remove_filter( 'wp_mail_content_type', $set_html );
        return $sent;
    }

    private function wmt_send_upgrade_email( $user_id, $tier ) {
        $settings = $this->wmt_get_settings();
        if ( $settings['notify_email'] !== 'yes' ) return;
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $subject = sprintf( '恭喜升級為【%s】會員！', $tier->name );
        $benefits = $this->wmt_get_perks_description( $tier );
        $benefits_html = '';
        foreach ( $benefits as $b ) {
            $benefits_html .= '<li>' . esc_html( $b ) . '</li>';
        }

        $body  = '<p>親愛的 ' . esc_html( $user->display_name ) . ' 您好，</p>';
        $body .= '<p>恭喜您已升級為 <strong style="color:#b8860b;">' . esc_html( $tier->name ) . '</strong> 會員！專屬禮遇如下：</p>';
        $body .= '<ul>' . $benefits_html . '</ul>';
        $body .= '<p>感謝您的支持，期待您繼續選購。</p>';

        $this->wmt_send_html_mail( $user->user_email, $subject, $body );
    }

    private function wmt_get_perks_description( $tier ) {
        $list = array();
        if ( $tier->discount_percent > 0 ) {
            $list[] = sprintf( '結帳自動享 %s 折優惠', number_format( 10 - ( $tier->discount_percent / 10 ), 1 ) );
        }
        if ( $tier->points_multiplier > 1 ) {
            $list[] = sprintf( '消費點數回饋 %s 倍', $tier->points_multiplier );
        }
        if ( $tier->birthday_coupon_amount > 0 ) {
            $list[] = sprintf( '生日當月自動獲得 $%s 生日禮券', number_format( $tier->birthday_coupon_amount ) );
        }
        if ( $tier->free_shipping_always ) {
            $list[] = '結帳自動免運費';
        } elseif ( $tier->free_shipping_per_month > 0 ) {
            $list[] = sprintf( '每月自動獲得 %d 張免運券', $tier->free_shipping_per_month );
        }
        if ( empty( $list ) ) $list[] = '尊榮會員身份標章';
        return $list;
    }

    /* =================================================================
     * 訂單掛勾：鑑賞期 + 防退貨刷等級
     * ================================================================= */

    public function wmt_on_order_completed( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $user_id = $order->get_customer_id();
        if ( ! $user_id ) return;

        global $wpdb;
        $settings = $this->wmt_get_settings();
        $guarantee_days = intval( $settings['guarantee_days'] );
        $confirm_after = date( 'Y-m-d H:i:s', strtotime( "+{$guarantee_days} days", current_time( 'timestamp' ) ) );

        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->wmt_table_orders()} WHERE order_id = %d", $order_id ) );
        if ( $exists ) {
            $wpdb->update( $this->wmt_table_orders(), array(
                'status' => 'pending', 'confirm_after' => $confirm_after, 'amount' => $order->get_total(),
            ), array( 'order_id' => $order_id ) );
        } else {
            $wpdb->insert( $this->wmt_table_orders(), array(
                'order_id'      => $order_id,
                'user_id'       => $user_id,
                'amount'        => $order->get_total(),
                'order_date'    => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' ),
                'confirm_after' => $confirm_after,
                'status'        => 'pending',
            ) );
        }

        if ( $guarantee_days <= 0 ) {
            // 鑑賞期設為 0 天：立即確認
            $this->wmt_confirm_single_order( $order_id );
            return;
        }

        if ( ! wp_next_scheduled( self::CRON_HOOK_CONFIRM, array( $order_id ) ) ) {
            wp_schedule_single_event( strtotime( $confirm_after ), self::CRON_HOOK_CONFIRM, array( $order_id ) );
        }
    }

    public function wmt_confirm_single_order( $order_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->wmt_table_orders()} WHERE order_id = %d", $order_id ) );
        if ( ! $row || $row->status !== 'pending' ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order || ! $order->has_status( array( 'completed' ) ) ) {
            $wpdb->update( $this->wmt_table_orders(), array( 'status' => 'reversed' ), array( 'order_id' => $order_id ) );
            return;
        }

        $wpdb->update( $this->wmt_table_orders(), array(
            'status' => 'counted', 'counted_at' => current_time( 'mysql' ),
        ), array( 'order_id' => $order_id ) );

        $this->wmt_recalculate_user( $row->user_id );
    }

    public function wmt_on_order_reversed( $order_id ) {
        global $wpdb;
        $row = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$this->wmt_table_orders()} WHERE order_id = %d", $order_id ) );
        $wpdb->update( $this->wmt_table_orders(), array( 'status' => 'reversed' ), array( 'order_id' => $order_id ) );
        wp_clear_scheduled_hook( self::CRON_HOOK_CONFIRM, array( $order_id ) );
        if ( $row ) {
            $this->wmt_recalculate_user( intval( $row ) );
        }
    }

    /* =================================================================
     * 每日排程：滾動計算升降級 + 每月權益發放
     * ================================================================= */

    // $return_log = true 時強制同時執行每月權益並回傳本次執行摘要（供後台手動測試按鈕使用）
    public function wmt_run_daily_cycle( $return_log = false ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $confirmed_count = 0;

        $due = $wpdb->get_results( $wpdb->prepare(
            "SELECT order_id FROM {$this->wmt_table_orders()} WHERE status = 'pending' AND confirm_after <= %s", $now
        ) );
        foreach ( $due as $d ) {
            $this->wmt_confirm_single_order( intval( $d->order_id ) );
            $confirmed_count++;
        }

        $user_ids = $wpdb->get_col( "SELECT DISTINCT user_id FROM {$this->wmt_table_orders()}
            UNION SELECT DISTINCT user_id FROM {$this->wmt_table_user_tier()}
            UNION SELECT ID FROM {$wpdb->users}" );

        $before_map = array();
        foreach ( $user_ids as $uid ) {
            $row = $this->wmt_get_user_tier_row( $uid );
            $before_map[ $uid ] = $row ? $row->tier_id : null;
        }

        foreach ( $user_ids as $uid ) {
            $this->wmt_recalculate_user( intval( $uid ) );
        }

        $upgrades = 0;
        $downgrades = 0;
        foreach ( $user_ids as $uid ) {
            $row = $this->wmt_get_user_tier_row( $uid );
            $after = $row ? $row->tier_id : null;
            $before = $before_map[ $uid ];
            if ( $after != $before ) {
                $before_tier = $before ? $this->wmt_get_tier( $before ) : null;
                $after_tier  = $after ? $this->wmt_get_tier( $after ) : null;
                if ( $after_tier && ( ! $before_tier || $after_tier->level > $before_tier->level ) ) {
                    $upgrades++;
                } elseif ( $before_tier && $after_tier && $after_tier->level < $before_tier->level ) {
                    $downgrades++;
                }
            }
        }

        // 每天補發一次（資料表會防止重複），避免會員在月初後才升級或生日資料較晚補填而漏券。
        $this->wmt_run_monthly_perks();
        $monthly_ran = true;

        $log = array(
            'time'             => current_time( 'mysql' ),
            'users_checked'    => count( $user_ids ),
            'orders_confirmed' => $confirmed_count,
            'upgrades'         => $upgrades,
            'downgrades'       => $downgrades,
            'monthly_ran'      => $monthly_ran,
        );
        update_option( self::LOG_OPT, $log );
        return $log;
    }

    public function wmt_recalculate_user( $user_id ) {
        global $wpdb;
        $tiers = $this->wmt_get_all_tiers( true );
        if ( empty( $tiers ) ) return;

        $now_ts = current_time( 'timestamp' );
        $default_tier = $this->wmt_get_default_tier();

        usort( $tiers, function ( $a, $b ) { return $b->level <=> $a->level; } );

        $qualified_tier = null;
        foreach ( $tiers as $tier ) {
            if ( $this->wmt_user_meets_tier_rule( $user_id, $tier ) ) {
                $qualified_tier = $tier;
                break;
            }
        }

        $current_row = $this->wmt_get_user_tier_row( $user_id );
        $current_tier = $current_row ? $this->wmt_get_tier( $current_row->tier_id ) : null;

        if ( ! $current_row && ! $qualified_tier ) {
            if ( $default_tier ) {
                $this->wmt_assign_tier( $user_id, $default_tier->id, intval( $default_tier->valid_days ), 'init' );
            }
            return;
        }

        if ( $qualified_tier && ( ! $current_tier || $qualified_tier->level > $current_tier->level ) ) {
            $this->wmt_assign_tier( $user_id, $qualified_tier->id, intval( $qualified_tier->valid_days ), 'upgrade' );
            return;
        }

        if ( $current_row && $current_row->expires_at && strtotime( $current_row->expires_at ) > $now_ts ) {
            return;
        }
        if ( $current_row && ! $current_row->expires_at ) {
            return;
        }

        if ( $current_tier && $current_row && $current_row->expires_at && strtotime( $current_row->expires_at ) <= $now_ts ) {
            $renew_amount = floatval( $current_tier->renewal_amount );
            $window_days = intval( $current_tier->cum_amount_days ) > 0 ? intval( $current_tier->cum_amount_days ) : 365;
            $spent = $this->wmt_get_cumulative_amount( $user_id, $window_days );

            if ( $renew_amount > 0 && $spent >= $renew_amount ) {
                $this->wmt_assign_tier( $user_id, $current_tier->id, intval( $current_tier->valid_days ), 'renewed' );
                return;
            }

            if ( $qualified_tier ) {
                $this->wmt_assign_tier( $user_id, $qualified_tier->id, intval( $qualified_tier->valid_days ), 'downgrade' );
            } else {
                $settings = $this->wmt_get_settings();
                if ( $settings['downgrade_to_default'] === 'yes' && $default_tier ) {
                    $this->wmt_assign_tier( $user_id, $default_tier->id, intval( $default_tier->valid_days ), 'downgrade' );
                }
            }
        }
    }

    private function wmt_user_meets_tier_rule( $user_id, $tier ) {
        if ( floatval( $tier->single_amount ) <= 0 && floatval( $tier->cum_amount ) <= 0 && intval( $tier->cum_count ) <= 0 ) {
            return false;
        }

        if ( floatval( $tier->single_amount ) > 0 ) {
            $max_single = $this->wmt_get_max_single_order( $user_id );
            if ( $max_single >= floatval( $tier->single_amount ) ) return true;
        }

        if ( floatval( $tier->cum_amount ) > 0 ) {
            $sum = $this->wmt_get_cumulative_amount( $user_id, intval( $tier->cum_amount_days ) );
            if ( $sum >= floatval( $tier->cum_amount ) ) return true;
        }

        if ( intval( $tier->cum_count ) > 0 ) {
            $cnt = $this->wmt_get_cumulative_count( $user_id, intval( $tier->cum_count_days ) );
            if ( $cnt >= intval( $tier->cum_count ) ) return true;
        }

        return false;
    }

    public function wmt_get_max_single_order( $user_id ) {
        global $wpdb;
        return (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(amount) FROM {$this->wmt_table_orders()} WHERE user_id = %d AND status = 'counted'", $user_id
        ) );
    }

    public function wmt_get_cumulative_amount( $user_id, $days ) {
        global $wpdb;
        $since = date( 'Y-m-d H:i:s', strtotime( "-{$days} days", current_time( 'timestamp' ) ) );
        $sum = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(amount) FROM {$this->wmt_table_orders()} WHERE user_id = %d AND status = 'counted' AND order_date >= %s",
            $user_id, $since
        ) );
        return (float) $sum;
    }

    public function wmt_get_cumulative_count( $user_id, $days ) {
        global $wpdb;
        $since = date( 'Y-m-d H:i:s', strtotime( "-{$days} days", current_time( 'timestamp' ) ) );
        $cnt = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->wmt_table_orders()} WHERE user_id = %d AND status = 'counted' AND order_date >= %s",
            $user_id, $since
        ) );
        return (int) $cnt;
    }

    // 會員中心用：列出尚在鑑賞期、還沒正式計入資格的訂單金額提示
    private function wmt_get_pending_orders_note( $user_id, $guarantee_days ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT amount, confirm_after FROM {$this->wmt_table_orders()} WHERE user_id = %d AND status = 'pending' ORDER BY confirm_after ASC",
            $user_id
        ) );
        if ( empty( $rows ) ) return '';
        $total = array_sum( array_map( function ( $r ) { return floatval( $r->amount ); }, $rows ) );
        $earliest = $rows[0]->confirm_after;
        return sprintf(
            '您有 $%s 元訂單尚在 %d 天鑑賞期內，最快將於 %s 正式計入升級資格。',
            number_format( $total ), $guarantee_days, date_i18n( 'Y/m/d', strtotime( $earliest ) )
        );
    }

    /* =================================================================
     * 每月權益：生日禮券 + 免運券
     * ================================================================= */

    private function wmt_run_monthly_perks() {
        global $wpdb;
        $month_key = date_i18n( 'Y-m' );
        $rows = $wpdb->get_results( "SELECT ut.user_id, ut.tier_id FROM {$this->wmt_table_user_tier()} ut" );

        foreach ( $rows as $row ) {
            $this->wmt_issue_monthly_perks_for_user( intval( $row->user_id ), $month_key );
        }
    }

    private function wmt_issue_monthly_perks_for_user( $user_id, $month_key ) {
        global $wpdb;
        $tier = $this->wmt_get_user_current_tier( $user_id );
        if ( ! $tier ) return;

        if ( floatval( $tier->birthday_coupon_amount ) > 0 ) {
            $birthday = get_user_meta( $user_id, 'billing_birthday', true );
            if ( $birthday && $this->wmt_is_birthday_month( $birthday ) ) {
                $already = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$this->wmt_table_coupons()} WHERE user_id = %d AND type = 'birthday' AND month_key = %s",
                    $user_id, $month_key
                ) );
                if ( ! $already ) {
                    $this->wmt_issue_coupon( $user_id, $tier->id, floatval( $tier->birthday_coupon_amount ), $month_key, sprintf( '%s 生日禮券', $tier->name ) );
                }
            }
        }

        if ( intval( $tier->free_shipping_per_month ) > 0 ) {
            $already_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->wmt_table_coupons()} WHERE user_id = %d AND type = 'free_shipping' AND month_key = %s",
                $user_id, $month_key
            ) );
            $need = max( 0, intval( $tier->free_shipping_per_month ) - $already_count );
            for ( $i = 0; $i < $need; $i++ ) {
                $this->wmt_issue_free_shipping_coupon( $user_id, $tier->id, $month_key );
            }
        }
    }

    private function wmt_is_birthday_month( $birthday_str ) {
        $ts = strtotime( $birthday_str );
        if ( ! $ts ) return false;
        return date( 'm', $ts ) === date_i18n( 'm' );
    }

    private function wmt_issue_coupon( $user_id, $tier_id, $amount, $month_key, $desc ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;
        $code = 'VIPBDAY-' . $user_id . '-' . str_replace( '-', '', $month_key ) . '-' . wp_generate_password( 4, false );

        $coupon = new WC_Coupon();
        $coupon->set_code( $code );
        $coupon->set_discount_type( 'fixed_cart' );
        $coupon->set_amount( $amount );
        $coupon->set_individual_use( true );
        $coupon->set_usage_limit( 1 );
        $coupon->set_email_restrictions( array( $user->user_email ) );
        $coupon->set_date_expires( strtotime( '+30 days' ) );
        $coupon->save();

        global $wpdb;
        $wpdb->insert( $this->wmt_table_coupons(), array(
            'user_id' => $user_id, 'tier_id' => $tier_id, 'type' => 'birthday',
            'coupon_code' => $code, 'month_key' => $month_key, 'issued_at' => current_time( 'mysql' ),
        ) );

        $this->wmt_send_html_mail(
            $user->user_email,
            sprintf( '🎂 生日快樂！您的 %s 已到帳', $desc ),
            sprintf(
                '<p>親愛的 %s 您好，生日快樂！專屬禮券已存入您的帳戶：</p><p style="font-size:20px;font-weight:bold;color:#b8860b;">%s</p><p>使用期限 30 天，結帳時輸入即可折抵。</p>',
                esc_html( $user->display_name ), esc_html( $code )
            )
        );
    }

    private function wmt_issue_free_shipping_coupon( $user_id, $tier_id, $month_key ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;
        $code = 'FREESHIP-' . $user_id . '-' . str_replace( '-', '', $month_key ) . '-' . wp_generate_password( 4, false );

        $coupon = new WC_Coupon();
        $coupon->set_code( $code );
        $coupon->set_discount_type( 'fixed_cart' );
        $coupon->set_amount( 0 );
        $coupon->set_free_shipping( true );
        $coupon->set_individual_use( false );
        $coupon->set_usage_limit( 1 );
        $coupon->set_email_restrictions( array( $user->user_email ) );
        $coupon->set_date_expires( strtotime( '+30 days' ) );
        $coupon->save();

        global $wpdb;
        $wpdb->insert( $this->wmt_table_coupons(), array(
            'user_id' => $user_id, 'tier_id' => $tier_id, 'type' => 'free_shipping',
            'coupon_code' => $code, 'month_key' => $month_key, 'issued_at' => current_time( 'mysql' ),
        ) );
    }

    public function wmt_filter_points_multiplier( $multiplier, $user_id ) {
        $tier = $this->wmt_get_user_current_tier( $user_id );
        return $tier ? floatval( $tier->points_multiplier ) : $multiplier;
    }

    /* =================================================================
     * 前台：結帳自動折扣 / 免運 / VIP 價格 / 購物車提醒
     * ================================================================= */

    public function wmt_apply_tier_discount_fee( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( ! is_user_logged_in() ) return;
        $tier = $this->wmt_get_user_current_tier( get_current_user_id() );
        if ( ! $tier || floatval( $tier->discount_percent ) <= 0 ) return;

        $subtotal = $cart->get_subtotal();
        $discount = round( $subtotal * ( floatval( $tier->discount_percent ) / 100 ), 2 );
        if ( $discount > 0 ) {
            $cart->add_fee( sprintf( '%s 專屬折扣', $tier->name ), -$discount, false );
        }
    }

    public function wmt_force_free_shipping( $rates, $package ) {
        if ( ! is_user_logged_in() ) return $rates;
        $tier = $this->wmt_get_user_current_tier( get_current_user_id() );
        if ( ! $tier || ! $tier->free_shipping_always ) return $rates;
        foreach ( $rates as $rate_id => $rate ) {
            $rates[ $rate_id ]->cost = 0;
            $rates[ $rate_id ]->taxes = array();
        }
        return $rates;
    }

    public function wmt_show_vip_price_html( $price_html, $product ) {
        if ( is_admin() || ! is_user_logged_in() ) return $price_html;
        $tier = $this->wmt_get_user_current_tier( get_current_user_id() );
        if ( ! $tier || floatval( $tier->discount_percent ) <= 0 ) return $price_html;

        $regular = (float) $product->get_price();
        if ( $regular <= 0 ) return $price_html;
        $vip_price = $regular * ( 1 - floatval( $tier->discount_percent ) / 100 );

        return $price_html . sprintf(
            '<br/><span class="wmt-vip-price">%s 專屬價：%s</span>',
            esc_html( $tier->name ), wc_price( $vip_price )
        );
    }

    public function wmt_cart_upgrade_notice() {
        if ( ! is_user_logged_in() ) return;
        $msg = $this->wmt_build_upgrade_message( get_current_user_id() );
        if ( $msg ) {
            echo '<div class="wmt-cart-notice">' . esc_html( $msg ) . '</div>';
        }
    }

    public function wmt_cart_notice_fragment( $fragments ) {
        if ( ! is_user_logged_in() ) return $fragments;
        $msg = $this->wmt_build_upgrade_message( get_current_user_id() );
        $html = $msg ? '<div class="wmt-cart-notice">' . esc_html( $msg ) . '</div>' : '';
        $fragments['.wmt-cart-notice-fragment'] = '<div class="wmt-cart-notice-fragment">' . $html . '</div>';
        return $fragments;
    }

    private function wmt_build_upgrade_message( $user_id ) {
        if ( ! WC()->cart ) return '';
        $tiers = $this->wmt_get_all_tiers( true );
        $current = $this->wmt_get_user_current_tier( $user_id );
        $cart_total = (float) WC()->cart->get_subtotal();

        $next_tier = null;
        foreach ( $tiers as $t ) {
            if ( $current && $t->level <= $current->level ) continue;
            if ( floatval( $t->cum_amount ) <= 0 && floatval( $t->single_amount ) <= 0 ) continue;
            if ( ! $next_tier || $t->level < $next_tier->level ) $next_tier = $t;
        }
        if ( ! $next_tier ) return '';

        $existing_cum = $this->wmt_get_cumulative_amount( $user_id, intval( $next_tier->cum_amount_days ?: 365 ) );
        $target_single = floatval( $next_tier->single_amount );
        $target_cum = floatval( $next_tier->cum_amount );

        $gap_single = $target_single > 0 ? max( 0, $target_single - $cart_total ) : PHP_INT_MAX;
        $gap_cum    = $target_cum > 0 ? max( 0, $target_cum - $existing_cum - $cart_total ) : PHP_INT_MAX;
        $gap = min( $gap_single, $gap_cum );

        if ( $gap === PHP_INT_MAX ) return '';
        if ( $gap <= 0 ) {
            return sprintf( '本次訂單將直接升級為【%s】會員！', $next_tier->name );
        }
        return sprintf( '再買 $%s 元即可升級【%s】會員！', number_format( ceil( $gap ) ), $next_tier->name );
    }

    /* =================================================================
     * 前台：VIP 尊榮看板 / 進度條 Shortcode
     * ================================================================= */

    private function wmt_get_member_coupon_rows( $user_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT type, coupon_code, month_key, issued_at FROM {$this->wmt_table_coupons()} WHERE user_id = %d ORDER BY issued_at DESC, id DESC LIMIT 50",
            $user_id
        ) );
    }

    private function wmt_render_member_coupons_html( $user_id ) {
        $rows = $this->wmt_get_member_coupon_rows( $user_id );
        if ( empty( $rows ) ) {
            return '<p class="wmt-help-text">目前沒有會員專屬券。每月免運券會由系統自動發放，之後可直接在這裡查看券碼。</p>';
        }

        ob_start();
        echo '<ul class="wmt-coupon-list">';
        foreach ( $rows as $row ) {
            $coupon_id = wc_get_coupon_id_by_code( $row->coupon_code );
            $coupon = $coupon_id ? new WC_Coupon( $coupon_id ) : null;
            $label = ( 'birthday' === $row->type ) ? '生日禮券' : '免運券';
            $status = '可使用';
            $expires_text = '無到期日';

            if ( ! $coupon || ! $coupon->get_id() ) {
                $status = '已失效';
            } else {
                $expires = $coupon->get_date_expires();
                if ( $expires ) {
                    $expires_text = '有效至 ' . date_i18n( 'Y/m/d', $expires->getTimestamp() );
                    if ( $expires->getTimestamp() < current_time( 'timestamp' ) ) $status = '已過期';
                }
                $limit = intval( $coupon->get_usage_limit() );
                if ( $limit > 0 && intval( $coupon->get_usage_count() ) >= $limit ) $status = '已使用';
            }
            ?>
            <li class="wmt-coupon-item">
                <strong><?php echo esc_html( $label ); ?></strong><br/>
                <span class="wmt-coupon-code"><?php echo esc_html( $row->coupon_code ); ?></span>
                <span class="wmt-coupon-meta"><?php echo esc_html( $status . '・' . $expires_text . '・' . $row->month_key ); ?></span>
            </li>
            <?php
        }
        echo '</ul>';
        return ob_get_clean();
    }

    public function wmt_shortcode_dashboard( $atts ) {
        if ( ! is_user_logged_in() ) return '<p>請先登入以查看會員資訊。</p>';
        $user_id = get_current_user_id();
        $tier = $this->wmt_get_user_current_tier( $user_id );
        $row = $this->wmt_get_user_tier_row( $user_id );
        $settings = $this->wmt_get_settings();
        if ( ! $tier ) return '';
        // 會員打開「我的會員」時也補發本月應得權益，避免等待排程。
        $this->wmt_issue_monthly_perks_for_user( $user_id, date_i18n( 'Y-m' ) );
        $birthday = get_user_meta( $user_id, 'billing_birthday', true );
        $pending_note = $this->wmt_get_pending_orders_note( $user_id, intval( $settings['guarantee_days'] ) );

        ob_start();
        ?>
        <style><?php echo $this->wmt_frontend_css(); ?></style>
        <div class="wmt-dashboard">
            <?php
            $birthday_status = isset( $_GET['wmt_birthday'] ) ? sanitize_key( wp_unslash( $_GET['wmt_birthday'] ) ) : '';
            if ( 'saved' === $birthday_status ) echo '<p class="wmt-status-message">生日已儲存。為保障資料正確，之後只有管理員能重設。</p>';
            if ( 'invalid' === $birthday_status ) echo '<p class="wmt-status-message wmt-error">生日格式不正確，請重新填寫。</p>';
            if ( 'locked' === $birthday_status ) echo '<p class="wmt-status-message wmt-error">生日已設定，無法自行更改；如需修正請聯絡管理員。</p>';
            ?>
            <div class="wmt-dash-head">
                <?php if ( $tier->badge_url ) : ?>
                    <img class="wmt-dash-badge-img" src="<?php echo esc_url( $tier->badge_url ); ?>" alt="<?php echo esc_attr( $tier->name ); ?>"/>
                <?php endif; ?>
                <div>
                    <p class="wmt-dash-title"><?php echo esc_html( $tier->name ); ?> 會員</p>
                    <p class="wmt-dash-sub">
                        <?php if ( $row && $row->expires_at ) : ?>
                            效期至 <?php echo esc_html( date_i18n( 'Y/m/d', strtotime( $row->expires_at ) ) ); ?>
                        <?php else : ?>
                            永久有效
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <p class="wmt-section-title">專屬權益</p>
            <ul class="wmt-perks-list">
                <?php foreach ( $this->wmt_get_perks_description( $tier ) as $perk ) : ?>
                    <li>✓ <?php echo esc_html( $perk ); ?></li>
                <?php endforeach; ?>
            </ul>

            <p class="wmt-section-title">升級進度</p>
            <?php echo $this->wmt_render_progress_bar_html( $user_id ); ?>

            <?php if ( $pending_note ) : ?>
                <div class="wmt-notice-box"><?php echo esc_html( $pending_note ); ?></div>
            <?php endif; ?>

            <div class="wmt-member-grid">
                <section class="wmt-member-box">
                    <h3>我的生日</h3>
                    <?php if ( $birthday ) : ?>
                        <p><strong><?php echo esc_html( date_i18n( 'Y 年 n 月 j 日', strtotime( $birthday ) ) ); ?></strong></p>
                        <p class="wmt-help-text">生日一經儲存即無法自行更改。如填寫錯誤，請聯絡管理員重設。</p>
                    <?php else : ?>
                        <form class="wmt-birthday-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('生日儲存後無法自行更改，確定資料正確嗎？');">
                            <?php wp_nonce_field( 'wmt_save_birthday' ); ?>
                            <input type="hidden" name="action" value="wmt_save_birthday"/>
                            <input type="date" name="birthday" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required/>
                            <button class="wmt-primary-btn" type="submit">儲存生日</button>
                        </form>
                        <p class="wmt-help-text">請確認後再送出；生日填寫後會鎖定，只有管理員能重設。</p>
                    <?php endif; ?>
                </section>

                <section class="wmt-member-box">
                    <h3>我的會員專屬券</h3>
                    <?php echo $this->wmt_render_member_coupons_html( $user_id ); ?>
                    <p class="wmt-help-text">結帳時輸入券碼即可使用；免運券仍須搭配商店中允許使用「免運券」的運送方式。</p>
                </section>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function wmt_shortcode_progress( $atts ) {
        if ( ! is_user_logged_in() ) return '';
        return '<style>' . $this->wmt_frontend_css() . '</style>' . $this->wmt_render_progress_bar_html( get_current_user_id() );
    }

    private function wmt_render_progress_bar_html( $user_id ) {
        $tiers = $this->wmt_get_all_tiers( true );
        $current = $this->wmt_get_user_current_tier( $user_id );

        $next_tier = null;
        foreach ( $tiers as $t ) {
            if ( $current && $t->level <= $current->level ) continue;
            if ( floatval( $t->cum_amount ) <= 0 && intval( $t->cum_count ) <= 0 ) continue;
            if ( ! $next_tier || $t->level < $next_tier->level ) $next_tier = $t;
        }
        if ( ! $next_tier ) {
            return '<div class="wmt-max-box">🏆 您已是最高等級會員！</div>';
        }

        $use_amount = floatval( $next_tier->cum_amount ) > 0;
        ob_start();
        if ( $use_amount ) {
            $spent = $this->wmt_get_cumulative_amount( $user_id, intval( $next_tier->cum_amount_days ) );
            $target = floatval( $next_tier->cum_amount );
            $percent = $target > 0 ? min( 100, round( ( $spent / $target ) * 100 ) ) : 0;
            $remain = max( 0, $target - $spent );
            ?>
            <div class="wmt-progress-label">距離升級<span class="wmt-progress-target"><?php echo esc_html( $next_tier->name ); ?></span>還差 <strong>$<?php echo esc_html( number_format( $remain ) ); ?></strong>（近 <?php echo esc_html( $next_tier->cum_amount_days ); ?> 天內）</div>
            <div class="wmt-progress-wrap"><div class="wmt-progress-bar" style="width:<?php echo esc_attr( $percent ); ?>%;"></div></div>
            <div class="wmt-progress-label"><?php echo esc_html( $percent ); ?>%（$<?php echo esc_html( number_format( $spent ) ); ?> / $<?php echo esc_html( number_format( $target ) ); ?>）</div>
            <?php
        } else {
            $cnt = $this->wmt_get_cumulative_count( $user_id, intval( $next_tier->cum_count_days ) );
            $target = intval( $next_tier->cum_count );
            $percent = $target > 0 ? min( 100, round( ( $cnt / $target ) * 100 ) ) : 0;
            $remain = max( 0, $target - $cnt );
            ?>
            <div class="wmt-progress-label">距離升級<span class="wmt-progress-target"><?php echo esc_html( $next_tier->name ); ?></span>還差 <strong><?php echo esc_html( $remain ); ?></strong> 次消費（近 <?php echo esc_html( $next_tier->cum_count_days ); ?> 天內）</div>
            <div class="wmt-progress-wrap"><div class="wmt-progress-bar" style="width:<?php echo esc_attr( $percent ); ?>%;"></div></div>
            <div class="wmt-progress-label"><?php echo esc_html( $percent ); ?>%（<?php echo esc_html( $cnt ); ?> / <?php echo esc_html( $target ); ?> 次）</div>
            <?php
        }
        return ob_get_clean();
    }

    /* =================================================================
     * 我的帳戶 endpoint
     * ================================================================= */

    public function wmt_add_my_account_menu_item( $items ) {
        $new = array();
        foreach ( $items as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'orders' ) $new['vip-tier'] = '我的會員';
        }
        return $new;
    }

    public function wmt_add_my_account_endpoint() {
        add_rewrite_endpoint( 'vip-tier', EP_ROOT | EP_PAGES );
    }

    public function wmt_myaccount_endpoint_content() {
        echo $this->wmt_shortcode_dashboard( array() );
    }

    /* =================================================================
     * VIP 隱密賣場：商品 meta box + 前台限制
     * ================================================================= */

    public function wmt_add_product_meta_box() {
        add_meta_box( 'wmt_exclusive_box', 'VIP 隱密賣場設定', array( $this, 'wmt_render_product_meta_box' ), 'product', 'side', 'default' );
    }

    public function wmt_render_product_meta_box( $post ) {
        wp_nonce_field( 'wmt_product_meta', 'wmt_product_meta_nonce' );
        $public_date = get_post_meta( $post->ID, '_wmt_public_release_date', true );
        $early_days  = get_post_meta( $post->ID, '_wmt_early_days', true );
        $min_tier_id = get_post_meta( $post->ID, '_wmt_min_tier_id', true );
        $tiers = $this->wmt_get_all_tiers( true );
        ?>
        <p><label>公開上架日期時間<br/>
            <input type="datetime-local" name="wmt_public_release_date" value="<?php echo esc_attr( $public_date ); ?>" style="width:100%;"/>
        </label></p>
        <p><label>VIP 早鳥天數（公開日前 N 天開放）<br/>
            <input type="number" name="wmt_early_days" value="<?php echo esc_attr( $early_days ?: 0 ); ?>" min="0" style="width:100%;"/>
        </label></p>
        <p><label>最低可見／可購買階級<br/>
            <select name="wmt_min_tier_id" style="width:100%;">
                <option value="">— 不限制（一般規則）—</option>
                <?php foreach ( $tiers as $t ) : ?>
                    <option value="<?php echo esc_attr( $t->id ); ?>" <?php selected( $min_tier_id, $t->id ); ?>><?php echo esc_html( $t->name ); ?>（以上）</option>
                <?php endforeach; ?>
            </select>
        </label></p>
        <p style="color:#888;font-size:12px;">留空公開日期＝不啟用限定上市功能。設定後，在「公開日期前 N 天」到「公開日期」之間，只有達到指定階級以上的會員能瀏覽與購買；一般訪客會看到「尚未開放」。公開日期到達後，恢復所有人皆可購買。</p>
        <?php
    }

    public function wmt_save_product_meta_box( $post_id ) {
        if ( ! isset( $_POST['wmt_product_meta_nonce'] ) || ! wp_verify_nonce( $_POST['wmt_product_meta_nonce'], 'wmt_product_meta' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_product', $post_id ) ) return;

        update_post_meta( $post_id, '_wmt_public_release_date', sanitize_text_field( $_POST['wmt_public_release_date'] ?? '' ) );
        update_post_meta( $post_id, '_wmt_early_days', intval( $_POST['wmt_early_days'] ?? 0 ) );
        update_post_meta( $post_id, '_wmt_min_tier_id', sanitize_text_field( $_POST['wmt_min_tier_id'] ?? '' ) );
    }

    private function wmt_product_lock_state( $product_id ) {
        $public_date = get_post_meta( $product_id, '_wmt_public_release_date', true );
        $min_tier_id = get_post_meta( $product_id, '_wmt_min_tier_id', true );
        if ( ! $public_date || ! $min_tier_id ) return array( 'locked' => false );

        $early_days = intval( get_post_meta( $product_id, '_wmt_early_days', true ) );
        $public_ts = strtotime( $public_date );
        $early_start_ts = $public_ts - ( $early_days * DAY_IN_SECONDS );
        $now = current_time( 'timestamp' );

        if ( $now >= $public_ts ) return array( 'locked' => false );
        if ( $now < $early_start_ts ) return array( 'locked' => true, 'not_yet_vip' => true, 'min_tier_id' => $min_tier_id );

        $qualified = false;
        if ( is_user_logged_in() ) {
            $tier = $this->wmt_get_user_current_tier( get_current_user_id() );
            $min_tier = $this->wmt_get_tier( $min_tier_id );
            if ( $tier && $min_tier && $tier->level >= $min_tier->level ) $qualified = true;
        }
        if ( current_user_can( 'manage_woocommerce' ) ) $qualified = true;

        return array( 'locked' => ! $qualified, 'min_tier_id' => $min_tier_id, 'public_date' => $public_date );
    }

    public function wmt_restrict_purchasable( $purchasable, $product ) {
        $state = $this->wmt_product_lock_state( $product->get_id() );
        if ( $state['locked'] ) return false;
        return $purchasable;
    }

    public function wmt_maybe_block_single_product() {
        global $post;
        if ( ! $post ) return;
        $state = $this->wmt_product_lock_state( $post->ID );
        if ( ! $state['locked'] ) return;

        $min_tier = ! empty( $state['min_tier_id'] ) ? $this->wmt_get_tier( $state['min_tier_id'] ) : null;
        $tier_name = $min_tier ? $min_tier->name : 'VIP';

        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20 );
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );

        echo '<h1 class="product_title">' . esc_html( get_the_title( $post->ID ) ) . '</h1>';
        echo '<div class="wmt-locked-notice"><p><strong>尚未開放</strong></p><p>此商品僅開放【' . esc_html( $tier_name ) . '】以上會員優先選購，敬請期待正式上市。</p></div>';
    }

    public function wmt_exclude_locked_products_from_archive( $query ) {
        if ( is_admin() || ! $query->is_main_query() ) return;
        if ( ! ( is_shop() || is_product_category() || is_product_tag() ) ) return;

        global $wpdb;
        $locked_ids = array();
        $products = $wpdb->get_results( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wmt_public_release_date' AND meta_value != ''" );
        foreach ( $products as $p ) {
            $state = $this->wmt_product_lock_state( $p->post_id );
            if ( $state['locked'] ) $locked_ids[] = $p->post_id;
        }
        if ( ! empty( $locked_ids ) ) {
            $existing = $query->get( 'post__not_in' ) ?: array();
            $query->set( 'post__not_in', array_merge( $existing, $locked_ids ) );
        }
    }

    /* =================================================================
     * 後台選單 / 頁面
     * ================================================================= */

    public function wmt_admin_menu() {
        add_menu_page( '會員階級系統', '會員階級', 'manage_woocommerce', 'wmt-tiers', array( $this, 'wmt_page_tiers' ), 'dashicons-awards', 56 );
        add_submenu_page( 'wmt-tiers', '階級設定', '階級設定', 'manage_woocommerce', 'wmt-tiers', array( $this, 'wmt_page_tiers' ) );
        add_submenu_page( 'wmt-tiers', '會員名單（CRM）', '會員名單', 'manage_woocommerce', 'wmt-members', array( $this, 'wmt_page_members' ) );
        add_submenu_page( 'wmt-tiers', '全站設定', '全站設定', 'manage_woocommerce', 'wmt-settings', array( $this, 'wmt_page_settings' ) );
    }

    public function wmt_page_tiers() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足' );
        $tiers = $this->wmt_get_all_tiers();
        $edit_id = isset( $_GET['edit'] ) ? intval( $_GET['edit'] ) : 0;
        $edit_tier = $edit_id ? $this->wmt_get_tier( $edit_id ) : null;
        ?>
        <div class="wrap">
            <h1>會員階級設定</h1>
            <?php if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success is-dismissible"><p>已儲存。</p></div>'; ?>
            <?php if ( isset( $_GET['deleted'] ) ) echo '<div class="notice notice-success is-dismissible"><p>已刪除。</p></div>'; ?>

            <h2><?php echo $edit_tier ? '編輯階級：' . esc_html( $edit_tier->name ) : '新增階級'; ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'wmt_save_tier' ); ?>
                <input type="hidden" name="action" value="wmt_save_tier"/>
                <input type="hidden" name="tier_id" value="<?php echo esc_attr( $edit_tier->id ?? 0 ); ?>"/>
                <table class="form-table">
                    <tr><th>階級名稱</th><td><input type="text" name="name" class="regular-text" required value="<?php echo esc_attr( $edit_tier->name ?? '' ); ?>" placeholder="例：黃金會員"/></td></tr>
                    <tr><th>Slug</th><td><input type="text" name="slug" class="regular-text" value="<?php echo esc_attr( $edit_tier->slug ?? '' ); ?>" placeholder="留空自動產生"/></td></tr>
                    <tr><th>階級層級（數字越大越高階）</th><td><input type="number" name="level" value="<?php echo esc_attr( $edit_tier->level ?? 0 ); ?>" required/></td></tr>
                    <tr><th>徽章圖示網址</th><td>
                        <input type="text" id="wmt_badge_url" name="badge_url" class="regular-text" value="<?php echo esc_attr( $edit_tier->badge_url ?? '' ); ?>"/>
                        <button type="button" class="button" id="wmt_upload_badge_btn">上傳圖示</button>
                        <div id="wmt_badge_preview" style="margin-top:6px;"><?php if ( ! empty( $edit_tier->badge_url ) ) echo '<img src="' . esc_url( $edit_tier->badge_url ) . '" style="width:40px;height:40px;border-radius:50%;"/>'; ?></div>
                    </td></tr>
                    <tr><th>是否為預設起始階級</th><td><label><input type="checkbox" name="is_default" <?php checked( ! empty( $edit_tier->is_default ) ); ?>/> 是（例如：一般會員）</label></td></tr>
                    <tr><th colspan="2"><h3>升級門檻（符合任一項即可升級，0 表示不啟用）</h3></th></tr>
                    <tr><th>單筆消費金額</th><td><input type="number" step="0.01" name="single_amount" value="<?php echo esc_attr( $edit_tier->single_amount ?? 0 ); ?>"/> 元（單筆訂單達此金額直接升級）</td></tr>
                    <tr><th>期間累積消費金額</th><td>
                        <input type="number" step="0.01" name="cum_amount" value="<?php echo esc_attr( $edit_tier->cum_amount ?? 0 ); ?>"/> 元　在
                        <input type="number" name="cum_amount_days" value="<?php echo esc_attr( $edit_tier->cum_amount_days ?? 365 ); ?>" style="width:80px;"/> 天內
                    </td></tr>
                    <tr><th>期間累積消費次數</th><td>
                        <input type="number" name="cum_count" value="<?php echo esc_attr( $edit_tier->cum_count ?? 0 ); ?>"/> 次　在
                        <input type="number" name="cum_count_days" value="<?php echo esc_attr( $edit_tier->cum_count_days ?? 180 ); ?>" style="width:80px;"/> 天內
                    </td></tr>
                    <tr><th colspan="2"><h3>效期與續約</h3></th></tr>
                    <tr><th>有效天數（0＝永久）</th><td><input type="number" name="valid_days" value="<?php echo esc_attr( $edit_tier->valid_days ?? 365 ); ?>"/></td></tr>
                    <tr><th>續約所需金額</th><td><input type="number" step="0.01" name="renewal_amount" value="<?php echo esc_attr( $edit_tier->renewal_amount ?? 0 ); ?>"/> 元（效期內累積消費達此金額可續約維持階級）</td></tr>
                    <tr><th colspan="2"><h3>專屬權益</h3></th></tr>
                    <tr><th>天天折扣</th><td><input type="number" step="0.01" name="discount_percent" value="<?php echo esc_attr( $edit_tier->discount_percent ?? 0 ); ?>"/> ％折扣（例：5 代表 95 折，10 代表 9 折）</td></tr>
                    <tr><th>點數回饋倍率</th><td><input type="number" step="0.1" name="points_multiplier" value="<?php echo esc_attr( $edit_tier->points_multiplier ?? 1 ); ?>"/> 倍</td></tr>
                    <tr><th>生日禮券金額</th><td><input type="number" step="0.01" name="birthday_coupon_amount" value="<?php echo esc_attr( $edit_tier->birthday_coupon_amount ?? 0 ); ?>"/> 元（生日當月 1 號自動發放）</td></tr>
                    <tr><th>每月免運券張數</th><td><input type="number" name="free_shipping_per_month" value="<?php echo esc_attr( $edit_tier->free_shipping_per_month ?? 0 ); ?>"/> 張</td></tr>
                    <tr><th>結帳永久免運</th><td><label><input type="checkbox" name="free_shipping_always" <?php checked( ! empty( $edit_tier->free_shipping_always ) ); ?>/> 啟用</label></td></tr>
                    <tr><th>啟用狀態</th><td><label><input type="checkbox" name="status" <?php checked( ! isset( $edit_tier->status ) || $edit_tier->status ); ?>/> 啟用</label></td></tr>
                </table>
                <?php submit_button( $edit_tier ? '更新階級' : '新增階級' ); ?>
            </form>

            <hr/>
            <h2>現有階級列表</h2>
            <table class="widefat striped">
                <thead><tr><th>徽章</th><th>名稱</th><th>層級</th><th>單筆門檻</th><th>累積金額門檻</th><th>累積次數門檻</th><th>折扣</th><th>點數倍率</th><th>狀態</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ( $tiers as $t ) : ?>
                    <tr>
                        <td><?php if ( $t->badge_url ) echo '<img src="' . esc_url( $t->badge_url ) . '" style="width:28px;height:28px;border-radius:50%;"/>'; ?></td>
                        <td><?php echo esc_html( $t->name ); ?> <?php if ( $t->is_default ) echo '<span class="dashicons dashicons-star-filled" title="預設階級"></span>'; ?></td>
                        <td><?php echo esc_html( $t->level ); ?></td>
                        <td><?php echo $t->single_amount > 0 ? '$' . number_format( $t->single_amount ) : '—'; ?></td>
                        <td><?php echo $t->cum_amount > 0 ? '$' . number_format( $t->cum_amount ) . '／' . $t->cum_amount_days . '天' : '—'; ?></td>
                        <td><?php echo $t->cum_count > 0 ? $t->cum_count . '次／' . $t->cum_count_days . '天' : '—'; ?></td>
                        <td><?php echo $t->discount_percent > 0 ? $t->discount_percent . '%' : '—'; ?></td>
                        <td><?php echo esc_html( $t->points_multiplier ); ?>x</td>
                        <td><?php echo $t->status ? '啟用' : '停用'; ?></td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wmt-tiers&edit=' . $t->id ) ); ?>">編輯</a> |
                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wmt_delete_tier&tier_id=' . $t->id ), 'wmt_delete_tier' ) ); ?>" onclick="return confirm('確定刪除此階級？');">刪除</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <script>
        jQuery(function($){
            $('#wmt_upload_badge_btn').on('click', function(e){
                e.preventDefault();
                var frame = wp.media({ title: '選擇徽章圖示', multiple: false });
                frame.on('select', function(){
                    var att = frame.state().get('selection').first().toJSON();
                    $('#wmt_badge_url').val(att.url);
                    $('#wmt_badge_preview').html('<img src="'+att.url+'" style="width:40px;height:40px;border-radius:50%;"/>');
                });
                frame.open();
            });
        });
        </script>
        <?php
    }

    public function wmt_page_members() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足' );
        global $wpdb;
        $tiers = $this->wmt_get_all_tiers();
        $default_tier = $this->wmt_get_default_tier();

        $filter_tier = isset( $_GET['tier_id'] ) ? sanitize_text_field( wp_unslash( $_GET['tier_id'] ) ) : '';
        $keyword     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $paged       = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $per_page    = 30;
        $offset      = ( $paged - 1 ) * $per_page;

        list( $where_sql, $params ) = $this->wmt_build_member_filter( $keyword, $filter_tier );

        $base_sql = "FROM {$wpdb->users} u LEFT JOIN {$this->wmt_table_user_tier()} ut ON ut.user_id = u.ID WHERE {$where_sql}";

        // 關鍵修正：只有真的有參數時才呼叫 prepare()，沒有篩選條件時直接執行純字串查詢
        $total = (int) $wpdb->get_var( $this->wmt_prepare_query( "SELECT COUNT(*) {$base_sql}", $params ) );

        $sql = "SELECT u.ID as user_id, u.display_name, u.user_email, ut.tier_id, ut.assigned_at, ut.expires_at {$base_sql}
                ORDER BY ut.assigned_at DESC, u.ID DESC LIMIT {$per_page} OFFSET {$offset}";
        $members = $wpdb->get_results( $this->wmt_prepare_query( $sql, $params ) );

        $total_pages = max( 1, ceil( $total / $per_page ) );
        ?>
        <div class="wrap">
            <h1>會員名單</h1>
            <?php if ( isset( $_GET['msg'] ) ) echo '<div class="notice notice-success is-dismissible"><p>已更新。</p></div>'; ?>
            <?php if ( isset( $_GET['init'] ) ) echo '<div class="notice notice-success is-dismissible"><p>已為所有會員初始化階級資料。</p></div>'; ?>

            <p class="description">此列表會顯示<strong>全站所有使用者</strong>（無論目前有沒有被系統分級）。若剛安裝外掛，會員可能都顯示「尚未分級」，這是正常現象，可按下方按鈕立即初始化，或等待每日排程自動處理。</p>

            <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:14px 0;">
                <input type="hidden" name="page" value="wmt-members"/>
                <input type="text" name="s" value="<?php echo esc_attr( $keyword ); ?>" placeholder="搜尋姓名或 Email"/>
                <select name="tier_id">
                    <option value="">全部</option>
                    <option value="none" <?php selected( $filter_tier, 'none' ); ?>>尚未分級</option>
                    <?php foreach ( $tiers as $t ) : ?>
                        <option value="<?php echo esc_attr( $t->id ); ?>" <?php selected( $filter_tier, (string) $t->id ); ?>><?php echo esc_html( $t->name ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button">篩選</button>
                <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wmt_export_members&tier_id=' . rawurlencode( $filter_tier ) . '&s=' . rawurlencode( $keyword ) ), 'wmt_export_members' ) ); ?>">匯出目前篩選結果 CSV</a>
                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wmt_init_all_members' ), 'wmt_init_all_members' ) ); ?>" onclick="return confirm('將為所有會員立即計算並指派階級，確定執行？');">立即初始化所有會員階級</a>
            </form>

            <p><strong><?php echo esc_html( $total ); ?></strong> 位符合篩選條件的會員</p>

            <table class="widefat striped">
                <thead><tr><th>會員</th><th>Email</th><th>生日</th><th>目前階級</th><th>取得時間</th><th>效期至</th><th>手動調整</th></tr></thead>
                <tbody>
                <?php if ( empty( $members ) ) : ?>
                    <tr><td colspan="7">沒有符合條件的會員。</td></tr>
                <?php endif; ?>
                <?php foreach ( $members as $m ) :
                    $tier = $m->tier_id ? $this->wmt_get_tier( $m->tier_id ) : null; ?>
                    <tr>
                        <td><?php echo esc_html( $m->display_name ); ?></td>
                        <td><?php echo esc_html( $m->user_email ); ?></td>
                        <td>
                            <?php $birthday = get_user_meta( $m->user_id, 'billing_birthday', true ); ?>
                            <?php if ( $birthday ) : ?>
                                <?php echo esc_html( $birthday ); ?><br/>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wmt_reset_birthday&user_id=' . intval( $m->user_id ) ), 'wmt_reset_birthday_' . intval( $m->user_id ) ) ); ?>" onclick="return confirm('確定要清除此會員的生日？清除後會員可重新填寫。');">重設生日</a>
                            <?php else : ?>
                                <span style="color:#999;">尚未填寫</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $tier ) : ?>
                                <span class="wmt-badge-chip">
                                    <?php if ( $tier->badge_url ) echo '<img src="' . esc_url( $tier->badge_url ) . '" style="width:16px;height:16px;border-radius:50%;"/>'; ?>
                                    <?php echo esc_html( $tier->name ); ?>
                                </span>
                            <?php else : ?>
                                <em style="color:#999;">尚未分級<?php echo $default_tier ? '（將歸入 ' . esc_html( $default_tier->name ) . '）' : ''; ?></em>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $m->assigned_at ? esc_html( $m->assigned_at ) : '—'; ?></td>
                        <td><?php echo $m->expires_at ? esc_html( $m->expires_at ) : ( $m->tier_id ? '永久' : '—' ); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:4px;">
                                <?php wp_nonce_field( 'wmt_manual_set_tier' ); ?>
                                <input type="hidden" name="action" value="wmt_manual_set_tier"/>
                                <input type="hidden" name="user_id" value="<?php echo esc_attr( $m->user_id ); ?>"/>
                                <select name="tier_id">
                                    <?php foreach ( $tiers as $t ) : ?>
                                        <option value="<?php echo esc_attr( $t->id ); ?>" <?php selected( $m->tier_id, $t->id ); ?>><?php echo esc_html( $t->name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" name="valid_days_override" placeholder="天數(選填)" style="width:100px;"/>
                                <button class="button">套用</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) : ?>
                <div style="margin-top:14px;">
                    <?php echo paginate_links( array(
                        'base'    => add_query_arg( 'paged', '%#%' ),
                        'format'  => '',
                        'current' => $paged,
                        'total'   => $total_pages,
                    ) ); ?>
                </div>
            <?php endif; ?>
        </div>
        <style>.wmt-badge-chip{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;background:#1a1a1a;color:#fff;font-size:12px;font-weight:600;}</style>
        <?php
    }

    public function wmt_handle_export_members() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足' );
        check_admin_referer( 'wmt_export_members' );
        global $wpdb;

        $filter_tier = isset( $_GET['tier_id'] ) ? sanitize_text_field( wp_unslash( $_GET['tier_id'] ) ) : '';
        $keyword     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

        list( $where_sql, $params ) = $this->wmt_build_member_filter( $keyword, $filter_tier );

        $sql = "SELECT u.ID as user_id, u.display_name, u.user_email, ut.tier_id, ut.assigned_at, ut.expires_at
                FROM {$wpdb->users} u LEFT JOIN {$this->wmt_table_user_tier()} ut ON ut.user_id = u.ID
                WHERE {$where_sql} ORDER BY ut.assigned_at DESC, u.ID DESC";
        $rows = $wpdb->get_results( $this->wmt_prepare_query( $sql, $params ) );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=vip-members-' . date( 'Ymd' ) . '.csv' );
        echo "\xEF\xBB\xBF"; // BOM for Excel 中文
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( '會員ID', '姓名', 'Email', '階級', '取得時間', '效期至' ) );
        foreach ( $rows as $r ) {
            $tier = $r->tier_id ? $this->wmt_get_tier( $r->tier_id ) : null;
            fputcsv( $out, array(
                $r->user_id, $r->display_name, $r->user_email,
                $tier ? $tier->name : '尚未分級',
                $r->assigned_at ?: '',
                $r->expires_at ? $r->expires_at : ( $r->tier_id ? '永久' : '' ),
            ) );
        }
        fclose( $out );
        exit;
    }

    public function wmt_page_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足' );
        $s = $this->wmt_get_settings();
        $log = get_option( self::LOG_OPT );
        $next_run = wp_next_scheduled( self::CRON_HOOK_DAILY );
        $manual_run_result = get_transient( 'wmt_manual_run_result' );
        $test_mail_result = get_transient( 'wmt_test_mail_result' );
        ?>
        <div class="wrap">
            <h1>會員階級系統 — 全站設定</h1>
            <?php if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success is-dismissible"><p>設定已儲存。</p></div>'; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'wmt_save_settings' ); ?>
                <input type="hidden" name="action" value="wmt_save_settings"/>
                <table class="form-table">
                    <tr><th>啟用會員分級系統</th><td><label><input type="checkbox" name="enable" <?php checked( $s['enable'], 'yes' ); ?>/> 啟用</label></td></tr>
                    <tr><th>鑑賞期／防退貨刷等級天數</th><td>
                        <input type="number" name="guarantee_days" value="<?php echo esc_attr( $s['guarantee_days'] ); ?>"/> 天
                        <p class="description">訂單狀態變為「已完成」後，需再等待此天數且訂單狀態仍維持「已完成」，才正式計入升級資格金額／次數。設為 0 代表立即計入。</p>
                    </td></tr>
                    <tr><th>升級 Email 通知</th><td><label><input type="checkbox" name="notify_email" <?php checked( $s['notify_email'], 'yes' ); ?>/> 升級時寄送通知信</label></td></tr>
                    <tr><th>效期到期未續約時</th><td><label><input type="checkbox" name="downgrade_to_default" <?php checked( $s['downgrade_to_default'], 'yes' ); ?>/> 自動降回預設（一般）階級</label></td></tr>
                </table>
                <?php submit_button( '儲存設定' ); ?>
            </form>

            <hr/>
            <h2>排程狀態與測試工具</h2>
            <p><strong>每日自動排程：</strong>
                <?php if ( $next_run ) : ?>
                    運作中，下次執行時間：<?php echo esc_html( date_i18n( 'Y-m-d H:i', $next_run ) ); ?>
                <?php else : ?>
                    尚未排定（請停用外掛後重新啟用一次）
                <?php endif; ?>
            </p>

            <?php if ( $log ) : ?>
                <p><strong>最近一次執行紀錄：</strong><?php echo esc_html( $log['time'] ); ?></p>
                <p>檢查會員數：<?php echo esc_html( $log['users_checked'] ); ?>　確認訂單數：<?php echo esc_html( $log['orders_confirmed'] ); ?>　升級：<?php echo esc_html( $log['upgrades'] ); ?> 人　降級：<?php echo esc_html( $log['downgrades'] ); ?> 人</p>
            <?php else : ?>
                <p class="description">系統尚未執行過每日排程，可點擊下方按鈕立即測試一次。</p>
            <?php endif; ?>

            <?php if ( $manual_run_result ) : delete_transient( 'wmt_manual_run_result' ); ?>
                <div class="notice notice-info"><p>手動執行完成：檢查 <?php echo esc_html( $manual_run_result['users_checked'] ); ?> 位會員，確認 <?php echo esc_html( $manual_run_result['orders_confirmed'] ); ?> 筆訂單，升級 <?php echo esc_html( $manual_run_result['upgrades'] ); ?> 人，降級 <?php echo esc_html( $manual_run_result['downgrades'] ); ?> 人。</p></div>
            <?php endif; ?>

            <p>
                <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wmt_run_manual_cycle' ), 'wmt_run_manual_cycle' ) ); ?>">立即手動執行一次每日排程（測試用）</a>
            </p>
            <p class="description">此按鈕會立即依目前資料判斷所有會員的升降級與續約，效果與每日凌晨 3 點自動執行的排程完全相同，可用來驗證「效期到期是否真的會降級」等邏輯是否正常。</p>

            <hr/>
            <h2>寄信測試</h2>
            <p class="description">本外掛使用 WordPress 內建 <code>wp_mail()</code> 寄送升級通知信、生日禮券信，寄送成功與否取決於主機的郵件設定。點擊下方按鈕寄一封測試信到您目前登入的管理員信箱，確認寄信功能是否正常。</p>

            <?php if ( $test_mail_result ) : delete_transient( 'wmt_test_mail_result' ); ?>
                <div class="notice <?php echo $test_mail_result['sent'] ? 'notice-success' : 'notice-error'; ?>">
                    <p><?php echo $test_mail_result['sent'] ? '已嘗試寄出至 ' . esc_html( $test_mail_result['to'] ) . '，請至信箱確認是否收到（含垃圾郵件夾）。' : '寄送失敗，請檢查主機 SMTP 設定或安裝 WP Mail SMTP 外掛。'; ?></p>
                </div>
            <?php endif; ?>

            <p>
                <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wmt_send_test_email' ), 'wmt_send_test_email' ) ); ?>">寄送測試信</a>
            </p>
        </div>
        <?php
    }
}

endif;

WC_Membership_Tiers::instance();
