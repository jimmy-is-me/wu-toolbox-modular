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
		add_action( 'admin_head', array( __CLASS__, 'admin_bar_styles' ) );
		add_action( 'admin_post_wutm_page_cache_save', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_wutm_page_cache_clear', array( __CLASS__, 'clear_from_request' ) );
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
			$meta = array_merge( array( 'url' => self::$request_url, 'created' => time(), 'original_size' => strlen( $html ) ), self::current_cache_context() );
			file_put_contents( self::$cache_file . '.json', wp_json_encode( $meta ), LOCK_EX );
		}
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

	private static function invalidate_related( int $post_id, string $post_type, string $reason ): void {
		$terms = wp_get_object_terms( $post_id, get_object_taxonomies( $post_type ), array( 'fields' => 'tt_ids' ) );
		self::invalidate_matching( $post_id, array( $post_type ), is_wp_error( $terms ) ? array() : array_map( 'absint', $terms ), $reason );
	}

	private static function invalidate_matching( int $post_id, array $post_types, array $term_ids, string $reason ): void {
		$count = 0;
		foreach ( glob( self::cache_dir() . '*.html.gz.json' ) ?: array() as $meta_file ) {
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
		self::record_invalidation( $reason, $count, 'related' );
	}

	private static function record_invalidation( string $reason, int $count, string $scope ): void {
		update_option( 'wutm_page_cache_last_invalidation', array( 'time' => time(), 'reason' => sanitize_text_field( $reason ), 'count' => $count, 'scope' => $scope ), false );
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
		$bar->add_node( array( 'id' => 'wutm-clear-page-cache', 'title' => '<span class="ab-icon dashicons dashicons-update-alt" aria-hidden="true"></span><span class="ab-label">清除頁面快取</span>', 'href' => wp_nonce_url( admin_url( 'admin-post.php?action=wutm_page_cache_clear' ), self::NONCE ), 'meta' => array( 'title' => '清除 WU Toolbox 產生的所有頁面快取' ) ) );
	}

	public static function admin_bar_styles(): void {
		if ( ! current_user_can( 'manage_options' ) ) return;
		echo '<style>#wpadminbar #wp-admin-bar-wutm-clear-page-cache>.ab-item{display:flex!important;align-items:center!important;gap:5px}#wpadminbar #wp-admin-bar-wutm-clear-page-cache .ab-icon{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:18px!important;height:32px!important;margin:0!important;padding:0!important}#wpadminbar #wp-admin-bar-wutm-clear-page-cache .ab-icon:before{content:"\f463"!important;top:auto!important;font-size:17px!important}</style>';
	}

	private static function diagnostics( array $stats ): array {
		$directory_ready = is_dir( self::cache_dir() ) || wp_mkdir_p( self::cache_dir() );
		$write_ready = false;
		if ( $directory_ready && is_writable( self::cache_dir() ) ) {
			$probe = self::cache_dir() . '.wutm-write-test-' . wp_generate_password( 8, false, false );
			$write_ready = false !== @file_put_contents( $probe, 'ok', LOCK_EX );
			if ( $write_ready ) @unlink( $probe );
		}
		$latest = $stats['items'][0]['time'] ?? 0;
		$last_invalidation = get_option( 'wutm_page_cache_last_invalidation', array() );
		return array(
			'gzip'             => function_exists( 'gzencode' ) && function_exists( 'gzdecode' ),
			'directory'        => $directory_ready && $write_ready,
			'latest'           => absint( $latest ),
			'last_invalidation'=> is_array( $last_invalidation ) ? $last_invalidation : array(),
		);
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
		$diagnostics  = self::diagnostics( $stats );
		$tab          = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$allowed_tabs = array( 'overview', 'status', 'settings', 'exclusions' );
		if ( ! in_array( $tab, $allowed_tabs, true ) ) {
			$tab = 'overview';
		}
		$base_url = add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) );
		?>
		<div class="wrap wutm-module-wrap wutm-page-cache">
			<h1>頁面快取</h1>
			<div class="wutm-cache-intro"><p class="wutm-module-subtitle">為未登入訪客建立壓縮實體快取，動態及敏感頁面會自動略過。</p><a class="button button-primary wutm-cache-clear" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wutm_page_cache_clear' ), self::NONCE ) ); ?>" onclick="return confirm('確定清除所有頁面快取嗎？');"><span class="dashicons dashicons-update-alt" aria-hidden="true"></span><span>清除頁面快取</span></a></div>
			<?php if ( isset( $_GET['wutm_cache_notice'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo 'saved' === sanitize_key( wp_unslash( $_GET['wutm_cache_notice'] ) ) ? '設定已儲存，舊快取已清除。' : '頁面快取已清除。'; ?></p></div><?php endif; ?>
			<div class="wutm-cache-summary"><div><span>已快取頁面</span><strong><?php echo esc_html( (string) $stats['count'] ); ?></strong></div><div><span>磁碟使用量</span><strong><?php echo esc_html( size_format( $stats['size'], 2 ) ); ?></strong></div><div><span>引擎狀態</span><strong class="<?php echo $diagnostics['gzip'] && $diagnostics['directory'] ? 'is-running' : 'is-warning'; ?>"><i></i><?php echo $diagnostics['gzip'] && $diagnostics['directory'] ? '運作中' : '需要處理'; ?></strong></div><div><span>有效期限</span><strong><?php echo esc_html( (string) absint( $settings['ttl'] ) ); ?> 秒</strong></div></div>
			<nav class="nav-tab-wrapper wutm-cache-tabs" aria-label="頁面快取設定">
			<?php foreach ( array( 'overview' => '總覽', 'status' => '運作狀態', 'settings' => '快取設定', 'exclusions' => '免快取頁面' ) as $key => $label ) : ?><a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $key, $base_url ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
			</nav>

			<?php if ( 'overview' === $tab ) : ?>
				<section class="card wutm-cache-panel"><h2>最近快取頁面</h2><p class="description">顯示最近 100 筆由本模組建立的 GZIP 快取。</p><div class="wutm-cache-table"><table class="widefat striped"><thead><tr><th>網址</th><th>壓縮大小</th><th>建立時間</th></tr></thead><tbody><?php if ( ! $stats['items'] ) : ?><tr><td colspan="3">目前尚無快取頁面。請用未登入視窗瀏覽前台頁面後再重新整理。</td></tr><?php else : foreach ( array_slice( $stats['items'], 0, 100 ) as $item ) : ?><tr><td><?php if ( $item['url'] ) : ?><a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $item['url'] ); ?></a><?php else : ?>無法取得網址<?php endif; ?></td><td><?php echo esc_html( size_format( $item['size'], 2 ) ); ?></td><td><?php echo esc_html( wp_date( 'Y-m-d H:i', $item['time'] ) ); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
			<?php elseif ( 'status' === $tab ) :
				$last = $diagnostics['last_invalidation'];
				$healthy = $diagnostics['gzip'] && $diagnostics['directory'];
				?>
				<section class="card wutm-cache-panel"><div class="wutm-cache-health <?php echo $healthy ? 'is-healthy' : 'has-error'; ?>"><span class="dashicons <?php echo $healthy ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span><div><h2><?php echo $healthy ? '頁面快取可正常使用' : '頁面快取需要處理'; ?></h2><p><?php echo $healthy ? ( $stats['count'] ? '已成功建立快取檔案，訪客再次瀏覽相同頁面時可直接使用。' : '環境檢查正常，目前正等待未登入訪客瀏覽可快取頁面。' ) : '請依下方檢查結果修正伺服器環境。'; ?></p></div></div><div class="wutm-cache-checks"><div><span class="dashicons <?php echo $diagnostics['gzip'] ? 'dashicons-yes-alt' : 'dashicons-no-alt'; ?>"></span><strong>GZIP 壓縮</strong><small><?php echo $diagnostics['gzip'] ? 'gzencode 與 gzdecode 可用' : 'PHP GZIP 函式不可用'; ?></small></div><div><span class="dashicons <?php echo $diagnostics['directory'] ? 'dashicons-yes-alt' : 'dashicons-no-alt'; ?>"></span><strong>快取目錄</strong><small><?php echo $diagnostics['directory'] ? '目錄可建立、寫入與讀取' : 'wp-content/cache 無法寫入'; ?></small></div><div><span class="dashicons dashicons-shield-alt"></span><strong>動態頁保護</strong><small>登入、購物車、結帳及會員頁自動略過</small></div><div><span class="dashicons dashicons-update"></span><strong>精準自動清除</strong><small>內容更新只清除單頁、首頁與相關列表</small></div></div><div class="wutm-cache-activity"><h3>最近活動</h3><dl><div><dt>最近建立快取</dt><dd><?php echo $diagnostics['latest'] ? esc_html( wp_date( 'Y-m-d H:i:s', $diagnostics['latest'] ) ) : '尚未建立'; ?></dd></div><div><dt>最近自動／手動清除</dt><dd><?php echo ! empty( $last['time'] ) ? esc_html( wp_date( 'Y-m-d H:i:s', absint( $last['time'] ) ) . '｜' . (string) ( $last['reason'] ?? '' ) . '｜清除 ' . absint( $last['count'] ?? 0 ) . ' 頁' ) : '尚無紀錄'; ?></dd></div><div><dt>快取目錄</dt><dd><code><?php echo esc_html( self::cache_dir() ); ?></code></dd></div></dl></div></section>
			<?php elseif ( 'settings' === $tab ) : ?>
				<section class="card wutm-cache-panel"><h2>快取效能設定</h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( self::NONCE ); ?><input type="hidden" name="action" value="wutm_page_cache_save"><input type="hidden" name="return_tab" value="settings"><div class="wutm-cache-field"><label for="wutm-cache-ttl">快取有效期限（秒）</label><input id="wutm-cache-ttl" type="number" min="60" max="<?php echo esc_attr( WEEK_IN_SECONDS ); ?>" name="ttl" value="<?php echo esc_attr( $settings['ttl'] ); ?>"><p>預設 3600 秒（1 小時），最長 7 天。儲存後會清除舊快取，以新期限重新建立。</p></div><?php submit_button( '儲存快取設定' ); ?></form><div class="wutm-cache-note"><strong>系統自動排除</strong><p>登入使用者、購物車、結帳、會員中心、搜尋、預覽、密碼保護內容、REST、帶查詢參數的網址，以及設定禁止快取標頭的回應。</p></div></section>
			<?php else : ?>
				<section class="card wutm-cache-panel"><h2>免快取頁面</h2><p>指定內容即時變動、不適合建立頁面快取的網址。原「免快取頁面」功能已整合至此，既有路徑會自動保留。</p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( self::NONCE ); ?><input type="hidden" name="action" value="wutm_page_cache_save"><input type="hidden" name="return_tab" value="exclusions"><div class="wutm-cache-field"><label for="wutm-cache-excluded">網址路徑</label><textarea id="wutm-cache-excluded" name="excluded_uri" rows="10" class="large-text code" placeholder="/dashboard/&#10;/member-area/&#10;/booking/"><?php echo esc_textarea( $settings['excluded_uri'] ); ?></textarea><p>每行一個網址片段，支援部分比對。例如 <code>/dashboard/</code> 也會排除其下層網址。</p></div><?php submit_button( '儲存免快取頁面' ); ?></form><div class="wutm-cache-examples"><strong>常見用途</strong><span>會員或客戶儀表板</span><span>即時預約與報名頁面</span><span>依訪客狀態變動的自訂頁面</span></div></section>
			<?php endif; ?>
		</div>
		<style>.wutm-page-cache{max-width:1220px}.wutm-cache-intro{display:flex;align-items:center;justify-content:space-between;gap:24px;margin-bottom:22px}.wutm-cache-intro .wutm-module-subtitle{margin:0!important}.wutm-cache-clear{display:inline-flex!important;align-items:center!important;justify-content:center;gap:6px;min-height:36px;white-space:nowrap}.wutm-cache-clear .dashicons{display:inline-flex;align-items:center;justify-content:center;font-size:17px;line-height:1;width:17px;height:17px;margin:0}.wutm-cache-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1px;background:#dcdcde;border:1px solid #dcdcde;border-radius:8px;overflow:hidden}.wutm-cache-summary>div{display:flex;flex-direction:column;gap:8px;padding:18px 22px;background:#fff}.wutm-cache-summary span{color:#646970}.wutm-cache-summary strong{font-size:20px}.wutm-cache-summary .is-running,.wutm-cache-summary .is-warning{display:inline-flex;align-items:center;gap:8px;width:max-content;padding:5px 10px;border-radius:999px;color:#fff;font-size:14px}.wutm-cache-summary .is-running{background:#00a32a}.wutm-cache-summary .is-warning{background:#dba617}.wutm-cache-summary .is-running i,.wutm-cache-summary .is-warning i{width:8px;height:8px;background:#fff;border-radius:50%}.wutm-cache-summary .is-running i{animation:wutm-cache-pulse 1.6s ease-out infinite}@keyframes wutm-cache-pulse{0%{box-shadow:0 0 0 0 #fff9}70%{box-shadow:0 0 0 7px #fff0}100%{box-shadow:0 0 0 0 #fff0}}.wutm-cache-tabs{margin-top:24px}.wutm-cache-panel{box-sizing:border-box;max-width:none!important;margin:0!important;padding:26px!important;border-top:0!important;border-radius:0 0 8px 8px!important}.wutm-cache-panel h2{margin-top:0}.wutm-cache-table{overflow:auto;margin-top:18px}.wutm-cache-table td:first-child{min-width:360px;word-break:break-all}.wutm-cache-field{max-width:720px;padding:20px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px}.wutm-cache-field label{display:block;margin-bottom:10px;font-size:15px;font-weight:600}.wutm-cache-field input[type=number]{width:260px;max-width:100%}.wutm-cache-field textarea{display:block;width:100%;min-height:210px;resize:vertical}.wutm-cache-field p{margin:9px 0 0;color:#646970}.wutm-cache-note{max-width:720px;margin-top:24px;padding:16px 18px;border-left:4px solid #2271b1;background:#f0f6fc}.wutm-cache-note p{margin:6px 0 0}.wutm-cache-examples{display:flex;flex-wrap:wrap;gap:8px;max-width:720px;margin-top:22px}.wutm-cache-examples strong{width:100%}.wutm-cache-examples span{padding:6px 10px;border-radius:16px;background:#f0f0f1;color:#50575e}.wutm-cache-health{display:flex;align-items:center;gap:16px;padding:18px 20px;border-radius:8px}.wutm-cache-health.is-healthy{background:#edfaef;border:1px solid #b8ddb9}.wutm-cache-health.has-error{background:#fcf0f1;border:1px solid #e6b8bb}.wutm-cache-health>.dashicons{width:34px;height:34px;font-size:34px}.wutm-cache-health.is-healthy>.dashicons{color:#00a32a}.wutm-cache-health.has-error>.dashicons{color:#d63638}.wutm-cache-health h2,.wutm-cache-health p{margin:0}.wutm-cache-health p{margin-top:5px;color:#50575e}.wutm-cache-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:20px}.wutm-cache-checks>div{display:grid;grid-template-columns:26px 1fr;gap:3px 9px;padding:16px;border:1px solid #dcdcde;border-radius:8px;background:#fff}.wutm-cache-checks .dashicons{grid-row:1/3;color:#2271b1}.wutm-cache-checks small{color:#646970}.wutm-cache-activity{margin-top:24px}.wutm-cache-activity dl{margin:0;border:1px solid #dcdcde;border-radius:8px;overflow:hidden}.wutm-cache-activity dl>div{display:grid;grid-template-columns:190px 1fr;border-bottom:1px solid #dcdcde}.wutm-cache-activity dl>div:last-child{border-bottom:0}.wutm-cache-activity dt,.wutm-cache-activity dd{margin:0;padding:12px 15px}.wutm-cache-activity dt{font-weight:600;background:#f6f7f7}.wutm-cache-activity dd{word-break:break-all}@media(max-width:782px){.wutm-cache-intro{align-items:flex-start;flex-direction:column}.wutm-cache-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.wutm-cache-tabs{display:flex;overflow-x:auto}.wutm-cache-tabs .nav-tab{flex:0 0 auto;margin-left:0}.wutm-cache-panel{padding:18px!important}.wutm-cache-checks{grid-template-columns:1fr}.wutm-cache-activity dl>div{grid-template-columns:1fr}.wutm-cache-activity dd{padding-top:0}}@media(max-width:480px){.wutm-cache-summary{grid-template-columns:1fr}}@media(prefers-reduced-motion:reduce){.wutm-cache-summary .is-running i{animation:none}}</style>
		<?php
	}
}

WUTM_Page_Cache::init();
