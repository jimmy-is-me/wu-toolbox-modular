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

	private static string $cache_file = '';
	private static string $request_url = '';
	private static bool $capturing = false;

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'serve_or_capture' ), -100 );
		add_action( 'send_headers', array( __CLASS__, 'apply_exclusion_headers' ), 1 );
		add_action( 'shutdown', array( __CLASS__, 'store_captured_page' ), 0 );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'register_admin_bar' ), 100 );
		add_action( 'admin_post_wutm_page_cache_save', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_wutm_page_cache_clear', array( __CLASS__, 'clear_from_request' ) );

		foreach ( array( 'save_post', 'deleted_post', 'switch_theme', 'wp_update_nav_menu', 'comment_post', 'transition_comment_status', 'woocommerce_order_status_changed', 'woocommerce_update_product' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'clear_on_content_change' ), 99 );
		}
	}

	private static function defaults(): array {
		return array(
			'ttl'          => 3600,
			'excluded_uri' => "/cart/\n/checkout/\n/my-account/",
		);
	}

	private static function settings(): array {
		$value = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
	}

	private static function cache_dir(): string {
		return trailingslashit( WP_CONTENT_DIR ) . 'cache/wutm-page-cache/';
	}

	public static function register_menu(): void {
		add_submenu_page( 'wu-toolbox-modular', '頁面快取', '頁面快取', 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
	}

	private static function request_is_cacheable(): bool {
		if ( is_admin() || is_user_logged_in() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_search() || is_404() || is_preview() || is_trackback() || post_password_required() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'GET' !== $method || ! empty( $_GET ) ) {
			return false;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		if ( preg_match( '#/(?:wp-admin|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-json)(?:/|$)#i', $uri ) ) {
			return false;
		}
		if ( self::is_excluded_uri( $uri ) ) {
			return false;
		}

		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}
		foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
			if ( preg_match( '/^(?:wordpress_logged_in_|wordpress_sec_|wp-postpass_|comment_author_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_)/', (string) $cookie_name ) ) {
				return false;
			}
		}
		return true;
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
		self::$request_url = $scheme . '://' . $host . $path;
		self::$cache_file  = self::cache_dir() . hash( 'sha256', $scheme . '|' . $host . '|' . $path ) . '.html.gz';
	}

	public static function serve_or_capture(): void {
		if ( ! self::request_is_cacheable() || ! function_exists( 'gzencode' ) ) {
			return;
		}
		self::resolve_request();
		$ttl = max( 60, absint( self::settings()['ttl'] ) );

		if ( is_readable( self::$cache_file ) && time() - (int) filemtime( self::$cache_file ) < $ttl ) {
			$compressed = file_get_contents( self::$cache_file );
			if ( false !== $compressed ) {
				header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
				header( 'Vary: Accept-Encoding', false );
				header( 'X-WUTM-Page-Cache: HIT' );
				$accepts_gzip = ! empty( $_SERVER['HTTP_ACCEPT_ENCODING'] ) && false !== stripos( wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ), 'gzip' );
				if ( $accepts_gzip ) {
					header( 'Content-Encoding: gzip' );
					header( 'Content-Length: ' . strlen( $compressed ) );
					echo $compressed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Stored compressed HTML response.
				} else {
					$decoded = gzdecode( $compressed );
					if ( false === $decoded ) {
						return;
					}
					echo $decoded; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Stored HTML response.
				}
				exit;
			}
		}

		self::$capturing = true;
		ob_start();
		header( 'X-WUTM-Page-Cache: MISS' );
	}

	public static function store_captured_page(): void {
		if ( ! self::$capturing || ! self::$cache_file || ob_get_level() < 1 || 200 !== http_response_code() ) {
			return;
		}
		$html = ob_get_contents();
		if ( ! is_string( $html ) || '' === trim( $html ) || false === stripos( $html, '<html' ) ) {
			return;
		}
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Set-Cookie:' )
				|| 0 === stripos( $header, 'Content-Encoding:' )
				|| ( 0 === stripos( $header, 'Content-Type:' ) && false === stripos( $header, 'text/html' ) )
				|| ( 0 === stripos( $header, 'Cache-Control:' ) && preg_match( '/(?:no-store|no-cache|private)/i', $header ) ) ) {
				return;
			}
		}

		$compressed = gzencode( $html, 6 );
		if ( false === $compressed || ! wp_mkdir_p( self::cache_dir() ) ) {
			return;
		}
		if ( false !== file_put_contents( self::$cache_file, $compressed, LOCK_EX ) ) {
			$meta = array( 'url' => self::$request_url, 'created' => time(), 'original_size' => strlen( $html ) );
			file_put_contents( self::$cache_file . '.json', wp_json_encode( $meta ), LOCK_EX );
		}
	}

	public static function clear_on_content_change(): void {
		self::clear_cache();
	}

	private static function clear_cache(): int {
		$root = wp_normalize_path( self::cache_dir() );
		if ( ! is_dir( $root ) ) {
			return 0;
		}
		$count = 0;
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$path = wp_normalize_path( $item->getPathname() );
			if ( 0 !== strpos( $path, $root ) ) {
				continue;
			}
			if ( $item->isDir() ) {
				@rmdir( $path );
			} elseif ( @unlink( $path ) ) {
				$count++;
			}
		}
		return $count;
	}

	public static function save_settings(): void {
		self::authorize_request();
		$settings = self::settings();
		if ( isset( $_POST['ttl'] ) ) {
			$settings['ttl'] = max( 60, min( WEEK_IN_SECONDS, absint( $_POST['ttl'] ) ) );
		}
		if ( isset( $_POST['excluded_uri'] ) ) {
			$paths = preg_split( '/\R/', sanitize_textarea_field( wp_unslash( $_POST['excluded_uri'] ) ) );
			$paths = array_values( array_unique( array_filter( array_map( 'trim', $paths ?: array() ) ) ) );
			$settings['excluded_uri'] = implode( "\n", $paths );
		}
		update_option( self::OPTION_KEY, $settings );
		self::clear_cache();
		$tab = isset( $_POST['return_tab'] ) ? sanitize_key( wp_unslash( $_POST['return_tab'] ) ) : 'overview';
		self::redirect_with_notice( 'saved', 0, $tab );
	}

	public static function clear_from_request(): void {
		self::authorize_request();
		$count = self::clear_cache();
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
		$bar->add_node( array( 'id' => 'wutm-clear-page-cache', 'title' => '<span class="ab-icon dashicons dashicons-update-alt" aria-hidden="true"></span><span class="ab-label">清除頁面快取</span>', 'href' => wp_nonce_url( admin_url( 'admin-post.php?action=wutm_page_cache_clear' ), self::NONCE ), 'meta' => array( 'title' => '清除 WU Toolbox 產生的所有頁面快取' ) ) );
	}

	private static function stats(): array {
		$stats = array( 'count' => 0, 'size' => 0, 'items' => array() );
		foreach ( glob( self::cache_dir() . '*.html.gz' ) ?: array() as $file ) {
			$stats['count']++;
			$stats['size'] += (int) filesize( $file );
			$meta = array();
			if ( is_readable( $file . '.json' ) ) {
				$meta = json_decode( (string) file_get_contents( $file . '.json' ), true );
			}
			$stats['items'][] = array( 'url' => esc_url_raw( $meta['url'] ?? '' ), 'size' => (int) filesize( $file ), 'time' => (int) filemtime( $file ) );
		}
		usort( $stats['items'], static function ( $a, $b ) { return $b['time'] <=> $a['time']; } );
		return $stats;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings     = self::settings();
		$stats        = self::stats();
		$tab          = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$allowed_tabs = array( 'overview', 'settings', 'exclusions' );
		if ( ! in_array( $tab, $allowed_tabs, true ) ) {
			$tab = 'overview';
		}
		$base_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		?>
		<div class="wrap wutm-module-wrap wutm-page-cache">
			<div class="wutm-cache-header"><div><h1>頁面快取</h1><p>為未登入訪客建立壓縮實體快取，動態及敏感頁面會自動略過。</p></div><a class="button button-primary wutm-cache-clear" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wutm_page_cache_clear' ), self::NONCE ) ); ?>" onclick="return confirm('確定清除所有頁面快取嗎？');"><span class="dashicons dashicons-update-alt" aria-hidden="true"></span>清除頁面快取</a></div>
			<?php if ( isset( $_GET['wutm_cache_notice'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo 'saved' === sanitize_key( wp_unslash( $_GET['wutm_cache_notice'] ) ) ? '設定已儲存，舊快取已清除。' : '頁面快取已清除。'; ?></p></div><?php endif; ?>
			<div class="wutm-cache-summary"><div><span>已快取頁面</span><strong><?php echo esc_html( (string) $stats['count'] ); ?></strong></div><div><span>磁碟使用量</span><strong><?php echo esc_html( size_format( $stats['size'], 2 ) ); ?></strong></div><div><span>引擎狀態</span><strong class="is-running"><i></i>運作中</strong></div><div><span>有效期限</span><strong><?php echo esc_html( (string) absint( $settings['ttl'] ) ); ?> 秒</strong></div></div>
			<nav class="nav-tab-wrapper wutm-cache-tabs" aria-label="頁面快取設定">
			<?php foreach ( array( 'overview' => '總覽', 'settings' => '快取設定', 'exclusions' => '免快取頁面' ) as $key => $label ) : ?><a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $key, $base_url ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
			</nav>

			<?php if ( 'overview' === $tab ) : ?>
				<section class="card wutm-cache-panel"><h2>最近快取頁面</h2><p class="description">顯示最近 100 筆由本模組建立的 GZIP 快取。</p><div class="wutm-cache-table"><table class="widefat striped"><thead><tr><th>網址</th><th>壓縮大小</th><th>建立時間</th></tr></thead><tbody><?php if ( ! $stats['items'] ) : ?><tr><td colspan="3">目前尚無快取頁面。請用未登入視窗瀏覽前台頁面後再重新整理。</td></tr><?php else : foreach ( array_slice( $stats['items'], 0, 100 ) as $item ) : ?><tr><td><?php if ( $item['url'] ) : ?><a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $item['url'] ); ?></a><?php else : ?>無法取得網址<?php endif; ?></td><td><?php echo esc_html( size_format( $item['size'], 2 ) ); ?></td><td><?php echo esc_html( wp_date( 'Y-m-d H:i', $item['time'] ) ); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
			<?php elseif ( 'settings' === $tab ) : ?>
				<section class="card wutm-cache-panel"><h2>快取效能設定</h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( self::NONCE ); ?><input type="hidden" name="action" value="wutm_page_cache_save"><input type="hidden" name="return_tab" value="settings"><div class="wutm-cache-field"><label for="wutm-cache-ttl">快取有效期限（秒）</label><input id="wutm-cache-ttl" type="number" min="60" max="<?php echo esc_attr( WEEK_IN_SECONDS ); ?>" name="ttl" value="<?php echo esc_attr( $settings['ttl'] ); ?>"><p>預設 3600 秒（1 小時），最長 7 天。儲存後會清除舊快取，以新期限重新建立。</p></div><?php submit_button( '儲存快取設定' ); ?></form><div class="wutm-cache-note"><strong>系統自動排除</strong><p>登入使用者、購物車、結帳、會員中心、搜尋、預覽、密碼保護內容、REST、帶查詢參數的網址，以及設定禁止快取標頭的回應。</p></div></section>
			<?php else : ?>
				<section class="card wutm-cache-panel"><h2>免快取頁面</h2><p>指定內容即時變動、不適合建立頁面快取的網址。原「免快取頁面」功能已整合至此，既有路徑會自動保留。</p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( self::NONCE ); ?><input type="hidden" name="action" value="wutm_page_cache_save"><input type="hidden" name="return_tab" value="exclusions"><div class="wutm-cache-field"><label for="wutm-cache-excluded">網址路徑</label><textarea id="wutm-cache-excluded" name="excluded_uri" rows="10" class="large-text code" placeholder="/dashboard/&#10;/member-area/&#10;/booking/"><?php echo esc_textarea( $settings['excluded_uri'] ); ?></textarea><p>每行一個網址片段，支援部分比對。例如 <code>/dashboard/</code> 也會排除其下層網址。</p></div><?php submit_button( '儲存免快取頁面' ); ?></form><div class="wutm-cache-examples"><strong>常見用途</strong><span>會員或客戶儀表板</span><span>即時預約與報名頁面</span><span>依訪客狀態變動的自訂頁面</span></div></section>
			<?php endif; ?>
		</div>
		<style>#wpadminbar #wp-admin-bar-wutm-clear-page-cache .ab-icon:before{content:"\f463";top:2px}.wutm-page-cache{max-width:1220px}.wutm-cache-header{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:26px 28px;background:#1d2327;color:#fff;border-radius:10px 10px 0 0}.wutm-cache-header h1{margin:0 0 7px;color:#fff}.wutm-cache-header p{margin:0;color:#c3c4c7}.wutm-cache-clear{display:inline-flex!important;align-items:center;gap:6px;white-space:nowrap}.wutm-cache-clear .dashicons{font-size:17px;width:17px;height:17px}.wutm-cache-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1px;background:#dcdcde;border:1px solid #dcdcde}.wutm-cache-summary>div{display:flex;flex-direction:column;gap:8px;padding:18px 22px;background:#fff}.wutm-cache-summary span{color:#646970}.wutm-cache-summary strong{font-size:20px}.wutm-cache-summary .is-running{display:inline-flex;align-items:center;gap:7px;color:#008a20}.wutm-cache-summary .is-running i{width:9px;height:9px;background:#00a32a;border-radius:50%;box-shadow:0 0 0 4px #e5f5e8}.wutm-cache-tabs{margin-top:24px}.wutm-cache-panel{box-sizing:border-box;max-width:none!important;margin:0!important;padding:26px!important;border-top:0!important;border-radius:0 0 8px 8px!important}.wutm-cache-panel h2{margin-top:0}.wutm-cache-table{overflow:auto;margin-top:18px}.wutm-cache-table td:first-child{min-width:360px;word-break:break-all}.wutm-cache-field{max-width:720px;padding:20px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px}.wutm-cache-field label{display:block;margin-bottom:10px;font-size:15px;font-weight:600}.wutm-cache-field input[type=number]{width:260px;max-width:100%}.wutm-cache-field textarea{display:block;width:100%;min-height:210px;resize:vertical}.wutm-cache-field p{margin:9px 0 0;color:#646970}.wutm-cache-note{max-width:720px;margin-top:24px;padding:16px 18px;border-left:4px solid #2271b1;background:#f0f6fc}.wutm-cache-note p{margin:6px 0 0}.wutm-cache-examples{display:flex;flex-wrap:wrap;gap:8px;max-width:720px;margin-top:22px}.wutm-cache-examples strong{width:100%}.wutm-cache-examples span{padding:6px 10px;border-radius:16px;background:#f0f0f1;color:#50575e}@media(max-width:782px){.wutm-cache-header{align-items:flex-start;flex-direction:column}.wutm-cache-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.wutm-cache-tabs{display:flex;overflow-x:auto}.wutm-cache-tabs .nav-tab{flex:0 0 auto;margin-left:0}.wutm-cache-panel{padding:18px!important}}@media(max-width:480px){.wutm-cache-summary{grid-template-columns:1fr}}</style>
		<?php
	}
}

WUTM_Page_Cache::init();
