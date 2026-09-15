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
		foreach ( preg_split( '/\R/', (string) self::settings()['excluded_uri'] ) as $excluded ) {
			$excluded = trim( $excluded );
			if ( '' !== $excluded && false !== stripos( $uri, $excluded ) ) {
				return false;
			}
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
		$ttl = isset( $_POST['ttl'] ) ? max( 60, min( WEEK_IN_SECONDS, absint( $_POST['ttl'] ) ) ) : 3600;
		$excluded = isset( $_POST['excluded_uri'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excluded_uri'] ) ) : '';
		update_option( self::OPTION_KEY, array( 'ttl' => $ttl, 'excluded_uri' => $excluded ) );
		self::clear_cache();
		self::redirect_with_notice( 'saved' );
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

	private static function redirect_with_notice( string $notice, int $count = 0 ): void {
		$fallback = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		$target   = wp_get_referer() ?: $fallback;
		wp_safe_redirect( add_query_arg( array( 'wutm_cache_notice' => $notice, 'wutm_cache_count' => $count ), $target ) );
		exit;
	}

	public static function register_admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bar->add_node( array( 'id' => 'wutm-clear-page-cache', 'title' => '清除頁面快取', 'href' => wp_nonce_url( admin_url( 'admin-post.php?action=wutm_page_cache_clear' ), self::NONCE ), 'meta' => array( 'title' => '清除 WU Toolbox 產生的所有頁面快取' ) ) );
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
		$settings = self::settings();
		$stats    = self::stats();
		?>
		<div class="wrap wutm-module-wrap wutm-page-cache"><h1>頁面快取</h1><p class="wutm-module-subtitle">為未登入訪客建立壓縮實體快取；登入狀態、購物車、結帳、會員中心、查詢參數與 WordPress 動態請求會自動略過。</p>
		<?php if ( isset( $_GET['wutm_cache_notice'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo 'saved' === sanitize_key( wp_unslash( $_GET['wutm_cache_notice'] ) ) ? '設定已儲存，舊快取已清除。' : '頁面快取已清除。'; ?></p></div><?php endif; ?>
		<div class="wutm-cache-grid"><div>
			<div class="card"><h2>快取狀態</h2><div class="wutm-cache-stats"><div><span>已快取頁面</span><strong><?php echo esc_html( (string) $stats['count'] ); ?></strong></div><div><span>磁碟使用量</span><strong><?php echo esc_html( size_format( $stats['size'], 2 ) ); ?></strong></div><div><span>引擎狀態</span><strong class="is-running">運作中</strong></div></div></div>
			<div class="card"><h2>效能設定</h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( self::NONCE ); ?><input type="hidden" name="action" value="wutm_page_cache_save"><p><label for="wutm-cache-ttl"><strong>快取有效期限（秒）</strong></label></p><input id="wutm-cache-ttl" type="number" min="60" max="<?php echo esc_attr( WEEK_IN_SECONDS ); ?>" name="ttl" value="<?php echo esc_attr( $settings['ttl'] ); ?>" class="regular-text"><p class="description">預設 3600 秒。儲存設定時會自動清除既有快取。</p><p><label for="wutm-cache-excluded"><strong>額外排除網址</strong></label></p><textarea id="wutm-cache-excluded" name="excluded_uri" rows="6" class="large-text code"><?php echo esc_textarea( $settings['excluded_uri'] ); ?></textarea><p class="description">每行一個網址片段；符合的頁面不會建立或使用快取。</p><?php submit_button( '儲存設定', 'primary', 'submit', false ); ?></form></div>
			<div class="card"><h2>清除快取</h2><p>刪除本模組產生的快取檔案，訪客下次瀏覽時會重新建立。</p><a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wutm_page_cache_clear' ), self::NONCE ) ); ?>" onclick="return confirm('確定清除所有頁面快取嗎？');">清除所有快取</a></div>
		</div><div class="card wutm-cache-list"><h2>最近快取頁面</h2><table class="widefat striped"><thead><tr><th>網址</th><th>壓縮大小</th><th>建立時間</th></tr></thead><tbody><?php if ( ! $stats['items'] ) : ?><tr><td colspan="3">目前尚無快取頁面。</td></tr><?php else : foreach ( array_slice( $stats['items'], 0, 100 ) as $item ) : ?><tr><td><?php if ( $item['url'] ) : ?><a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $item['url'] ); ?></a><?php else : ?>無法取得網址<?php endif; ?></td><td><?php echo esc_html( size_format( $item['size'], 2 ) ); ?></td><td><?php echo esc_html( wp_date( 'Y-m-d H:i', $item['time'] ) ); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></div></div>
		<style>.wutm-cache-grid{display:grid;grid-template-columns:360px minmax(0,1fr);gap:20px;max-width:1200px;margin-top:20px}.wutm-cache-grid>.card,.wutm-cache-grid>div>.card{max-width:none;margin:0 0 20px;padding:20px}.wutm-cache-stats{display:grid;gap:12px}.wutm-cache-stats>div{display:flex;align-items:center;justify-content:space-between;padding:12px;background:#f6f7f7;border-radius:6px}.wutm-cache-stats span{color:#50575e}.wutm-cache-stats strong{font-size:18px}.wutm-cache-stats .is-running{padding:4px 10px;border-radius:20px;background:#00a32a;color:#fff;font-size:13px}.wutm-cache-list{overflow:auto}.wutm-cache-list td:first-child{word-break:break-all}@media(max-width:900px){.wutm-cache-grid{grid-template-columns:1fr}}</style></div>
		<?php
	}
}

WUTM_Page_Cache::init();
