<?php
/**
 * Plugin Name: Wumetax Quick Support - AI Extension
 * Description: Wumetax Quick Support 的 AI 擴充。支援 Google Gemini / Perplexity / OpenAI、知識庫上下文、連線驗證、使用紀錄、額度與頻率限制。
 * Version: 1.2.0
 * Author: Wumetax
 * Author URI: https://wumetax.com/
 * Text Domain: wumetax-quick-support-ai
 * Requires Plugins: wumetax-quick-support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wumetax_Quick_Support_AI_v120' ) ) {

	class Wumetax_Quick_Support_AI_v120 {

		const OPTION_KEY      = 'wumetax_qsa_settings_v100'; // 沿用舊 key，升級不掉設定。
		const USAGE_KEY       = 'wumetax_qsa_usage_v100';
		const DB_VERSION_KEY  = 'wumetax_qsa_db_version';
		const DB_VERSION      = '1.2.0';
		const NONCE_ACTION    = 'wumetax_qsa_nonce_v120';
		const TEST_NONCE      = 'wumetax_qsa_test_v120';
		const ADMIN_SLUG      = 'wumetax-quick-support-ai';
		const LOG_PAGE_SIZE   = 30;

		public static function init() {
			add_filter( 'wumetax_qs_ai_available', [ __CLASS__, 'ai_available' ] );
			add_action( 'wumetax_qs_ai_panel', [ __CLASS__, 'render_panel' ] );
			add_action( 'wp_footer', [ __CLASS__, 'render_assets' ], 10000 );

			add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );

			add_action( 'wp_ajax_wumetax_qsa_ask', [ __CLASS__, 'ajax_ask' ] );
			add_action( 'wp_ajax_nopriv_wumetax_qsa_ask', [ __CLASS__, 'ajax_ask' ] );
			add_action( 'wp_ajax_wumetax_qsa_test_connection', [ __CLASS__, 'ajax_test_connection' ] );

			add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
			add_action( 'admin_init', [ __CLASS__, 'maybe_install_table' ] );
			add_action( 'admin_post_wumetax_qsa_save_v120', [ __CLASS__, 'admin_save' ] );
			add_action( 'admin_post_wumetax_qsa_clear_logs_v120', [ __CLASS__, 'admin_clear_logs' ] );
			add_action( 'admin_notices', [ __CLASS__, 'dependency_notice' ] );
		}

		public static function defaults() {
			return [
				'enabled'            => 0,
				'provider'           => 'gemini',
				'gemini_key'         => '',
				'gemini_model'       => 'gemini-3.8-flash',
				'perplexity_key'     => '',
				'perplexity_model'   => 'sonar',
				'perplexity_web'     => 0,
				'openai_key'         => '',
				'openai_model'       => 'gpt-5.6-luna',
				'use_context'        => 1,
				'context_limit'      => 4,
				'session_limit'      => 5,
				'ip_daily_limit'     => 20,
				'site_daily_limit'   => 200,
				'cooldown'           => 4,
				'max_input'          => 500,
				'max_tokens'         => 700,
				'log_questions'      => 1,
				'log_retention_days' => 30,
				'system_prompt'      => '你是 Wumetax 網站的 AI 助理。請使用繁體中文回答，語氣簡潔、清楚、專業。優先依照提供的 Wumetax 網站資料回答服務、價格、流程、WordPress、WooCommerce、網站主機、Cloudflare、SEO 與網站維護問題；若網站資料沒有提供某項 Wumetax 專屬資訊，不要自行編造價格、承諾或政策。一般網站技術問題可以依你的知識協助。回答不要冗長，必要時提醒使用者可透過 Wumetax 聯絡方式進一步確認。',
			];
		}

		public static function settings() {
			$s = get_option( self::OPTION_KEY, [] );
			return wp_parse_args( is_array( $s ) ? $s : [], self::defaults() );
		}

		public static function dependency_notice() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			if ( ! function_exists( 'wumetax_quick_support_get_context' ) ) {
				echo '<div class="notice notice-warning"><p><strong>Wumetax AI 擴充：</strong>請先安裝並啟用 Wumetax Quick Support v1.3.1 以上版本。</p></div>';
			}
		}

		/* =====================================================
		 * DATABASE / LOGS
		 * ===================================================== */

		private static function table_name() {
			global $wpdb;
			return $wpdb->prefix . 'wumetax_qsa_logs';
		}

		public static function install_table() {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$table   = self::table_name();
			$charset = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				provider VARCHAR(24) NOT NULL DEFAULT '',
				model VARCHAR(120) NOT NULL DEFAULT '',
				status VARCHAR(24) NOT NULL DEFAULT '',
				question TEXT NULL,
				answer_excerpt TEXT NULL,
				source_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				ip_hash VARCHAR(32) NOT NULL DEFAULT '',
				session_hash VARCHAR(32) NOT NULL DEFAULT '',
				response_ms INT UNSIGNED NOT NULL DEFAULT 0,
				prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
				output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
				total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
				cost DECIMAL(14,8) NULL,
				error_message TEXT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY provider (provider),
				KEY status (status)
			) {$charset};";

			dbDelta( $sql );
			update_option( self::DB_VERSION_KEY, self::DB_VERSION, false );
		}

		public static function maybe_install_table() {
			if ( get_option( self::DB_VERSION_KEY ) !== self::DB_VERSION ) {
				self::install_table();
			}
		}

		private static function trim_text( $text, $limit ) {
			$text = trim( wp_strip_all_tags( (string) $text ) );
			if ( function_exists( 'mb_substr' ) ) {
				return mb_substr( $text, 0, $limit, 'UTF-8' );
			}
			return substr( $text, 0, $limit );
		}

		private static function log_event( $args = [] ) {
			global $wpdb;
			$s = self::settings();
			$defaults = [
				'provider'       => '',
				'model'          => '',
				'status'         => '',
				'question'       => '',
				'answer'         => '',
				'source_count'   => 0,
				'session_id'     => '',
				'response_ms'    => 0,
				'prompt_tokens'  => 0,
				'output_tokens'  => 0,
				'total_tokens'   => 0,
				'cost'           => null,
				'error_message'  => '',
			];
			$a = wp_parse_args( $args, $defaults );

			$question = ! empty( $s['log_questions'] ) ? self::trim_text( $a['question'], 500 ) : '';
			$answer   = self::trim_text( $a['answer'], 300 );
			$ip_hash  = self::key_hash( self::client_ip() );
			$ses_hash = $a['session_id'] ? self::key_hash( $a['session_id'] ) : '';

			$wpdb->insert(
				self::table_name(),
				[
					'created_at'     => current_time( 'mysql' ),
					'provider'       => sanitize_key( $a['provider'] ),
					'model'          => sanitize_text_field( $a['model'] ),
					'status'         => sanitize_key( $a['status'] ),
					'question'       => $question,
					'answer_excerpt' => $answer,
					'source_count'   => absint( $a['source_count'] ),
					'ip_hash'        => $ip_hash,
					'session_hash'   => $ses_hash,
					'response_ms'    => absint( $a['response_ms'] ),
					'prompt_tokens'  => absint( $a['prompt_tokens'] ),
					'output_tokens'  => absint( $a['output_tokens'] ),
					'total_tokens'   => absint( $a['total_tokens'] ),
					'cost'           => is_numeric( $a['cost'] ) ? (float) $a['cost'] : null,
					'error_message'  => self::trim_text( $a['error_message'], 600 ),
				],
				[ '%s','%s','%s','%s','%s','%s','%d','%s','%s','%d','%d','%d','%d','%f','%s' ]
			);

			self::maybe_prune_logs();
		}

		private static function maybe_prune_logs() {
			if ( get_transient( 'wumetax_qsa_pruned_today' ) ) {
				return;
			}
			global $wpdb;
			$s    = self::settings();
			$days = max( 1, min( 365, absint( $s['log_retention_days'] ) ) );
			$cut  = wp_date( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . ' WHERE created_at < %s', $cut ) );
			set_transient( 'wumetax_qsa_pruned_today', 1, DAY_IN_SECONDS );
		}

		/* =====================================================
		 * PUBLIC REST CHANNEL
		 * ===================================================== */

		private static function public_token() {
			return substr(
				hash_hmac(
					'sha256',
					'wumetax-qsa-public|' . home_url( '/' ),
					wp_salt( 'auth' )
				),
				0,
				40
			);
		}

		public static function register_rest_routes() {
			register_rest_route(
				'wumetax-qsa/v1',
				'/ask',
				[
					'methods'             => 'POST',
					'callback'            => [ __CLASS__, 'rest_ask' ],
					'permission_callback' => '__return_true',
				]
			);
		}

		private static function rest_result( $success, $data, $status = 200 ) {
			$response = new WP_REST_Response(
				[
					'success' => (bool) $success,
					'data'    => $data,
				],
				$status
			);
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			return $response;
		}

		public static function rest_ask( WP_REST_Request $request ) {
			/*
			 * 前台 AI 改走 REST API，避免部分主機 / Cloudflare / 安全外掛
			 * 對 wp-admin/admin-ajax.php 的攔截或登入重新導向回傳 HTML。
			 */
			$token = (string) $request->get_header( 'X-Wumetax-AI-Token' );
			if ( ! $token || ! hash_equals( self::public_token(), $token ) ) {
				return self::rest_result( false, [ 'message' => 'AI 請求驗證失敗，請重新整理頁面後再試。' ], 403 );
			}

			/* 避免伺服器 PHP notice/warning 汙染 JSON。 */
			if ( function_exists( 'ini_set' ) ) {
				@ini_set( 'display_errors', '0' );
			}

			try {
				$s = self::settings();
				if ( ! self::ai_available( false ) ) {
					return self::rest_result( false, [ 'message' => 'AI 助理目前未啟用。' ], 503 );
				}

				$question = sanitize_textarea_field( (string) $request->get_param( 'question' ) );
				$session  = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $request->get_param( 'session_id' ) );
				$raw_hist = $request->get_param( 'history' );
				if ( is_string( $raw_hist ) ) {
					$raw_hist = json_decode( $raw_hist, true );
				}
				$history  = self::sanitize_history( is_array( $raw_hist ) ? $raw_hist : [] );
				$provider = $s['provider'];
				$model    = self::provider_model( $provider, $s );

				if ( '' === $session || strlen( $session ) < 12 ) {
					return self::rest_result( false, [ 'message' => '無法建立本次 AI 對話識別，請重新整理頁面後再試。' ], 400 );
				}
				if ( '' === trim( $question ) ) {
					return self::rest_result( false, [ 'message' => '請先輸入問題。' ], 400 );
				}

				$max_input = absint( $s['max_input'] );
				$length    = function_exists( 'mb_strlen' ) ? mb_strlen( $question, 'UTF-8' ) : strlen( $question );
				if ( $length > $max_input ) {
					return self::rest_result( false, [ 'message' => '問題太長，請控制在 ' . $max_input . ' 個字元內。' ], 400 );
				}

				$rate = self::rate_check( $session, $s );
				if ( is_wp_error( $rate ) ) {
					self::log_event( [
						'provider'      => $provider,
						'model'         => $model,
						'status'        => 'blocked',
						'question'      => $question,
						'session_id'    => $session,
						'error_message' => $rate->get_error_message(),
					] );
					return self::rest_result( false, [ 'message' => $rate->get_error_message() ], 429 );
				}

				$context_items = [];
				$context_text  = '';
				if ( ! empty( $s['use_context'] ) && function_exists( 'wumetax_quick_support_get_context' ) ) {
					$context_items = wumetax_quick_support_get_context( $question, absint( $s['context_limit'] ) );
					$context_text  = self::build_context_text( $context_items );
				}

				$start  = microtime( true );
				$result = self::call_provider( $provider, $question, $history, $context_text, $s );
				$ms = (int) round( ( microtime( true ) - $start ) * 1000 );

				if ( is_wp_error( $result ) ) {
					self::log_event( [
						'provider'      => $provider,
						'model'         => $model,
						'status'        => 'error',
						'question'      => $question,
						'session_id'    => $session,
						'source_count'  => count( $context_items ),
						'response_ms'   => $ms,
						'error_message' => $result->get_error_message(),
					] );
					return self::rest_result( false, [ 'message' => $result->get_error_message() ], 502 );
				}

				self::rate_commit( $rate, $s );
				$u = self::usage_today();
				$sources = [];
				foreach ( $context_items as $item ) {
					$sources[] = [ 'title' => $item['title'], 'url' => $item['url'] ];
				}
				foreach ( $result['citations'] as $url ) {
					$sources[] = [ 'title' => wp_parse_url( $url, PHP_URL_HOST ) ?: '外部來源', 'url' => $url ];
				}
				$sources = array_slice( $sources, 0, 6 );
				$usage   = $result['usage'] ?? [ 'prompt_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'cost' => null ];

				self::log_event( [
					'provider'      => $provider,
					'model'         => $model,
					'status'        => 'success',
					'question'      => $question,
					'answer'        => $result['text'],
					'session_id'    => $session,
					'source_count'  => count( $sources ),
					'response_ms'   => $ms,
					'prompt_tokens' => $usage['prompt_tokens'],
					'output_tokens' => $usage['output_tokens'],
					'total_tokens'  => $usage['total_tokens'],
					'cost'          => $usage['cost'],
				] );

				$remaining = max(
					0,
					min(
						absint( $s['session_limit'] ) - ( $rate['session_n'] + 1 ),
						absint( $s['ip_daily_limit'] ) - ( $rate['ip_n'] + 1 ),
						absint( $s['site_daily_limit'] ) - $u['total']
					)
				);

				return self::rest_result(
					true,
					[
						'answer'    => $result['text'],
						'sources'   => $sources,
						'remaining' => $remaining,
						'provider'  => $provider,
					],
					200
				);

			} catch ( Throwable $e ) {
				self::log_event( [
					'status'        => 'error',
					'error_message' => 'REST exception: ' . $e->getMessage(),
				] );
				return self::rest_result( false, [ 'message' => 'AI 服務發生伺服器錯誤，請稍後再試。' ], 500 );
			}
		}

		/* =====================================================
		 * API KEYS / AVAILABILITY
		 * ===================================================== */

		private static function get_api_key( $provider, $settings = null, $override = '' ) {
			if ( '' !== trim( (string) $override ) ) {
				return trim( (string) $override );
			}

			$s = is_array( $settings ) ? $settings : self::settings();

			if ( 'gemini' === $provider ) {
				if ( defined( 'WUMETAX_GEMINI_API_KEY' ) && WUMETAX_GEMINI_API_KEY ) {
					return (string) WUMETAX_GEMINI_API_KEY;
				}
				return trim( (string) ( $s['gemini_key'] ?? '' ) );
			}

			if ( 'perplexity' === $provider ) {
				if ( defined( 'WUMETAX_PERPLEXITY_API_KEY' ) && WUMETAX_PERPLEXITY_API_KEY ) {
					return (string) WUMETAX_PERPLEXITY_API_KEY;
				}
				return trim( (string) ( $s['perplexity_key'] ?? '' ) );
			}

			if ( 'openai' === $provider ) {
				if ( defined( 'WUMETAX_OPENAI_API_KEY' ) && WUMETAX_OPENAI_API_KEY ) {
					return (string) WUMETAX_OPENAI_API_KEY;
				}
				return trim( (string) ( $s['openai_key'] ?? '' ) );
			}

			return '';
		}

		private static function provider_model( $provider, $settings = null ) {
			$s = is_array( $settings ) ? $settings : self::settings();

			if ( 'perplexity' === $provider ) {
				return trim( (string) ( $s['perplexity_model'] ?? '' ) );
			}

			if ( 'openai' === $provider ) {
				return trim( (string) ( $s['openai_model'] ?? '' ) );
			}

			return trim( (string) ( $s['gemini_model'] ?? '' ) );
		}

		public static function ai_available( $available = false ) {
			$s = self::settings();
			if ( empty( $s['enabled'] ) || ! function_exists( 'wumetax_quick_support_get_context' ) ) {
				return false;
			}
			return '' !== self::get_api_key( $s['provider'], $s );
		}

		/* =====================================================
		 * ADMIN
		 * ===================================================== */

		public static function admin_menu() {
			add_submenu_page( 'wu-toolbox-modular', '知識庫支援 AI 擴充', '知識庫支援 AI 擴充', 'manage_options', self::ADMIN_SLUG, [ __CLASS__, 'admin_page' ] );
		}

		public static function admin_save() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( '你沒有權限進行此操作。' );
			}
			check_admin_referer( 'wumetax_qsa_save_v120' );
			$old = self::settings();
			$in  = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : [];

			$provider = isset( $in['provider'] ) && in_array( $in['provider'], [ 'gemini', 'perplexity', 'openai' ], true ) ? $in['provider'] : 'gemini';
			$new = [
				'enabled'            => ! empty( $in['enabled'] ) ? 1 : 0,
				'provider'           => $provider,
				'gemini_key'         => isset( $in['gemini_key'] ) && '' !== trim( $in['gemini_key'] ) ? sanitize_text_field( $in['gemini_key'] ) : $old['gemini_key'],
				'gemini_model'       => sanitize_text_field( $in['gemini_model'] ?? $old['gemini_model'] ),
				'perplexity_key'     => isset( $in['perplexity_key'] ) && '' !== trim( $in['perplexity_key'] ) ? sanitize_text_field( $in['perplexity_key'] ) : $old['perplexity_key'],
				'perplexity_model'   => sanitize_text_field( $in['perplexity_model'] ?? $old['perplexity_model'] ),
				'perplexity_web'     => ! empty( $in['perplexity_web'] ) ? 1 : 0,
				'openai_key'         => isset( $in['openai_key'] ) && '' !== trim( $in['openai_key'] ) ? sanitize_text_field( $in['openai_key'] ) : $old['openai_key'],
				'openai_model'       => sanitize_text_field( $in['openai_model'] ?? $old['openai_model'] ),
				'use_context'        => ! empty( $in['use_context'] ) ? 1 : 0,
				'context_limit'      => max( 1, min( 8, absint( $in['context_limit'] ?? 4 ) ) ),
				'session_limit'      => max( 1, min( 50, absint( $in['session_limit'] ?? 5 ) ) ),
				'ip_daily_limit'     => max( 1, min( 500, absint( $in['ip_daily_limit'] ?? 20 ) ) ),
				'site_daily_limit'   => max( 1, min( 10000, absint( $in['site_daily_limit'] ?? 200 ) ) ),
				'cooldown'           => max( 0, min( 60, absint( $in['cooldown'] ?? 4 ) ) ),
				'max_input'          => max( 100, min( 4000, absint( $in['max_input'] ?? 500 ) ) ),
				'max_tokens'         => max( 100, min( 4000, absint( $in['max_tokens'] ?? 700 ) ) ),
				'log_questions'      => ! empty( $in['log_questions'] ) ? 1 : 0,
				'log_retention_days' => max( 1, min( 365, absint( $in['log_retention_days'] ?? 30 ) ) ),
				'system_prompt'      => sanitize_textarea_field( $in['system_prompt'] ?? $old['system_prompt'] ),
			];
			update_option( self::OPTION_KEY, $new, false );
			wp_safe_redirect( add_query_arg( [ 'page' => self::ADMIN_SLUG, 'updated' => 1 ], admin_url( 'admin.php' ) ) );
			exit;
		}

		public static function admin_clear_logs() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( '你沒有權限進行此操作。' );
			}
			check_admin_referer( 'wumetax_qsa_clear_logs_v120' );
			global $wpdb;
			$wpdb->query( 'DELETE FROM ' . self::table_name() );
			wp_safe_redirect( add_query_arg( [ 'page' => self::ADMIN_SLUG, 'logs_cleared' => 1 ], admin_url( 'admin.php' ) ) );
			exit;
		}

		private static function usage_today() {
			$today = wp_date( 'Y-m-d' );
			$u = get_option( self::USAGE_KEY, [] );
			if ( ! is_array( $u ) || ( $u['date'] ?? '' ) !== $today ) {
				$u = [ 'date' => $today, 'total' => 0, 'gemini' => 0, 'perplexity' => 0, 'openai' => 0, 'blocked' => 0 ];
			}
			foreach ( [ 'total', 'gemini', 'perplexity', 'openai', 'blocked' ] as $key ) {
				if ( ! isset( $u[ $key ] ) ) {
					$u[ $key ] = 0;
				}
			}
			return $u;
		}

		private static function save_usage( $u ) {
			update_option( self::USAGE_KEY, $u, false );
		}

		private static function bump_usage( $key ) {
			$u = self::usage_today();
			if ( ! isset( $u[ $key ] ) ) {
				$u[ $key ] = 0;
			}
			$u[ $key ]++;
			self::save_usage( $u );
			return $u;
		}

		private static function get_logs( $page, $provider = '', $status = '' ) {
			global $wpdb;
			$table = self::table_name();
			$page  = max( 1, absint( $page ) );
			$where = [ '1=1' ];
			$args  = [];
			if ( in_array( $provider, [ 'gemini', 'perplexity', 'openai' ], true ) ) {
				$where[] = 'provider = %s';
				$args[]  = $provider;
			}
			$valid_status = [ 'success', 'error', 'blocked', 'test_success', 'test_error' ];
			if ( in_array( $status, $valid_status, true ) ) {
				$where[] = 'status = %s';
				$args[]  = $status;
			}
			$where_sql = implode( ' AND ', $where );
			$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
			if ( $args ) {
				$count_sql = $wpdb->prepare( $count_sql, $args );
			}
			$total  = (int) $wpdb->get_var( $count_sql );
			$offset = ( $page - 1 ) * self::LOG_PAGE_SIZE;
			$data_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
			$data_args = array_merge( $args, [ self::LOG_PAGE_SIZE, $offset ] );
			$rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_args ), ARRAY_A );
			return [ 'rows' => $rows ?: [], 'total' => $total, 'pages' => max( 1, (int) ceil( $total / self::LOG_PAGE_SIZE ) ) ];
		}

		private static function status_label( $status ) {
			$map = [
				'success'      => '成功',
				'error'        => '錯誤',
				'blocked'      => '遭限制',
				'test_success' => '驗證成功',
				'test_error'   => '驗證失敗',
			];
			return $map[ $status ] ?? $status;
		}

		public static function admin_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			self::maybe_install_table();
			$s = self::settings();
			$u = self::usage_today();
			$gemini_const = defined( 'WUMETAX_GEMINI_API_KEY' ) && WUMETAX_GEMINI_API_KEY;
			$perp_const   = defined( 'WUMETAX_PERPLEXITY_API_KEY' ) && WUMETAX_PERPLEXITY_API_KEY;
			$openai_const = defined( 'WUMETAX_OPENAI_API_KEY' ) && WUMETAX_OPENAI_API_KEY;
			$log_page     = isset( $_GET['qsa_log_page'] ) ? max( 1, absint( $_GET['qsa_log_page'] ) ) : 1;
			$log_provider = isset( $_GET['qsa_provider'] ) ? sanitize_key( wp_unslash( $_GET['qsa_provider'] ) ) : '';
			$log_status   = isset( $_GET['qsa_status'] ) ? sanitize_key( wp_unslash( $_GET['qsa_status'] ) ) : '';
			$logs         = self::get_logs( $log_page, $log_provider, $log_status );
			$test_nonce   = wp_create_nonce( self::TEST_NONCE );
			?>
			<style>
			.wuqsa-admin{max-width:1180px;margin:28px 20px 60px 0}.wuqsa-admin *{box-sizing:border-box}.wuqsa-head{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;margin-bottom:22px}.wuqsa-head h1{margin:0 0 7px;font-size:28px}.wuqsa-head p{margin:0;color:#646970}.wuqsa-save{min-height:40px!important;padding:0 20px!important}.wuqsa-notice{padding:13px 16px;margin-bottom:18px;background:#fff;border-left:4px solid #4fa567}.wuqsa-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:20px}.wuqsa-stat{padding:18px;background:#fff;border:1px solid #dcdcde;border-radius:11px}.wuqsa-stat strong{display:block;font-size:26px;line-height:1.1}.wuqsa-stat span{display:block;margin-top:5px;color:#777;font-size:12px}.wuqsa-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;overflow:hidden;margin-bottom:20px}.wuqsa-card-head{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:18px 20px;background:#f7f8f7;border-bottom:1px solid #e5e5e5}.wuqsa-card-head h2{margin:0 0 5px;font-size:17px}.wuqsa-card-head p{margin:0;color:#72777c;font-size:12px}.wuqsa-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;padding:20px}.wuqsa-field label{display:block;font-weight:650;margin-bottom:7px}.wuqsa-field input[type=text],.wuqsa-field input[type=password],.wuqsa-field input[type=number],.wuqsa-field select,.wuqsa-field textarea{width:100%}.wuqsa-field input[type=text],.wuqsa-field input[type=password],.wuqsa-field input[type=number],.wuqsa-field select{min-height:40px}.wuqsa-field input[type=checkbox]{width:16px!important;height:16px!important;min-height:0!important;margin:0!important}.wuqsa-field textarea{min-height:150px}.wuqsa-help{display:block;margin-top:6px;color:#777;font-size:12px;line-height:1.55}.wuqsa-toggle{display:inline-flex!important;align-items:center;gap:9px;margin:0!important}.wuqsa-toggle-box{padding:12px 14px;border:1px solid #dfe4e1;border-radius:9px;background:#fbfcfb}.wuqsa-full{grid-column:1/-1}.wuqsa-status{display:inline-flex;align-items:center;gap:7px;padding:6px 10px;border-radius:999px;background:#eef7f1;color:#347c4b;font-size:12px;font-weight:700}.wuqsa-status.off{background:#f2f2f2;color:#666}.wuqsa-test-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:10px}.wuqsa-test-row .button{min-height:36px}.wuqsa-test-result{font-size:12px;color:#646970}.wuqsa-test-result.ok{color:#26733b}.wuqsa-test-result.bad{color:#b32d2e}.wuqsa-provider-block{display:none;grid-column:1/-1;padding:18px;border:1px solid #e1e6e3;border-radius:11px;background:#fbfcfb}.wuqsa-provider-block.is-active{display:block}.wuqsa-provider-inner{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.wuqsa-provider-note{grid-column:1/-1;margin:0;color:#72777c;font-size:12px;line-height:1.6}.wuqsa-log-toolbar{display:flex;gap:9px;align-items:center;flex-wrap:wrap}.wuqsa-log-toolbar select{min-width:130px}.wuqsa-table-wrap{overflow:auto}.wuqsa-table{width:100%;border-collapse:collapse}.wuqsa-table th,.wuqsa-table td{padding:11px 12px;border-bottom:1px solid #eceeed;text-align:left;vertical-align:top;font-size:12px}.wuqsa-table th{background:#fafbfa;color:#50575e;white-space:nowrap}.wuqsa-table td small{color:#7b817d}.wuqsa-log-question{min-width:220px;max-width:360px;line-height:1.55}.wuqsa-log-error{display:block;margin-top:4px;color:#b32d2e}.wuqsa-log-status{display:inline-flex;padding:4px 7px;border-radius:999px;background:#eef7f1;color:#347c4b;font-weight:700;white-space:nowrap}.wuqsa-log-status.error,.wuqsa-log-status.test_error{background:#fff0f0;color:#b32d2e}.wuqsa-log-status.blocked{background:#fff7e6;color:#996600}.wuqsa-pagination{padding:14px 20px}.wuqsa-pagination .page-numbers{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;margin-right:4px;padding:0 8px;border:1px solid #dcdcde;border-radius:6px;text-decoration:none}.wuqsa-pagination .current{background:#1d2327;color:#fff;border-color:#1d2327}.wuqsa-danger{color:#b32d2e!important;border-color:#dba7a7!important}.wuqsa-privacy{padding:12px 20px;border-top:1px solid #eceeed;color:#72777c;font-size:11px;line-height:1.6}@media(max-width:800px){.wuqsa-stats,.wuqsa-grid,.wuqsa-provider-inner{grid-template-columns:1fr 1fr}.wuqsa-head,.wuqsa-card-head{align-items:flex-start;flex-direction:column}}@media(max-width:560px){.wuqsa-stats,.wuqsa-grid,.wuqsa-provider-inner{grid-template-columns:1fr}}
			</style>
			<style>.wuqsa-admin{max-width:1240px;margin:20px 0 0!important}.wuqsa-admin>.wutm-module-subtitle{margin-bottom:18px}.wuqsa-actions{display:flex;justify-content:flex-end;margin:0 0 18px}.wuqsa-card,.wuqsa-stat{border-radius:8px}@media(max-width:782px){.wuqsa-actions{justify-content:stretch}.wuqsa-actions .button{width:100%}}</style>

			<div class="wrap wutm-module-wrap sac-tools-page wuqsa-admin">
				<h1>知識庫支援 AI 擴充</h1>
				<p class="wutm-module-subtitle">設定 AI 供應商、知識庫上下文、使用額度與操作紀錄；未完成 API Key 設定時，前台仍保留一般內容搜尋。</p>
				<?php if ( isset( $_GET['updated'] ) ) : ?><div class="wuqsa-notice"><strong>已儲存。</strong> AI 擴充設定已更新。</div><?php endif; ?>
				<?php if ( isset( $_GET['logs_cleared'] ) ) : ?><div class="wuqsa-notice"><strong>已清除。</strong> AI 使用紀錄已清空。</div><?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wuqsa-settings-form">
					<input type="hidden" name="action" value="wumetax_qsa_save_v120"><?php wp_nonce_field( 'wumetax_qsa_save_v120' ); ?>
					<div class="wuqsa-actions"><button type="submit" class="button button-primary wuqsa-save">儲存設定</button></div>
					<div class="wuqsa-stats"><div class="wuqsa-stat"><strong><?php echo esc_html( $u['total'] ); ?></strong><span>今日 AI 回答</span></div><div class="wuqsa-stat"><strong><?php echo esc_html( $u['gemini'] ); ?></strong><span>Gemini</span></div><div class="wuqsa-stat"><strong><?php echo esc_html( $u['perplexity'] ); ?></strong><span>Perplexity</span></div><div class="wuqsa-stat"><strong><?php echo esc_html( $u['openai'] ); ?></strong><span>OpenAI</span></div><div class="wuqsa-stat"><strong><?php echo esc_html( $u['blocked'] ); ?></strong><span>今日遭限制</span></div></div>

					<div class="wuqsa-card"><div class="wuqsa-card-head"><div><h2>AI 供應商</h2><p>API Key 只保存在 WordPress 伺服器端，不會輸出到前端。可在這裡直接驗證連線。</p></div><?php $on = self::ai_available( false ); ?><span class="wuqsa-status <?php echo $on ? '' : 'off'; ?>"><?php echo $on ? '前台可使用' : '尚未完成設定'; ?></span></div><div class="wuqsa-grid">
						<div class="wuqsa-field wuqsa-full wuqsa-toggle-box"><label class="wuqsa-toggle"><input type="checkbox" name="settings[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> 啟用 AI 助理</label><span class="wuqsa-help">必須同時有目前 Provider 的 API Key，前台才會出現「AI 助理」分頁。</span></div>
						<div class="wuqsa-field"><label>Provider</label><select name="settings[provider]" id="wuqsa-provider"><option value="gemini" <?php selected( $s['provider'], 'gemini' ); ?>>Google Gemini</option><option value="perplexity" <?php selected( $s['provider'], 'perplexity' ); ?>>Perplexity</option><option value="openai" <?php selected( $s['provider'], 'openai' ); ?>>OpenAI</option></select><span class="wuqsa-help">只會顯示目前選取 Provider 的 API Key、Model 與專屬設定。</span></div>
						<div class="wuqsa-field"><label>目前狀態</label><span class="wuqsa-status <?php echo $on ? '' : 'off'; ?>"><?php echo $on ? '可使用' : '尚未完成設定'; ?></span></div>

						<div class="wuqsa-provider-block <?php echo 'gemini' === $s['provider'] ? 'is-active' : ''; ?>" data-provider-panel="gemini">
							<div class="wuqsa-provider-inner">
								<div class="wuqsa-field"><label>Gemini API Key</label><input type="password" id="wuqsa-gemini-key" name="settings[gemini_key]" value="" placeholder="<?php echo esc_attr( $gemini_const ? '已由 wp-config.php 提供' : ( $s['gemini_key'] ? '已儲存；留空即保留原值' : '貼上 Gemini API Key' ) ); ?>" <?php disabled( $gemini_const ); ?>><span class="wuqsa-help">可在 wp-config.php 定義 <code>WUMETAX_GEMINI_API_KEY</code>。</span></div>
								<div class="wuqsa-field"><label>Gemini Model</label><input type="text" id="wuqsa-gemini-model" name="settings[gemini_model]" value="<?php echo esc_attr( $s['gemini_model'] ); ?>"><div class="wuqsa-test-row"><button type="button" class="button wuqsa-test" data-provider="gemini">驗證 Gemini 連線</button><span class="wuqsa-test-result" id="wuqsa-test-gemini"></span></div></div>
							</div>
						</div>

						<div class="wuqsa-provider-block <?php echo 'perplexity' === $s['provider'] ? 'is-active' : ''; ?>" data-provider-panel="perplexity">
							<div class="wuqsa-provider-inner">
								<div class="wuqsa-field"><label>Perplexity API Key</label><input type="password" id="wuqsa-perplexity-key" name="settings[perplexity_key]" value="" placeholder="<?php echo esc_attr( $perp_const ? '已由 wp-config.php 提供' : ( $s['perplexity_key'] ? '已儲存；留空即保留原值' : '貼上 Perplexity API Key' ) ); ?>" <?php disabled( $perp_const ); ?>><span class="wuqsa-help">可在 wp-config.php 定義 <code>WUMETAX_PERPLEXITY_API_KEY</code>。</span></div>
								<div class="wuqsa-field"><label>Perplexity Model</label><select id="wuqsa-perplexity-model" name="settings[perplexity_model]"><option value="sonar" <?php selected( $s['perplexity_model'], 'sonar' ); ?>>sonar</option><option value="sonar-pro" <?php selected( $s['perplexity_model'], 'sonar-pro' ); ?>>sonar-pro</option><option value="sonar-deep-research" <?php selected( $s['perplexity_model'], 'sonar-deep-research' ); ?>>sonar-deep-research</option><option value="sonar-reasoning-pro" <?php selected( $s['perplexity_model'], 'sonar-reasoning-pro' ); ?>>sonar-reasoning-pro</option></select><div class="wuqsa-test-row"><button type="button" class="button wuqsa-test" data-provider="perplexity">驗證 Perplexity 連線</button><span class="wuqsa-test-result" id="wuqsa-test-perplexity"></span></div></div>
								<div class="wuqsa-field wuqsa-full wuqsa-toggle-box"><label class="wuqsa-toggle"><input type="checkbox" name="settings[perplexity_web]" value="1" <?php checked( ! empty( $s['perplexity_web'] ) ); ?>> Perplexity 允許網路搜尋</label><span class="wuqsa-help">關閉時會要求 Sonar 不使用 Web Search。</span></div>
							</div>
						</div>

						<div class="wuqsa-provider-block <?php echo 'openai' === $s['provider'] ? 'is-active' : ''; ?>" data-provider-panel="openai">
							<div class="wuqsa-provider-inner">
								<div class="wuqsa-field"><label>OpenAI API Key</label><input type="password" id="wuqsa-openai-key" name="settings[openai_key]" value="" placeholder="<?php echo esc_attr( $openai_const ? '已由 wp-config.php 提供' : ( $s['openai_key'] ? '已儲存；留空即保留原值' : '貼上 OpenAI API Key' ) ); ?>" <?php disabled( $openai_const ); ?>><span class="wuqsa-help">可在 wp-config.php 定義 <code>WUMETAX_OPENAI_API_KEY</code>；Key 只保存在伺服器端。</span></div>
								<div class="wuqsa-field"><label>OpenAI Model</label><input type="text" id="wuqsa-openai-model" name="settings[openai_model]" value="<?php echo esc_attr( $s['openai_model'] ); ?>"><span class="wuqsa-help">預設 <code>gpt-5.6-luna</code>，適合高流量、成本敏感的網站客服；可自行改成帳號可用的模型 ID。</span><div class="wuqsa-test-row"><button type="button" class="button wuqsa-test" data-provider="openai">驗證 OpenAI 連線</button><span class="wuqsa-test-result" id="wuqsa-test-openai"></span></div></div>
								<p class="wuqsa-provider-note">OpenAI 使用 Responses API；網站知識庫內容仍由 Quick Support 先搜尋後放進上下文，不會把 API Key 輸出到瀏覽器。</p>
							</div>
						</div>
					</div></div>

					<div class="wuqsa-card"><div class="wuqsa-card-head"><div><h2>知識庫與回答</h2><p>優先把 Quick Support 搜到的 Docs / Post / Product 內容當作 AI 上下文。</p></div></div><div class="wuqsa-grid">
						<div class="wuqsa-field wuqsa-toggle-box"><label class="wuqsa-toggle"><input type="checkbox" name="settings[use_context]" value="1" <?php checked( ! empty( $s['use_context'] ) ); ?>> 使用網站知識庫上下文</label></div>
						<div class="wuqsa-field"><label>最多帶入幾筆相關內容</label><input type="number" min="1" max="8" name="settings[context_limit]" value="<?php echo esc_attr( $s['context_limit'] ); ?>"></div>
						<div class="wuqsa-field"><label>單次問題最多字元</label><input type="number" min="100" max="4000" name="settings[max_input]" value="<?php echo esc_attr( $s['max_input'] ); ?>"></div>
						<div class="wuqsa-field"><label>最大回答 tokens</label><input type="number" min="100" max="4000" name="settings[max_tokens]" value="<?php echo esc_attr( $s['max_tokens'] ); ?>"></div>
						<div class="wuqsa-field wuqsa-full"><label>System Prompt</label><textarea name="settings[system_prompt]"><?php echo esc_textarea( $s['system_prompt'] ); ?></textarea></div>
					</div></div>

					<div class="wuqsa-card"><div class="wuqsa-card-head"><div><h2>額度、防刷與紀錄</h2><p>所有限制都在伺服器端檢查；使用紀錄不保存原始 IP，只保存不可逆雜湊。</p></div></div><div class="wuqsa-grid">
						<div class="wuqsa-field"><label>每個瀏覽 Session</label><input type="number" min="1" max="50" name="settings[session_limit]" value="<?php echo esc_attr( $s['session_limit'] ); ?>"></div>
						<div class="wuqsa-field"><label>每 IP 每日上限</label><input type="number" min="1" max="500" name="settings[ip_daily_limit]" value="<?php echo esc_attr( $s['ip_daily_limit'] ); ?>"></div>
						<div class="wuqsa-field"><label>全站每日上限</label><input type="number" min="1" max="10000" name="settings[site_daily_limit]" value="<?php echo esc_attr( $s['site_daily_limit'] ); ?>"></div>
						<div class="wuqsa-field"><label>兩次提問冷卻秒數</label><input type="number" min="0" max="60" name="settings[cooldown]" value="<?php echo esc_attr( $s['cooldown'] ); ?>"></div>
						<div class="wuqsa-field wuqsa-toggle-box"><label class="wuqsa-toggle"><input type="checkbox" name="settings[log_questions]" value="1" <?php checked( ! empty( $s['log_questions'] ) ); ?>> 使用紀錄保存問題摘要</label><span class="wuqsa-help">最多保存前 500 個字；若關閉仍會記錄 Provider、狀態、tokens、耗時與錯誤。</span></div>
						<div class="wuqsa-field"><label>使用紀錄保留天數</label><input type="number" min="1" max="365" name="settings[log_retention_days]" value="<?php echo esc_attr( $s['log_retention_days'] ); ?>"></div>
					</div></div>
				</form>

				<div class="wuqsa-card" id="wuqsa-logs"><div class="wuqsa-card-head"><div><h2>使用紀錄</h2><p>顯示 AI 問答、被限制的要求、API 錯誤與後台連線驗證。</p></div><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('確定要清除全部 AI 使用紀錄嗎？');"><input type="hidden" name="action" value="wumetax_qsa_clear_logs_v120"><?php wp_nonce_field( 'wumetax_qsa_clear_logs_v120' ); ?><button type="submit" class="button wuqsa-danger">清除紀錄</button></form></div>
					<div style="padding:14px 20px;border-bottom:1px solid #eceeed"><form method="get" class="wuqsa-log-toolbar"><input type="hidden" name="page" value="<?php echo esc_attr( self::ADMIN_SLUG ); ?>"><select name="qsa_provider"><option value="">全部 Provider</option><option value="gemini" <?php selected( $log_provider, 'gemini' ); ?>>Gemini</option><option value="perplexity" <?php selected( $log_provider, 'perplexity' ); ?>>Perplexity</option><option value="openai" <?php selected( $log_provider, 'openai' ); ?>>OpenAI</option></select><select name="qsa_status"><option value="">全部狀態</option><?php foreach ( [ 'success','error','blocked','test_success','test_error' ] as $st ) : ?><option value="<?php echo esc_attr( $st ); ?>" <?php selected( $log_status, $st ); ?>><?php echo esc_html( self::status_label( $st ) ); ?></option><?php endforeach; ?></select><button class="button" type="submit">篩選</button><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::ADMIN_SLUG . '#wuqsa-logs' ) ); ?>">重設</a></form></div>
					<div class="wuqsa-table-wrap"><table class="wuqsa-table"><thead><tr><th>時間</th><th>狀態</th><th>Provider / Model</th><th>問題 / 錯誤</th><th>用量</th><th>耗時</th></tr></thead><tbody>
					<?php if ( empty( $logs['rows'] ) ) : ?><tr><td colspan="6" style="padding:28px;text-align:center;color:#777">目前沒有符合條件的紀錄。</td></tr><?php endif; ?>
					<?php foreach ( $logs['rows'] as $row ) : ?><tr><td><?php echo esc_html( $row['created_at'] ); ?></td><td><span class="wuqsa-log-status <?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( self::status_label( $row['status'] ) ); ?></span></td><td><strong><?php $provider_labels = [ 'gemini' => 'Gemini', 'perplexity' => 'Perplexity', 'openai' => 'OpenAI' ]; echo esc_html( $provider_labels[ $row['provider'] ] ?? ( $row['provider'] ?: '-' ) ); ?></strong><br><small><?php echo esc_html( $row['model'] ?: '-' ); ?></small></td><td class="wuqsa-log-question"><?php echo $row['question'] ? esc_html( $row['question'] ) : '<small>未保存問題內容</small>'; ?><?php if ( $row['error_message'] ) : ?><span class="wuqsa-log-error"><?php echo esc_html( $row['error_message'] ); ?></span><?php endif; ?></td><td><small>Prompt <?php echo esc_html( $row['prompt_tokens'] ); ?><br>Output <?php echo esc_html( $row['output_tokens'] ); ?><br>Total <?php echo esc_html( $row['total_tokens'] ); ?><?php if ( null !== $row['cost'] && '' !== $row['cost'] ) : ?><br>Cost $<?php echo esc_html( rtrim( rtrim( number_format( (float) $row['cost'], 8, '.', '' ), '0' ), '.' ) ); ?><?php endif; ?></small></td><td><?php echo esc_html( number_format_i18n( absint( $row['response_ms'] ) ) ); ?> ms<br><small><?php echo esc_html( absint( $row['source_count'] ) ); ?> sources</small></td></tr><?php endforeach; ?>
					</tbody></table></div>
					<?php if ( $logs['pages'] > 1 ) : ?><div class="wuqsa-pagination"><?php echo wp_kses_post( paginate_links( [ 'base' => add_query_arg( [ 'page' => self::ADMIN_SLUG, 'qsa_provider' => $log_provider, 'qsa_status' => $log_status, 'qsa_log_page' => '%#%' ], admin_url( 'admin.php' ) ) . '#wuqsa-logs', 'format' => '', 'current' => $log_page, 'total' => $logs['pages'], 'prev_text' => '‹', 'next_text' => '›' ] ) ); ?></div><?php endif; ?>
					<div class="wuqsa-privacy">隱私說明：外掛不保存原始 IP，只保存以 WordPress Salt 產生的短雜湊，用於辨識防刷紀錄。若關閉「保存問題摘要」，問題文字不會寫入使用紀錄。</div>
				</div>
			</div>

			<script>
			(function(){
				'use strict';
				const ajaxUrl=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				const nonce=<?php echo wp_json_encode( $test_nonce ); ?>;
				const providerSelect=document.getElementById('wuqsa-provider');
				function value(id){const e=document.getElementById(id);return e?e.value:'';}
				function syncProvider(){
					const selected=providerSelect?providerSelect.value:'gemini';
					document.querySelectorAll('[data-provider-panel]').forEach(panel=>panel.classList.toggle('is-active',panel.dataset.providerPanel===selected));
				}
				if(providerSelect){providerSelect.addEventListener('change',syncProvider);syncProvider();}
				async function test(btn){
					const provider=btn.dataset.provider;const out=document.getElementById('wuqsa-test-'+provider);out.className='wuqsa-test-result';out.textContent='驗證中…';btn.disabled=true;
					const keyId={gemini:'wuqsa-gemini-key',perplexity:'wuqsa-perplexity-key',openai:'wuqsa-openai-key'}[provider];
					const modelId={gemini:'wuqsa-gemini-model',perplexity:'wuqsa-perplexity-model',openai:'wuqsa-openai-model'}[provider];
					const body=new URLSearchParams();body.append('action','wumetax_qsa_test_connection');body.append('nonce',nonce);body.append('provider',provider);body.append('api_key',value(keyId));body.append('model',value(modelId));
					try{
						const r=await fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},credentials:'same-origin',body:body.toString()});
						const raw=await r.text();let data;try{data=JSON.parse(raw);}catch(e){throw new Error(raw.trim().startsWith('<')?'伺服器回傳 HTML，而不是 JSON。請檢查安全外掛、Cloudflare 或 PHP 錯誤紀錄。':'伺服器回應格式不正確。');}
						if(!data||!data.success)throw new Error(data&&data.data&&data.data.message?data.data.message:'驗證失敗。');
						out.classList.add('ok');out.textContent='✓ '+data.data.message;
					}catch(err){out.classList.add('bad');out.textContent='✕ '+(err.message||'驗證失敗');}
					finally{btn.disabled=false;}
				}
				document.querySelectorAll('.wuqsa-test').forEach(btn=>btn.addEventListener('click',()=>test(btn)));
			})();
			</script>
			<?php
		}

		/* =====================================================
		 * TEST CONNECTION
		 * ===================================================== */

		public static function ajax_test_connection() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( [ 'message' => '權限不足。' ], 403 );
			}

			check_ajax_referer( self::TEST_NONCE, 'nonce' );
			nocache_headers();

			$s = self::settings();
			$allowed = [ 'gemini', 'perplexity', 'openai' ];
			$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'gemini';
			$provider = in_array( $provider, $allowed, true ) ? $provider : 'gemini';
			$model   = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
			$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
			$key     = self::get_api_key( $provider, $s, $api_key );
			$start   = microtime( true );

			if ( '' === $key || '' === $model ) {
				wp_send_json_error( [ 'message' => '請先填寫 API Key 與 Model。' ], 400 );
			}

			if ( 'gemini' === $provider ) {
				$model_clean = preg_replace( '#^models/#', '', $model );
				$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model_clean ) . ':generateContent';
				$response = wp_remote_post( $url, [
					'timeout' => 25,
					'headers' => [ 'x-goog-api-key' => $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
					'body'    => wp_json_encode( [
						'contents' => [ [ 'role' => 'user', 'parts' => [ [ 'text' => 'Reply exactly: OK' ] ] ] ],
						'generationConfig' => [ 'maxOutputTokens' => 32 ],
					] ),
				] );
			} elseif ( 'perplexity' === $provider ) {
				$response = wp_remote_post( 'https://api.perplexity.ai/v1/sonar', [
					'timeout' => 25,
					'headers' => [ 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
					'body'    => wp_json_encode( [
						'model' => $model,
						'messages' => [ [ 'role' => 'user', 'content' => 'Reply exactly: OK' ] ],
						'max_tokens' => 16,
						'stream' => false,
						'web_search_options' => [ 'disable_search' => true ],
					] ),
				] );
			} else {
				$response = wp_remote_post( 'https://api.openai.com/v1/responses', [
					'timeout' => 30,
					'headers' => [ 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
					'body'    => wp_json_encode( [
						'model'             => $model,
						'input'             => 'Reply exactly: OK',
						'max_output_tokens' => 32,
						'store'             => false,
					] ),
				] );
			}

			$ms = (int) round( ( microtime( true ) - $start ) * 1000 );

			if ( is_wp_error( $response ) ) {
				self::log_event( [ 'provider' => $provider, 'model' => $model, 'status' => 'test_error', 'response_ms' => $ms, 'error_message' => $response->get_error_message() ] );
				wp_send_json_error( [ 'message' => $response->get_error_message() ], 502 );
			}

			$code = wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );
			$data = json_decode( $raw, true );

			if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
				$msg = self::api_error_message( $provider, $code, $raw, $data );
				self::log_event( [ 'provider' => $provider, 'model' => $model, 'status' => 'test_error', 'response_ms' => $ms, 'error_message' => $msg ] );
				wp_send_json_error( [ 'message' => $msg ], 400 );
			}

			$labels = [ 'gemini' => 'Gemini', 'perplexity' => 'Perplexity', 'openai' => 'OpenAI' ];
			$message = $labels[ $provider ] . ' API Key、模型與實際生成皆可使用。';
			self::log_event( [ 'provider' => $provider, 'model' => $model, 'status' => 'test_success', 'response_ms' => $ms ] );
			wp_send_json_success( [ 'message' => $message ] );
		}

		/* =====================================================
		 * RATE LIMIT
		 * ===================================================== */

		private static function client_ip() {
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && ! empty( $_SERVER['HTTP_CF_RAY'] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			} else {
				$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
			}
			return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
		}

		private static function key_hash( $value ) {
			return substr( hash_hmac( 'sha256', (string) $value, wp_salt( 'auth' ) ), 0, 24 );
		}

		private static function rate_check( $session_id, $s ) {
			$today       = wp_date( 'Ymd' );
			$session_key = 'wuqsa_s_' . self::key_hash( $session_id );
			$ip_key      = 'wuqsa_i_' . self::key_hash( self::client_ip() . '|' . $today );
			$cool_key    = 'wuqsa_c_' . self::key_hash( $session_id . '|' . self::client_ip() );
			$session_n   = absint( get_transient( $session_key ) );
			$ip_n        = absint( get_transient( $ip_key ) );
			$usage       = self::usage_today();

			if ( $usage['total'] >= absint( $s['site_daily_limit'] ) ) {
				self::bump_usage( 'blocked' );
				return new WP_Error( 'site_limit', '今日 AI 協助額度已達上限，您仍可以使用內容搜尋或直接聯絡我們。' );
			}
			if ( $session_n >= absint( $s['session_limit'] ) ) {
				self::bump_usage( 'blocked' );
				return new WP_Error( 'session_limit', '本次瀏覽的 AI 問答次數已使用完畢，您仍可以使用內容搜尋。' );
			}
			if ( $ip_n >= absint( $s['ip_daily_limit'] ) ) {
				self::bump_usage( 'blocked' );
				return new WP_Error( 'ip_limit', '今日 AI 問答次數已達上限，請稍後再試或直接聯絡我們。' );
			}
			if ( absint( $s['cooldown'] ) > 0 && get_transient( $cool_key ) ) {
				self::bump_usage( 'blocked' );
				return new WP_Error( 'cooldown', '請稍候幾秒再送出下一個問題。' );
			}

			return [ 'session_key' => $session_key, 'ip_key' => $ip_key, 'cool_key' => $cool_key, 'session_n' => $session_n, 'ip_n' => $ip_n ];
		}

		private static function rate_commit( $rate, $s ) {
			set_transient( $rate['session_key'], $rate['session_n'] + 1, 12 * HOUR_IN_SECONDS );
			set_transient( $rate['ip_key'], $rate['ip_n'] + 1, DAY_IN_SECONDS + HOUR_IN_SECONDS );
			if ( absint( $s['cooldown'] ) > 0 ) {
				set_transient( $rate['cool_key'], 1, absint( $s['cooldown'] ) );
			}
			$u = self::bump_usage( 'total' );
			$provider = $s['provider'];
			if ( isset( $u[ $provider ] ) ) {
				$u[ $provider ]++;
				self::save_usage( $u );
			}
		}

		/* =====================================================
		 * CONTEXT / HISTORY
		 * ===================================================== */

		private static function sanitize_history( $raw ) {
			if ( ! is_array( $raw ) ) {
				return [];
			}
			$out = [];
			foreach ( array_slice( $raw, -6 ) as $m ) {
				if ( ! is_array( $m ) ) {
					continue;
				}
				$role = ( $m['role'] ?? '' ) === 'assistant' ? 'assistant' : 'user';
				$text = sanitize_textarea_field( $m['text'] ?? '' );
				$text = self::trim_text( $text, 1200 );
				if ( '' !== trim( $text ) ) {
					$out[] = [ 'role' => $role, 'text' => $text ];
				}
			}
			return $out;
		}

		private static function build_context_text( $items ) {
			if ( empty( $items ) ) {
				return '';
			}
			$parts = [ '以下是 Wumetax 網站內部資料。Wumetax 專屬資訊（價格、方案、流程、政策）應優先以這些資料為準；資料沒有寫到的部分不要自行編造。' ];
			foreach ( $items as $i => $item ) {
				$parts[] = sprintf( "\n[%d] %s\n類型：%s / %s\n網址：%s\n內容：%s", $i + 1, $item['title'], $item['type'], $item['category'], $item['url'], $item['content'] );
			}
			return implode( "\n", $parts );
		}

		/* =====================================================
		 * API CALLS
		 * ===================================================== */

		private static function api_error_message( $provider, $code, $raw, $data ) {
			$msg = '';
			if ( is_array( $data ) ) {
				$msg = $data['error']['message'] ?? $data['detail'][0]['msg'] ?? $data['message'] ?? '';
			}
			if ( ! is_string( $msg ) || '' === trim( $msg ) ) {
				if ( is_string( $raw ) && 0 === strpos( ltrim( $raw ), '<' ) ) {
					$msg = ucfirst( $provider ) . ' API 回傳 HTML，而不是 JSON。';
				} else {
					$msg = ucfirst( $provider ) . ' API 回傳錯誤（HTTP ' . $code . '）。';
				}
			}
			return sanitize_text_field( self::trim_text( $msg, 500 ) );
		}

		private static function call_gemini( $question, $history, $context, $s ) {
			$key   = self::get_api_key( 'gemini', $s );
			$model = preg_replace( '#^models/#', '', trim( (string) $s['gemini_model'] ) );
			if ( '' === $key || '' === $model ) {
				return new WP_Error( 'gemini_config', 'Gemini API 尚未完成設定。' );
			}

			$contents = [];
			foreach ( $history as $m ) {
				$contents[] = [ 'role' => 'assistant' === $m['role'] ? 'model' : 'user', 'parts' => [ [ 'text' => $m['text'] ] ] ];
			}
			$user_text  = $context ? $context . "\n\n使用者問題：\n" . $question : $question;
			$contents[] = [ 'role' => 'user', 'parts' => [ [ 'text' => $user_text ] ] ];

			$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent';
			$response = wp_remote_post( $url, [
				'timeout' => 40,
				'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json', 'x-goog-api-key' => $key ],
				'body'    => wp_json_encode( [
					'systemInstruction' => [ 'parts' => [ [ 'text' => $s['system_prompt'] ] ] ],
					'contents'          => $contents,
					'generationConfig'  => [ 'maxOutputTokens' => absint( $s['max_tokens'] ) ],
				] ),
			] );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );
			$data = json_decode( $raw, true );
			if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
				return new WP_Error( 'gemini_http', self::api_error_message( 'gemini', $code, $raw, $data ) );
			}

			$text = '';
			$parts = $data['candidates'][0]['content']['parts'] ?? [];
			foreach ( $parts as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
			if ( '' === trim( $text ) ) {
				return new WP_Error( 'gemini_empty', 'Gemini 沒有回傳可顯示的文字。' );
			}
			$usage = $data['usageMetadata'] ?? [];
			return [
				'text'      => trim( $text ),
				'citations' => [],
				'usage'     => [
					'prompt_tokens' => absint( $usage['promptTokenCount'] ?? 0 ),
					'output_tokens' => absint( $usage['candidatesTokenCount'] ?? 0 ),
					'total_tokens'  => absint( $usage['totalTokenCount'] ?? 0 ),
					'cost'          => null,
				],
			];
		}

		private static function call_perplexity( $question, $history, $context, $s ) {
			$key = self::get_api_key( 'perplexity', $s );
			if ( '' === $key ) {
				return new WP_Error( 'perplexity_config', 'Perplexity API 尚未完成設定。' );
			}

			$messages = [ [ 'role' => 'system', 'content' => $s['system_prompt'] ] ];
			foreach ( $history as $m ) {
				$messages[] = [ 'role' => $m['role'], 'content' => $m['text'] ];
			}
			$user_text  = $context ? $context . "\n\n使用者問題：\n" . $question : $question;
			$messages[] = [ 'role' => 'user', 'content' => $user_text ];

			$body = [
				'model'       => $s['perplexity_model'],
				'messages'    => $messages,
				'max_tokens'  => absint( $s['max_tokens'] ),
				'temperature' => 0.2,
				'stream'      => false,
				'web_search_options' => [ 'disable_search' => empty( $s['perplexity_web'] ) ],
			];

			$response = wp_remote_post( 'https://api.perplexity.ai/v1/sonar', [
				'timeout' => 45,
				'headers' => [ 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
				'body'    => wp_json_encode( $body ),
			] );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );
			$data = json_decode( $raw, true );
			if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
				return new WP_Error( 'perplexity_http', self::api_error_message( 'perplexity', $code, $raw, $data ) );
			}
			$text = $data['choices'][0]['message']['content'] ?? '';
			if ( '' === trim( $text ) ) {
				return new WP_Error( 'perplexity_empty', 'Perplexity 沒有回傳可顯示的文字。' );
			}
			$citations = [];
			if ( ! empty( $data['citations'] ) && is_array( $data['citations'] ) ) {
				foreach ( array_slice( $data['citations'], 0, 5 ) as $url ) {
					$url = esc_url_raw( $url );
					if ( $url ) {
						$citations[] = $url;
					}
				}
			}
			$usage = $data['usage'] ?? [];
			$cost  = isset( $usage['cost']['total_cost'] ) && is_numeric( $usage['cost']['total_cost'] ) ? (float) $usage['cost']['total_cost'] : null;
			return [
				'text'      => trim( $text ),
				'citations' => $citations,
				'usage'     => [
					'prompt_tokens' => absint( $usage['prompt_tokens'] ?? 0 ),
					'output_tokens' => absint( $usage['completion_tokens'] ?? 0 ),
					'total_tokens'  => absint( $usage['total_tokens'] ?? 0 ),
					'cost'          => $cost,
				],
			];
		}


		private static function call_openai( $question, $history, $context, $s ) {
			$key   = self::get_api_key( 'openai', $s );
			$model = trim( (string) ( $s['openai_model'] ?? '' ) );

			if ( '' === $key || '' === $model ) {
				return new WP_Error( 'openai_config', 'OpenAI API 尚未完成設定。' );
			}

			$input = [];
			foreach ( $history as $m ) {
				$input[] = [
					'role'    => 'assistant' === $m['role'] ? 'assistant' : 'user',
					'content' => $m['text'],
				];
			}

			$user_text = $context ? $context . "

使用者問題：
" . $question : $question;
			$input[] = [ 'role' => 'user', 'content' => $user_text ];

			$response = wp_remote_post( 'https://api.openai.com/v1/responses', [
				'timeout' => 45,
				'headers' => [
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				],
				'body' => wp_json_encode( [
					'model'             => $model,
					'instructions'      => $s['system_prompt'],
					'input'             => $input,
					'max_output_tokens' => absint( $s['max_tokens'] ),
					'store'             => false,
				] ),
			] );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );
			$data = json_decode( $raw, true );

			if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
				return new WP_Error( 'openai_http', self::api_error_message( 'openai', $code, $raw, $data ) );
			}

			$text = '';
			if ( ! empty( $data['output'] ) && is_array( $data['output'] ) ) {
				foreach ( $data['output'] as $item ) {
					if ( ! is_array( $item ) || 'message' !== ( $item['type'] ?? '' ) || empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
						continue;
					}
					foreach ( $item['content'] as $part ) {
						if ( is_array( $part ) && 'output_text' === ( $part['type'] ?? '' ) && isset( $part['text'] ) ) {
							$text .= (string) $part['text'];
						}
					}
				}
			}

			if ( '' === trim( $text ) ) {
				return new WP_Error( 'openai_empty', 'OpenAI 沒有回傳可顯示的文字。' );
			}

			$usage = is_array( $data['usage'] ?? null ) ? $data['usage'] : [];
			return [
				'text'      => trim( $text ),
				'citations' => [],
				'usage'     => [
					'prompt_tokens' => absint( $usage['input_tokens'] ?? 0 ),
					'output_tokens' => absint( $usage['output_tokens'] ?? 0 ),
					'total_tokens'  => absint( $usage['total_tokens'] ?? 0 ),
					'cost'          => null,
				],
			];
		}

		private static function call_provider( $provider, $question, $history, $context, $s ) {
			if ( 'perplexity' === $provider ) {
				return self::call_perplexity( $question, $history, $context, $s );
			}
			if ( 'openai' === $provider ) {
				return self::call_openai( $question, $history, $context, $s );
			}
			return self::call_gemini( $question, $history, $context, $s );
		}

		/* =====================================================
		 * AJAX ASK
		 * ===================================================== */

		public static function ajax_ask() {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );
			nocache_headers();
			$s = self::settings();
			if ( ! self::ai_available( false ) ) {
				wp_send_json_error( [ 'message' => 'AI 助理目前未啟用。' ], 503 );
			}

			$question = isset( $_POST['question'] ) ? sanitize_textarea_field( wp_unslash( $_POST['question'] ) ) : '';
			$session  = isset( $_POST['session_id'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', wp_unslash( $_POST['session_id'] ) ) : '';
			$raw_hist = isset( $_POST['history'] ) ? json_decode( wp_unslash( $_POST['history'] ), true ) : [];
			$history  = self::sanitize_history( $raw_hist );
			$provider = $s['provider'];
			$model    = self::provider_model( $provider, $s );

			if ( '' === $session || strlen( $session ) < 12 ) {
				wp_send_json_error( [ 'message' => '無法建立本次 AI 對話識別，請重新整理頁面後再試。' ], 400 );
			}
			if ( '' === trim( $question ) ) {
				wp_send_json_error( [ 'message' => '請先輸入問題。' ], 400 );
			}
			$max_input = absint( $s['max_input'] );
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $question, 'UTF-8' ) : strlen( $question );
			if ( $length > $max_input ) {
				wp_send_json_error( [ 'message' => '問題太長，請控制在 ' . $max_input . ' 個字元內。' ], 400 );
			}

			$rate = self::rate_check( $session, $s );
			if ( is_wp_error( $rate ) ) {
				self::log_event( [ 'provider' => $provider, 'model' => $model, 'status' => 'blocked', 'question' => $question, 'session_id' => $session, 'error_message' => $rate->get_error_message() ] );
				wp_send_json_error( [ 'message' => $rate->get_error_message() ], 429 );
			}

			$context_items = [];
			$context_text  = '';
			if ( ! empty( $s['use_context'] ) && function_exists( 'wumetax_quick_support_get_context' ) ) {
				$context_items = wumetax_quick_support_get_context( $question, absint( $s['context_limit'] ) );
				$context_text  = self::build_context_text( $context_items );
			}

			$start  = microtime( true );
			$result = self::call_provider( $provider, $question, $history, $context_text, $s );
			$ms     = (int) round( ( microtime( true ) - $start ) * 1000 );

			if ( is_wp_error( $result ) ) {
				self::log_event( [ 'provider' => $provider, 'model' => $model, 'status' => 'error', 'question' => $question, 'session_id' => $session, 'source_count' => count( $context_items ), 'response_ms' => $ms, 'error_message' => $result->get_error_message() ] );
				wp_send_json_error( [ 'message' => $result->get_error_message() ], 502 );
			}

			self::rate_commit( $rate, $s );
			$u = self::usage_today();
			$sources = [];
			foreach ( $context_items as $item ) {
				$sources[] = [ 'title' => $item['title'], 'url' => $item['url'] ];
			}
			foreach ( $result['citations'] as $url ) {
				$sources[] = [ 'title' => wp_parse_url( $url, PHP_URL_HOST ) ?: '外部來源', 'url' => $url ];
			}
			$sources = array_slice( $sources, 0, 6 );
			$usage   = $result['usage'] ?? [ 'prompt_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'cost' => null ];

			self::log_event( [
				'provider'      => $provider,
				'model'         => $model,
				'status'        => 'success',
				'question'      => $question,
				'answer'        => $result['text'],
				'session_id'    => $session,
				'source_count'  => count( $sources ),
				'response_ms'   => $ms,
				'prompt_tokens' => $usage['prompt_tokens'],
				'output_tokens' => $usage['output_tokens'],
				'total_tokens'  => $usage['total_tokens'],
				'cost'          => $usage['cost'],
			] );

			$remaining = max( 0, min( absint( $s['session_limit'] ) - ( $rate['session_n'] + 1 ), absint( $s['ip_daily_limit'] ) - ( $rate['ip_n'] + 1 ), absint( $s['site_daily_limit'] ) - $u['total'] ) );
			wp_send_json_success( [ 'answer' => $result['text'], 'sources' => $sources, 'remaining' => $remaining, 'provider' => $provider ] );
		}

		/* =====================================================
		 * FRONTEND
		 * ===================================================== */

		public static function render_panel() {
			if ( ! self::ai_available( false ) ) {
				return;
			}
			$s = self::settings();
			?>
			<div class="wuqsa-chat" id="wuqsa-chat">
				<div class="wuqsa-intro"><span class="wuqsa-live"><i></i> AI 網站助理</span><strong>有網站問題，可以直接問我</strong><p>我會優先參考 Wumetax 的支援文件、網站知識與商品資料，再由 AI 協助整理回答。</p></div>
				<div class="wuqsa-messages" id="wuqsa-messages"><div class="wuqsa-msg wuqsa-msg--ai">您好，有 WordPress、WooCommerce、網站主機、維護或 Wumetax 服務問題都可以問我。</div></div>
				<form class="wuqsa-form" id="wuqsa-form"><textarea id="wuqsa-input" rows="1" maxlength="<?php echo esc_attr( $s['max_input'] ); ?>" placeholder="輸入您的問題..."></textarea><button type="submit" id="wuqsa-send" aria-label="送出 AI 問題">↗</button></form>
				<div class="wuqsa-foot"><span>AI 回覆可能有誤，重要資訊請以正式頁面或人工確認為準。</span><span id="wuqsa-remaining">本次最多 <?php echo esc_html( $s['session_limit'] ); ?> 次</span></div>
			</div>
			<?php
		}

		public static function render_assets() {
			if ( is_admin() || ! self::ai_available( false ) ) {
				return;
			}
			$nonce      = wp_create_nonce( self::NONCE_ACTION );
			$ajax_url   = admin_url( 'admin-ajax.php' );
			$rest_url   = rest_url( 'wumetax-qsa/v1/ask' );
			$rest_token = self::public_token();
			?>
			<style id="wumetax-qsa-v120-css">
			#wuqs-ai-view{background:#fff;overflow:hidden;min-height:0}.wuqsa-chat{display:flex;flex-direction:column;flex:1 1 auto;min-height:0;height:100%;padding:10px 18px 10px}.wuqsa-intro{flex:0 0 auto;padding:11px 13px 12px;border:1px solid #e5ebe7;border-radius:13px;background:#f8fbf9;transition:.18s ease}.wuqsa-chat.has-chat .wuqsa-intro{display:none}.wuqsa-live{display:flex;align-items:center;gap:6px;margin-bottom:6px;color:#347c4b;font-size:10px;font-weight:800;letter-spacing:.05em}.wuqsa-live i{width:7px;height:7px;border-radius:50%;background:#36c96b;box-shadow:0 0 0 4px rgba(54,201,107,.1)}.wuqsa-intro strong{display:block;margin-bottom:4px;color:#171b19;font-size:14px}.wuqsa-intro p{margin:0;color:#758079;font-size:11.5px;line-height:1.55}.wuqsa-messages{flex:1 1 auto;min-height:84px;overflow:auto;padding:10px 2px;display:flex;flex-direction:column;gap:9px;scrollbar-width:thin;overscroll-behavior:contain}.wuqsa-msg{max-width:88%;padding:10px 12px;border-radius:13px;font-size:12.5px;line-height:1.65;white-space:pre-wrap;word-break:break-word}.wuqsa-msg--ai{align-self:flex-start;background:#f3f6f4;color:#39423d;border-bottom-left-radius:5px}.wuqsa-msg--user{align-self:flex-end;background:#347c4b;color:#fff;border-bottom-right-radius:5px}.wuqsa-msg--error{align-self:flex-start;background:#fff1f1;color:#9f2f31}.wuqsa-thinking{display:flex;gap:5px;align-items:center}.wuqsa-thinking i{width:6px;height:6px;border-radius:50%;background:#4fa567;animation:wuqsa-dot 1s infinite ease-in-out}.wuqsa-thinking i:nth-child(2){animation-delay:.12s}.wuqsa-thinking i:nth-child(3){animation-delay:.24s}@keyframes wuqsa-dot{0%,80%,100%{opacity:.3;transform:translateY(0)}40%{opacity:1;transform:translateY(-3px)}}.wuqsa-sources{max-width:88%;padding:0 2px 4px}.wuqsa-sources strong{display:block;margin-bottom:5px;color:#758079;font-size:10px}.wuqsa-sources a{display:block;overflow:hidden;margin:3px 0;color:#347c4b!important;font-size:11px;text-decoration:none!important;text-overflow:ellipsis;white-space:nowrap}.wuqsa-form{display:flex;gap:8px;flex:0 0 auto;padding-top:9px;border-top:1px solid #e7ebe8}.wuqsa-form textarea{flex:1;height:54px!important;min-height:54px!important;max-height:90px!important;resize:none;padding:11px 12px!important;border:1px solid #dfe6e1!important;border-radius:12px!important;background:#fff!important;box-shadow:none!important;color:#171b19!important;font:inherit!important;font-size:13px!important;line-height:1.5!important;overflow:auto}.wuqsa-form textarea:focus{border-color:#9bc9a7!important;outline:0!important}.wuqsa-form button{flex:0 0 48px;width:48px;height:48px;align-self:flex-end;border:0;border-radius:12px;background:#347c4b;color:#fff;font-size:22px;cursor:pointer}.wuqsa-form button:disabled{opacity:.5;cursor:not-allowed}.wuqsa-foot{display:flex;justify-content:space-between;gap:10px;flex:0 0 auto;padding-top:7px;color:#8a948e;font-size:9px;line-height:1.35}.wuqsa-foot span:first-child{max-width:72%}@media(max-width:700px){.wuqsa-chat{padding-left:13px;padding-right:13px}.wuqsa-intro{padding:10px 12px}.wuqsa-messages{min-height:70px}}
			</style>
			<script id="wumetax-qsa-v120-js">
			(function(){
				'use strict';
				const nonce=<?php echo wp_json_encode( $nonce ); ?>,fallbackAjax=<?php echo wp_json_encode( $ajax_url ); ?>,restUrl=<?php echo wp_json_encode( $rest_url ); ?>,restToken=<?php echo wp_json_encode( $rest_token ); ?>;
				let history=[],busy=false;
				function sessionId(){let id=sessionStorage.getItem('wuqsa_session_v120');if(!id){if(window.crypto&&crypto.randomUUID){id=crypto.randomUUID();}else{id='s_'+Date.now()+'_'+Math.random().toString(36).slice(2);}sessionStorage.setItem('wuqsa_session_v120',id);}return id;}
				function els(){return{chat:document.getElementById('wuqsa-chat'),form:document.getElementById('wuqsa-form'),input:document.getElementById('wuqsa-input'),send:document.getElementById('wuqsa-send'),messages:document.getElementById('wuqsa-messages'),remaining:document.getElementById('wuqsa-remaining')}}
				function addMsg(text,type){const e=els();const d=document.createElement('div');d.className='wuqsa-msg wuqsa-msg--'+type;d.textContent=text;e.messages.appendChild(d);e.messages.scrollTop=e.messages.scrollHeight;return d;}
				function addThinking(){const e=els();const d=document.createElement('div');d.className='wuqsa-msg wuqsa-msg--ai wuqsa-thinking';d.innerHTML='<i></i><i></i><i></i>';e.messages.appendChild(d);e.messages.scrollTop=e.messages.scrollHeight;return d;}
				function addSources(items){if(!Array.isArray(items)||!items.length)return;const e=els(),box=document.createElement('div');box.className='wuqsa-sources';const h=document.createElement('strong');h.textContent='參考資料';box.appendChild(h);items.forEach(s=>{const a=document.createElement('a');a.href=s.url;a.target='_blank';a.rel='noopener';a.textContent=s.title||s.url;box.appendChild(a);});e.messages.appendChild(box);e.messages.scrollTop=e.messages.scrollHeight;}
				function friendlyNonJson(raw,channel){const text=String(raw||'').trim();if(text.startsWith('<!DOCTYPE')||text.startsWith('<html')||text.startsWith('<'))return 'AI '+channel+' 通道被伺服器回傳成 HTML。請到後台「Wumetax AI 助理」確認連線驗證；若仍發生，請檢查 Cloudflare / 安全規則是否攔截 WordPress API。';return 'AI 伺服器回應格式不正確，請稍後再試。';}
				async function requestRest(question){
					const payload={question:question,session_id:sessionId(),history:history};
					const r=await fetch(restUrl,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-Wumetax-AI-Token':restToken},credentials:'same-origin',body:JSON.stringify(payload)});
					const raw=await r.text();let data;try{data=JSON.parse(raw);}catch(e){throw new Error(friendlyNonJson(raw,'REST'));}return data;
				}
				async function requestLegacy(question){
					const body=new URLSearchParams();body.append('action','wumetax_qsa_ask');body.append('nonce',nonce);body.append('question',question);body.append('session_id',sessionId());body.append('history',JSON.stringify(history));
					const r=await fetch((window.WumetaxQuickSupport||{}).ajaxUrl||fallbackAjax,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','Accept':'application/json'},credentials:'same-origin',body:body.toString()});
					const raw=await r.text();let data;try{data=JSON.parse(raw);}catch(e){throw new Error(friendlyNonJson(raw,'AJAX'));}return data;
				}
				async function ask(question){const e=els();if(busy)return;busy=true;e.send.disabled=true;if(e.chat)e.chat.classList.add('has-chat');addMsg(question,'user');const thinking=addThinking();try{let data;try{data=await requestRest(question);}catch(restError){console.warn('Wumetax AI REST failed, fallback to AJAX:',restError);data=await requestLegacy(question);}thinking.remove();if(!data||!data.success){throw new Error(data&&data.data&&data.data.message?data.data.message:'AI 暫時無法回答，請稍後再試。');}addMsg(data.data.answer,'ai');addSources(data.data.sources);history.push({role:'user',text:question},{role:'assistant',text:data.data.answer});history=history.slice(-6);if(e.remaining)e.remaining.textContent='剩餘約 '+data.data.remaining+' 次';}catch(err){if(thinking&&thinking.isConnected)thinking.remove();addMsg(err.message||'AI 暫時無法回答，請稍後再試。','error');}finally{busy=false;e.send.disabled=false;e.input.focus();}}
				function autosize(input){input.style.height='54px';input.style.height=Math.min(90,Math.max(54,input.scrollHeight))+'px';}
				function bind(){const e=els();if(!e.form||e.form.dataset.bound)return;e.form.dataset.bound='1';e.form.addEventListener('submit',ev=>{ev.preventDefault();const q=e.input.value.trim();if(!q)return;e.input.value='';autosize(e.input);ask(q);});e.input.addEventListener('input',()=>autosize(e.input));e.input.addEventListener('keydown',ev=>{if(ev.key==='Enter'&&!ev.shiftKey){ev.preventDefault();e.form.requestSubmit();}});}
				document.addEventListener('wumetax:qs-ai-open',()=>{bind();const e=els();if(e.input)setTimeout(()=>e.input.focus(),80);});if(document.readyState!=='loading')bind();else document.addEventListener('DOMContentLoaded',bind,{once:true});
			})();
			</script>
			<?php
		}
	}

	Wumetax_Quick_Support_AI_v120::init();
}
