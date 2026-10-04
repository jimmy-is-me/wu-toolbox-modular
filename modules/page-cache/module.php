<?php
/**
 * WU Toolbox Modular：頁面快取。
 *
 * 為未登入訪客建立安全的 GZIP 實體頁面快取，並排除 WordPress 與
 * WooCommerce 的登入、購物車、結帳、會員及其他動態請求。
 */

defined( 'ABSPATH' ) || exit;

final class WUTM_Page_Cache {
	private const OPTION_KEY = 'wutm_page_cache_settings';
	private const PAGE_SLUG  = 'wu-page-cache';
	private const NONCE      = 'wutm_page_cache_action';
	private const MAX_HTML_BYTES = 4194304;

	private static string $cache_file = '';
	private static string $request_url = '';
	private static string $device_variant = 'desktop';
	private static bool $capturing = false;
	private static bool $translation_buffer = false;
	private static string $language = '';
	private static string $generation = '';
	private static array $context = array();

	public static function init(): void {
		if ( is_admin() && current_user_can( 'manage_options' ) && ! get_option( 'wutm_page_cache_output_355', false ) ) {
			$count = self::clear_cache();
			self::record_invalidation( '升級多語言完整輸出快取引擎', $count, 'all' );
			update_option( 'wutm_page_cache_device_variants_264', 1, false );
			update_option( 'wutm_page_cache_output_354', 1, false );
			update_option( 'wutm_page_cache_output_355', 1, false );
			update_option( 'wutm_page_cache_safe_gzip_353', 1, false );
		}
		add_action( 'init', array( __CLASS__, 'prepare_translation_buffer' ), PHP_INT_MIN );
		// Allow canonical redirects and other plugins' privacy exclusions to run first.
		add_action( 'template_redirect', array( __CLASS__, 'serve_or_capture' ), PHP_INT_MAX );
		add_action( 'send_headers', array( __CLASS__, 'apply_exclusion_headers' ), 1 );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'register_admin_bar' ), 100 );
		add_action( 'admin_head', array( __CLASS__, 'admin_bar_styles' ) );
		add_action( 'wp_head', array( __CLASS__, 'admin_bar_styles' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'admin_post_wutm_page_cache_save', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_wutm_page_cache_clear', array( __CLASS__, 'clear_from_request' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_on_global_change' ), 99 );
		add_action( 'activated_plugin', array( __CLASS__, 'clear_on_global_change' ), 99 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'clear_on_global_change' ), 99 );
		if ( ! empty( self::settings()['auto_invalidate'] ) ) {
			add_action( 'save_post', array( __CLASS__, 'invalidate_post' ), 99, 3 );
			add_action( 'before_delete_post', array( __CLASS__, 'invalidate_post_before_delete' ), 99, 2 );
			add_action( 'set_object_terms', array( __CLASS__, 'invalidate_object_terms' ), 99, 6 );
			add_action( 'edited_term', array( __CLASS__, 'invalidate_term' ), 99, 3 );
			add_action( 'delete_term', array( __CLASS__, 'invalidate_term' ), 99, 3 );
			add_action( 'comment_post', array( __CLASS__, 'invalidate_comment_post' ), 99, 3 );
			add_action( 'transition_comment_status', array( __CLASS__, 'invalidate_comment_status' ), 99, 3 );
			add_action( 'switch_theme', array( __CLASS__, 'clear_on_global_change' ), 99 );
			add_action( 'customize_save_after', array( __CLASS__, 'clear_on_global_change' ), 99 );
			add_action( 'wp_update_nav_menu', array( __CLASS__, 'clear_on_global_change' ), 99 );
			add_action( 'update_option_sidebars_widgets', array( __CLASS__, 'clear_on_global_change' ), 99 );
			add_action( 'trp_save_editor_translations_regular_strings', array( __CLASS__, 'clear_on_translation_change' ), 99 );
			add_action( 'trp_save_editor_translations_gettext_strings', array( __CLASS__, 'clear_on_translation_change' ), 99 );
			add_action( 'update_option_trp_settings', array( __CLASS__, 'clear_on_translation_change' ), 99 );
			add_action( 'wutm_translation_dictionary_updated', array( __CLASS__, 'clear_on_translation_change' ), 99 );
		}
	}

	/** Capture outside TranslatePress's init buffer, after its translation is complete. */
	public static function prepare_translation_buffer(): void {
		if ( ! class_exists( 'TRP_Translate_Press' ) || is_admin() || is_user_logged_in() || wp_doing_ajax() || wp_doing_cron()
			|| 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! empty( $_GET ) ) return;
		self::$translation_buffer = true;
		ob_start( array( __CLASS__, 'store_captured_page' ), self::MAX_HTML_BYTES + 1 );
	}

	private static function defaults(): array {
		return array(
			'ttl'             => 3600,
			'auto_invalidate' => 1,
			'excluded_uri'    => "/cart/\n/checkout/\n/my-account/",
		);
	}

	private static function settings(): array {
		$value = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
	}

	private static function cache_dir(): string {
		return trailingslashit( WP_CONTENT_DIR ) . 'cache/wutm-page-cache/';
	}

	/** Coordinate cache reads/writes with cleanup of this module's cache directory. */
	private static function acquire_cache_lock( int $operation, bool $non_blocking = true ) {
		$directory = self::cache_dir();
		if ( is_link( rtrim( $directory, '/' ) ) || ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) ) {
			return false;
		}
		$handle = @fopen( $directory . '.wutm-cache.lock', 'c' );
		if ( false === $handle ) {
			return false;
		}
		$lock_flags = $operation | ( $non_blocking ? LOCK_NB : 0 );
		if ( ! @flock( $handle, $lock_flags ) ) {
			fclose( $handle );
			return false;
		}
		return $handle;
	}

	private static function release_cache_lock( $handle ): void {
		if ( is_resource( $handle ) ) {
			@flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	public static function register_menu(): void {
		add_submenu_page( 'wu-toolbox-modular', '頁面快取', '頁面快取', 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
	}

	private static function request_is_cacheable(): bool {
		return '' === self::request_exclusion();
	}

	private static function request_exclusion(): string {
		if ( is_admin() || is_user_logged_in() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_search() || is_404() || is_preview() || is_trackback() || post_password_required() ) {
			return 'dynamic-request';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest-request';
		}
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return 'do-not-cache';
		}
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) || isset( $_SERVER['PHP_AUTH_USER'] ) ) {
			return 'authorization-header';
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'GET' !== $method || ! empty( $_GET ) ) {
			return 'method-or-query';
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		if ( preg_match( '#/(?:wp-admin|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-json)(?:/|$)#i', $uri ) ) {
			return 'system-path';
		}
		if ( self::is_excluded_uri( $uri ) ) {
			return 'excluded-path';
		}


		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return 'commerce-page';
		}
		foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
			if ( preg_match( '/^(?:PHPSESSID|wutm_404_redirect_notice|wordpress_logged_in_|wordpress_sec_|wp-postpass_|comment_author_|woocommerce_items_in_cart|woocommerce_cart_hash|woocommerce_recently_viewed|wp_woocommerce_session_|edd_items_in_cart|edd_cart_token)/i', (string) $cookie_name ) ) {
				return 'private-cookie';
			}
		}
		return '';
	}

	private static function is_translatepress_translation( string $uri ): bool {
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return false;
		}
		$settings = get_option( 'trp_settings', array() );
		if ( ! is_array( $settings ) ) {
			return false;
		}
		$default = $settings['default-language'] ?? '';
		$published = $settings['publish-languages'] ?? $settings['translation-languages'] ?? array();
		if ( ! is_array( $published ) ) {
			return false;
		}
		global $TRP_LANGUAGE;
		if ( is_string( $TRP_LANGUAGE ) && '' !== $TRP_LANGUAGE && $TRP_LANGUAGE !== $default && in_array( $TRP_LANGUAGE, $published, true ) ) {
			return true;
		}
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home_path && '/' !== $home_path ) {
			$home_path = '/' . trim( $home_path, '/' );
			if ( 0 !== strpos( $path, $home_path . '/' ) ) {
				return false;
			}
			$path = substr( $path, strlen( $home_path ) );
		}
		$first_segment = strtolower( strtok( trim( $path, '/' ), '/' ) ?: '' );
		$slugs = isset( $settings['url-slugs'] ) && is_array( $settings['url-slugs'] ) ? $settings['url-slugs'] : array();
		foreach ( $published as $language ) {
			if ( ! is_string( $language ) || $language === $default ) {
				continue;
			}
			$slug = isset( $slugs[ $language ] ) ? trim( (string) $slugs[ $language ], '/' ) : strtolower( strtok( str_replace( '-', '_', $language ), '_' ) ?: '' );
			if ( '' !== $slug && $first_segment === strtolower( $slug ) ) {
				return true;
			}
		}
		return false;
	}

	private static function is_excluded_uri( string $uri ): bool {
		foreach ( preg_split( '/\R/', (string) self::settings()['excluded_uri'] ) as $excluded ) {
			$excluded = trim( $excluded );
			if ( '' !== $excluded && false !== stripos( $uri, $excluded ) ) {
				return true;
			}
		}
		return false;
	}

	/** Preserve the former No Cache Pages behavior for other server/plugin caches. */
	public static function apply_exclusion_headers(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		if ( ! self::is_excluded_uri( $uri ) || headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'Pragma: no-cache', true );
		header( 'Expires: 0', true );
	}

	private static function resolve_request(): void {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$host = preg_replace( '/[^a-z0-9.:-]/i', '', (string) $host );
		$path = wp_parse_url( $uri, PHP_URL_PATH ) ?: '/';
		$scheme = is_ssl() ? 'https' : 'http';
		self::$device_variant = self::detect_device_variant();
		global $TRP_LANGUAGE;
		$trp = class_exists( 'TRP_Translate_Press' ) ? get_option( 'trp_settings', array() ) : array();
		self::$language = is_string( $TRP_LANGUAGE ) ? sanitize_text_field( $TRP_LANGUAGE ) : (string) ( $trp['default-language'] ?? '' );
		self::$request_url = $scheme . '://' . $host . $path;
		self::$cache_file  = self::cache_dir() . hash( 'sha256', 'device-v3|' . self::$device_variant . '|' . self::$language . '|' . $scheme . '|' . $host . '|' . $path ) . '.html.gz';
	}

	private static function detect_device_variant(): string {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		if ( preg_match( '/(?:ipad|macintosh.*mobile|tablet|playbook|silk|kindle|kftt|kfapwi|android(?!.*mobile))/i', $user_agent ) ) {
			return 'tablet';
		}
		if ( preg_match( '/(?:iphone|ipod|android.*mobile|windows phone|blackberry|bb10|opera mini|mobile|webos)/i', $user_agent ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	public static function serve_or_capture(): void {
		$reason = self::request_exclusion() ?: self::response_exclusion();
		if ( ! function_exists( 'gzencode' ) || ! function_exists( 'gzdecode' ) ) $reason = 'gzip-unavailable';
		if ( headers_sent() ) $reason = 'headers-sent';
		if ( $reason ) {
			self::report_result( 'BYPASS', $reason );
			return;
		}
		self::resolve_request();
		$ttl = max( 60, absint( self::settings()['ttl'] ) );

		if ( is_readable( self::$cache_file ) && time() - (int) filemtime( self::$cache_file ) < $ttl ) {
			$cache_lock = self::acquire_cache_lock( LOCK_SH );
			$compressed = false;
			if ( false !== $cache_lock ) {
				clearstatcache( true, self::$cache_file );
				if ( is_readable( self::$cache_file ) && time() - (int) @filemtime( self::$cache_file ) < $ttl && ! is_link( self::$cache_file ) && filesize( self::$cache_file ) <= self::MAX_HTML_BYTES ) {
					$compressed = @file_get_contents( self::$cache_file );
				}
				self::release_cache_lock( $cache_lock );
			}
			$decoded = false !== $compressed ? @gzdecode( $compressed, self::MAX_HTML_BYTES ) : false;
			if ( is_string( $decoded ) && self::is_complete_html( $decoded ) ) {
				header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
				header( 'Vary: Accept-Encoding, User-Agent', false );
				self::report_result( 'HIT', 'cache-hit' );
				header( 'X-WUTM-Cache-Device: ' . self::$device_variant );
				// Stored HTML is already translated. Do not parse/translate it a second time.
				if ( class_exists( 'TRP_Translate_Press' ) ) add_filter( 'trp_stop_translating_page', '__return_true', PHP_INT_MAX );
				// Preserve v3.5.3: leave network compression to the existing web server.
				echo $decoded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated stored HTML.

				exit;
			}
		}

		if ( class_exists( 'TRP_Translate_Press' ) && ! self::$translation_buffer ) {
			self::report_result( 'BYPASS', 'translation-buffer-unavailable' );
			return;
		}
		$cache_lock = self::acquire_cache_lock( LOCK_SH );
		if ( false === $cache_lock ) {
			self::report_result( 'BYPASS', 'directory-or-lock' );
			return;
		}
		self::$generation = self::read_generation();
		self::release_cache_lock( $cache_lock );
		self::$context = self::current_cache_context();
		self::$capturing = true;
		// Our own final buffer receives the output of nested theme/WP buffers.
		// A size threshold bounds memory; streamed/partial output is never cached.
		if ( ! self::$translation_buffer ) ob_start( array( __CLASS__, 'store_captured_page' ), self::MAX_HTML_BYTES + 1 );
		self::report_result( 'MISS', 'awaiting-output' );
		header( 'X-WUTM-Cache-Device: ' . self::$device_variant );
		header( 'Vary: Accept-Encoding, User-Agent', false );
	}

	private static function response_exclusion(): string {
		if ( 200 !== http_response_code() ) return 'http-status';
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Set-Cookie:' )
				|| 0 === stripos( $header, 'Content-Encoding:' )
				|| 0 === stripos( $header, 'Content-Length:' )
				|| ( 0 === stripos( $header, 'Content-Type:' ) && false === stripos( $header, 'text/html' ) )
				|| ( 0 === stripos( $header, 'Vary:' ) && preg_match( '/(?:\*|\bCookie\b|\bAuthorization\b|\bAccept-Language\b)/i', $header ) )
				|| ( 0 === stripos( $header, 'Cache-Control:' ) && preg_match( '/(?:no-store|no-cache|private)/i', $header ) ) ) {
				return 'private-response';
			}
		}
		return '';
	}

	private static function is_complete_html( string $html ): bool {
		return strlen( $html ) <= self::MAX_HTML_BYTES && false !== stripos( $html, '<html' ) && false !== stripos( $html, '</html>' );
	}

	/** Output handlers must return the original response, even if caching fails. */
	public static function store_captured_page( string $html, int $phase ): string {
		if ( ! self::$capturing ) {
			self::$translation_buffer = false;
			return $html;
		}
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) || ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) ) {
			self::$capturing = false;
			try { self::report_result( 'BYPASS', 'streamed-output', false ); } catch ( Throwable $error ) { /* Keep streamed responses intact. */ }
			return $html;
		}
		self::$capturing = false;
		try {
			$reason = self::request_exclusion() ?: self::response_exclusion();
			$error = error_get_last();
			if ( connection_aborted() ) $reason = 'connection-aborted';
			if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) $reason = 'fatal-error';
			if ( ! self::is_complete_html( $html ) ) $reason = strlen( $html ) > self::MAX_HTML_BYTES ? 'large-output' : 'incomplete-html';
			if ( $reason ) {
				self::report_result( 'BYPASS', $reason, false );
				return $html;
			}
			self::persist_html( $html );
		} catch ( Throwable $error ) {
			// Cache failures must never break the visitor's page or flush other buffers.
			try { self::report_result( 'BYPASS', 'write-error', false ); } catch ( Throwable $ignored ) { /* Diagnostics are best effort. */ }
		}
		return $html;
	}

	private static function read_generation(): string {
		$file = self::cache_dir() . '.wutm-generation';
		return is_readable( $file ) ? (string) @file_get_contents( $file ) : '';
	}

	private static function rotate_generation(): void {
		$file = self::cache_dir() . '.wutm-generation';
		if ( ! is_link( $file ) ) @file_put_contents( $file, wp_generate_password( 24, false, false ), LOCK_EX );
	}

	/** Publish metadata and compressed HTML by rename, never a half-written response. */
	private static function persist_html( string $html ): void {
		$compressed = gzencode( $html, 6 );
		$metadata = wp_json_encode( array_merge( array( 'url' => self::$request_url, 'device' => self::$device_variant, 'language' => self::$language, 'created' => time(), 'original_size' => strlen( $html ) ), self::$context ) );
		if ( ! is_string( $compressed ) || ! is_string( $metadata ) ) {
			self::report_result( 'BYPASS', 'write-error', false );
			return;
		}
		$lock = self::acquire_cache_lock( LOCK_EX );
		if ( false === $lock ) {
			self::report_result( 'BYPASS', 'directory-or-lock', false );
			return;
		}
		$stored = false;
		try {
			if ( self::$generation === self::read_generation() ) {
				$stored = self::write_cache_atomically( $compressed, $metadata );
			}
		} finally { self::release_cache_lock( $lock ); }
		self::report_result( $stored ? 'STORED' : 'BYPASS', $stored ? 'cache-created' : 'write-or-invalidated', false );
	}

	/** Readers hold a shared lock; publish only finished cache and metadata files. */
	private static function write_cache_atomically( string $compressed, string $metadata ): bool {
		$cache_tmp = @tempnam( self::cache_dir(), '.wutm-' );
		$meta_tmp = @tempnam( self::cache_dir(), '.wutm-' );
		if ( false === $cache_tmp || false === $meta_tmp ) {
			if ( false !== $cache_tmp ) @unlink( $cache_tmp );
			if ( false !== $meta_tmp ) @unlink( $meta_tmp );
			return false;
		}
		$complete = @file_put_contents( $cache_tmp, $compressed ) === strlen( $compressed )
			&& @file_put_contents( $meta_tmp, $metadata ) === strlen( $metadata );
		$stored = $complete && @rename( $cache_tmp, self::$cache_file ) && @rename( $meta_tmp, self::$cache_file . '.json' );
		if ( ! $stored ) {
			@unlink( self::$cache_file );
			@unlink( self::$cache_file . '.json' );
		}
		if ( is_file( $cache_tmp ) ) @unlink( $cache_tmp );
		if ( is_file( $meta_tmp ) ) @unlink( $meta_tmp );
		return $stored;
	}

	/** One anonymous diagnostic sample, at most once per 30 s; no per-hit DB writes. */
	private static function report_result( string $status, string $reason, bool $send_header = true ): void {
		if ( is_admin() || is_user_logged_in() || wp_doing_ajax() || wp_doing_cron() ) return;
		if ( $send_header && ! headers_sent() ) {
			header( 'X-WUTM-Page-Cache: ' . $status );
			header( 'X-WUTM-Cache-Reason: ' . $reason );
		}
		$file = self::cache_dir() . '.wutm-last-result.json';
		if ( is_link( $file ) ) return;
		// MISS is provisional: only persist the final result or a genuine bypass/hit.
		if ( 'MISS' === $status || ( is_file( $file ) && time() - (int) @filemtime( $file ) < 30 ) ) return;
		$lock = self::acquire_cache_lock( LOCK_EX );
		if ( false === $lock ) return;
		try {
			clearstatcache( true, $file );
			if ( ! is_file( $file ) || time() - (int) @filemtime( $file ) >= 30 ) {
				@file_put_contents( $file, wp_json_encode( array( 'time' => time(), 'status' => $status, 'reason' => $reason ) ), LOCK_EX );
			}
		} finally { self::release_cache_lock( $lock ); }
	}

	private static function current_cache_context(): array {
		$post_id   = is_singular() ? get_queried_object_id() : 0;
		$post_type = $post_id ? get_post_type( $post_id ) : '';
		$terms     = array();
		$contexts  = array();

		if ( $post_id ) {
			$terms = wp_get_object_terms( $post_id, get_object_taxonomies( (string) $post_type ), array( 'fields' => 'tt_ids' ) );
			$terms = is_wp_error( $terms ) ? array() : array_map( 'absint', $terms );
			$contexts[] = 'singular';
		}
		if ( is_front_page() ) $contexts[] = 'front';
		if ( is_home() ) $contexts[] = 'home';
		if ( is_post_type_archive() ) {
			$archive_type = get_query_var( 'post_type' );
			foreach ( (array) $archive_type as $type ) $contexts[] = 'post_type:' . sanitize_key( $type );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$terms[] = absint( $term->term_taxonomy_id );
				$taxonomy = get_taxonomy( $term->taxonomy );
				if ( $taxonomy ) foreach ( (array) $taxonomy->object_type as $type ) $contexts[] = 'post_type:' . sanitize_key( $type );
			}
		}

		return array(
			'post_id'   => absint( $post_id ),
			'post_type' => sanitize_key( (string) $post_type ),
			'terms'     => array_values( array_unique( $terms ) ),
			'contexts'  => array_values( array_unique( $contexts ) ),
		);
	}

	public static function invalidate_post( int $post_id, WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
		self::invalidate_related( $post_id, $post->post_type, '儲存' . $post->post_type );
	}

	public static function invalidate_post_before_delete( int $post_id, WP_Post $post ): void {
		self::invalidate_related( $post_id, $post->post_type, '刪除' . $post->post_type );
	}

	public static function invalidate_object_terms( int $object_id ): void {
		$post_type = get_post_type( $object_id );
		if ( $post_type ) self::invalidate_related( $object_id, $post_type, '更新分類關聯' );
	}

	public static function invalidate_term( int $term_id, int $term_taxonomy_id, string $taxonomy ): void {
		$taxonomy_object = get_taxonomy( $taxonomy );
		self::invalidate_matching( 0, $taxonomy_object ? (array) $taxonomy_object->object_type : array(), array( $term_taxonomy_id ), '更新分類' );
	}

	public static function invalidate_comment_post( int $comment_id, $approved, array $comment_data ): void {
		$post_id = absint( $comment_data['comment_post_ID'] ?? 0 );
		if ( $post_id ) self::invalidate_related( $post_id, (string) get_post_type( $post_id ), '新增留言' );
	}

	public static function invalidate_comment_status( string $new_status, string $old_status, WP_Comment $comment ): void {
		if ( $comment->comment_post_ID ) self::invalidate_related( (int) $comment->comment_post_ID, (string) get_post_type( $comment->comment_post_ID ), '更新留言' );
	}

	public static function clear_on_global_change(): void {
		$count = self::clear_cache();
		self::record_invalidation( '全站外觀或導覽變更', $count, 'all' );
	}

	public static function clear_on_translation_change(): void {
		$count = self::clear_cache();
		self::record_invalidation( '翻譯內容或語言設定更新', $count, 'all' );
	}

	private static function invalidate_related( int $post_id, string $post_type, string $reason ): void {
		$terms = wp_get_object_terms( $post_id, get_object_taxonomies( $post_type ), array( 'fields' => 'tt_ids' ) );
		self::invalidate_matching( $post_id, array( $post_type ), is_wp_error( $terms ) ? array() : array_map( 'absint', $terms ), $reason );
	}

	private static function invalidate_matching( int $post_id, array $post_types, array $term_ids, string $reason ): void {
		$cache_lock = self::acquire_cache_lock( LOCK_EX, false );
		if ( false === $cache_lock ) {
			return;
		}
		$count = 0;
		self::rotate_generation();
		foreach ( glob( self::cache_dir() . '*.html.gz.json' ) ?: array() as $meta_file ) {
			if ( is_link( $meta_file ) ) continue;
			$meta = json_decode( (string) file_get_contents( $meta_file ), true );
			if ( ! is_array( $meta ) ) continue;
			$contexts = (array) ( $meta['contexts'] ?? array() );
			$matches_post = $post_id && $post_id === absint( $meta['post_id'] ?? 0 );
			$matches_type = false;
			foreach ( $post_types as $post_type ) {
				if ( in_array( 'post_type:' . sanitize_key( $post_type ), $contexts, true ) || ( 'post' === $post_type && in_array( 'home', $contexts, true ) ) ) {
					$matches_type = true;
					break;
				}
			}
			$matches_term = (bool) array_intersect( array_map( 'absint', (array) ( $meta['terms'] ?? array() ) ), $term_ids );
			$matches_front = in_array( 'front', $contexts, true );
			if ( ! $matches_post && ! $matches_type && ! $matches_term && ! $matches_front ) continue;

			$cache_file = substr( $meta_file, 0, -5 );
			if ( is_file( $cache_file ) && @unlink( $cache_file ) ) $count++;
			@unlink( $meta_file );
		}
		self::release_cache_lock( $cache_lock );
		self::record_invalidation( $reason, $count, 'related' );
	}

	private static function record_invalidation( string $reason, int $count, string $scope ): void {
		update_option( 'wutm_page_cache_last_invalidation', array( 'time' => time(), 'reason' => sanitize_text_field( $reason ), 'count' => $count, 'scope' => $scope ), false );
	}

	private static function clear_cache(): int {
		$root = wp_normalize_path( self::cache_dir() );
		if ( ! is_dir( $root ) || is_link( $root ) ) {
			return 0;
		}
		$cache_lock = self::acquire_cache_lock( LOCK_EX, false );
		if ( false === $cache_lock ) {
			return 0;
		}
		$count = 0;
		self::rotate_generation();
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$path = wp_normalize_path( $item->getPathname() );
			if ( 0 !== strpos( $path, $root ) || in_array( basename( $path ), array( '.wutm-cache.lock', '.wutm-generation' ), true ) || $item->isLink() ) {
				continue;
			}
			if ( $item->isDir() ) {
				@rmdir( $path );
			} elseif ( @unlink( $path ) && str_ends_with( $path, '.html.gz' ) ) {
				$count++;
			}
		}
		self::release_cache_lock( $cache_lock );
		return $count;
	}

	public static function save_settings(): void {
		self::authorize_request();
		$settings = self::settings();
		if ( isset( $_POST['ttl'] ) ) {
			$settings['ttl'] = max( 60, min( WEEK_IN_SECONDS, absint( $_POST['ttl'] ) ) );
		}
		if ( isset( $_POST['settings_form'] ) ) {
			$settings['auto_invalidate'] = isset( $_POST['auto_invalidate'] ) ? 1 : 0;
		}
		if ( isset( $_POST['excluded_uri'] ) ) {
			$paths = preg_split( '/\R/', sanitize_textarea_field( wp_unslash( $_POST['excluded_uri'] ) ) );
			$paths = array_values( array_unique( array_filter( array_map( 'trim', $paths ?: array() ) ) ) );
			$settings['excluded_uri'] = implode( "\n", $paths );
		}
		update_option( self::OPTION_KEY, $settings );
		$count = self::clear_cache();
		self::record_invalidation( '更新快取設定', $count, 'all' );
		$tab = isset( $_POST['return_tab'] ) ? sanitize_key( wp_unslash( $_POST['return_tab'] ) ) : 'overview';
		self::redirect_with_notice( 'saved', 0, $tab );
	}

	public static function clear_from_request(): void {
		self::authorize_request();
		$count = self::clear_cache();
		self::record_invalidation( '管理員手動清除', $count, 'all' );
		self::redirect_with_notice( 'cleared', $count );
	}

	private static function authorize_request(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '抱歉，您沒有管理頁面快取的權限。', 'wu-toolbox-modular' ) );
		}
		check_admin_referer( self::NONCE );
	}

	private static function redirect_with_notice( string $notice, int $count = 0, string $tab = '' ): void {
		$fallback = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		$target   = wp_get_referer() ?: $fallback;
		$args     = array( 'wutm_cache_notice' => $notice, 'wutm_cache_count' => $count );
		if ( $tab ) {
			$args['tab'] = $tab;
		}
		wp_safe_redirect( add_query_arg( $args, $target ) );
		exit;
	}

	public static function register_admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$bar->add_node( array( 'id' => 'wutm-clear-page-cache', 'title' => '<span class="ab-icon dashicons dashicons-update-alt" aria-hidden="true"></span><span class="ab-label">頁面快取</span>', 'href' => $url, 'meta' => array( 'title' => '查看頁面快取狀態與設定' ) ) );
		$bar->add_node( array( 'id' => 'wutm-page-cache-settings', 'parent' => 'wutm-clear-page-cache', 'title' => '狀態與設定', 'href' => $url ) );
		$bar->add_node( array( 'id' => 'wutm-page-cache-purge', 'parent' => 'wutm-clear-page-cache', 'title' => '清除所有頁面快取', 'href' => wp_nonce_url( admin_url( 'admin-post.php?action=wutm_page_cache_clear' ), self::NONCE ) ) );
	}

	public static function admin_assets( string $hook ): void {
		if ( current_user_can( 'manage_options' ) && str_ends_with( $hook, '_page_' . self::PAGE_SLUG ) ) {
			wp_enqueue_style( 'wutm-page-cache-admin', WUTM_URL . 'assets/css/page-cache-admin.css', array(), WUTM_VERSION );
		}
	}

	public static function admin_bar_styles(): void {
		if ( ! current_user_can( 'manage_options' ) || ( function_exists( 'is_admin_bar_showing' ) && ! is_admin_bar_showing() ) ) return;
		echo '<style>#wpadminbar #wp-admin-bar-wutm-clear-page-cache>.ab-item{display:flex!important;align-items:center!important;gap:5px}#wpadminbar #wp-admin-bar-wutm-clear-page-cache .ab-icon{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:18px!important;height:32px!important;margin:0!important;padding:0!important}#wpadminbar #wp-admin-bar-wutm-clear-page-cache .ab-icon:before{content:"\f463"!important;top:auto!important;font-size:17px!important}</style>';
	}

	private static function diagnostics( array $stats ): array {
		$directory_ready = is_dir( self::cache_dir() ) || wp_mkdir_p( self::cache_dir() );
		$write_ready = false;
		if ( $directory_ready && is_writable( self::cache_dir() ) ) {
			$cache_lock = self::acquire_cache_lock( LOCK_SH );
			if ( false !== $cache_lock ) {
				$probe = self::cache_dir() . '.wutm-write-test-' . wp_generate_password( 8, false, false );
				$write_ready = false !== @file_put_contents( $probe, 'ok', LOCK_EX );
				if ( $write_ready ) @unlink( $probe );
				self::release_cache_lock( $cache_lock );
			}
		}
		$latest = $stats['items'][0]['time'] ?? 0;
		$last_invalidation = get_option( 'wutm_page_cache_last_invalidation', array() );
		$result_file = self::cache_dir() . '.wutm-last-result.json';
		$result = is_readable( $result_file ) && ! is_link( $result_file ) ? json_decode( (string) @file_get_contents( $result_file ), true ) : array();
		return array(
			'gzip'             => function_exists( 'gzencode' ) && function_exists( 'gzdecode' ),
			'directory'        => $directory_ready && $write_ready,
			'latest'           => absint( $latest ),
			'last_invalidation'=> is_array( $last_invalidation ) ? $last_invalidation : array(),
			'last_result'      => is_array( $result ) ? $result : array(),
		);
	}

	private static function stats(): array {
		$stats = array( 'count' => 0, 'expired' => 0, 'size' => 0, 'items' => array() );
		if ( ! is_dir( self::cache_dir() ) || is_link( self::cache_dir() ) ) {
			return $stats;
		}
		$cache_lock = self::acquire_cache_lock( LOCK_SH, false );
		if ( false === $cache_lock ) {
			return $stats;
		}
		$ttl = max( 60, absint( self::settings()['ttl'] ) );
		foreach ( glob( self::cache_dir() . '*.html.gz' ) ?: array() as $file ) {
			if ( is_link( $file ) ) continue;
			$expired = time() - (int) filemtime( $file ) >= $ttl;
			$stats[ $expired ? 'expired' : 'count' ]++;
			$stats['size'] += (int) filesize( $file );
			$meta = array();
			if ( is_readable( $file . '.json' ) ) {
				$meta = json_decode( (string) file_get_contents( $file . '.json' ), true );
			}
			$meta = is_array( $meta ) ? $meta : array();
			$device = sanitize_key( (string) ( $meta['device'] ?? 'legacy' ) );
			$stats['items'][] = array( 'url' => esc_url_raw( (string) ( $meta['url'] ?? '' ) ), 'device' => $device, 'size' => (int) filesize( $file ), 'time' => (int) filemtime( $file ), 'expired' => $expired );
			// Bound dashboard memory; scanning the directory happens only on this admin page.
			if ( count( $stats['items'] ) > 100 ) {
				usort( $stats['items'], static function ( $a, $b ) { return $b['time'] <=> $a['time']; } );
				array_pop( $stats['items'] );
			}
		}
		usort( $stats['items'], static function ( $a, $b ) { return $b['time'] <=> $a['time']; } );
		self::release_cache_lock( $cache_lock );
		return $stats;
	}

	private static function reason_label( string $reason ): string {
		$labels = array(
			'cache-hit' => '已命中有效快取', 'cache-created' => '已成功寫入完整頁面',
			'authorization-header' => '請求帶有驗證資訊，不共用公開快取',
			'dynamic-request' => '登入、搜尋、預覽或其他動態請求不共用快取',
			'rest-request' => 'REST API 不建立頁面快取', 'do-not-cache' => '其他功能指定此頁不可快取',
			'method-or-query' => '非 GET 請求或網址帶查詢參數', 'system-path' => 'WordPress 系統網址',
			'excluded-path' => '符合免快取路徑', 'translation-buffer-unavailable' => '翻譯輸出尚未準備完成',
			'commerce-page' => '購物車、結帳或會員中心', 'private-cookie' => '訪客帶有購物車、登入或工作階段 Cookie',
			'private-response' => '回應有 Cookie、禁止快取、非 HTML 或自訂編碼／長度標頭',
			'http-status' => '回應不是 HTTP 200', 'headers-sent' => '頁面開始前已有內容輸出',
			'directory-or-lock' => '快取目錄無法寫入或正在清理，已略過等待',
			'streamed-output' => '串流、主動分段輸出或已清空的頁面不建立快取',
			'large-output' => '頁面超過 4 MB，為保護記憶體使用而略過',
			'incomplete-html' => '未取得完整 HTML 文件', 'fatal-error' => '頁面執行有致命錯誤',
			'connection-aborted' => '訪客連線中斷', 'gzip-unavailable' => 'PHP GZIP 函式不可用',
			'write-or-invalidated' => '寫入失敗或產生頁面期間已有內容更新／清除',
			'write-error' => '快取寫入失敗，訪客頁面仍照常顯示',
		);
		return $labels[ $reason ] ?? '尚無訪客診斷紀錄';
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$settings = self::settings();
		$stats = self::stats();
		$diagnostics = self::diagnostics( $stats );
		$healthy = $diagnostics['gzip'] && $diagnostics['directory'];
		$result = $diagnostics['last_result'];
		$last = $diagnostics['last_invalidation'];
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$tabs = array( 'overview' => '總覽', 'status' => '運作診斷', 'settings' => '快取設定', 'exclusions' => '免快取頁面' );
		if ( ! isset( $tabs[ $tab ] ) ) $tab = 'overview';
		$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$state = ! $healthy ? '需要處理' : ( $stats['count'] ? '已建立快取' : '等待建立' );
		?>
		<div class="wrap wutm-module-wrap wutm-page-cache">
			<h1>頁面快取</h1>
			<div class="wutm-cache-intro"><p>公開頁面更快，交易資料更安全。只快取未登入訪客可共用的完整頁面，不修改網站內容或資產。</p><a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wutm_page_cache_clear' ), self::NONCE ) ); ?>" onclick="return confirm('確定清除所有 WU 頁面快取嗎？');">清除所有頁面快取</a></div>
			<?php if ( isset( $_GET['wutm_cache_notice'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo 'saved' === sanitize_key( wp_unslash( $_GET['wutm_cache_notice'] ) ) ? '設定已儲存，舊快取已清除。' : '頁面快取已清除。'; ?></p></div><?php endif; ?>
			<div class="wutm-cache-summary">
				<div><span>有效快取版本</span><strong><?php echo esc_html( $stats['count'] ); ?></strong><small>同一頁分電腦／平板／手機版本</small></div>
				<div><span>磁碟使用量</span><strong><?php echo esc_html( size_format( $stats['size'], 2 ) ); ?></strong><small><?php echo esc_html( $stats['expired'] ); ?> 個已過期版本（再次瀏覽會重建）</small></div>
				<div><span>引擎狀態</span><strong class="wutm-cache-badge <?php echo $healthy ? 'is-ready' : 'has-error'; ?>"><?php echo esc_html( $state ); ?></strong><small><?php echo $healthy ? 'GZIP 與目錄寫入檢查通過' : '請查看運作診斷'; ?></small></div>
				<div><span>快取期限</span><strong><?php echo esc_html( absint( $settings['ttl'] ) ); ?> 秒</strong><small><?php echo ! empty( $settings['auto_invalidate'] ) ? '內容更新：自動清除相關頁面' : '內容更新：請手動清除'; ?></small></div>
			</div>
			<nav class="wutm-cache-tabs" aria-label="頁面快取設定"><?php foreach ( $tabs as $key => $label ) : ?><a class="<?php echo $tab === $key ? 'is-active' : ''; ?>" <?php echo $tab === $key ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( add_query_arg( 'tab', $key, $base_url ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav>
			<section class="wutm-cache-panel">
			<?php if ( 'overview' === $tab || 'status' === $tab ) : ?>
				<div class="wutm-cache-diagnostic <?php echo $healthy ? '' : 'has-error'; ?>">
					<h2><?php echo 'status' === $tab ? '最近訪客診斷' : '快取是否成功？'; ?></h2>
					<p><?php echo esc_html( self::reason_label( (string) ( $result['reason'] ?? '' ) ) ); ?></p>
					<?php if ( ! empty( $result['time'] ) ) : ?><small><?php echo esc_html( wp_date( 'Y-m-d H:i:s', absint( $result['time'] ) ) ); ?> · <?php echo esc_html( (string) ( $result['status'] ?? '' ) ); ?> · 每 30 秒至多一筆抽樣，不是即時訪客計數。</small><?php else : ?><small>若訪客瀏覽後仍無紀錄，請檢查其他快取、CDN 或伺服器是否在 WordPress 執行前就已回應。</small><?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if ( 'overview' === $tab ) : ?>
				<h2>最近快取頁面</h2><p class="description">最多顯示最近 100 個版本。這裡是 WU 實體快取檔案，不是瀏覽次數，也不包含 CDN 或其他外掛的快取。</p>
				<?php if ( ! $stats['items'] ) : ?><div class="wutm-cache-empty"><strong>目前沒有頁面快取檔案</strong><p>用新的無痕視窗（未登入、沒有購物車）開啟不帶「?」參數的公開頁面，再開啟同一頁。回到此頁重新整理，查看清單與運作診斷。</p><a class="button" href="<?php echo esc_url( add_query_arg( 'tab', 'status', $base_url ) ); ?>">查看診斷與排除原因</a></div>
				<?php else : $devices = array( 'desktop' => '電腦', 'tablet' => '平板', 'mobile' => '手機', 'legacy' => '舊版' ); ?>
				<div class="wutm-cache-table"><table class="widefat striped"><thead><tr><th>頁面網址</th><th>裝置／狀態</th><th>壓縮大小</th><th>建立時間</th></tr></thead><tbody>
				<?php foreach ( $stats['items'] as $item ) : ?><tr><td data-label="頁面網址"><?php if ( $item['url'] ) : ?><a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $item['url'] ); ?></a><?php else : ?>無法取得網址<?php endif; ?></td><td data-label="裝置／狀態"><?php echo esc_html( $devices[ $item['device'] ] ?? '其他' ); ?><small><?php echo $item['expired'] ? '已過期' : '有效'; ?></small></td><td data-label="壓縮大小"><?php echo esc_html( size_format( $item['size'], 2 ) ); ?></td><td data-label="建立時間"><?php echo esc_html( wp_date( 'Y-m-d H:i', $item['time'] ) ); ?></td></tr><?php endforeach; ?>
				</tbody></table></div><?php endif; ?>
			<?php elseif ( 'status' === $tab ) : ?>
				<h2>環境與安全檢查</h2><div class="wutm-cache-checks">
					<div><strong>GZIP 壓縮</strong><span><?php echo $diagnostics['gzip'] ? '可用：gzencode／gzdecode' : '不可用：請洽主機商啟用 PHP zlib'; ?></span></div>
					<div><strong>目錄讀寫</strong><span><?php echo $diagnostics['directory'] ? '已通過實際寫入測試' : '無法建立或寫入，或正被清理鎖定；請稍後重試或檢查權限'; ?></span></div>
					<div><strong>安全排除</strong><span>登入與交易頁仍排除；翻譯頁依語言分開快取</span></div>
					<div><strong>建立完整頁面</strong><span>巢狀輸出完成後才寫入；串流／不完整 HTML／超過 4 MB 會略過</span></div>
				</div>
				<dl class="wutm-cache-activity">
					<div><dt>最近建立</dt><dd><?php echo $diagnostics['latest'] ? esc_html( wp_date( 'Y-m-d H:i:s', $diagnostics['latest'] ) ) : '尚未建立'; ?></dd></div>
					<div><dt>最近清除</dt><dd><?php echo ! empty( $last['time'] ) ? esc_html( wp_date( 'Y-m-d H:i:s', absint( $last['time'] ) ) . '｜' . (string) ( $last['reason'] ?? '' ) . '｜' . absint( $last['count'] ?? 0 ) . ' 個版本' ) : '尚無紀錄'; ?></dd></div>
					<div><dt>快取目錄</dt><dd><code><?php echo esc_html( self::cache_dir() ); ?></code></dd></div>
				</dl>
				<div class="wutm-cache-note"><strong>驗證步驟</strong><ol><li>從管理列「頁面快取」進入此頁並清除舊快取。</li><li>新的無痕視窗開啟公開頁面（含 /en/ 等翻譯頁）兩次，不登入、不加入購物車、不加查詢參數。</li><li>瀏覽器網路面板第一次應為 <code>X-WUTM-Page-Cache: MISS</code>，第二次為 <code>HIT</code>；<code>BYPASS</code> 的原因見 <code>X-WUTM-Cache-Reason</code>。</li><li>若一直為 MISS，查看最近診斷與目錄讀寫；若完全沒有 WU 標頭與紀錄，檢查模組是否啟用，以及其他快取是否先回應。</li></ol></div>
			<?php elseif ( 'settings' === $tab ) : ?>
				<h2>快取效能設定</h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( self::NONCE ); ?><input type="hidden" name="action" value="wutm_page_cache_save"><input type="hidden" name="return_tab" value="settings"><input type="hidden" name="settings_form" value="1">
				<div class="wutm-cache-field"><label for="wutm-cache-ttl">快取有效期限（秒）</label><input id="wutm-cache-ttl" type="number" min="60" max="<?php echo esc_attr( WEEK_IN_SECONDS ); ?>" name="ttl" value="<?php echo esc_attr( $settings['ttl'] ); ?>"><p>預設 3600 秒（1 小時），可設定 60 秒至 7 天。儲存時清除舊快取。</p></div>
				<div class="wutm-cache-field"><label><input type="checkbox" name="auto_invalidate" value="1" <?php checked( ! empty( $settings['auto_invalidate'] ) ); ?>> 內容更新時自動清除相關快取</label><p>預設開啟。文章、頁面、商品、分類與留言更新會清除相關版本；選單、外觀或外掛更新會清除全部。關閉後仍保留外掛更新時的安全清除。</p></div>
				<?php submit_button( '儲存快取設定' ); ?></form><div class="wutm-cache-note"><strong>效能設計</strong><p>前台不載入本模組的 CSS／JS，不排程預熱、不輪詢、不逐次寫資料庫。只在此管理頁掃描統計；診斷只抽樣原因代碼，不記錄網址、IP 或 Cookie 值。</p></div>
			<?php else : ?>
				<h2>免快取頁面</h2><p>原有設定完整保留。公開翻譯頁已支援快取；若此處曾加入 /en/ 等翻譯路徑，請移除該行後清除快取。交易與個人化頁面仍應排除。</p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( self::NONCE ); ?><input type="hidden" name="action" value="wutm_page_cache_save"><input type="hidden" name="return_tab" value="exclusions"><div class="wutm-cache-field"><label for="wutm-cache-excluded">網址路徑（每行一個）</label><textarea id="wutm-cache-excluded" name="excluded_uri" rows="9" class="large-text code" placeholder="/dashboard/&#10;/booking/"><?php echo esc_textarea( $settings['excluded_uri'] ); ?></textarea><p>以網址片段比對，例如 <code>/dashboard/</code> 也會排除下層頁面。登入、結帳與購物車等敏感請求仍會自動排除。</p></div><?php submit_button( '儲存免快取頁面' ); ?></form>
			<?php endif; ?>
			</section>
		</div>
		<?php
	}
}

WUTM_Page_Cache::init();
