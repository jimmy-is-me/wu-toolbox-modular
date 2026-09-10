<?php
/**
 * WU Toolbox Modular：會員點數。
 *
 * 以使用者提供的「購物點數（消費回饋與結帳折抵）」為基礎，保留前台樣式，
 * 僅調整成可由 WU Toolbox 按需載入的模組生命週期。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WC_Member_Points_Rewards' ) ) :

final class WC_Member_Points_Rewards {

    const OPTION_KEY   = 'wcmp_settings';
    const SESSION_KEY  = 'wcmp_points_to_use';
    const CRON_HOOK    = 'wcmp_daily_expire_points';
    const VERSION      = '6.0.1-wutm';
    const VERSION_OPT  = 'wcmp_plugin_version';

    private static $instance = null;
    private $initialized = false;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'before_woocommerce_init', array( $this, 'wcmp_declare_hpos_compatibility' ) );
        add_action( 'plugins_loaded', array( $this, 'wcmp_init' ), 20 );
    }

    public function wcmp_declare_hpos_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }

    /* ---------------------------------------------------------------
     * 啟用 / 停用
     * --------------------------------------------------------------- */

    public function wcmp_activate() {
        $this->wcmp_create_tables();
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
        if ( false === get_option( self::OPTION_KEY ) ) {
            update_option( self::OPTION_KEY, $this->wcmp_default_settings() );
        }
        add_rewrite_endpoint( 'my-points', EP_ROOT | EP_PAGES );
        flush_rewrite_rules();
        update_option( self::VERSION_OPT, self::VERSION );
    }

    public function wcmp_deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        flush_rewrite_rules();
    }

    private function wcmp_create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $ledger = $wpdb->prefix . 'member_points_ledger';
        dbDelta( "CREATE TABLE {$ledger} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(20) NOT NULL,
            amount BIGINT NOT NULL,
            remaining BIGINT NOT NULL DEFAULT 0,
            order_id BIGINT UNSIGNED NULL,
            reason VARCHAR(255) NULL,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY order_id (order_id),
            KEY type (type),
            KEY expires_at (expires_at)
        ) {$charset_collate};" );

        $log = $wpdb->prefix . 'member_points_settings_log';
        dbDelta( "CREATE TABLE {$log} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            changed_by VARCHAR(100) NULL,
            old_settings LONGTEXT NULL,
            new_settings LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset_collate};" );
    }

    public function wcmp_ensure_tables_exist() {
        global $wpdb;
        $log_table = $wpdb->prefix . 'member_points_settings_log';
        $ledger_table = $wpdb->prefix . 'member_points_ledger';
        $exists_log = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $log_table ) );
        $exists_ledger = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $ledger_table ) );
        if ( ! $exists_log || ! $exists_ledger ) {
            $this->wcmp_create_tables();
        }
    }

    /* ---------------------------------------------------------------
     * 初始化
     * --------------------------------------------------------------- */

    public function wcmp_init() {
        if ( $this->initialized ) return;
        $this->initialized = true;

        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-error"><p>「購物點數」外掛需要先安裝並啟用 WooCommerce。</p></div>';
            } );
            return;
        }

        if ( get_option( self::VERSION_OPT ) !== self::VERSION ) {
            $this->wcmp_create_tables();
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
        if ( false === get_option( self::OPTION_KEY ) ) {
            update_option( self::OPTION_KEY, $this->wcmp_default_settings() );
        }

        add_action( 'admin_menu', array( $this, 'wcmp_admin_menu' ) );
        add_action( 'admin_post_wcmp_save_settings', array( $this, 'wcmp_handle_save_settings' ) );
        add_action( 'admin_post_wcmp_manual_adjust', array( $this, 'wcmp_handle_manual_adjust' ) );
        add_action( 'admin_post_wcmp_export_members', array( $this, 'wcmp_handle_export_members' ) );
        add_action( 'admin_post_wcmp_export_ledger', array( $this, 'wcmp_handle_export_ledger' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'wcmp_admin_assets' ) );
        add_action( self::CRON_HOOK, array( $this, 'wcmp_expire_points_cron' ) );

        add_filter( 'woocommerce_account_menu_items', array( $this, 'wcmp_add_my_account_menu_item' ) );
        add_action( 'init', array( $this, 'wcmp_add_my_account_endpoint' ) );
        add_action( 'wp_loaded', array( $this, 'wcmp_maybe_flush_rewrite_rules' ) );

        add_action( 'woocommerce_account_my-points_endpoint', array( $this, 'wcmp_my_points_endpoint_content' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'wcmp_enqueue_checkout_assets' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'wcmp_enqueue_myaccount_assets' ) );
        add_action( 'woocommerce_before_order_notes', array( $this, 'wcmp_render_points_box' ) );
        add_action( 'wp_ajax_wcmp_apply_points', array( $this, 'wcmp_ajax_apply_points' ) );
        add_action( 'wp_ajax_nopriv_wcmp_apply_points', array( $this, 'wcmp_ajax_apply_points' ) );
        add_filter( 'woocommerce_cart_calculate_fees', array( $this, 'wcmp_apply_points_fee' ) );

        add_action( 'woocommerce_checkout_order_processed', array( $this, 'wcmp_on_order_created' ), 10, 3 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'wcmp_on_order_completed' ) );
        add_action( 'woocommerce_order_status_cancelled', array( $this, 'wcmp_on_order_cancelled_or_refunded' ) );
        add_action( 'woocommerce_order_status_refunded', array( $this, 'wcmp_on_order_cancelled_or_refunded' ) );
        add_action( 'woocommerce_order_status_failed', array( $this, 'wcmp_on_order_cancelled_or_refunded' ) );

        add_action( 'woocommerce_review_order_after_order_total', array( $this, 'wcmp_render_points_summary_in_review' ) );

        add_action( 'add_meta_boxes', array( $this, 'wcmp_add_order_meta_box' ) );
        add_shortcode( 'wcmp_points_balance', array( $this, 'wcmp_shortcode_balance' ) );
    }

    public function wcmp_maybe_flush_rewrite_rules() {
        if ( get_option( self::VERSION_OPT ) !== self::VERSION ) {
            flush_rewrite_rules();
            update_option( self::VERSION_OPT, self::VERSION );
        }
    }

    /* ---------------------------------------------------------------
     * 設定值
     * --------------------------------------------------------------- */

    public function wcmp_default_settings() {
        return array(
            'enable_points'        => 'yes',
            'enable_earn'          => 'yes',
            'enable_redeem'        => 'yes',
            'front_label'          => '點數',
            'earn_ratio'           => 10,
            'max_points_per_order' => 0,
            'redeem_ratio'         => 1,
            'max_redeem_percent'   => 30,
            'expire_days'          => 0,
            'min_order_amount'     => 0,
            'code_prefix'          => 'pts_',
        );
    }

    public function wcmp_get_settings() {
        $settings = get_option( self::OPTION_KEY, array() );
        return wp_parse_args( $settings, $this->wcmp_default_settings() );
    }

    private function wcmp_points_enabled() {
        $s = $this->wcmp_get_settings();
        return $s['enable_points'] === 'yes';
    }
    private function wcmp_earn_enabled() {
        $s = $this->wcmp_get_settings();
        return $this->wcmp_points_enabled() && $s['enable_earn'] === 'yes';
    }
    private function wcmp_redeem_enabled() {
        $s = $this->wcmp_get_settings();
        return $this->wcmp_points_enabled() && $s['enable_redeem'] === 'yes';
    }

    // 金額顯示：整數時不顯示小數點（例如 45 元），有小數才顯示到小數第 2 位（例如 45.5 元）
    public function wcmp_format_amount( $amount ) {
        $amount = floatval( $amount );
        if ( abs( $amount - round( $amount ) ) < 0.005 ) {
            return number_format( round( $amount ), 0 );
        }
        return number_format( $amount, 2 );
    }

    /* ---------------------------------------------------------------
     * 帳本核心函式
     * --------------------------------------------------------------- */

    private function wcmp_table() {
        global $wpdb;
        return $wpdb->prefix . 'member_points_ledger';
    }
    private function wcmp_log_table() {
        global $wpdb;
        return $wpdb->prefix . 'member_points_settings_log';
    }

    public function wcmp_get_balance( $user_id ) {
        $balance = get_user_meta( $user_id, 'wcmp_points_balance', true );
        return $balance === '' ? 0 : intval( $balance );
    }

    private function wcmp_set_balance( $user_id, $balance ) {
        update_user_meta( $user_id, 'wcmp_points_balance', max( 0, intval( $balance ) ) );
    }

    public function wcmp_add_ledger_entry( $user_id, $type, $amount, $order_id = null, $reason = '', $expire_days_override = null ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $amount = intval( $amount );

        if ( $amount > 0 ) {
            $settings = $this->wcmp_get_settings();
            $days = is_null( $expire_days_override ) ? intval( $settings['expire_days'] ) : intval( $expire_days_override );
            $expires_at = null;
            if ( $days > 0 ) {
                $expires_at = date( 'Y-m-d H:i:s', strtotime( "+{$days} days", current_time( 'timestamp' ) ) );
            }

            $wpdb->insert( $this->wcmp_table(), array(
                'user_id'    => $user_id,
                'type'       => $type,
                'amount'     => $amount,
                'remaining'  => $amount,
                'order_id'   => $order_id,
                'reason'     => $reason,
                'expires_at' => $expires_at,
                'created_at' => $now,
            ) );

            $this->wcmp_set_balance( $user_id, $this->wcmp_get_balance( $user_id ) + $amount );
        } else {
            $need = abs( $amount );
            $lots = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, remaining FROM {$this->wcmp_table()}
                 WHERE user_id = %d AND remaining > 0
                 AND ( expires_at IS NULL OR expires_at > %s )
                 ORDER BY created_at ASC",
                $user_id, $now
            ) );

            foreach ( $lots as $lot ) {
                if ( $need <= 0 ) break;
                $take = min( $lot->remaining, $need );
                $wpdb->update(
                    $this->wcmp_table(),
                    array( 'remaining' => $lot->remaining - $take ),
                    array( 'id' => $lot->id )
                );
                $need -= $take;
            }

            $wpdb->insert( $this->wcmp_table(), array(
                'user_id'    => $user_id,
                'type'       => $type,
                'amount'     => $amount,
                'remaining'  => 0,
                'order_id'   => $order_id,
                'reason'     => $reason,
                'expires_at' => null,
                'created_at' => $now,
            ) );

            $this->wcmp_set_balance( $user_id, $this->wcmp_get_balance( $user_id ) + $amount );
        }

        return true;
    }

    public function wcmp_get_recent_ledger( $user_id, $limit = 20 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->wcmp_table()} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            $user_id, $limit
        ) );
    }

    public function wcmp_get_all_members( $limit = 500, $keyword = '' ) {
        $args = array(
            'number'  => $limit,
            'orderby' => 'registered',
            'order'   => 'DESC',
            'fields'  => array( 'ID', 'display_name', 'user_email', 'user_login' ),
        );
        if ( $keyword ) {
            $args['search'] = '*' . $keyword . '*';
            $args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
        }
        $users = get_users( $args );
        $data = array();
        foreach ( $users as $u ) {
            $data[] = array(
                'ID'           => $u->ID,
                'display_name' => $u->display_name,
                'user_email'   => $u->user_email,
                'balance'      => $this->wcmp_get_balance( $u->ID ),
            );
        }
        usort( $data, function ( $a, $b ) {
            return $b['balance'] <=> $a['balance'];
        } );
        return $data;
    }

    private function wcmp_find_user_by_keyword( $keyword ) {
        if ( ! $keyword ) return null;
        if ( is_email( $keyword ) ) {
            $user = get_user_by( 'email', $keyword );
            if ( $user ) return $user;
        }
        $user = get_user_by( 'login', $keyword );
        if ( $user ) return $user;

        $users = get_users( array(
            'search'         => '*' . $keyword . '*',
            'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
            'number'         => 1,
        ) );
        return ! empty( $users ) ? $users[0] : null;
    }

    public function wcmp_get_expiring_soon( $user_id, $days = 30 ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $until = date( 'Y-m-d H:i:s', strtotime( "+{$days} days", current_time( 'timestamp' ) ) );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(expires_at) as expire_date, SUM(remaining) as pts
             FROM {$this->wcmp_table()}
             WHERE user_id = %d AND remaining > 0 AND expires_at IS NOT NULL AND expires_at BETWEEN %s AND %s
             GROUP BY DATE(expires_at) ORDER BY expire_date ASC",
            $user_id, $now, $until
        ) );
    }

    public function wcmp_expire_points_cron() {
        global $wpdb;
        $now = current_time( 'mysql' );
        $lots = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->wcmp_table()} WHERE remaining > 0 AND expires_at IS NOT NULL AND expires_at <= %s",
            $now
        ) );

        foreach ( $lots as $lot ) {
            $expired_amount = intval( $lot->remaining );
            if ( $expired_amount <= 0 ) continue;

            $wpdb->update( $this->wcmp_table(), array( 'remaining' => 0 ), array( 'id' => $lot->id ) );

            $wpdb->insert( $this->wcmp_table(), array(
                'user_id'    => $lot->user_id,
                'type'       => 'expire',
                'amount'     => -$expired_amount,
                'remaining'  => 0,
                'order_id'   => null,
                'reason'     => '點數效期到期自動扣除',
                'expires_at' => null,
                'created_at' => $now,
            ) );

            $this->wcmp_set_balance( $lot->user_id, $this->wcmp_get_balance( $lot->user_id ) - $expired_amount );
        }
    }

    /* ---------------------------------------------------------------
     * 折抵 / 回饋 計算工具
     * --------------------------------------------------------------- */

    public function wcmp_calc_max_usable_points( $user_id, $eligible_amount ) {
        if ( ! $this->wcmp_redeem_enabled() ) return 0;

        $settings = $this->wcmp_get_settings();
        $redeem_ratio = max( 0.0001, floatval( $settings['redeem_ratio'] ) );
        $max_percent  = floatval( $settings['max_redeem_percent'] );
        $min_order    = floatval( $settings['min_order_amount'] );

        if ( $eligible_amount < $min_order ) return 0;

        $max_discount_amount = $eligible_amount * ( $max_percent / 100 );
        $max_points_by_percent = intval( floor( $max_discount_amount / $redeem_ratio ) );

        $balance = $this->wcmp_get_balance( $user_id );

        return max( 0, min( $balance, $max_points_by_percent ) );
    }

    public function wcmp_calc_discount_amount( $points ) {
        $settings = $this->wcmp_get_settings();
        return round( $points * floatval( $settings['redeem_ratio'] ), 2 );
    }

    public function wcmp_calc_earn_points( $eligible_amount ) {
        if ( ! $this->wcmp_earn_enabled() ) return 0;

        $settings = $this->wcmp_get_settings();
        $earn_ratio = max( 0.0001, floatval( $settings['earn_ratio'] ) );
        $earn = intval( floor( $eligible_amount / $earn_ratio ) );

        $max_per_order = intval( $settings['max_points_per_order'] );
        if ( $max_per_order > 0 ) {
            $earn = min( $earn, $max_per_order );
        }
        return max( 0, $earn );
    }

    private function wcmp_get_eligible_amount_from_cart() {
        if ( ! WC()->cart ) return 0;
        $subtotal = WC()->cart->get_subtotal();
        $coupon_discount = WC()->cart->get_discount_total();
        $points_discount = $this->wcmp_calc_discount_amount( $this->wcmp_get_session_points() );
        return max( 0, $subtotal - $coupon_discount - $points_discount );
    }

    private function wcmp_get_eligible_amount_from_order( $order ) {
        $subtotal = $order->get_subtotal();
        $coupon_discount = $order->get_discount_total();
        $points_discount = floatval( $order->get_meta( '_wcmp_points_discount' ) );
        return max( 0, $subtotal - $coupon_discount - $points_discount );
    }

    /* ---------------------------------------------------------------
     * 結帳頁：顯示點數區塊與 AJAX 套用
     * --------------------------------------------------------------- */

    private function wcmp_get_session_points() {
        if ( ! WC()->session ) return 0;
        return intval( WC()->session->get( self::SESSION_KEY, 0 ) );
    }

    private function wcmp_set_session_points( $points ) {
        if ( WC()->session ) {
            WC()->session->set( self::SESSION_KEY, intval( $points ) );
        }
    }

    public function wcmp_enqueue_checkout_assets() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
        if ( ! $this->wcmp_redeem_enabled() ) return;

        $css = "
        #wcmp-points-box.wcmp-box{
            border:1px solid #e5e5e5;background:#fff;border-radius:10px;padding:18px 20px;margin-bottom:22px;
            box-shadow:0 1px 2px rgba(0,0,0,.03);
        }
        #wcmp-points-box .wcmp-box-title{display:flex;align-items:center;gap:6px;margin:0 0 10px;font-size:16px;}
        #wcmp-points-box .wcmp-box-title:before{content:'\\2726';color:#c9a227;}
        #wcmp-points-box .wcmp-balance-line{margin:0 0 4px;}
        #wcmp-points-box .wcmp-balance-pill{display:inline-block;background:#f4ede1;color:#8a6d1d;font-weight:700;border-radius:20px;padding:2px 12px;margin-left:4px;}
        #wcmp-points-box .wcmp-sub-title{margin:14px 0 6px;font-size:14px;color:#444;border-top:1px dashed #eee;padding-top:12px;}
        #wcmp-points-box .wcmp-hint-line{margin:0 0 10px;font-size:13px;color:#666;}
        #wcmp-points-box .wcmp-redeem-controls{display:flex;flex-wrap:wrap;gap:8px 10px;align-items:stretch;margin:6px 0 10px;}
        #wcmp-points-box .wcmp-input-group{display:flex;gap:8px;flex:1 1 220px;min-width:220px;}
        #wcmp-points-box #wcmp-points-input{flex:1;min-width:80px;height:40px;border:1px solid #d0d0d0;border-radius:6px;padding:0 12px;font-size:14px;box-sizing:border-box;}
        #wcmp-points-box .wcmp-quick-actions{display:flex;gap:8px;flex-wrap:wrap;}
        #wcmp-points-box .wcmp-redeem-controls .button{height:40px;line-height:38px;padding:0 16px;border-radius:6px;font-size:13px;box-sizing:border-box;white-space:nowrap;}
        #wcmp-points-box #wcmp-apply-btn{background:#2271b1;border-color:#2271b1;color:#fff;}
        #wcmp-points-box #wcmp-apply-btn:hover{background:#195d8f;border-color:#195d8f;color:#fff;}
        #wcmp-points-box #wcmp-use-all-btn,#wcmp-points-box #wcmp-clear-btn{background:#f5f5f5;border-color:#d7d7d7;color:#333;}
        #wcmp-points-box .wcmp-message{min-height:20px;font-size:13px;margin:4px 0 0;transition:color .15s;}
        #wcmp-points-box .wcmp-message.wcmp-msg-success{color:#1e7e34;font-weight:600;}
        #wcmp-points-box .wcmp-message.wcmp-msg-error{color:#c0392b;font-weight:600;}
        #wcmp-points-box .wcmp-message.wcmp-msg-neutral{color:#666;}
        @media (max-width:480px){
            #wcmp-points-box .wcmp-input-group{flex:1 1 100%;}
            #wcmp-points-box .wcmp-quick-actions{flex:1 1 100%;}
            #wcmp-points-box .wcmp-quick-actions .button{flex:1;}
        }
        ";
        wp_register_style( 'wcmp-checkout-style', false, array(), self::VERSION );
        wp_enqueue_style( 'wcmp-checkout-style' );
        wp_add_inline_style( 'wcmp-checkout-style', $css );

        wp_register_script( 'wcmp-checkout', '', array( 'jquery' ), self::VERSION, true );
        wp_enqueue_script( 'wcmp-checkout' );

        $inline = "
        jQuery(function($){
            function setMessage(text, type){
                var \$m = $('#wcmp-points-message');
                \$m.text(text);
                \$m.removeClass('wcmp-msg-success wcmp-msg-error wcmp-msg-neutral');
                \$m.addClass('wcmp-msg-' + type);
            }
            function wcmpApply(points){
                $.ajax({
                    url: wcmp_params.ajax_url,
                    type: 'POST',
                    data: { action: 'wcmp_apply_points', nonce: wcmp_params.nonce, points: points },
                    success: function(res){
                        if(res.success){
                            setMessage(res.data.message, points > 0 ? 'success' : 'neutral');
                            $(document.body).trigger('update_checkout');
                        } else {
                            setMessage(res.data.message, 'error');
                        }
                    }
                });
            }
            $(document).on('click', '#wcmp-apply-btn', function(e){
                e.preventDefault();
                var points = parseInt($('#wcmp-points-input').val()) || 0;
                wcmpApply(points);
            });
            $(document).on('click', '#wcmp-use-all-btn', function(e){
                e.preventDefault();
                var max = parseInt($('#wcmp-points-input').attr('max')) || 0;
                $('#wcmp-points-input').val(max);
                wcmpApply(max);
            });
            $(document).on('click', '#wcmp-clear-btn', function(e){
                e.preventDefault();
                $('#wcmp-points-input').val(0);
                wcmpApply(0);
            });
        });
        ";
        wp_add_inline_script( 'wcmp-checkout', $inline );
        wp_localize_script( 'wcmp-checkout', 'wcmp_params', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wcmp_points_nonce' ),
        ) );
    }

    public function wcmp_render_points_box() {
        if ( ! $this->wcmp_points_enabled() ) return;
        if ( ! is_user_logged_in() ) return;

        $settings = $this->wcmp_get_settings();
        $user_id  = get_current_user_id();
        $balance  = $this->wcmp_get_balance( $user_id );
        $label    = esc_html( $settings['front_label'] );

        echo '<div id="wcmp-points-box" class="wcmp-box">';
        echo '<h3 class="wcmp-box-title">我的' . $label . '</h3>';
        echo '<p class="wcmp-balance-line">目前可用' . $label . '：<span class="wcmp-balance-pill">' . intval( $balance ) . '</span></p>';

        if ( ! $this->wcmp_redeem_enabled() ) {
            echo '<p class="wcmp-hint-line">此網站目前僅開放累積' . $label . '，尚未開放結帳折抵。</p>';
            echo '</div>';
            return;
        }

        $eligible = $this->wcmp_get_eligible_amount_from_cart();
        $max_usable = $this->wcmp_calc_max_usable_points( $user_id, $eligible );
        $current_points = $this->wcmp_get_session_points();

        echo '<h4 class="wcmp-sub-title">' . $label . '折抵</h4>';
        echo '<p class="wcmp-hint-line">本次訂單最多可使用 <strong>' . intval( $max_usable ) . '</strong> 點</p>';

        if ( $max_usable > 0 ) {
            echo '<div class="wcmp-redeem-controls">
                <div class="wcmp-input-group">
                    <input type="number" id="wcmp-points-input" min="0" max="' . intval( $max_usable ) . '" value="' . intval( $current_points ) . '" />
                    <button type="button" id="wcmp-apply-btn" class="button">套用</button>
                </div>
                <div class="wcmp-quick-actions">
                    <button type="button" id="wcmp-use-all-btn" class="button">全部使用</button>
                    <button type="button" id="wcmp-clear-btn" class="button">清除</button>
                </div>
            </div>';
        } else {
            echo '<p class="wcmp-hint-line">此筆訂單目前無法使用' . $label . '折抵（可能餘額不足、未達最低折抵金額或已達上限）。</p>';
        }

        echo '<p id="wcmp-points-message" class="wcmp-message wcmp-msg-neutral"></p>';
        echo '</div>';
    }

    public function wcmp_ajax_apply_points() {
        check_ajax_referer( 'wcmp_points_nonce', 'nonce' );

        if ( ! $this->wcmp_redeem_enabled() ) {
            wp_send_json_error( array( 'message' => '目前尚未開放點數折抵。' ) );
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => '請先登入會員。' ) );
        }

        $settings = $this->wcmp_get_settings();
        $user_id  = get_current_user_id();
        $points   = isset( $_POST['points'] ) ? intval( $_POST['points'] ) : 0;

        $this->wcmp_set_session_points( 0 );
        $eligible = $this->wcmp_get_eligible_amount_from_cart();
        $max_usable = $this->wcmp_calc_max_usable_points( $user_id, $eligible );

        if ( $points < 0 ) $points = 0;
        if ( $points > $max_usable ) $points = $max_usable;

        $this->wcmp_set_session_points( $points );

        $discount = $this->wcmp_calc_discount_amount( $points );

        wp_send_json_success( array(
            'message' => $points > 0
                ? '已套用 ' . $points . ' 點，折抵 ' . $this->wcmp_format_amount( $discount ) . ' 元。'
                : '已清除' . $settings['front_label'] . '折抵。',
        ) );
    }

    public function wcmp_apply_points_fee( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( ! $this->wcmp_redeem_enabled() ) return;
        if ( ! is_user_logged_in() ) return;

        $points = $this->wcmp_get_session_points();
        if ( $points <= 0 ) return;

        // WooCommerce recalculates fees repeatedly. Revalidate against the latest
        // cart value without recursively subtracting the points fee itself.
        $eligible = max( 0, (float) $cart->get_subtotal() - (float) $cart->get_discount_total() );
        $points = min( $points, $this->wcmp_calc_max_usable_points( get_current_user_id(), $eligible ) );
        $this->wcmp_set_session_points( $points );
        if ( $points <= 0 ) return;

        $discount = $this->wcmp_calc_discount_amount( $points );
        if ( $discount <= 0 ) return;

        $settings = $this->wcmp_get_settings();
        // 顧客看到的折抵項目名稱只顯示點數名稱，不再顯示折扣碼前綴（前綴僅供後台內部識別用，見 wcmp_on_order_created）
        $cart->add_fee( $settings['front_label'] . '折抵', -$discount, false );
    }

    public function wcmp_render_points_summary_in_review() {
        if ( ! $this->wcmp_earn_enabled() ) return;
        if ( ! is_user_logged_in() ) return;
        $settings = $this->wcmp_get_settings();
        $eligible = $this->wcmp_get_eligible_amount_from_cart();
        $earn = $this->wcmp_calc_earn_points( $eligible );
        echo '<tr class="wcmp-estimated-earn"><th>預計回饋' . esc_html( $settings['front_label'] ) . '</th><td>' . intval( $earn ) . ' 點</td></tr>';
    }

    /* ---------------------------------------------------------------
     * 訂單流程
     * --------------------------------------------------------------- */

    public function wcmp_on_order_created( $order_id, $posted_data, $order ) {
        if ( ! $this->wcmp_redeem_enabled() ) return;
        if ( ! $order instanceof WC_Order ) $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Checkout callbacks may be replayed by payment or compatibility plugins.
        // Never deduct the same order's points more than once.
        if ( $order->get_meta( '_wcmp_points_used', true ) !== '' ) return;

        $user_id = $order->get_customer_id();
        if ( ! $user_id ) return;

        $points = $this->wcmp_get_session_points();
        if ( $points <= 0 ) return;

        $balance = $this->wcmp_get_balance( $user_id );
        $eligible = max( 0, (float) $order->get_subtotal() - (float) $order->get_discount_total() );
        $points = min( $points, $balance, $this->wcmp_calc_max_usable_points( $user_id, $eligible ) );
        if ( $points <= 0 ) return;

        $settings = $this->wcmp_get_settings();
        $discount = $this->wcmp_calc_discount_amount( $points );
        $prefix = $settings['code_prefix'] ? $settings['code_prefix'] : 'pts_';
        $discount_code = $prefix . $order_id; // 僅供後台內部識別／報表使用，不會顯示給顧客

        $order->update_meta_data( '_wcmp_points_used', $points );
        $order->update_meta_data( '_wcmp_points_discount', $discount );
        $order->update_meta_data( '_wcmp_points_refunded', 'no' );
        $order->update_meta_data( '_wcmp_points_earned', 0 );
        $order->update_meta_data( '_wcmp_discount_code', $discount_code );
        $order->add_order_note( sprintf( '會員使用 %d 點%s折抵，折抵金額 %s 元。（內部代碼：%s）', $points, $settings['front_label'], $this->wcmp_format_amount( $discount ), $discount_code ) );
        $order->save();

        $this->wcmp_add_ledger_entry(
            $user_id,
            'redeem',
            -$points,
            $order_id,
            '訂單折抵 #' . $order->get_order_number()
        );

        $this->wcmp_set_session_points( 0 );
    }

    public function wcmp_on_order_completed( $order_id ) {
        if ( ! $this->wcmp_earn_enabled() ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $user_id = $order->get_customer_id();
        if ( ! $user_id ) return;

        if ( $order->get_meta( '_wcmp_points_earn_processed' ) === 'yes' || $order->get_meta( '_wcmp_points_earned' ) ) return;

        $settings = $this->wcmp_get_settings();
        $eligible = $this->wcmp_get_eligible_amount_from_order( $order );
        $earn = $this->wcmp_calc_earn_points( $eligible );

        if ( $earn > 0 ) {
            $this->wcmp_add_ledger_entry(
                $user_id,
                'earn',
                $earn,
                $order_id,
                '訂單消費回饋 #' . $order->get_order_number()
            );
            $order->add_order_note( sprintf( '訂單完成，會員獲得 %d 點%s回饋。', $earn, $settings['front_label'] ) );
        }

        $order->update_meta_data( '_wcmp_points_earned', $earn );
        $order->update_meta_data( '_wcmp_points_earn_processed', 'yes' );
        $order->save();
    }

    public function wcmp_on_order_cancelled_or_refunded( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $user_id = $order->get_customer_id();
        if ( ! $user_id ) return;

        $settings = $this->wcmp_get_settings();

        $used = intval( $order->get_meta( '_wcmp_points_used' ) );
        $refunded_flag = $order->get_meta( '_wcmp_points_refunded' );
        if ( $used > 0 && $refunded_flag !== 'yes' ) {
            $this->wcmp_add_ledger_entry(
                $user_id,
                'refund_redeem',
                $used,
                $order_id,
                '訂單取消／退款，退還折抵點數 #' . $order->get_order_number()
            );
            $order->update_meta_data( '_wcmp_points_refunded', 'yes' );
            $order->add_order_note( sprintf( '訂單取消／退款，已退還會員 %d 點%s。', $used, $settings['front_label'] ) );
        }

        $earned = intval( $order->get_meta( '_wcmp_points_earned' ) );
        $earn_reclaimed = $order->get_meta( '_wcmp_points_earn_reclaimed' );
        if ( $earned > 0 && $earn_reclaimed !== 'yes' ) {
            $this->wcmp_add_ledger_entry(
                $user_id,
                'cancel_earn',
                -$earned,
                $order_id,
                '訂單取消／退款，收回消費回饋 #' . $order->get_order_number()
            );
            $order->update_meta_data( '_wcmp_points_earn_reclaimed', 'yes' );
            $order->add_order_note( sprintf( '訂單取消／退款，已收回會員 %d 點%s回饋。', $earned, $settings['front_label'] ) );
        }

        $order->save();
    }

    /* ---------------------------------------------------------------
     * 訂單編輯頁：點數資訊 Meta Box（相容傳統與 HPOS）
     * --------------------------------------------------------------- */

    public function wcmp_add_order_meta_box() {
        $screen = 'shop_order';
        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
            $screen = wc_get_page_screen_id( 'shop-order' );
        }
        add_meta_box( 'wcmp_order_points', '購物點數', array( $this, 'wcmp_render_order_meta_box' ), $screen, 'side', 'default' );
    }

    public function wcmp_render_order_meta_box( $post_or_order_object ) {
        $order = ( $post_or_order_object instanceof WC_Order ) ? $post_or_order_object : wc_get_order( $post_or_order_object->ID );
        if ( ! $order ) {
            echo '<p>找不到訂單資料。</p>';
            return;
        }

        $settings = $this->wcmp_get_settings();
        $used = intval( $order->get_meta( '_wcmp_points_used' ) );
        $discount = floatval( $order->get_meta( '_wcmp_points_discount' ) );
        $earned = intval( $order->get_meta( '_wcmp_points_earned' ) );
        $discount_code = $order->get_meta( '_wcmp_discount_code' );
        $user_id = $order->get_customer_id();

        echo '<p>本單使用折抵：<strong>' . $used . '</strong> 點（折抵 ' . $this->wcmp_format_amount( $discount ) . ' 元）</p>';
        if ( $discount_code ) {
            echo '<p>內部識別代碼：<code>' . esc_html( $discount_code ) . '</code></p>';
        }
        echo '<p>本單消費回饋：<strong>' . $earned . '</strong> 點</p>';
        if ( $user_id ) {
            echo '<p>會員目前' . esc_html( $settings['front_label'] ) . '餘額：<strong>' . intval( $this->wcmp_get_balance( $user_id ) ) . '</strong> 點</p>';
            $link = add_query_arg( array( 'page' => 'wcmp-points', 'tab' => 'members', 'uid' => $user_id ), admin_url( 'admin.php' ) );
            echo '<p><a href="' . esc_url( $link ) . '" class="button button-small">查看該會員完整點數紀錄</a></p>';
        } else {
            echo '<p>此訂單非會員訂單，無對應點數帳戶。</p>';
        }
    }

    /* ---------------------------------------------------------------
     * 短代碼：[wcmp_points_balance]
     * --------------------------------------------------------------- */

    public function wcmp_shortcode_balance( $atts ) {
        if ( ! $this->wcmp_points_enabled() ) return '';
        if ( ! is_user_logged_in() ) return '';
        $settings = $this->wcmp_get_settings();
        $balance = $this->wcmp_get_balance( get_current_user_id() );
        return '<span class="wcmp-shortcode-balance">' . intval( $balance ) . ' ' . esc_html( $settings['front_label'] ) . '</span>';
    }

    /* ---------------------------------------------------------------
     * 會員中心：我的點數
     * --------------------------------------------------------------- */

    public function wcmp_add_my_account_endpoint() {
        add_rewrite_endpoint( 'my-points', EP_ROOT | EP_PAGES );
    }

    public function wcmp_add_my_account_menu_item( $items ) {
        if ( ! $this->wcmp_points_enabled() ) return $items;

        $settings = $this->wcmp_get_settings();
        $new_items = array();
        foreach ( $items as $key => $label ) {
            $new_items[ $key ] = $label;
            if ( 'orders' === $key ) {
                $new_items['my-points'] = '我的' . $settings['front_label'];
            }
        }
        if ( ! isset( $new_items['my-points'] ) ) {
            $new_items['my-points'] = '我的' . $settings['front_label'];
        }
        return $new_items;
    }

    public function wcmp_enqueue_myaccount_assets() {
        if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) return;

        $css = "
        .wcmp-points-wrap{max-width:760px;}
        .wcmp-hero{background:linear-gradient(135deg,#2271b1,#1a5a8a);color:#fff;border-radius:12px;padding:24px 28px;margin-bottom:20px;}
        .wcmp-hero .wcmp-hero-label{font-size:14px;opacity:.85;margin:0 0 6px;}
        .wcmp-hero .wcmp-hero-balance{font-size:40px;font-weight:700;margin:0;line-height:1.2;}
        .wcmp-hero .wcmp-hero-balance span{font-size:16px;font-weight:400;margin-left:6px;opacity:.85;}
        .wcmp-expiring-card{background:#fff8e5;border:1px solid #ffe08a;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:13px;color:#5a4400;}
        .wcmp-expiring-card strong{color:#8a6400;}
        .wcmp-rules-card{background:#f6f7f7;border:1px solid #e2e4e7;border-radius:10px;padding:18px 20px;margin-bottom:20px;}
        .wcmp-rules-card h4{margin-top:0;}
        .wcmp-rules-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;list-style:none;margin:0;padding:0;}
        .wcmp-rules-grid li{background:#fff;border:1px solid #e2e4e7;border-radius:8px;padding:10px 12px;font-size:13px;color:#3c434a;}
        .wcmp-rules-grid li strong{display:block;font-size:15px;color:#1d2327;margin-top:2px;}
        .wcmp-records-card h4{margin-bottom:10px;}
        table.wcmp-records-table{width:100%;border-collapse:collapse;background:#fff;}
        table.wcmp-records-table th{background:#f0f0f1;text-align:left;padding:10px;font-size:13px;}
        table.wcmp-records-table td{padding:10px;border-bottom:1px solid #eee;font-size:13px;}
        table.wcmp-records-table tr:nth-child(even) td{background:#fafafa;}
        .wcmp-amount-pos{color:#1e7e34;font-weight:600;}
        .wcmp-amount-neg{color:#c0392b;font-weight:600;}
        .wcmp-box{border:1px solid #ddd;padding:16px 18px;margin-bottom:20px;border-radius:10px;background:#fff;}
        .wcmp-box-title{margin:0 0 8px;}
        .wcmp-sub-title{margin:14px 0 4px;}
        .wcmp-balance-pill{display:inline-block;background:#eef4fb;color:#2271b1;font-weight:700;border-radius:20px;padding:2px 12px;}
        .wcmp-message{color:#c0392b;min-height:18px;}
        ";
        wp_register_style( 'wcmp-myaccount', false, array(), self::VERSION );
        wp_enqueue_style( 'wcmp-myaccount' );
        wp_add_inline_style( 'wcmp-myaccount', $css );
    }

    public function wcmp_my_points_endpoint_content() {
        if ( ! $this->wcmp_points_enabled() ) {
            echo '<p>此網站目前未開放點數功能。</p>';
            return;
        }

        $user_id  = get_current_user_id();
        $settings = $this->wcmp_get_settings();
        $balance  = $this->wcmp_get_balance( $user_id );
        $records  = $this->wcmp_get_recent_ledger( $user_id, 20 );
        $expiring = $this->wcmp_get_expiring_soon( $user_id, 30 );
        $label    = esc_html( $settings['front_label'] );

        echo '<div class="wcmp-points-wrap">';

        echo '<div class="wcmp-hero">';
        echo '<p class="wcmp-hero-label">我的' . $label . '</p>';
        echo '<p class="wcmp-hero-balance">' . intval( $balance ) . '<span>點可用</span></p>';
        echo '</div>';

        if ( ! empty( $expiring ) ) {
            echo '<div class="wcmp-expiring-card">';
            echo '<strong>即將到期提醒：</strong><br>';
            foreach ( $expiring as $row ) {
                echo esc_html( intval( $row->pts ) . ' 點將於 ' . $row->expire_date . ' 到期，請盡快使用。' ) . '<br>';
            }
            echo '</div>';
        }

        echo '<div class="wcmp-rules-card">';
        echo '<h4>規則摘要</h4>';
        echo '<ul class="wcmp-rules-grid">';
        if ( $this->wcmp_earn_enabled() ) {
            echo '<li>消費回饋<strong>每 ' . esc_html( $settings['earn_ratio'] ) . ' 元回饋 1 點</strong></li>';
        }
        if ( $this->wcmp_redeem_enabled() ) {
            echo '<li>折抵比例<strong>1 點折 ' . esc_html( $settings['redeem_ratio'] ) . ' 元</strong></li>';
            echo '<li>單筆最高折抵<strong>訂單可折抵金額的 ' . esc_html( $settings['max_redeem_percent'] ) . '%</strong></li>';
            echo '<li>最低折抵訂單金額<strong>' . esc_html( $settings['min_order_amount'] ) . ' 元</strong></li>';
        }
        echo '<li>點數效期<strong>' . ( intval( $settings['expire_days'] ) > 0 ? intval( $settings['expire_days'] ) . ' 天' : '永久有效' ) . '</strong></li>';
        echo '</ul>';
        echo '</div>';

        echo '<div class="wcmp-records-card">';
        echo '<h4>最近點數紀錄</h4>';
        if ( empty( $records ) ) {
            echo '<p>目前尚無點數紀錄。</p>';
        } else {
            echo '<table class="wcmp-records-table"><thead><tr><th>日期</th><th>類型</th><th>異動點數</th><th>訂單</th><th>說明</th></tr></thead><tbody>';
            foreach ( $records as $r ) {
                $cls = $r->amount > 0 ? 'wcmp-amount-pos' : 'wcmp-amount-neg';
                echo '<tr>';
                echo '<td>' . esc_html( $r->created_at ) . '</td>';
                echo '<td>' . esc_html( $this->wcmp_type_label( $r->type ) ) . '</td>';
                echo '<td class="' . $cls . '">' . ( $r->amount > 0 ? '+' : '' ) . intval( $r->amount ) . '</td>';
                echo '<td>' . ( $r->order_id ? '#' . intval( $r->order_id ) : '-' ) . '</td>';
                echo '<td>' . esc_html( $r->reason ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        echo '</div>';
    }

    private function wcmp_type_label( $type ) {
        $map = array(
            'earn'          => '消費回饋',
            'redeem'        => '結帳折抵',
            'manual_add'    => '後台手動增加',
            'manual_deduct' => '後台手動扣除',
            'expire'        => '效期到期',
            'refund_redeem' => '退還折抵',
            'cancel_earn'   => '收回回饋',
        );
        return isset( $map[ $type ] ) ? $map[ $type ] : $type;
    }

    /* ---------------------------------------------------------------
     * 後台：單一主選單 + 分頁（總覽 / 會員點數 / 設定）
     * --------------------------------------------------------------- */

    public function wcmp_admin_menu() {
        add_submenu_page(
            'wu-toolbox-modular',
            '購物點數',
            '會員點數',
            'manage_woocommerce',
            'wcmp-points',
            array( $this, 'wcmp_render_admin_page' )
        );
    }

    public function wcmp_admin_assets( $hook ) {
        if ( strpos( $hook, 'wcmp-points' ) === false ) return;
        wp_register_style( 'wcmp-admin', false, array(), self::VERSION );
        wp_enqueue_style( 'wcmp-admin' );
        wp_add_inline_style( 'wcmp-admin', '
            .wcmp-tabs{margin:20px 0;border-bottom:1px solid #ccd0d4;}
            .wcmp-tabs a{display:inline-block;padding:10px 16px;text-decoration:none;color:#50575e;border:1px solid transparent;border-bottom:none;margin-right:4px;}
            .wcmp-tabs a.active{background:#fff;border-color:#ccd0d4;color:#1d2327;font-weight:600;border-radius:4px 4px 0 0;}
            .wcmp-status-banner{background:#eef4fb;border:1px solid #cde0f3;border-radius:8px;padding:12px 16px;margin:10px 0 20px;font-size:14px;}
            .wcmp-status-banner strong{color:#2271b1;}
            .wcmp-toggle-card{background:#fff;border:1px solid #e2e4e7;border-radius:10px;padding:16px 18px;margin-bottom:10px;display:flex;gap:14px;align-items:flex-start;}
            .wcmp-toggle-card.disabled-look{opacity:.5;}
            .wcmp-switch{position:relative;display:inline-block;width:44px;height:24px;flex:0 0 44px;margin-top:2px;}
            .wcmp-switch input{opacity:0;width:0;height:0;}
            .wcmp-slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background-color:#ccc;transition:.2s;border-radius:24px;}
            .wcmp-slider:before{position:absolute;content:"";height:18px;width:18px;left:3px;bottom:3px;background-color:#fff;transition:.2s;border-radius:50%;}
            .wcmp-switch input:checked + .wcmp-slider{background-color:#2271b1;}
            .wcmp-switch input:checked + .wcmp-slider:before{transform:translateX(20px);}
            .wcmp-switch input:disabled + .wcmp-slider{background-color:#ddd;cursor:not-allowed;}
            .wcmp-toggle-text .wcmp-toggle-title{font-weight:600;font-size:14px;margin-bottom:3px;}
            .wcmp-toggle-text .wcmp-toggle-desc{color:#666;font-size:13px;line-height:1.5;}
            .wcmp-field-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:20px;margin-top:15px;}
            .wcmp-field-grid label{display:block;font-weight:600;margin-bottom:6px;}
            .wcmp-field-grid input[type=text],.wcmp-field-grid input[type=number]{width:100%;}
            .wcmp-field-grid .desc{color:#666;font-size:12px;margin-top:4px;}
            .wcmp-log-table td{vertical-align:top;}
            .wcmp-rank-col{width:48px;color:#787c82;}
            .wcmp-member-row td{vertical-align:middle;}
            .wcmp-balance-badge{display:inline-block;background:#eef4fb;color:#2271b1;font-weight:700;border-radius:14px;padding:2px 10px;}
            .wcmp-filter-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0;}
            .wcmp-filter-bar select{min-width:220px;}
            .wcmp-selected-callout{background:#fff8e5;border:1px solid #ffe08a;border-radius:8px;padding:10px 14px;margin:10px 0;font-size:13px;}
            .wcmp-export-link{margin-left:auto;}
        ' );

        wp_register_script( 'wcmp-admin-js', '', array( 'jquery' ), self::VERSION, true );
        wp_enqueue_script( 'wcmp-admin-js' );
        wp_add_inline_script( 'wcmp-admin-js', "
        jQuery(function($){
            function syncMasterToggle(){
                var master = $('#wcmp-enable-points').is(':checked');
                $('.wcmp-sub-toggle').prop('disabled', !master);
                $('.wcmp-sub-toggle').closest('.wcmp-toggle-card').toggleClass('disabled-look', !master);
            }
            $('#wcmp-enable-points').on('change', syncMasterToggle);
            syncMasterToggle();

            $('.wcmp-auto-submit').on('change', function(){
                $(this).closest('form').trigger('submit');
            });
        });
        " );
    }

    public function wcmp_render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'overview';
        $base_url = admin_url( 'admin.php?page=wcmp-points' );
        ?>
        <div class="wrap">
            <h1>購物點數</h1>
            <p>提供您的客戶於消費時擁有點數回饋，並可用於結帳折抵。</p>
            <h2 class="wcmp-tabs">
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'overview', $base_url ) ); ?>" class="<?php echo $tab === 'overview' ? 'active' : ''; ?>">總覽</a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'members', $base_url ) ); ?>" class="<?php echo $tab === 'members' ? 'active' : ''; ?>">會員點數</a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $base_url ) ); ?>" class="<?php echo $tab === 'settings' ? 'active' : ''; ?>">設定</a>
            </h2>
            <?php
            if ( 'members' === $tab ) {
                $this->wcmp_render_members_tab();
            } elseif ( 'settings' === $tab ) {
                $this->wcmp_render_settings_tab();
            } else {
                $this->wcmp_render_overview_tab();
            }
            ?>
        </div>
        <?php
    }

    private function wcmp_get_status_text() {
        if ( ! $this->wcmp_points_enabled() ) return '總功能已關閉，點數回饋與折抵目前皆不會運作。';
        if ( $this->wcmp_earn_enabled() && $this->wcmp_redeem_enabled() ) return '功能全開：顧客消費可獲得回饋，也能在結帳頁使用點數折抵。';
        if ( $this->wcmp_earn_enabled() ) return '僅開放回饋累積：顧客消費會獲得點數，但結帳頁尚不能折抵，適合先觀察發放狀況。';
        if ( $this->wcmp_redeem_enabled() ) return '僅開放折抵：顧客可使用既有點數折抵，但目前消費不會再累積新點數。';
        return '總功能已開啟，但回饋與折抵都關閉，等同於暫停點數的實際效果。';
    }

    /* ---- 設定分頁 ---- */

    private function wcmp_render_settings_tab() {
        $settings = $this->wcmp_get_settings();
        ?>
        <?php if ( isset( $_GET['saved'] ) ) : ?>
            <div class="notice notice-success"><p>設定已儲存，永久連結已自動同步，無需手動前往「設定→永久連結」重新整理。</p></div>
        <?php endif; ?>

        <div class="wcmp-status-banner">目前狀態：<strong><?php echo esc_html( $this->wcmp_get_status_text() ); ?></strong></div>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="wcmp_save_settings" />
            <?php wp_nonce_field( 'wcmp_save_settings_nonce' ); ?>

            <h3>點數設定</h3>
            <p class="description">三個開關由上而下為階層關係：關閉「功能啟用」會連帶停用下面兩者；「回饋」與「折抵」可各自獨立開關。</p>

            <div class="wcmp-toggle-card">
                <label class="wcmp-switch">
                    <input type="checkbox" id="wcmp-enable-points" name="enable_points" value="yes" <?php checked( $settings['enable_points'], 'yes' ); ?> />
                    <span class="wcmp-slider"></span>
                </label>
                <div class="wcmp-toggle-text">
                    <div class="wcmp-toggle-title">總功能開關</div>
                    <div class="wcmp-toggle-desc">此開關是最上層總開關。關閉後，即使下方「回饋」「折抵」顯示為開啟，也會一併停止運作：顧客結帳看不到點數區塊、消費不會累積點數、會員中心也不會出現「我的點數」。適合暫時全面下架此功能時使用。</div>
                </div>
            </div>

            <div class="wcmp-toggle-card">
                <label class="wcmp-switch">
                    <input type="checkbox" class="wcmp-sub-toggle" name="enable_earn" value="yes" <?php checked( $settings['enable_earn'], 'yes' ); ?> <?php disabled( $settings['enable_points'], 'no' ); ?> />
                    <span class="wcmp-slider"></span>
                </label>
                <div class="wcmp-toggle-text">
                    <div class="wcmp-toggle-title">消費回饋開關</div>
                    <div class="wcmp-toggle-desc">開啟後，顧客訂單狀態變成「已完成」時，會依下方「發放比例」自動計算並存入點數。關閉後不影響既有點數餘額，只是之後的訂單不會再產生新回饋。</div>
                </div>
            </div>

            <div class="wcmp-toggle-card">
                <label class="wcmp-switch">
                    <input type="checkbox" class="wcmp-sub-toggle" name="enable_redeem" value="yes" <?php checked( $settings['enable_redeem'], 'yes' ); ?> <?php disabled( $settings['enable_points'], 'no' ); ?> />
                    <span class="wcmp-slider"></span>
                </label>
                <div class="wcmp-toggle-text">
                    <div class="wcmp-toggle-title">結帳折抵開關</div>
                    <div class="wcmp-toggle-desc">開啟後，標準結帳頁會顯示可用點數與折抵輸入框。關閉後結帳頁會立即隱藏折抵區塊，顧客當下若已套用的點數折抵也會被清除，不會影響會員既有點數餘額。</div>
                </div>
            </div>

            <div class="wcmp-field-grid">
                <div>
                    <label>發放比例（NT$X＝1 點）</label>
                    <input type="number" step="0.01" min="0.01" name="earn_ratio" value="<?php echo esc_attr( $settings['earn_ratio'] ); ?>" />
                    <p class="desc">例：填 10 代表每消費 10 元回饋 1 點</p>
                </div>
                <div>
                    <label>每筆訂單最高點數</label>
                    <input type="number" min="0" name="max_points_per_order" value="<?php echo esc_attr( $settings['max_points_per_order'] ); ?>" />
                    <p class="desc">0＝不限制</p>
                </div>
                <div>
                    <label>折抵比例（1 點＝NT$X）</label>
                    <input type="number" step="0.01" min="0.01" name="redeem_ratio" value="<?php echo esc_attr( $settings['redeem_ratio'] ); ?>" />
                    <p class="desc">例：填 1 代表 1 點可折 1 元</p>
                </div>
                <div>
                    <label>最高折抵訂單金額 %</label>
                    <input type="number" min="0" max="100" name="max_redeem_percent" value="<?php echo esc_attr( $settings['max_redeem_percent'] ); ?>" />
                    <p class="desc">限制單筆訂單最多折抵可折抵金額的百分比</p>
                </div>
                <div>
                    <label>點數有效期限（天）</label>
                    <input type="number" min="0" name="expire_days" value="<?php echo esc_attr( $settings['expire_days'] ); ?>" />
                    <p class="desc">0＝永不到期，從每筆點數取得日起算</p>
                </div>
                <div>
                    <label>最低折抵訂單金額</label>
                    <input type="number" step="0.01" min="0" name="min_order_amount" value="<?php echo esc_attr( $settings['min_order_amount'] ); ?>" />
                    <p class="desc">0＝不限制</p>
                </div>
                <div>
                    <label>點數名稱</label>
                    <input type="text" name="front_label" value="<?php echo esc_attr( $settings['front_label'] ); ?>" />
                    <p class="desc">前台顯示給顧客看的名稱</p>
                </div>
                <div>
                    <label>折扣碼前綴</label>
                    <input type="text" name="code_prefix" value="<?php echo esc_attr( $settings['code_prefix'] ); ?>" placeholder="pts_" />
                    <p class="desc">僅用於後台內部識別代碼（訂單備註／Meta），不會顯示在顧客結帳畫面。留空＝預設 pts_</p>
                </div>
            </div>

            <p style="margin-top:20px;"><button type="submit" class="button button-primary">儲存設定</button></p>
        </form>

        <?php $this->wcmp_render_settings_log(); ?>
        <?php
    }

    private function wcmp_render_settings_log() {
        global $wpdb;
        $this->wcmp_ensure_tables_exist();
        $logs = $wpdb->get_results( "SELECT * FROM {$this->wcmp_log_table()} ORDER BY created_at DESC LIMIT 30" );
        $field_labels = array(
            'enable_points'        => '總功能開關',
            'enable_earn'          => '消費回饋開關',
            'enable_redeem'        => '結帳折抵開關',
            'front_label'          => '點數名稱',
            'earn_ratio'           => '發放比例',
            'max_points_per_order' => '每筆訂單最高點數',
            'redeem_ratio'         => '折抵比例',
            'max_redeem_percent'   => '最高折抵百分比',
            'expire_days'          => '點數有效期限',
            'min_order_amount'     => '最低折抵訂單金額',
            'code_prefix'          => '折扣碼前綴',
        );
        ?>
        <details open>
            <summary style="cursor:pointer;font-weight:600;">設定變更紀錄（近 30 筆）</summary>
            <?php if ( empty( $logs ) ) : ?>
                <p>目前尚無變更紀錄。每次按下「儲存設定」後，這裡都會新增一筆紀錄；若儲存後仍未出現，請確認資料庫帳號是否有建立資料表的權限。</p>
            <?php else : ?>
                <table class="widefat striped wcmp-log-table" style="margin-top:10px;">
                    <thead><tr><th style="width:160px;">時間</th><th style="width:140px;">操作人員</th><th>變更內容</th></tr></thead>
                    <tbody>
                    <?php foreach ( $logs as $log ) :
                        $old = json_decode( $log->old_settings, true );
                        $new = json_decode( $log->new_settings, true );
                        $diffs = array();
                        if ( is_array( $old ) && is_array( $new ) ) {
                            foreach ( $new as $k => $v ) {
                                $old_v = isset( $old[ $k ] ) ? $old[ $k ] : null;
                                if ( (string) $old_v !== (string) $v ) {
                                    $label = isset( $field_labels[ $k ] ) ? $field_labels[ $k ] : $k;
                                    $diffs[] = '<strong>' . esc_html( $label ) . '</strong>：' . esc_html( $old_v === null ? '(無)' : $old_v ) . ' → ' . esc_html( $v );
                                }
                            }
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html( $log->created_at ); ?></td>
                            <td><?php echo esc_html( $log->changed_by ); ?></td>
                            <td><?php echo $diffs ? implode( '<br>', $diffs ) : '本次儲存內容與上次相同，無欄位變更。'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </details>
        <?php
    }

    public function wcmp_handle_save_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足' );
        check_admin_referer( 'wcmp_save_settings_nonce' );

        $this->wcmp_ensure_tables_exist();

        $old_settings = $this->wcmp_get_settings();

        $new_settings = array(
            'enable_points'        => isset( $_POST['enable_points'] ) ? 'yes' : 'no',
            'enable_earn'          => isset( $_POST['enable_earn'] ) ? 'yes' : 'no',
            'enable_redeem'        => isset( $_POST['enable_redeem'] ) ? 'yes' : 'no',
            'front_label'          => sanitize_text_field( $_POST['front_label'] ?? '點數' ),
            'earn_ratio'           => max( 0.01, floatval( $_POST['earn_ratio'] ?? 10 ) ),
            'max_points_per_order' => max( 0, intval( $_POST['max_points_per_order'] ?? 0 ) ),
            'redeem_ratio'         => max( 0.01, floatval( $_POST['redeem_ratio'] ?? 1 ) ),
            'max_redeem_percent'   => min( 100, max( 0, floatval( $_POST['max_redeem_percent'] ?? 30 ) ) ),
            'expire_days'          => max( 0, intval( $_POST['expire_days'] ?? 0 ) ),
            'min_order_amount'     => max( 0, floatval( $_POST['min_order_amount'] ?? 0 ) ),
            'code_prefix'          => sanitize_text_field( $_POST['code_prefix'] ?? '' ) ?: 'pts_',
        );

        update_option( self::OPTION_KEY, $new_settings );

        global $wpdb;
        $current_user = wp_get_current_user();
        $inserted = $wpdb->insert( $this->wcmp_log_table(), array(
            'changed_by'   => $current_user && $current_user->exists() ? $current_user->user_login : 'system',
            'old_settings' => wp_json_encode( $old_settings ),
            'new_settings' => wp_json_encode( $new_settings ),
            'created_at'   => current_time( 'mysql' ),
        ) );

        if ( false === $inserted ) {
            error_log( 'WC Member Points: 設定變更紀錄寫入失敗 - ' . $wpdb->last_error );
        }

        flush_rewrite_rules();

        wp_safe_redirect( add_query_arg( array( 'page' => 'wcmp-points', 'tab' => 'settings', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ---- 會員點數分頁 ---- */

    private function wcmp_render_members_tab() {
        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $uid    = isset( $_GET['uid'] ) ? intval( $_GET['uid'] ) : 0;
        $found_user = null;

        if ( $uid ) {
            $found_user = get_userdata( $uid );
        }

        $list_url = remove_query_arg( array( 's', 'uid', 'adjusted' ) );
        ?>
        <?php if ( isset( $_GET['adjusted'] ) ) : ?>
            <div class="notice notice-success"><p>點數已調整。</p></div>
        <?php endif; ?>

        <div class="wcmp-filter-bar">
            <form method="get" style="display:flex;gap:8px;align-items:center;">
                <input type="hidden" name="page" value="wcmp-points" />
                <input type="hidden" name="tab" value="members" />
                <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="輸入 Email、帳號或姓名搜尋會員" class="regular-text" />
                <button class="button button-primary">搜尋</button>
                <?php if ( $found_user || $search ) : ?>
                    <a href="<?php echo esc_url( $list_url ); ?>" class="button">回列表</a>
                <?php endif; ?>
            </form>
            <a class="button wcmp-export-link" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'wcmp_export_members' ), admin_url( 'admin-post.php' ) ), 'wcmp_export_members_nonce' ) ); ?>">匯出全部會員點數 CSV</a>
        </div>

        <?php if ( $uid && ! $found_user ) : ?>
            <p>找不到符合的會員。</p>
        <?php endif; ?>

        <?php if ( $found_user ) :
            $user_id = $found_user->ID;
            $balance = $this->wcmp_get_balance( $user_id );
            $records = $this->wcmp_get_recent_ledger( $user_id, 30 );
            ?>
            <h2><?php echo esc_html( $found_user->display_name ); ?>（<?php echo esc_html( $found_user->user_email ); ?>）</h2>
            <p>目前餘額：<strong><?php echo intval( $balance ); ?></strong> 點</p>

            <h3>手動調整點數</h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="wcmp_manual_adjust" />
                <input type="hidden" name="user_id" value="<?php echo intval( $user_id ); ?>" />
                <?php wp_nonce_field( 'wcmp_manual_adjust_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th>調整方式</th>
                        <td>
                            <label><input type="radio" name="direction" value="add" checked /> 增加</label>
                            <label style="margin-left:15px;"><input type="radio" name="direction" value="deduct" /> 扣除</label>
                        </td>
                    </tr>
                    <tr>
                        <th>點數數量</th>
                        <td><input type="number" min="1" name="points" required style="width:120px;" /></td>
                    </tr>
                    <tr>
                        <th>到期日（僅增加時可設定）</th>
                        <td><input type="date" name="expiry_date" /> <p class="description">留空則依全站設定之效期天數計算。</p></td>
                    </tr>
                    <tr>
                        <th>原因</th>
                        <td><input type="text" name="reason" class="regular-text" required placeholder="例如：出貨延遲補償" /></td>
                    </tr>
                </table>
                <?php submit_button( '送出調整' ); ?>
            </form>

            <h3>點數紀錄</h3>
            <table class="widefat striped">
                <thead><tr><th>日期</th><th>類型</th><th>異動點數</th><th>剩餘（僅入帳批次）</th><th>訂單</th><th>說明</th></tr></thead>
                <tbody>
                <?php foreach ( $records as $r ) : ?>
                    <tr>
                        <td><?php echo esc_html( $r->created_at ); ?></td>
                        <td><?php echo esc_html( $this->wcmp_type_label( $r->type ) ); ?></td>
                        <td><?php echo ( $r->amount > 0 ? '+' : '' ) . intval( $r->amount ); ?></td>
                        <td><?php echo $r->amount > 0 ? intval( $r->remaining ) : '-'; ?></td>
                        <td><?php echo $r->order_id ? '#' . intval( $r->order_id ) : '-'; ?></td>
                        <td><?php echo esc_html( $r->reason ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else :
            $members = $this->wcmp_get_all_members( 500, $search );
            ?>
            <h3>
                <?php echo $search ? '搜尋結果（依目前點數由高到低排序）' : '所有會員列表（依目前點數由高到低排序，含尚未擁有點數的會員）'; ?>
            </h3>
            <?php if ( empty( $members ) ) : ?>
                <p>找不到符合的會員，或目前網站尚無任何會員。</p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th class="wcmp-rank-col">#</th><th>會員</th><th>Email</th><th>目前餘額</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ( $members as $i => $m ) :
                        $detail_url = add_query_arg( array( 'page' => 'wcmp-points', 'tab' => 'members', 'uid' => $m['ID'] ), admin_url( 'admin.php' ) );
                        ?>
                        <tr class="wcmp-member-row">
                            <td class="wcmp-rank-col"><?php echo intval( $i + 1 ); ?></td>
                            <td><?php echo esc_html( $m['display_name'] ); ?></td>
                            <td><?php echo esc_html( $m['user_email'] ); ?></td>
                            <td><span class="wcmp-balance-badge"><?php echo intval( $m['balance'] ); ?> 點</span></td>
                            <td><a href="<?php echo esc_url( $detail_url ); ?>" class="button button-small">查看詳細紀錄</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description">最多顯示 500 位會員（依註冊時間排序後再依點數排序）；若會員數較多，請改用上方搜尋框直接找到特定會員。</p>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    public function wcmp_handle_manual_adjust() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足' );
        check_admin_referer( 'wcmp_manual_adjust_nonce' );

        $user_id = intval( $_POST['user_id'] ?? 0 );
        $direction = sanitize_text_field( $_POST['direction'] ?? 'add' );
        $points = max( 1, intval( $_POST['points'] ?? 0 ) );
        $reason = sanitize_text_field( $_POST['reason'] ?? '' );
        $expiry_date = sanitize_text_field( $_POST['expiry_date'] ?? '' );

        if ( ! $user_id || ! $reason ) wp_die( '缺少必要欄位' );

        if ( 'add' === $direction ) {
            $expire_days_override = null;
            if ( $expiry_date ) {
                $days = ceil( ( strtotime( $expiry_date ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS );
                $expire_days_override = max( 0, intval( $days ) );
            }
            $this->wcmp_add_ledger_entry( $user_id, 'manual_add', $points, null, $reason, $expire_days_override );
        } else {
            $balance = $this->wcmp_get_balance( $user_id );
            $deduct = min( $balance, $points );
            if ( $deduct > 0 ) {
                $this->wcmp_add_ledger_entry( $user_id, 'manual_deduct', -$deduct, null, $reason );
            }
        }

        wp_safe_redirect( add_query_arg( array( 'page' => 'wcmp-points', 'tab' => 'members', 'uid' => $user_id, 'adjusted' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ---- 總覽分頁 ---- */

    private function wcmp_render_overview_tab() {
        global $wpdb;
        $table = $this->wcmp_table();
        $settings = $this->wcmp_get_settings();

        $filter_type = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : '';
        $filter_user = isset( $_GET['user_id'] ) ? intval( $_GET['user_id'] ) : 0;

        $circulating = intval( $wpdb->get_var( "SELECT SUM(remaining) FROM {$table} WHERE amount > 0" ) );
        $issued = intval( $wpdb->get_var( "SELECT SUM(amount) FROM {$table} WHERE type IN ('earn','manual_add')" ) );
        $used = abs( intval( $wpdb->get_var( "SELECT SUM(amount) FROM {$table} WHERE type = 'redeem'" ) ) );
        $expired = abs( intval( $wpdb->get_var( "SELECT SUM(amount) FROM {$table} WHERE type = 'expire'" ) ) );

        $where = array( '1=1' );
        $params = array();
        if ( $filter_type ) { $where[] = 'type = %s'; $params[] = $filter_type; }
        if ( $filter_user ) { $where[] = 'user_id = %d'; $params[] = $filter_user; }
        $where_sql = implode( ' AND ', $where );

        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT 100";
        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

        $members = $this->wcmp_get_all_members( 500 );
        $selected_member = $filter_user ? get_userdata( $filter_user ) : null;
        ?>
        <div class="wcmp-status-banner">目前狀態：<strong><?php echo esc_html( $this->wcmp_get_status_text() ); ?></strong>　｜　點數名稱：<strong><?php echo esc_html( $settings['front_label'] ); ?></strong></div>
        <ul>
            <li>目前流通點數（尚未使用且未到期）：<strong><?php echo $circulating; ?></strong></li>
            <li>累計已發放（消費回饋 + 手動增加）：<strong><?php echo $issued; ?></strong></li>
            <li>累計已使用（結帳折抵）：<strong><?php echo $used; ?></strong></li>
            <li>累計已到期：<strong><?php echo $expired; ?></strong></li>
        </ul>

        <form method="get" class="wcmp-filter-bar">
            <input type="hidden" name="page" value="wcmp-points" />
            <input type="hidden" name="tab" value="overview" />
            <select name="type" class="wcmp-auto-submit">
                <option value="">全部類型</option>
                <?php foreach ( array( 'earn', 'redeem', 'manual_add', 'manual_deduct', 'expire', 'refund_redeem', 'cancel_earn' ) as $t ) : ?>
                    <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $filter_type, $t ); ?>><?php echo esc_html( $this->wcmp_type_label( $t ) ); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="user_id" class="wcmp-auto-submit">
                <option value="">全部會員（下拉後可直接輸入姓名／Email 快速跳轉）</option>
                <?php foreach ( $members as $m ) : ?>
                    <option value="<?php echo intval( $m['ID'] ); ?>" <?php selected( $filter_user, $m['ID'] ); ?>>
                        <?php echo esc_html( $m['display_name'] . '（' . $m['user_email'] . '）－ ' . intval( $m['balance'] ) . ' 點' ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="button">篩選</button>
            <a class="button wcmp-export-link" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'wcmp_export_ledger', 'type' => $filter_type, 'user_id' => $filter_user ), admin_url( 'admin-post.php' ) ), 'wcmp_export_ledger_nonce' ) ); ?>">匯出目前篩選結果 CSV</a>
        </form>
        <p class="description">選單改為「選擇後立即套用篩選」，不需要再另外點擊篩選按鈕；下拉選單也可以直接輸入關鍵字快速定位到會員，不必再另外查會員 ID。</p>

        <?php if ( $selected_member ) :
            $sel_balance = $this->wcmp_get_balance( $selected_member->ID );
            $manual_link = add_query_arg( array( 'page' => 'wcmp-points', 'tab' => 'members', 'uid' => $selected_member->ID ), admin_url( 'admin.php' ) );
            ?>
            <div class="wcmp-selected-callout">
                目前篩選會員：<strong><?php echo esc_html( $selected_member->display_name ); ?></strong>（<?php echo esc_html( $selected_member->user_email ); ?>），目前餘額 <strong><?php echo intval( $sel_balance ); ?></strong> 點。
                若要為此會員<strong>手動增加或扣除點數</strong>，請前往
                <a href="<?php echo esc_url( $manual_link ); ?>">「會員點數」分頁該會員的詳細頁面</a>操作（總覽分頁僅供查詢與匯出，不提供加扣點數）。
            </div>
        <?php endif; ?>

        <table class="widefat striped">
            <thead><tr><th>日期</th><th>會員</th><th>類型</th><th>異動點數</th><th>訂單</th><th>說明</th></tr></thead>
            <tbody>
            <?php foreach ( $rows as $r ) :
                $u = get_userdata( $r->user_id );
                ?>
                <tr>
                    <td><?php echo esc_html( $r->created_at ); ?></td>
                    <td><?php echo $u ? esc_html( $u->user_email ) : ( '#' . intval( $r->user_id ) ); ?></td>
                    <td><?php echo esc_html( $this->wcmp_type_label( $r->type ) ); ?></td>
                    <td><?php echo ( $r->amount > 0 ? '+' : '' ) . intval( $r->amount ); ?></td>
                    <td><?php echo $r->order_id ? '#' . intval( $r->order_id ) : '-'; ?></td>
                    <td><?php echo esc_html( $r->reason ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /* ---- CSV 匯出 ---- */

    public function wcmp_handle_export_members() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足' );
        check_admin_referer( 'wcmp_export_members_nonce' );

        $members = $this->wcmp_get_all_members( 5000 );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename=members-points-' . date( 'Ymd-His' ) . '.csv' );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, array( '會員ID', '姓名', 'Email', '目前餘額' ) );
        foreach ( $members as $m ) {
            fputcsv( $out, array( $m['ID'], $m['display_name'], $m['user_email'], $m['balance'] ) );
        }
        fclose( $out );
        exit;
    }

    public function wcmp_handle_export_ledger() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( '權限不足' );
        check_admin_referer( 'wcmp_export_ledger_nonce' );

        global $wpdb;
        $filter_type = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : '';
        $filter_user = isset( $_GET['user_id'] ) ? intval( $_GET['user_id'] ) : 0;

        $where = array( '1=1' );
        $params = array();
        if ( $filter_type ) { $where[] = 'type = %s'; $params[] = $filter_type; }
        if ( $filter_user ) { $where[] = 'user_id = %d'; $params[] = $filter_user; }
        $where_sql = implode( ' AND ', $where );

        $sql = "SELECT * FROM {$this->wcmp_table()} WHERE {$where_sql} ORDER BY created_at DESC LIMIT 5000";
        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename=points-ledger-' . date( 'Ymd-His' ) . '.csv' );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, array( '日期', '會員ID', '會員Email', '類型', '異動點數', '訂單編號', '說明' ) );
        foreach ( $rows as $r ) {
            $u = get_userdata( $r->user_id );
            fputcsv( $out, array(
                $r->created_at,
                $r->user_id,
                $u ? $u->user_email : '',
                $this->wcmp_type_label( $r->type ),
                $r->amount,
                $r->order_id ? $r->order_id : '',
                $r->reason,
            ) );
        }
        fclose( $out );
        exit;
    }
}

WC_Member_Points_Rewards::instance();

endif;
