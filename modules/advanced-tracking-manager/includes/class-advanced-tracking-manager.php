<?php
/**
 * Advanced Tracking Manager — Core Class
 * Version: 2.4.0
 * Author:  wumetax
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WUTM_Advanced_Tracking_Manager {

	private static $instance = null;

	const MENU_SLUG = 'wu-advanced-tracking-manager';

	/* ============================================================
	 * Singleton
	 * ============================================================ */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ============================================================
	 * Activation / Deactivation
	 * ============================================================ */
	public static function activate() {
		self::maybe_initialize_defaults();
	}

	public static function deactivate() {
		update_option( 'atm_plugin_state', 'deactivated' );
	}

	private static function maybe_initialize_defaults() {
		if ( get_option( 'atm_initialized', '' ) === 'yes' ) {
			return;
		}

		$defaults = array(
			'atm_ga4_id'             => '',
			'atm_ga4_enabled'        => 0,
			'atm_google_ads_id'      => '',
			'atm_google_ads_enabled' => 0,
			'atm_gtm_id'             => '',
			'atm_gtm_enabled'        => 0,
			'atm_fb_pixel_id'        => '',
			'atm_fb_pixel_enabled'   => 0,
			'atm_fb_capi_token'      => '',
			'atm_fb_capi_enabled'    => 0,
			'atm_checkout_code'      => '',
			'atm_checkout_enabled'   => 0,
			'atm_exclude_admin'      => 1,
			'atm_debug_mode'         => 0,
			'atm_last_diagnostic'    => array(),
			'atm_last_saved_at'      => '',
			'atm_last_saved_by'      => '',
			'atm_last_saved_ip'      => '',
		);

		foreach ( $defaults as $key => $value ) {
			if ( get_option( $key, null ) === null ) {
				add_option( $key, $value );
			}
		}

		update_option( 'atm_initialized',  'yes' );
		update_option( 'atm_plugin_state', 'active' );
	}

	/* ============================================================
	 * Constructor — hook registration
	 * ============================================================ */
	private function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init',            array( $this, 'register_settings' ) );
		add_action( 'admin_init',            array( $this, 'handle_form_submit' ) );
		add_action( 'admin_init',            array( $this, 'maybe_initialize_runtime_defaults' ) );
		add_action( 'admin_notices',         array( $this, 'maybe_show_health_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		add_action( 'wp_head',       array( $this, 'insert_header_codes' ), 1 );
		add_action( 'wp_body_open',  array( $this, 'insert_body_codes'   ), 1 );
		add_action( 'wp_footer',     array( $this, 'output_debug_comments' ), 999 );

		if ( class_exists( 'WooCommerce' ) ) {
			add_action( 'woocommerce_thankyou', array( $this, 'insert_checkout_tracking' ), 10, 1 );
		}

		add_action( 'wp_ajax_atm_run_diagnostics',   array( $this, 'ajax_run_diagnostics' ) );
		add_action( 'wp_ajax_atm_clear_diagnostics', array( $this, 'ajax_clear_diagnostics' ) );
	}

	public function maybe_initialize_runtime_defaults() {
		self::maybe_initialize_defaults();
	}

	/* ============================================================
	 * Admin menu
	 * ============================================================ */
	public function register_admin_menu() {
		add_submenu_page(
            'wu-toolbox-modular',
            '進階追蹤程式碼管理器',
            '進階追蹤管理',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_admin_page' ),
			3
		);
	}

	/* ============================================================
	 * Settings registration
	 * ============================================================ */
	public function register_settings() {
		$settings = array(
			'atm_ga4_id'             => array( 'sanitize_callback' => array( $this, 'sanitize_ga4_id' ),         'default' => '' ),
			'atm_ga4_enabled'        => array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ),       'default' => 0 ),
			'atm_google_ads_id'      => array( 'sanitize_callback' => array( $this, 'sanitize_google_ads_id' ),  'default' => '' ),
			'atm_google_ads_enabled' => array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ),       'default' => 0 ),
			'atm_gtm_id'             => array( 'sanitize_callback' => array( $this, 'sanitize_gtm_id' ),         'default' => '' ),
			'atm_gtm_enabled'        => array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ),       'default' => 0 ),
			'atm_fb_pixel_id'        => array( 'sanitize_callback' => array( $this, 'sanitize_fb_pixel_id' ),    'default' => '' ),
			'atm_fb_pixel_enabled'   => array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ),       'default' => 0 ),
			'atm_fb_capi_token'      => array( 'sanitize_callback' => 'sanitize_text_field',                    'default' => '' ),
			'atm_fb_capi_enabled'    => array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ),       'default' => 0 ),
			'atm_checkout_code'      => array( 'sanitize_callback' => array( $this, 'sanitize_checkout_code' ),  'default' => '' ),
			'atm_checkout_enabled'   => array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ),       'default' => 0 ),
			'atm_exclude_admin'      => array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ),       'default' => 1 ),
			'atm_debug_mode'         => array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ),       'default' => 0 ),
		);

		foreach ( $settings as $option_name => $args ) {
			register_setting( 'atm_settings', $option_name, $args );
		}
	}

	/* ============================================================
	 * Sanitizers
	 * ============================================================ */
	public function sanitize_checkbox( $v )         { return ! empty( $v ) ? 1 : 0; }
	public function sanitize_checkout_code( $v )    { return wp_kses_post( $v ); }
	public function sanitize_ga4_id( $v )           { return preg_replace( '/[^A-Z0-9\-]/', '', strtoupper( trim( $v ) ) ); }
	public function sanitize_google_ads_id( $v )    { return preg_replace( '/[^A-Z0-9\-\/]/', '', strtoupper( trim( $v ) ) ); }
	public function sanitize_gtm_id( $v )           { return preg_replace( '/[^A-Z0-9\-]/', '', strtoupper( trim( $v ) ) ); }
	public function sanitize_fb_pixel_id( $v )      { return preg_replace( '/[^0-9]/', '', trim( $v ) ); }

	/* ============================================================
	 * Form submission handler
	 * ============================================================ */
	public function handle_form_submit() {
		if ( ! isset( $_POST['atm_submit'], $_POST['atm_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['atm_nonce'] ) ), 'atm_save_settings' ) ) {
			return;
		}

		$text_fields = array(
			'atm_ga4_id'        => 'sanitize_ga4_id',
			'atm_google_ads_id' => 'sanitize_google_ads_id',
			'atm_gtm_id'        => 'sanitize_gtm_id',
			'atm_fb_pixel_id'   => 'sanitize_fb_pixel_id',
			'atm_fb_capi_token' => 'sanitize_text_field',
		);

		foreach ( $text_fields as $field => $callback ) {
			$raw = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
			$value = method_exists( $this, $callback )
				? $this->$callback( $raw )
				: call_user_func( $callback, $raw );
			update_option( $field, $value );
		}

		$checkboxes = array(
			'atm_ga4_enabled', 'atm_google_ads_enabled', 'atm_gtm_enabled',
			'atm_fb_pixel_enabled', 'atm_fb_capi_enabled',
			'atm_checkout_enabled', 'atm_exclude_admin', 'atm_debug_mode',
		);
		foreach ( $checkboxes as $field ) {
			update_option( $field, isset( $_POST[ $field ] ) ? 1 : 0 );
		}

		$checkout_code = isset( $_POST['atm_checkout_code'] )
			? wp_kses_post( wp_unslash( $_POST['atm_checkout_code'] ) )
			: '';
		update_option( 'atm_checkout_code', $checkout_code );

		update_option( 'atm_last_saved_at', current_time( 'mysql' ) );
		update_option( 'atm_last_saved_by', get_current_user_id() );
		update_option( 'atm_last_saved_ip',
			! empty( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		update_option( 'atm_initialized',  'yes' );
		update_option( 'atm_plugin_state', 'active' );

		set_transient( 'atm_settings_saved', true, 10 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/* ============================================================
	 * Admin notices
	 * ============================================================ */
	public function maybe_show_health_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $screen->id ) && strpos( $screen->id, self::MENU_SLUG ) === false ) {
			return;
		}
		if ( get_transient( 'atm_settings_saved' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>設定已成功儲存。</strong></p></div>';
			delete_transient( 'atm_settings_saved' );
		}

		$warnings     = array();
		$ga4_enabled        = (int) get_option( 'atm_ga4_enabled',        0 );
		$google_ads_enabled = (int) get_option( 'atm_google_ads_enabled', 0 );
		$gtm_enabled        = (int) get_option( 'atm_gtm_enabled',        0 );
		$fb_pixel_enabled   = (int) get_option( 'atm_fb_pixel_enabled',   0 );
		$fb_capi_enabled    = (int) get_option( 'atm_fb_capi_enabled',    0 );
		$checkout_enabled   = (int) get_option( 'atm_checkout_enabled',   0 );

		if ( $ga4_enabled        && ! get_option( 'atm_ga4_id',        '' ) ) $warnings[] = 'GA4 已啟用，但尚未填入 GA4 ID。';
		if ( $google_ads_enabled && ! get_option( 'atm_google_ads_id', '' ) ) $warnings[] = 'Google Ads 已啟用，但尚未填入 Ads ID。';
		if ( $gtm_enabled        && ! get_option( 'atm_gtm_id',        '' ) ) $warnings[] = 'GTM 已啟用，但尚未填入 Container ID。';
		if ( $fb_pixel_enabled   && ! get_option( 'atm_fb_pixel_id',   '' ) ) $warnings[] = 'Meta Pixel 已啟用，但尚未填入 Pixel ID。';
		if ( $fb_capi_enabled && ( ! get_option( 'atm_fb_pixel_id', '' ) || ! get_option( 'atm_fb_capi_token', '' ) ) )
			$warnings[] = 'Facebook CAPI 已啟用，但 Pixel ID 或 Access Token 不完整。';
		if ( $checkout_enabled   && ! get_option( 'atm_checkout_code',  '' ) ) $warnings[] = '結帳追蹤已啟用，但尚未填入程式碼。';
		if ( $gtm_enabled && ( $ga4_enabled || $google_ads_enabled ) )
			$warnings[] = 'GTM 與 GA4 / Google Ads 同時啟用，請確認是否造成重複追蹤。';
		if ( $gtm_enabled && ! function_exists( 'wp_body_open' ) )
			$warnings[] = '目前主題可能沒有 wp_body_open()，GTM noscript 區塊可能無法輸出。';

		if ( ! empty( $warnings ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p><strong>追蹤設定提醒：</strong> '
				. esc_html( implode( ' ', $warnings ) ) . '</p></div>';
		}
	}

	/* ============================================================
	 * Enqueue admin assets
	 * ============================================================ */
	public function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
			return;
		}
		wp_enqueue_script(
			'atm-admin',
			plugin_dir_url( WUTM_ATM_FILE ) . 'assets/admin.js',
			array( 'jquery' ),
			WUTM_ATM_VERSION,
			true
		);
		wp_localize_script( 'atm-admin', 'atmData', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'atm_diagnostics' ),
		) );
		wp_enqueue_style(
			'atm-admin',
			plugin_dir_url( WUTM_ATM_FILE ) . 'assets/admin.css',
			array(),
			WUTM_ATM_VERSION
		);
	}

	/* ============================================================
	 * Should tracking fire?
	 * ============================================================ */
	private function should_output() {
		if ( is_admin() ) {
			return false;
		}
		if ( (int) get_option( 'atm_exclude_admin', 1 ) && current_user_can( 'manage_options' ) ) {
			return false;
		}
		return true;
	}

	/* ============================================================
	 * Frontend output — wp_head
	 * ============================================================ */
	public function insert_header_codes() {
		if ( ! $this->should_output() ) {
			return;
		}

		$ga4_enabled        = (int) get_option( 'atm_ga4_enabled',        0 );
		$google_ads_enabled = (int) get_option( 'atm_google_ads_enabled', 0 );
		$gtm_enabled        = (int) get_option( 'atm_gtm_enabled',        0 );
		$fb_pixel_enabled   = (int) get_option( 'atm_fb_pixel_enabled',   0 );

		$ga4_id        = get_option( 'atm_ga4_id',        '' );
		$google_ads_id = get_option( 'atm_google_ads_id', '' );
		$gtm_id        = get_option( 'atm_gtm_id',        '' );
		$fb_pixel_id   = get_option( 'atm_fb_pixel_id',   '' );

		// GA4
		if ( $ga4_enabled && ! empty( $ga4_id ) ) {
			$ga4_id_esc = esc_attr( $ga4_id );
			$ga4_id_js  = esc_js( $ga4_id );
			$ads_snippet = '';
			if ( $google_ads_enabled && ! empty( $google_ads_id ) ) {
				$ads_snippet = "gtag('config', '" . esc_js( $google_ads_id ) . "');\n";
			}
			echo "\n<!-- Advanced Tracking Manager: GA4 -->\n";
			echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $ga4_id_esc . '"></script>' . "\n";
			echo "<script>\n";
			echo "window.dataLayer = window.dataLayer || [];\n";
			echo "function gtag(){dataLayer.push(arguments);}\n";
			echo "gtag('js', new Date());\n";
			echo "gtag('config', '" . $ga4_id_js . "');\n";
			echo $ads_snippet;
			echo "</script>\n";
		}

		// GTM head
		if ( $gtm_enabled && ! empty( $gtm_id ) ) {
			$gtm_id_esc = esc_attr( $gtm_id );
			$gtm_id_js  = esc_js( $gtm_id );
			echo "\n<!-- Advanced Tracking Manager: GTM -->\n";
			echo "<script>\n";
			echo "(function(w,d,s,l,i){\n";
			echo "  w[l]=w[l]||[];\n";
			echo "  w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});\n";
			echo "  var f=d.getElementsByTagName(s)[0],\n";
			echo "      j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';\n";
			echo "  j.async=true;\n";
			echo "  j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;\n";
			echo "  f.parentNode.insertBefore(j,f);\n";
			echo "})(window,document,'script','dataLayer','" . $gtm_id_js . "');\n";
			echo "</script>\n";
		}

		// Meta Pixel
		if ( $fb_pixel_enabled && ! empty( $fb_pixel_id ) ) {
			$pixel_esc = esc_attr( $fb_pixel_id );
			$pixel_js  = esc_js( $fb_pixel_id );
			echo "\n<!-- Advanced Tracking Manager: Meta Pixel -->\n";
			echo "<script>\n";
			echo "!function(f,b,e,v,n,t,s){\n";
			echo "if(f.fbq)return;n=f.fbq=function(){n.callMethod?\n";
			echo "n.callMethod.apply(n,arguments):n.queue.push(arguments)};\n";
			echo "if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';\n";
			echo "n.queue=[];t=b.createElement(e);t.async=!0;\n";
			echo "t.src=v;s=b.getElementsByTagName(e)[0];\n";
			echo "s.parentNode.insertBefore(t,s)}(window,document,'script',\n";
			echo "'https://connect.facebook.net/en_US/fbevents.js');\n";
			echo "fbq('init','" . $pixel_js . "');\n";
			echo "fbq('track','PageView');\n";
			echo "</script>\n";
			echo '<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=' . $pixel_esc . '&ev=PageView&noscript=1"/></noscript>' . "\n";
		}
	}

	/* ============================================================
	 * Frontend output — wp_body_open (GTM noscript)
	 * ============================================================ */
	public function insert_body_codes() {
		if ( ! $this->should_output() ) {
			return;
		}
		$gtm_enabled = (int) get_option( 'atm_gtm_enabled', 0 );
		$gtm_id      = get_option( 'atm_gtm_id', '' );
		if ( $gtm_enabled && ! empty( $gtm_id ) ) {
			echo "\n<!-- Advanced Tracking Manager: GTM noscript -->\n";
			echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr( $gtm_id ) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
		}
	}

	/* ============================================================
	 * WooCommerce thank-you page tracking
	 * ============================================================ */
	public function insert_checkout_tracking( $order_id ) {
		if ( ! $this->should_output() ) {
			return;
		}
		$checkout_enabled = (int) get_option( 'atm_checkout_enabled', 0 );
		$checkout_code    = get_option( 'atm_checkout_code', '' );
		if ( ! $checkout_enabled || empty( $checkout_code ) ) {
			return;
		}
		// Replace {{order_id}} placeholder
		$code = str_replace( '{{order_id}}', absint( $order_id ), $checkout_code );
		echo "\n<!-- Advanced Tracking Manager: Checkout -->\n";
		echo $code . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/* ============================================================
	 * Debug comments in footer
	 * ============================================================ */
	public function output_debug_comments() {
		if ( ! (int) get_option( 'atm_debug_mode', 0 ) ) {
			return;
		}
		if ( ! $this->should_output() ) {
			echo "\n<!-- [ATM Debug] 追蹤碼已被排除（管理員或後台）-->\n";
			return;
		}
		$lines = array( '<!-- [ATM Debug] v' . WUTM_ATM_VERSION );
		$map = array(
			'GA4'        => array( 'atm_ga4_enabled', 'atm_ga4_id' ),
			'Google Ads' => array( 'atm_google_ads_enabled', 'atm_google_ads_id' ),
			'GTM'        => array( 'atm_gtm_enabled', 'atm_gtm_id' ),
			'Meta Pixel' => array( 'atm_fb_pixel_enabled', 'atm_fb_pixel_id' ),
		);
		foreach ( $map as $label => $keys ) {
			$enabled = (int) get_option( $keys[0], 0 );
			$id      = get_option( $keys[1], '' );
			$lines[] = "  $label: " . ( $enabled ? 'ON  ID=' . $id : 'OFF' );
		}
		$lines[] = '-->';
		echo "\n" . implode( "\n", $lines ) . "\n";
	}

	/* ============================================================
	 * Diagnostics
	 * ============================================================ */
	private function diagnostic_item( $status, $title, $message, $details = '' ) {
		return array(
			'status'  => $status,
			'title'   => $title,
			'message' => $message,
			'details' => $details,
		);
	}

	private function run_diagnostics() {
		$settings = array(
			'ga4_id'             => $this->sanitize_ga4_id(        get_option( 'atm_ga4_id',        '' ) ),
			'ga4_enabled'        => (int) get_option( 'atm_ga4_enabled',        0 ),
			'google_ads_id'      => $this->sanitize_google_ads_id( get_option( 'atm_google_ads_id', '' ) ),
			'google_ads_enabled' => (int) get_option( 'atm_google_ads_enabled', 0 ),
			'gtm_id'             => $this->sanitize_gtm_id(        get_option( 'atm_gtm_id',        '' ) ),
			'gtm_enabled'        => (int) get_option( 'atm_gtm_enabled',        0 ),
			'fb_pixel_id'        => $this->sanitize_fb_pixel_id(   get_option( 'atm_fb_pixel_id',   '' ) ),
			'fb_pixel_enabled'   => (int) get_option( 'atm_fb_pixel_enabled',   0 ),
			'exclude_admin'      => (int) get_option( 'atm_exclude_admin',      1 ),
		);

		$items    = array();
		$errors   = 0;
		$warnings = 0;

		// Fetch frontend HTML
		$frontend_url = home_url( '/' );
		$response     = wp_safe_remote_get( $frontend_url, array(
			'timeout'     => 15,
			'redirection' => 5,
			'sslverify'   => true,
			'headers'     => array(
				'Accept'     => 'text/html,application/xhtml+xml',
				'User-Agent' => 'Advanced Tracking Manager/' . WUTM_ATM_VERSION,
			),
		) );

		$html         = '';
		$status_code  = 0;
		$response_url = $frontend_url;
		$headers      = array();

		if ( is_wp_error( $response ) ) {
			$errors++;
			$items[] = $this->diagnostic_item( 'error', '無法讀取前台網站', $response->get_error_message(),
				'可能原因：防火牆、SSL、DNS、主機拒絕本機請求，或網站正在維護。' );
		} else {
			$status_code = (int) wp_remote_retrieve_response_code( $response );
			$html        = (string) wp_remote_retrieve_body( $response );
			$headers     = wp_remote_retrieve_headers( $response );

			if ( $status_code >= 200 && $status_code < 300 ) {
				$items[] = $this->diagnostic_item( 'success', '前台網站可正常讀取', 'HTTP ' . $status_code, '前台網址：' . $frontend_url );
			} elseif ( $status_code >= 300 && $status_code < 400 ) {
				$warnings++;
				$items[] = $this->diagnostic_item( 'warning', '前台發生重新導向', 'HTTP ' . $status_code,
					'請確認 Tag Assistant 連線時使用正確的正式網址。' );
			} else {
				$errors++;
				$items[] = $this->diagnostic_item( 'error', '前台回應異常', 'HTTP ' . $status_code,
					'請確認網站是否被主機、防火牆或維護模式阻擋。' );
			}

			if ( empty( $html ) ) {
				$errors++;
				$items[] = $this->diagnostic_item( 'error', '前台 HTML 是空的', '沒有取得有效的 HTML。',
					'這通常和主機阻擋 loopback request、快取異常或 PHP 錯誤有關。' );
			} else {
				$items[] = $this->diagnostic_item( 'success', '成功取得前台 HTML',
					'HTML 長度：' . number_format( strlen( $html ) ) . ' bytes', '已可進一步檢查追蹤碼。' );
			}
		}

		// Admin exclusion check
		if ( $settings['exclude_admin'] && current_user_can( 'manage_options' ) ) {
			$warnings++;
			$items[] = $this->diagnostic_item( 'warning', '目前測試帳號被排除',
				'你是管理員且啟用了「排除管理員流量」。',
				'請取消排除管理員，或改用無痕視窗 / 登出後測試。' );
		}

		// wp_body_open check
		if ( ! function_exists( 'wp_body_open' ) ) {
			$warnings++;
			$items[] = $this->diagnostic_item( 'warning', 'wp_body_open() 不存在',
				'GTM noscript 區塊可能無法輸出。',
				'請在主題 <body> 後加入 <?php wp_body_open(); ?>。' );
		} else {
			$items[] = $this->diagnostic_item( 'success', 'wp_body_open() 可用', 'WordPress 支援 wp_body_open()。', '' );
		}

		// Cache header check
		if ( ! empty( $headers ) ) {
			$cache_info = '';
			if ( isset( $headers['cache-control'] ) )   $cache_info = 'Cache-Control: '   . (string) $headers['cache-control'];
			elseif ( isset( $headers['cf-cache-status'] ) ) $cache_info = 'CF-Cache-Status: ' . (string) $headers['cf-cache-status'];
			elseif ( isset( $headers['x-cache'] ) )     $cache_info = 'X-Cache: '          . (string) $headers['x-cache'];

			if ( $cache_info ) {
				$warnings++;
				$items[] = $this->diagnostic_item( 'warning', '偵測到快取標頭', $cache_info,
					'修改追蹤設定後請清除 WordPress、主機與 CDN 快取再測試。' );
			}
		}

		// GTM duplicate check
		if ( $settings['gtm_enabled'] && ! empty( $settings['gtm_id'] ) && ! empty( $html ) ) {
			$count = substr_count( $html, $settings['gtm_id'] );
			if ( $count > 2 ) {
				$warnings++;
				$items[] = $this->diagnostic_item( 'warning', '可能有重複 GTM 安裝',
					'GTM ID 在 HTML 中出現 ' . $count . ' 次。',
					'請到瀏覽器 Network 搜尋 gtm.js，確認實際請求數量。' );
			}
		}

		// Tracking code presence checks
		if ( ! empty( $html ) ) {
			$checks = array(
				array(
					'enabled' => $settings['gtm_enabled'],
					'id'      => $settings['gtm_id'],
					'label'   => 'GTM Container ID',
					'needle2' => 'googletagmanager.com/gtm.js',
					'needle2_label' => 'GTM gtm.js 載入網址',
				),
				array(
					'enabled' => $settings['ga4_enabled'],
					'id'      => $settings['ga4_id'],
					'label'   => 'GA4 Measurement ID',
					'needle2' => 'googletagmanager.com/gtag/js',
					'needle2_label' => 'gtag.js 載入網址',
				),
				array(
					'enabled' => $settings['google_ads_enabled'],
					'id'      => $settings['google_ads_id'],
					'label'   => 'Google Ads ID',
					'needle2' => '',
					'needle2_label' => '',
				),
				array(
					'enabled' => $settings['fb_pixel_enabled'],
					'id'      => $settings['fb_pixel_id'],
					'label'   => 'Meta Pixel ID',
					'needle2' => '',
					'needle2_label' => '',
				),
			);

			foreach ( $checks as $check ) {
				if ( ! $check['enabled'] ) {
					$items[] = $this->diagnostic_item( 'info', $check['label'] . ' 未啟用', '略過此項目。', '' );
					continue;
				}
				if ( empty( $check['id'] ) ) {
					$errors++;
					$items[] = $this->diagnostic_item( 'error', $check['label'] . ' 已啟用但沒有 ID', '請填入正確格式的 ID。', '' );
					continue;
				}
				if ( strpos( $html, $check['id'] ) !== false ) {
					$items[] = $this->diagnostic_item( 'success', '找到 ' . $check['label'], '前台 HTML 已包含：' . $check['id'], '' );
				} else {
					$errors++;
					$items[] = $this->diagnostic_item( 'error', '找不到 ' . $check['label'],
						'前台 HTML 沒有找到：' . $check['id'],
						'請檢查排除管理員、快取、wp_head()、是否有安全外掛或 CSP 阻擋。' );
				}
				if ( ! empty( $check['needle2'] ) ) {
					if ( strpos( $html, $check['needle2'] ) !== false ) {
						$items[] = $this->diagnostic_item( 'success', '找到 ' . $check['needle2_label'],
							'前台 HTML 已包含 ' . $check['needle2'] . '。', '' );
					} else {
						$errors++;
						$items[] = $this->diagnostic_item( 'error', '找不到 ' . $check['needle2_label'],
							'前台沒有找到 ' . $check['needle2'] . '。', '請確認 wp_head() 是否存在。' );
					}
				}
			}
		}

		// GTM + GA4 conflict
		if ( $settings['gtm_enabled'] && ( $settings['ga4_enabled'] || $settings['google_ads_enabled'] ) ) {
			$warnings++;
			$items[] = $this->diagnostic_item( 'warning', '可能存在 Google 重複安裝',
				'GTM 與 GA4 / Google Ads 同時啟用。',
				'如果 GA4 已在 GTM 容器內設定，請關閉這裡的直接安裝。' );
		}

		$overall = $errors > 0 ? 'error' : ( $warnings > 0 ? 'warning' : 'success' );

		$result = array(
			'version'      => WUTM_ATM_VERSION,
			'checked_at'   => current_time( 'mysql' ),
			'frontend_url' => $frontend_url,
			'status_code'  => $status_code,
			'status'       => $overall,
			'errors'       => $errors,
			'warnings'     => $warnings,
			'items'        => $items,
		);

		update_option( 'atm_last_diagnostic', $result );
		return $result;
	}

	public function ajax_run_diagnostics() {
		check_ajax_referer( 'atm_diagnostics', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '權限不足。' ), 403 );
		}
		wp_send_json_success( $this->run_diagnostics() );
	}

	public function ajax_clear_diagnostics() {
		check_ajax_referer( 'atm_diagnostics', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '權限不足。' ), 403 );
		}
		delete_option( 'atm_last_diagnostic' );
		wp_send_json_success( array( 'message' => '已清除。' ) );
	}

	/* ============================================================
	 * Admin page render
	 * ============================================================ */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '您沒有足夠的權限訪問此功能。' );
		}
		require_once WUTM_ATM_DIR . 'templates/admin-page.php';
	}
}
