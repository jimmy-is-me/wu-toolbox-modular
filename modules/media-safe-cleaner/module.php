<?php
/** Media safety cleaner adapted from the supplied WUMETAX v2.2.0 reference. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! is_admin() ) { return; }

final class WUTM_Media_Safe_Cleaner {
	private static $page_hook = '';
	const VERSION     = '3.7.5';
	const SLUG        = 'wu-media-safe-cleaner';
	const MAX_RESULTS = 15000;
	const PER_PAGE    = 48;
	const BATCH_SIZE  = 20;
	const EXPIRES     = HOUR_IN_SECONDS;

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_wutm_mc_create_preview', array( __CLASS__, 'create_preview' ) );
		add_action( 'admin_post_wutm_mc_quick_folder', array( __CLASS__, 'quick_folder' ) );
		add_action( 'admin_post_wutm_mc_download_csv', array( __CLASS__, 'download_csv' ) );
		add_action( 'wp_ajax_wutm_mc_delete_images', array( __CLASS__, 'delete_images' ) );
	}

	private static function can_access() {
		return current_user_can( 'manage_options' );
	}

	/** A failed reference lookup must never be interpreted as an unused image. */
	private static function db_read( $method, $sql ) {
		global $wpdb;
		$result = $wpdb->$method( $sql );
		if ( ! empty( $wpdb->last_error ) ) {
			throw new RuntimeException( '資料庫檢查未完成，已停止處理；請確認資料庫狀態後重新預覽。' );
		}
		return $result;
	}
	/** WordPress owns deletion; refuse local paths outside this site's uploads. */
	private static function safe_attachment_paths( $id ) {
		$uploads = wp_upload_dir( null, false );
		$root = empty( $uploads['error'] ) ? realpath( $uploads['basedir'] ) : false;
		$file = get_attached_file( $id, true );
		if ( ! $root || ! is_string( $file ) || ! $file ) return false;
		$root = rtrim( wp_normalize_path( $root ), '/' ) . '/';
		$filtered_file = get_attached_file( $id );
		if ( ! is_string( $filtered_file ) || ! $filtered_file ) return false;
		$paths = array( $file, $filtered_file );
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();
		if ( ! empty( $meta['file'] ) && ! is_string( $meta['file'] ) ) return false;
		$meta_file = ! empty( $meta['file'] ) && is_string( $meta['file'] ) ? $uploads['basedir'] . '/' . $meta['file'] : $file;
		$paths[] = $meta_file;
		$names = array();
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( ! is_array( $size ) ) return false;
			if ( ! empty( $size['file'] ) ) $names[] = $size['file'];
		}
		foreach ( array( 'thumb', 'original_image', 'source_image', 'animated_video', 'animated_video_poster' ) as $key ) {
			if ( ! empty( $meta[$key] ) ) $names[] = $meta[$key];
		}
		foreach ( (array) ( get_post_meta( $id, '_wp_attachment_backup_sizes', true ) ?: array() ) as $size ) {
			if ( ! is_array( $size ) ) return false;
			if ( is_array( $size ) && ! empty( $size['file'] ) ) $names[] = $size['file'];
		}
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || wp_basename( $name ) !== $name || strpos( $name, '\\' ) !== false ) return false;
			$paths[] = dirname( $file ) . '/' . $name;
			$paths[] = dirname( $filtered_file ) . '/' . $name;
			$paths[] = dirname( $meta_file ) . '/' . $name;
		}
		foreach ( $paths as $path ) {
			if ( strpos( $path, '://' ) !== false ) return false;
			if ( is_dir( $path ) ) return false;
			$exists = file_exists( $path );
			$resolved = $exists ? realpath( $path ) : realpath( dirname( $path ) );
			if ( ! $resolved ) return false;
			$resolved = wp_normalize_path( $resolved );
			if ( ! $exists ) $resolved = rtrim( $resolved, '/' ) . '/';
			if ( DIRECTORY_SEPARATOR === '\\' ) {
				if ( strpos( strtolower( $resolved ), strtolower( $root ) ) !== 0 ) return false;
			} elseif ( strpos( $resolved, $root ) !== 0 ) return false;
		}
		return true;
	}

	private static function transient_key() {
		return 'wutm_mc_preview_' . get_current_user_id();
	}

	private static function admin_link( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function read_preview( $token ) {
		$stored = get_transient( self::transient_key() );
		if ( ! is_array( $stored ) || empty( $token ) || empty( $stored['token'] ) ) {
			return null;
		}
		return hash_equals( (string) $stored['token'], (string) $token ) ? $stored : null;
	}

	public static function menu() {
		self::$page_hook = add_submenu_page( 'wu-toolbox-modular', '媒體安全清理', '媒體安全清理', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( $hook ) {
		if ( self::$page_hook !== $hook || ! self::$page_hook || ! self::can_access() ) {
			return;
		}

		wp_register_style( 'wutm-mc-admin-inline', false, array(), self::VERSION );
		wp_enqueue_style( 'wutm-mc-admin-inline' );
		wp_add_inline_style( 'wutm-mc-admin-inline', self::get_inline_css() );

		wp_register_script( 'wutm-mc-admin-inline', false, array(), self::VERSION, true );
		wp_enqueue_script( 'wutm-mc-admin-inline' );
		wp_add_inline_script(
			'wutm-mc-admin-inline',
			'window.WUTM_MC=' . wp_json_encode(
				array(
					'ajax'    => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wutm_mc_delete_images' ),
					'batch'   => self::BATCH_SIZE,
					'confirm' => '永久刪除',
				)
			) . ';' . "\n" . self::get_inline_js()
		);
	}

	private static function check_date( $raw ) {
		$date = trim( (string) $raw );
		if ( '' === $date ) {
			return '';
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			throw new RuntimeException( '日期格式有誤，請重新輸入。' );
		}
		return $date;
	}

	private static function form_filters( $source = null ) {
		if ( null === $source ) {
			$source = $_POST;
		}
		$from    = self::check_date( sanitize_text_field( wp_unslash( $source['from'] ?? '' ) ) );
		$before  = self::check_date( sanitize_text_field( wp_unslash( $source['before'] ?? '' ) ) );
		$keyword = sanitize_text_field( wp_unslash($source['keyword'] ?? '' ) );
		$keyword = trim($keyword );
		if ( function_exists( 'mb_substr' ) ) {
			$keyword = mb_substr($keyword, 0, 100 );
		} else {
			$keyword = substr($keyword, 0, 100 );
		}
		$type = sanitize_key( wp_unslash($source['type'] ?? 'all' ) );
		if ( ! array_key_exists( $type, self::types() ) ) {
			throw new RuntimeException( '不支援選擇的圖片格式。' );
		}
		if ( ! $from && ! $before && '' ===$keyword ) {
			throw new RuntimeException( '為避免誤選全站圖片，請從左側選擇年月資料夾，或至少填入一個日期／檔名關鍵字。' );
		}
		if ( $from &&$before && strcmp( $from,$before ) >= 0 ) {
			throw new RuntimeException( '起始日期必須早於結束日期。' );
		}
		return array(
			'from'           => $from,
			'before'         => $before,
			'keyword'        => $keyword,
			'type'           => $type,
			'protect_parent' => ! empty( $source['protect_parent'] ),
			'protect_refs'   => true,
			'folder_label'   => sanitize_text_field( wp_unslash( $source['folder_label'] ?? '' ) ),
		);
	}

	private static function types() {
		return array(
			'all'  => '全部圖片格式',
			'jpeg' => 'JPG / JPEG',
			'png'  => 'PNG',
			'webp' => 'WebP',
			'gif'  => 'GIF',
			'avif' => 'AVIF',
			'heic' => 'HEIC / HEIF',
		);
	}

	private static function type_mimes( $type ) {$map = array(
			'jpeg' => array( 'image/jpeg', 'image/pjpeg' ),
			'png'  => array( 'image/png' ),
			'webp' => array( 'image/webp' ),
			'gif'  => array( 'image/gif' ),
			'avif' => array( 'image/avif' ),
			'heic' => array( 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence' ),
		);
		return $map[$type ] ?? array();
	}

	/** 取得媒體庫中依「年/月」分組的圖片統計（供左側目錄使用） */
	private static function get_folder_tree( $protect_parent = true ) {
		global $wpdb;
		$parent_sql =$protect_parent ? 'AND p.post_parent = 0' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows =self::db_read( 'get_results',
			"SELECT YEAR(p.post_date) AS y, MONTH(p.post_date) AS m, COUNT(p.ID) AS cnt
			 FROM {$wpdb->posts} p
			 WHERE p.post_type = 'attachment'
			   AND p.post_status IN ('inherit', 'private', 'publish')
			   AND p.post_mime_type LIKE 'image/%'
			   {$parent_sql}
			 GROUP BY y, m
			 ORDER BY y DESC, m DESC"
		);

		$tree = array();
		if ( $rows ) {
			foreach ( $rows as$r ) {
				$y = (int)$r->y;
				$m = (int)$r->m;
				if ( $y <= 0 ) {
					continue;
				}
				if ( ! isset( $tree[$y ] ) ) {
					$tree[$y ] = array(
						'total'  => 0,
						'months' => array(),
					);
				}
				$tree[ $y ]['total']       += (int)$r->cnt;
				$tree[$y ]['months'][ $m ] = (int)$r->cnt;
			}
		}
		return $tree;
	}

	private static function collect_ids( $filters ) {
		global $wpdb;
		$where  = array(
			"p.post_type = 'attachment'",
			"p.post_status IN ('inherit', 'private', 'publish')",
			"p.post_mime_type LIKE 'image/%'",
		);
		$params = array();
		if ( $filters['from'] ) {$where[]  = 'p.post_date >= %s';
			$params[] =$filters['from'] . ' 00:00:00';
		}
		if ( $filters['before'] ) {$where[]  = 'p.post_date < %s';
			$params[] =$filters['before'] . ' 00:00:00';
		}
		$mimes = self::type_mimes($filters['type'] );
		if ( $mimes ) {$where[] = 'p.post_mime_type IN (' . implode( ',', array_fill( 0, count( $mimes ), '%s' ) ) . ')';
			foreach ( $mimes as$mime ) {
				$params[] =$mime;
			}
		}
		if ( $filters['keyword'] ) {
			$pattern = '%' .$wpdb->esc_like( $filters['keyword'] ) . '%';$where[] = "(p.post_title LIKE %s OR p.post_name LIKE %s OR EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm
				WHERE pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file' AND pm.meta_value LIKE %s
			))";
			array_push( $params,$pattern, $pattern,$pattern );
		}
		if ( $filters['protect_parent'] ) {
			$where[] = 'p.post_parent = 0';
		}$params[] = self::MAX_RESULTS + 1;
		$sql      = "SELECT p.ID FROM {$wpdb->posts} p WHERE " . implode( ' AND ', $where ) . ' ORDER BY p.post_date DESC, p.ID DESC LIMIT %d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids = self::db_read( 'get_col',$wpdb->prepare( $sql,$params ) );
		if ( count( $ids ) > self::MAX_RESULTS ) {
			throw new RuntimeException( '符合條件的圖片超過 15,000 張，請以年月資料夾或檔名分次篩選，避免一次產生過大清單。' );
		}
		return array_map( 'absint', $ids );
	}

	private static function save_snapshot_and_redirect( $filters ) {$ids   = self::collect_ids( $filters );$token = wp_generate_password( 28, false, false );
		set_transient(
			self::transient_key(),
			array(
				'token'      => $token,
				'ids'        => $ids,
				'filters'    => $filters,
				'created_at' => time(),
			),
			self::EXPIRES
		);
		wp_safe_redirect( self::admin_link( array( 'token' => $token ) ) );
		exit;
	}

	public static function create_preview() {
		if ( ! self::can_access() ) {
			wp_die( '沒有管理員權限。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wutm_mc_create_preview' );
		try {
			$filters = self::form_filters($_POST );
			self::save_snapshot_and_redirect( $filters );
		} catch ( RuntimeException $e ) {
			wp_die( esc_html( $e->getMessage() ), '無法建立預覽', array( 'back_link' => true ) );
		}
	}

	public static function quick_folder() {
		if ( ! self::can_access() ) {
			wp_die( '沒有管理員權限。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wutm_mc_quick_folder' );
		try {
			$year           = absint( $_GET['y'] ?? 0 );$month          = absint( $_GET['m'] ?? 0 );$protect_parent = ! isset( $_GET['pp'] ) || '1' === (string)$_GET['pp'];
			$type           = sanitize_key( wp_unslash($_GET['type'] ?? 'all' ) );
			if ( ! array_key_exists( $type, self::types() ) ) {$type = 'all';
			}

			if ( $year < 1970 ||$year > 2100 ) {
				throw new RuntimeException( '無效的年份資料夾。' );
			}

			if ( $month >= 1 && $month <= 12 ) {$from         = sprintf( '%04d-%02d-01', $year,$month );
				$next_year    = 12 ===$month ? $year + 1 :$year;
				$next_month   = 12 === $month ? 1 : $month + 1;
				$before       = sprintf( '%04d-%02d-01',$next_year, $next_month );$folder_label = sprintf( '%04d 年 %02d 月', $year,$month );
			} else {
				$from         = sprintf( '%04d-01-01',$year );
				$before       = sprintf( '%04d-01-01',$year + 1 );
				$folder_label = sprintf( '%04d 全年度', $year );
			}

			$filters = array(
				'from'           => $from,
				'before'         => $before,
				'keyword'        => '',
				'type'           => $type,
				'protect_parent' => $protect_parent,
				'protect_refs'   => true,
				'folder_label'   => $folder_label,
			);
			self::save_snapshot_and_redirect( $filters );
		} catch ( RuntimeException $e ) {
			wp_die( esc_html( $e->getMessage() ), '無法開啟資料夾', array( 'back_link' => true ) );
		}
	}

	private static function matches_filters( $post,$f ) {
		if ( ! $post || 'attachment' !== $post->post_type || 0 !== strpos( (string)$post->post_mime_type, 'image/' )
			|| ! in_array( $post->post_status, array( 'inherit', 'private', 'publish' ), true ) ) {
			return false;
		}
		if ( $f['from'] && $post->post_date <$f['from'] . ' 00:00:00' ) {
			return false;
		}
		if ( $f['before'] && $post->post_date >=$f['before'] . ' 00:00:00' ) {
			return false;
		}
		$mimes = self::type_mimes($f['type'] );
		if ( $mimes && ! in_array( $post->post_mime_type,$mimes, true ) ) {
			return false;
		}
		if ( $f['protect_parent'] && (int)$post->post_parent > 0 ) {
			return false;
		}
		if ( $f['keyword'] ) {
			$haystack =$post->post_title . "\n" . $post->post_name . "\n" . get_post_meta( $post->ID, '_wp_attached_file', true );
			if ( false === stripos( $haystack,$f['keyword'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 高效批次檢查圖片引用狀態，大幅減少資料庫查詢次數（避免逐張全表掃描造成卡頓）
	 *
	 * @param int[] $ids 媒體 ID 陣列.
	 * @return array<int, string> 每個 ID 對應的保護原因（空字串代表未偵測到引用）.
	 */
	private static function batch_reference_reasons( array $ids ) {
		global $wpdb;
		$reasons = array_fill_keys( $ids, '' );
		if ( empty( $ids ) ) {
			return $reasons;
		}

		// 預先載入 post 與 postmeta 快取（1 次查詢取代數十次）
		_prime_post_caches( $ids, false, true );

		$site_icon   = (int) get_option( 'site_icon' );
		$custom_logo = (int) get_theme_mod( 'custom_logo' );
		$header_img  = (string) get_theme_mod( 'header_image' );
		$bg_img      = (string) get_theme_mod( 'background_image' );

		$files_by_id = array();
		$urls_by_id  = array();

		foreach ( $ids as $id ) {
			if ( $id === $site_icon ) {
				$reasons[ $id ] = '網站圖示使用中';
				continue;
			}
			if ( $id === $custom_logo ) {
				$reasons[ $id ] = '網站 Logo 使用中';
				continue;
			}
			$url  = (string) wp_get_attachment_url( $id );
			$file = (string) get_post_meta( $id, '_wp_attached_file', true );
			if ( $url && ( $url === $header_img || $url === $bg_img ) ) {
				$reasons[ $id ] = '佈景主題圖片使用中';
				continue;
			}
			$urls_by_id[ $id ]  = $url;
			$files_by_id[ $id ] = $file;
		}

		// 1. 批次檢查是否為精選圖片 (_thumbnail_id)
		$id_strings   = array_map( 'strval', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $id_strings ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$thumb_matches = self::db_read( 'get_col',
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value IN ($placeholders)",
				$id_strings
			)
		);
		foreach ( $thumb_matches as $tid ) {
			$tid = (int) $tid;
			if ( isset( $reasons[ $tid ] ) && '' === $reasons[ $tid ] ) {
				$reasons[ $tid ] = '文章精選圖片使用中';
			}
		}

		// 2. 批次檢查是否有其他媒體共用同一個實體檔案 (_wp_attached_file)
		$valid_files = array_filter( array_values( $files_by_id ) );
		if ( ! empty( $valid_files ) ) {
			$unique_files = array_values( array_unique( $valid_files ) );
			$f_holders    = implode( ',', array_fill( 0, count( $unique_files ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$shared_rows = self::db_read( 'get_results',
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ($f_holders)",
					$unique_files
				)
			);
			$file_counts = array();
			foreach ( $shared_rows as $sr ) {
				$file_counts[ $sr->meta_value ][] = (int) $sr->post_id;
			}
			foreach ( $files_by_id as $id => $file ) {
				if ( '' === $reasons[ $id ] && $file && isset( $file_counts[ $file ] ) && count( $file_counts[ $file ] ) > 1 ) {
					$reasons[ $id ] = '其他媒體紀錄共用此檔案';
				}
			}
		}

		// 3. 針對尚未被標記的圖片，檢查文章內容與頁面編輯器引用
		$remaining_ids = array();
		foreach ( $ids as $id ) {
			if ( '' === $reasons[ $id ] ) {
				$remaining_ids[] = $id;
			}
		}

		if ( ! empty( $remaining_ids ) ) {
			foreach ( $remaining_ids as $id ) {
				$url      = $urls_by_id[ $id ] ?? '';
				$file     = $files_by_id[ $id ] ?? '';
				$patterns = array( '%' . $wpdb->esc_like( 'wp-image-' . $id ) . '%' );
				if ( $url ) {
					$patterns[] = '%' . $wpdb->esc_like( $url ) . '%';
				}
				if ( $file ) {
					$patterns[] = '%' . $wpdb->esc_like( $file ) . '%';
				}
				$pieces = array_fill( 0, count( $patterns ), 'post_content LIKE %s' );
				$sql    = "SELECT ID FROM {$wpdb->posts} WHERE post_type <> 'attachment' AND post_status NOT IN ('trash', 'auto-draft') AND (" . implode( ' OR ', $pieces ) . ') LIMIT 1';
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( self::db_read( 'get_var', $wpdb->prepare( $sql, $patterns ) ) ) {
					$reasons[ $id ] = '文章或頁面內容引用中';
					continue;
				}

				$meta_patterns = array(
					'%' . $wpdb->esc_like( '"id":' . $id ) . '%',
					'%' . $wpdb->esc_like( '"image_id":' . $id ) . '%',
				);
				foreach ( array( $url, $file, str_replace( '/', '\\/', $url ) ) as $value ) {
					if ( $value ) $meta_patterns[] = '%' . $wpdb->esc_like( $value ) . '%';
				}
				$builder       = self::db_read( 'get_var',
					$wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta}
						WHERE meta_key IN ('_elementor_data', '_bricks_page_content_2', '_bricks_page_content', '_fl_builder_data')
						AND (" . implode( ' OR ', array_fill( 0, count( $meta_patterns ), 'meta_value LIKE %s' ) ) . ") LIMIT 1",
						$meta_patterns
					)
				);
				if ( $builder ) {
					$reasons[ $id ] = '頁面編輯器資料引用中';
					continue;
				}
				if ( self::db_read( 'get_var', $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND FIND_IN_SET(%s, meta_value) > 0 LIMIT 1", (string) $id ) ) ) {
					$reasons[ $id ] = '商品圖庫使用中';
				}
			}
		}

		return $reasons;
	}

	public static function delete_images() {
		if ( ! self::can_access() ) {
			wp_send_json_error( array( 'message' => '沒有管理員權限。' ), 403 );
		}
		check_ajax_referer( 'wutm_mc_delete_images', 'nonce' );
		$token    = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$snapshot = self::read_preview( $token );
		if ( ! $snapshot ) {
			wp_send_json_error( array( 'message' => '預覽清單已過期，請重新選擇資料夾或執行篩選。' ), 400 );
		}
		$confirmation = sanitize_text_field( wp_unslash( $_POST['confirmation'] ?? '' ) );
		if ( '永久刪除' !== $confirmation ) {
			wp_send_json_error( array( 'message' => '請在下方確認欄輸入「永久刪除」四個字。' ), 400 );
		}
		$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) );
		if ( 'all' === $mode ) {
			$offset = absint( $_POST['offset'] ?? 0 );
			if ( $offset >= count( $snapshot['ids'] ) ) {
				wp_send_json_error( array( 'message' => '批次位置超出清單範圍。' ), 400 );
			}
			$ids = array_slice( $snapshot['ids'], $offset, self::BATCH_SIZE );
		} elseif ( 'selected' === $mode ) {
			$input = json_decode( wp_unslash( $_POST['ids'] ?? '[]' ), true );
			if ( ! is_array( $input ) || count( $input ) > self::BATCH_SIZE || ! count( $input ) ) {
				wp_send_json_error( array( 'message' => '每批處理數量應為 1 至 ' . self::BATCH_SIZE . ' 張。' ), 400 );
			}
			$allowed = array_fill_keys( $snapshot['ids'], true );
			$ids     = array();
			foreach ( $input as $candidate ) {
				$id = absint( $candidate );
				if ( ! $id || ! isset( $allowed[ $id ] ) ) {
					wp_send_json_error( array( 'message' => '包含不在預覽範圍內的媒體。' ), 400 );
				}
				$ids[ $id ] = $id;
			}
			$ids = array_values( $ids );
		} else {
			wp_send_json_error( array( 'message' => '不支援的處理模式。' ), 400 );
		}

		// 批次預載快取與引用檢查
		try {
			$reasons = self::batch_reference_reasons( $ids );
		} catch ( RuntimeException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 503 );
		}

		$result = array(
			'processed' => 0,
			'deleted'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'leftover'  => 0,
			'messages'  => array(),
			'outcomes'  => array(),
		);

		foreach ( $ids as $id ) {
			++$result['processed'];
			$post     = get_post( $id );
			$rel_file = (string) get_post_meta( $id, '_wp_attached_file', true );
			$fname    = $rel_file ? wp_basename( $rel_file ) : ( $post ? $post->post_title : '未知檔案' );

			if ( ! self::matches_filters( $post, $snapshot['filters'] ) || ! current_user_can( 'delete_post', $id ) ) {
				++$result['skipped'];
				$result['messages'][] = sprintf( '[略過] #%d (%s)：圖片已不存在、條件異動或權限不足', $id, $fname );
				$result['outcomes'][] = array(
					'id'     => $id,
					'status' => 'skipped',
					'note'   => '條件異動或已略過',
				);
				continue;
			}

			$reason = $reasons[ $id ] ?? '';
			if ( ! $reason && ! self::safe_attachment_paths( $id ) ) $reason = '檔案路徑或縮圖資料不安全，請人工檢查';
			if ( $reason ) {
				++$result['skipped'];
				$result['messages'][] = sprintf( '[保護保留] #%d (%s)：%s', $id, $fname, $reason );
				$result['outcomes'][] = array(
					'id'     => $id,
					'status' => 'skipped',
					'note'   => $reason,
				);
				continue;
			}

			$original = get_attached_file( $id );
			$deleted  = wp_delete_attachment( $id, true );
			if ( ! $deleted || get_post( $id ) ) {
				++$result['failed'];
				$result['messages'][] = sprintf( '[刪除失敗] #%d (%s)：請檢查檔案系統權限', $id, $fname );
				$result['outcomes'][] = array(
					'id'     => $id,
					'status' => 'failed',
					'note'   => '刪除失敗',
				);
				continue;
			}

			++$result['deleted'];
			$result['messages'][] = sprintf( '[已永久刪除] #%d (%s)：媒體紀錄與縮圖已清理', $id, $fname );
			$result['outcomes'][] = array(
				'id'     => $id,
				'status' => 'deleted',
				'note'   => '已永久刪除',
			);

			if ( is_string( $original ) && '' !== $original && file_exists( $original ) ) {
				++$result['leftover'];
				$result['messages'][] = sprintf( '[注意] #%d (%s)：資料庫紀錄已刪，但原始檔案仍存在，請檢查目錄權限', $id, $fname );
			}
		}
		wp_send_json_success( $result );
	}

	private static function csv_text( $value ) {
		$value = (string) $value;
		if ( preg_match( '/^[\x00-\x20]*[=+\-@]/u', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	public static function download_csv() {
		if ( ! self::can_access() ) {
			wp_die( '沒有管理員權限。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wutm_mc_download_csv' );
		$token    = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		$snapshot = self::read_preview( $token );
		if ( ! $snapshot ) {
			wp_die( '預覽已過期，請重新搜尋。' );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="wumetax-media-preview-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		if ( ! $out ) {
			exit;
		}
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( '媒體 ID', '標題', '檔案名稱', '相對路徑', '上傳日期（站點時間）', '圖片網址', '附屬文章 ID' ) );
		foreach ( array_chunk( $snapshot['ids'], 200 ) as $chunk ) {
			_prime_post_caches( $chunk, false, true );
			foreach ( $chunk as $id ) {
				$p = get_post( $id );
				if ( ! $p ) {
					continue;
				}
				$rel = (string) get_post_meta( $p->ID, '_wp_attached_file', true );
				$url = wp_get_attachment_url( $p->ID );
				fputcsv(
					$out,
					array(
						$p->ID,
						self::csv_text( $p->post_title ),
						self::csv_text( wp_basename( $rel ) ),
						self::csv_text( $rel ),
						$p->post_date,
						self::csv_text( $url ),
						$p->post_parent,
					)
				);
			}
		}
		fclose( $out );
		exit;
	}

	public static function render() {
		if ( ! self::can_access() ) {
			return;
		}
		$token    = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		$snapshot = self::read_preview( $token );
		$f        = $snapshot['filters'] ?? array(
			'from'           => '',
			'before'         => '',
			'keyword'        => '',
			'type'           => 'all',
			'protect_parent' => true,
			'folder_label'   => '',
		);

		$count = $snapshot ? count( $snapshot['ids'] ) : 0;
		$page  = max( 1, absint( $_GET['wutm_mc_page'] ?? 1 ) );
		$pages = max( 1, (int) ceil( $count / self::PER_PAGE ) );
		$page  = min( $page, $pages );
		$show  = $snapshot ? array_slice( $snapshot['ids'], ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ) : array();
		$csv   = $snapshot ? wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'wutm_mc_download_csv',
					'token'  => $snapshot['token'],
				),
				admin_url( 'admin-post.php' )
			),
			'wutm_mc_download_csv'
		) : '';

		try {
			$folder_tree = self::get_folder_tree( ! empty( $f['protect_parent'] ) );
			$reasons_map = $show ? self::batch_reference_reasons( $show ) : array();
		} catch ( RuntimeException $e ) {
			echo '<div class="wrap wutm-ui-root"><h1>媒體安全清理</h1><p role="alert">' . esc_html( $e->getMessage() ) . '</p></div>';
			return;
		}

		$current_path = '尚未載入目錄（請從左側點選年月目錄，或於上方設定篩選條件）';
		if ( $snapshot ) {
			$parts = array();
			if ( ! empty( $f['folder_label'] ) ) {
				$parts[] = '目錄：' . $f['folder_label'];
			} elseif ( $f['from'] || $f['before'] ) {
				$parts[] = '日期區間：' . ( $f['from'] ?: '最早期' ) . ' 至 ' . ( $f['before'] ?: '最新' );
			}
			if ( 'all' !== $f['type'] ) {
				$types   = self::types();
				$parts[] = '格式：' . ( $types[ $f['type'] ] ?? $f['type'] );
			}
			if ( '' !== $f['keyword'] ) {
				$parts[] = '關鍵字：「' . $f['keyword'] . '」';
			}
			$parts[]      = $f['protect_parent'] ? '篩選：僅未附屬文章圖片' : '篩選：包含已附屬文章圖片';
			$current_path = implode( '  |  ', $parts );
		}
		?>
		<div class="wrap wumc-app wutm-ui-root">
			<header class="wumc-topbar wutm-ui-header">
				<div class="wumc-brand">
					<div>
						<h1>WUMETAX 媒體安全清理總管 <span>v<?php echo esc_html( self::VERSION ); ?></span></h1>
						<p>WordPress 原生媒體庫清理工具 · 檢查可辨識的圖片引用 · 同步清理原始檔與縮圖</p>
					</div>
				</div>
				<div class="wumc-topbar-warning">
					<strong>注意：執行刪除前請務必備份資料庫與 wp-content/uploads 目錄</strong>
					<span>永久刪除無法復原；系統會保護可辨識的引用，但未偵測到引用不代表確定未使用；外部網站、動態程式與第三方設定仍須人工確認。</span>
				</div>
			</header>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="wumc-toolbar">
				<input type="hidden" name="action" value="wutm_mc_create_preview">
				<?php wp_nonce_field( 'wutm_mc_create_preview' ); ?>

				<div class="wumc-toolbar-group">
					<label class="wumc-tool-field">
						<span>上傳起日 (含)</span>
						<input name="from" type="date" value="<?php echo esc_attr( $f['from'] ); ?>">
					</label>
					<label class="wumc-tool-field">
						<span>上傳迄日 (不含)</span>
						<input name="before" type="date" value="<?php echo esc_attr( $f['before'] ); ?>">
					</label>
					<label class="wumc-tool-field">
						<span>檔案格式</span>
						<select name="type">
							<?php foreach ( self::types() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $f['type'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="wumc-tool-field wumc-tool-search">
						<span>檔名 / 標題 / 路徑關鍵字</span>
						<input type="search" name="keyword" maxlength="100" value="<?php echo esc_attr( $f['keyword'] ); ?>" placeholder="例如 IMG_、113、banner...">
					</label>
					<label class="wumc-tool-check" title="建議勾選：只列出沒有掛載在特定文章底下的圖片">
						<input type="checkbox" name="protect_parent" id="wumc-protect-parent-toggle" value="1" <?php checked( ! empty( $f['protect_parent'] ) ); ?>>
						<span>僅列未附屬文章圖片</span>
					</label>
				</div>

				<div class="wumc-toolbar-actions">
					<button class="button button-primary wumc-btn-scan" type="submit">依條件載入媒體</button>
					<?php if ( $snapshot && $count > 0 ) : ?>
						<a class="button" href="<?php echo esc_url( $csv ); ?>">匯出 CSV 清單 (<?php echo esc_html( number_format_i18n( $count ) ); ?>)</a>
					<?php endif; ?>
				</div>
			</form>

			<div class="wumc-explorer">
				<aside class="wumc-sidebar">
					<div class="wumc-sidebar-header">
						<span>年月目錄索引</span>
						<small><?php echo ! empty( $f['protect_parent'] ) ? '僅未附屬' : '全部圖片'; ?></small>
					</div>
					<div class="wumc-folder-tree">
						<?php if ( empty( $folder_tree ) ) : ?>
							<div class="wumc-tree-empty">無符合條件的圖片目錄</div>
						<?php else : ?>
							<?php
							foreach ( $folder_tree as $year => $ydata ) :
								$year_url    = wp_nonce_url(
									add_query_arg(
										array(
											'action' => 'wutm_mc_quick_folder',
											'y'      => $year,
											'm'      => 0,
											'pp'     => ! empty( $f['protect_parent'] ) ? '1' : '0',
											'type'   => $f['type'],
										),
										admin_url( 'admin-post.php' )
									),
									'wutm_mc_quick_folder'
								);
								$is_year_act = ( $f['from'] === sprintf( '%04d-01-01', $year ) && $f['before'] === sprintf( '%04d-01-01', $year + 1 ) );
								?>
								<details class="wumc-year-group" open>
									<summary class="wumc-year-node <?php echo $is_year_act ? 'is-active' : ''; ?>">
										<span class="wumc-folder-label"><?php echo (int) $year; ?> 年</span>
										<a href="<?php echo esc_url( $year_url ); ?>" class="wumc-year-all-link" title="載入 <?php echo (int) $year; ?> 全年圖片">全年 (<?php echo esc_html( number_format_i18n( $ydata['total'] ) ); ?>)</a>
									</summary>
									<ul class="wumc-month-list">
										<?php
										foreach ( $ydata['months'] as $month => $mcnt ) :
											$m_from      = sprintf( '%04d-%02d-01', $year, $month );
											$is_m_active = ( $f['from'] === $m_from && empty( $f['keyword'] ) );
											$month_url   = wp_nonce_url(
												add_query_arg(
													array(
														'action' => 'wutm_mc_quick_folder',
														'y'      => $year,
														'm'      => $month,
														'pp'     => ! empty( $f['protect_parent'] ) ? '1' : '0',
														'type'   => $f['type'],
													),
													admin_url( 'admin-post.php' )
												),
												'wutm_mc_quick_folder'
											);
											?>
											<li>
												<a href="<?php echo esc_url( $month_url ); ?>" class="wumc-month-link <?php echo $is_m_active ? 'is-active' : ''; ?>">
													<span><?php echo esc_html( sprintf( '%04d / %02d 月', $year, $month ) ); ?></span>
													<span class="wumc-badge"><?php echo esc_html( number_format_i18n( $mcnt ) ); ?></span>
												</a>
											</li>
										<?php endforeach; ?>
									</ul>
								</details>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</aside>

				<main class="wumc-main">
					<div class="wumc-addressbar">
						<div class="wumc-breadcrumb">
							<span><?php echo esc_html( $current_path ); ?></span>
							<?php if ( $snapshot ) : ?>
								<strong class="wumc-count-pill">共 <?php echo esc_html( number_format_i18n( $count ) ); ?> 張</strong>
							<?php endif; ?>
						</div>

						<?php if ( $snapshot && $count > 0 ) : ?>
							<div class="wumc-view-controls">
								<button type="button" class="button button-small" id="wumc-select-clean">勾選本頁可清理項目</button>
								<label class="wumc-select-all-box">
									<input type="checkbox" id="wumc-check-page">
									<span>全選本頁 (<?php echo count( $show ); ?>)</span>
								</label>
								<span class="wumc-selected-counter" id="wumc-selected-counter">已選 0 張</span>
								<div class="wumc-view-switch" role="group" aria-label="檢視模式">
									<button type="button" class="button button-small wumc-view-btn is-active" data-view="grid">網格檢視</button>
									<button type="button" class="button button-small wumc-view-btn" data-view="list">詳細清單</button>
								</div>
							</div>
						<?php endif; ?>
					</div>

					<div class="wumc-viewport" id="wumc-viewport" data-view-mode="grid">
						<?php if ( ! $snapshot ) : ?>
							<div class="wumc-empty-state">
								<h2>請從左側點選「年月目錄」或於上方設定篩選條件</h2>
								<p>點選左側年份或月份即可快速預覽該期間上傳的媒體檔案，系統會自動標示是否被文章或網站設定引用。</p>
							</div>
						<?php elseif ( 0 === $count ) : ?>
							<div class="wumc-empty-state">
								<h2>此目錄或篩選條件下沒有符合的圖片</h2>
								<p>請點選左側其他月份目錄，或調整上方的日期與關鍵字條件。</p>
							</div>
						<?php else : ?>
							<?php
							// 批次載入本頁所有圖片快取與引用狀態（極速化，不再逐張重複查詢）
							$items       = array();

							foreach ( $show as $id ) {
								$p = get_post( $id );
								if ( ! $p ) {
									continue;
								}
								$rel_path = (string) get_post_meta( $id, '_wp_attached_file', true );
								$filename = $rel_path ? wp_basename( $rel_path ) : '未知檔名';
								$meta     = wp_get_attachment_metadata( $id );

								// 優先從 metadata 讀取檔案大小，避免每次都對硬碟做 filesize() 造成卡頓
								$bytes = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;
								if ( ! $bytes ) {
									$abs_path = get_attached_file( $id );
									if ( $abs_path && file_exists( $abs_path ) ) {
										$bytes = (int) filesize( $abs_path );
									}
								}
								$filesize  = $bytes > 0 ? size_format( $bytes, 1 ) : '未知大小';
								$dims      = ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) ? $meta['width'] . 'x' . $meta['height'] : '未知尺寸';
								$ext       = strtoupper( pathinfo( $filename, PATHINFO_EXTENSION ) ?: str_replace( 'image/', '', (string) $p->post_mime_type ) );
								$ref_note  = $reasons_map[ $id ] ?? '';
								$edit_link = get_edit_post_link( $id ) ?: admin_url( 'upload.php' );
								$full_url  = wp_get_attachment_url( $id );

								$items[] = array(
									'id'        => (int) $id,
									'title'     => $p->post_title ?: '（無標題）',
									'filename'  => $filename,
									'rel_path'  => $rel_path,
									'ext'       => $ext,
									'date'      => substr( $p->post_date, 0, 16 ),
									'parent'    => (int) $p->post_parent,
									'filesize'  => $filesize,
									'dims'      => $dims,
									'ref_note'  => $ref_note,
									'edit_link' => $edit_link,
									'full_url'  => $full_url,
								);
							}
							?>

							<!-- 1. 網格檢視 (Grid View) -->
							<div class="wumc-grid-container">
								<?php foreach ( $items as $item ) : ?>
									<?php
									$id           = $item['id'];
									$is_protected = ! empty( $item['ref_note'] );
									?>
									<div class="wumc-file-card <?php echo $is_protected ? 'is-protected' : 'is-clean'; ?>" data-media-row="<?php echo (int) $id; ?>" data-clean="<?php echo $is_protected ? '0' : '1'; ?>">
										<div class="wumc-card-top">
											<label class="wumc-card-check-label">
												<input type="checkbox" class="wumc-image-checkbox" value="<?php echo (int) $id; ?>" data-clean="<?php echo $is_protected ? '0' : '1'; ?>">
												<span class="wumc-card-id">ID: #<?php echo (int) $id; ?></span>
											</label>
											<div class="wumc-card-links">
												<?php if ( $item['full_url'] ) : ?>
													<a href="<?php echo esc_url( $item['full_url'] ); ?>" target="_blank" rel="noopener noreferrer">原圖</a>
												<?php endif; ?>
												<a href="<?php echo esc_url( $item['edit_link'] ); ?>" target="_blank" rel="noopener noreferrer">編輯</a>
											</div>
										</div>
										<div class="wumc-card-thumb">
											<?php echo wp_get_attachment_image( $id, 'thumbnail', true, array( 'class' => 'wumc-thumb-img', 'loading' => 'lazy', 'decoding' => 'async' ) ); ?>
										</div>
										<div class="wumc-card-body">
											<div class="wumc-info-row">
												<span class="wumc-info-label">檔名</span>
												<span class="wumc-info-val wumc-val-filename"><?php echo esc_html( $item['filename'] ); ?></span>
											</div>
											<div class="wumc-info-row">
												<span class="wumc-info-label">標題</span>
												<span class="wumc-info-val"><?php echo esc_html( $item['title'] ); ?></span>
											</div>
											<div class="wumc-info-row">
												<span class="wumc-info-label">規格</span>
												<span class="wumc-info-val"><?php echo esc_html( $item['ext'] . ' · ' . $item['dims'] . ' · ' . $item['filesize'] ); ?></span>
											</div>
											<div class="wumc-info-row">
												<span class="wumc-info-label">時間</span>
												<span class="wumc-info-val"><?php echo esc_html( $item['date'] ); ?></span>
											</div>
											<div class="wumc-card-status" data-status-cell>
												<?php if ( $item['ref_note'] ) : ?>
													<span class="wumc-tag wumc-tag-warn">保護中：<?php echo esc_html( $item['ref_note'] ); ?></span>
												<?php elseif ( $item['parent'] > 0 ) : ?>
													<span class="wumc-tag wumc-tag-info">已附屬文章 #<?php echo (int) $item['parent']; ?></span>
												<?php else : ?>
													<span class="wumc-tag wumc-tag-ok">可清理：未偵測引用</span>
												<?php endif; ?>
											</div>
										</div>
									</div>
								<?php endforeach; ?>
							</div>

							<!-- 2. 詳細清單檢視 (List View) -->
							<div class="wumc-list-container">
								<table class="widefat striped wumc-table">
									<thead>
										<tr>
											<th class="wumc-col-check">選取</th>
											<th width="70">媒體 ID</th>
											<th width="64">縮圖</th>
											<th>檔案名稱 / 相對路徑</th>
											<th>媒體標題</th>
											<th width="140">格式 / 尺寸 / 大小</th>
											<th width="135">上傳時間</th>
											<th width="180">引用檢測狀態</th>
											<th width="100">操作</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $items as $item ) : ?>
											<?php $id = $item['id']; ?>
											<tr data-media-row-list="<?php echo (int) $id; ?>">
												<td>
													<input type="checkbox" class="wumc-image-checkbox-mirror" data-id="<?php echo (int) $id; ?>">
												</td>
												<td><code>#<?php echo (int) $id; ?></code></td>
												<td>
													<?php echo wp_get_attachment_image( $id, array( 48, 48 ), true, array( 'class' => 'wumc-list-thumb', 'loading' => 'lazy', 'decoding' => 'async' ) ); ?>
												</td>
												<td>
													<strong class="wumc-list-filename"><?php echo esc_html( $item['filename'] ); ?></strong>
													<div class="wumc-muted"><?php echo esc_html( $item['rel_path'] ); ?></div>
												</td>
												<td><?php echo esc_html( $item['title'] ); ?></td>
												<td>
													<div><strong><?php echo esc_html( $item['ext'] ); ?></strong> · <?php echo esc_html( $item['filesize'] ); ?></div>
													<div class="wumc-muted"><?php echo esc_html( $item['dims'] ); ?> px</div>
												</td>
												<td><?php echo esc_html( $item['date'] ); ?></td>
												<td data-status-cell-list="<?php echo (int) $id; ?>">
													<?php if ( $item['ref_note'] ) : ?>
														<span class="wumc-tag wumc-tag-warn">保護中：<?php echo esc_html( $item['ref_note'] ); ?></span>
													<?php elseif ( $item['parent'] > 0 ) : ?>
														<span class="wumc-tag wumc-tag-info">已附屬文章 #<?php echo (int) $item['parent']; ?></span>
													<?php else : ?>
														<span class="wumc-tag wumc-tag-ok">可清理：未偵測引用</span>
													<?php endif; ?>
												</td>
												<td>
													<?php if ( $item['full_url'] ) : ?>
														<a class="button button-small" href="<?php echo esc_url( $item['full_url'] ); ?>" target="_blank" rel="noopener noreferrer">原圖</a>
													<?php endif; ?>
													<a class="button button-small" href="<?php echo esc_url( $item['edit_link'] ); ?>" target="_blank" rel="noopener noreferrer">編輯</a>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>
					</div>

					<?php if ( $snapshot && $count > 0 ) : ?>
						<footer class="wumc-statusbar" id="wumc-danger-zone" data-token="<?php echo esc_attr( $snapshot['token'] ); ?>" data-total="<?php echo (int) $count; ?>">
							<div class="wumc-pagination">
								<?php
								$base = self::admin_link( array( 'token' => $snapshot['token'] ) );
								if ( $page > 1 ) {
									echo '<a class="button" href="' . esc_url( add_query_arg( 'wutm_mc_page', $page - 1, $base ) ) . '">上一頁</a>';
								}
								echo '<span class="wumc-page-indicator">第 <strong>' . (int) $page . '</strong> / ' . (int) $pages . ' 頁（每頁 ' . (int) self::PER_PAGE . ' 張）</span>';
								if ( $page < $pages ) {
									echo '<a class="button" href="' . esc_url( add_query_arg( 'wutm_mc_page', $page + 1, $base ) ) . '">下一頁</a>';
								}
								?>
							</div>

							<div class="wumc-delete-dock">
								<label class="wumc-confirm-box" for="wumc-confirm">
									<span>安全鎖定：輸入「<strong>永久刪除</strong>」解鎖</span>
									<input id="wumc-confirm" type="text" autocomplete="off" spellcheck="false" placeholder="請輸入：永久刪除">
								</label>
								<button type="button" class="button wumc-btn-danger" id="wumc-delete-selected">刪除本頁勾選圖片</button>
								<button type="button" class="button wumc-btn-danger-outline" id="wumc-delete-all">分批清理此清單全部 (<?php echo esc_html( number_format_i18n( $count ) ); ?> 張)</button>
								<button type="button" class="button" id="wumc-pause" hidden>暫停後續批次</button>
							</div>
						</footer>

						<div id="wumc-progress-area" class="wumc-progress-drawer" hidden>
							<div class="wumc-progress-row">
								<progress id="wumc-progress" max="100" value="0"></progress>
								<span id="wumc-status" role="status" aria-live="polite">準備中...</span>
							</div>
							<div class="wumc-log-wrapper">
								<div class="wumc-log-title">執行明細紀錄（包含已刪除與保護略過項目）：</div>
								<pre id="wumc-log"></pre>
							</div>
						</div>
					<?php endif; ?>
				</main>
			</div>
		</div>
		<?php
	}

	private static function get_inline_css() {
		return '
		.wumc-app {
			margin: 14px 18px 0 2px;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans TC", "Microsoft JhengHei", sans-serif;
			color: #1e293b;
		}
		.wumc-app * { box-sizing: border-box; }
		.wumc-app [hidden] { display: none !important; }

		/* Topbar */
		.wumc-topbar {
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 16px;
			background: #ffffff;
			border: 1px solid #cbd5e1;
			border-radius: 6px 6px 0 0;
			padding: 12px 18px;
			flex-wrap: wrap;
		}
		.wumc-brand h1 {
			margin: 0;
			font-size: 18px;
			font-weight: 700;
			color: #0f172a;
			padding: 0;
		}
		.wumc-brand h1 span {
			font-size: 12px;
			background: #e2e8f0;
			color: #334155;
			padding: 2px 7px;
			border-radius: 4px;
			vertical-align: middle;
			margin-left: 6px;
		}
		.wumc-brand p {
			margin: 4px 0 0;
			font-size: 12.5px;
			color: #64748b;
		}
		.wumc-topbar-warning {
			background: #fffbeb;
			border: 1px solid #fcd34d;
			color: #92400e;
			padding: 8px 12px;
			border-radius: 4px;
			font-size: 12px;
			display: flex;
			flex-direction: column;
			gap: 2px;
		}

		/* Toolbar */
		.wumc-toolbar {
			display: flex;
			justify-content: space-between;
			align-items: flex-end;
			gap: 12px;
			background: #f8fafc;
			border: 1px solid #cbd5e1;
			border-top: none;
			padding: 10px 18px;
			flex-wrap: wrap;
		}
		.wumc-toolbar-group {
			display: flex;
			align-items: flex-end;
			gap: 10px;
			flex-wrap: wrap;
		}
		.wumc-tool-field {
			display: flex;
			flex-direction: column;
			gap: 3px;
			font-size: 12px;
			font-weight: 600;
			color: #334155;
		}
		.wumc-tool-field input,
		.wumc-tool-field select {
			height: 30px;
			border: 1px solid #94a3b8;
			border-radius: 4px;
			padding: 0 8px;
			background: #fff;
			font-size: 12.5px;
		}
		.wumc-tool-search input {
			min-width: 200px;
		}
		.wumc-tool-check {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			height: 30px;
			padding: 0 10px;
			background: #fff;
			border: 1px solid #cbd5e1;
			border-radius: 4px;
			font-size: 12.5px;
			font-weight: 600;
			color: #1e293b;
			cursor: pointer;
		}
		.wumc-toolbar-actions {
			display: flex;
			align-items: center;
			gap: 8px;
		}

		/* Explorer Layout */
		.wumc-explorer {
			display: grid;
			grid-template-columns: 230px 1fr;
			background: #ffffff;
			border: 1px solid #cbd5e1;
			border-top: none;
			border-radius: 0 0 6px 6px;
			min-height: 600px;
			overflow: hidden;
		}
		@media (max-width: 960px) {
			.wumc-explorer { grid-template-columns: 1fr; }
		}

		/* Left Sidebar */
		.wumc-sidebar {
			background: #f1f5f9;
			border-right: 1px solid #cbd5e1;
			display: flex;
			flex-direction: column;
		}
		.wumc-sidebar-header {
			padding: 10px 14px;
			font-weight: 700;
			font-size: 12.5px;
			color: #0f172a;
			border-bottom: 1px solid #cbd5e1;
			display: flex;
			justify-content: space-between;
			align-items: center;
			background: #e2e8f0;
		}
		.wumc-sidebar-header small {
			font-weight: 600;
			color: #475569;
			font-size: 11px;
		}
		.wumc-folder-tree {
			padding: 8px;
			overflow-y: auto;
			max-height: 740px;
		}
		.wumc-year-group { margin-bottom: 4px; }
		.wumc-year-node {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 6px 8px;
			border-radius: 4px;
			cursor: pointer;
			font-weight: 700;
			font-size: 13px;
			color: #0f172a;
			user-select: none;
		}
		.wumc-year-node:hover { background: #e2e8f0; }
		.wumc-year-node.is-active { background: #dbeafe; color: #1d4ed8; }
		.wumc-year-all-link {
			font-size: 11px;
			font-weight: 600;
			color: #2563eb;
			text-decoration: none;
			padding: 1px 6px;
			border-radius: 3px;
			background: #eff6ff;
			border: 1px solid #bfdbfe;
		}
		.wumc-year-all-link:hover {
			background: #2563eb;
			color: #fff;
		}
		.wumc-month-list {
			margin: 3px 0 6px 10px;
			padding-left: 8px;
			border-left: 2px solid #cbd5e1;
			list-style: none;
		}
		.wumc-month-list li { margin: 2px 0; }
		.wumc-month-link {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 5px 8px;
			border-radius: 4px;
			text-decoration: none;
			color: #334155;
			font-size: 12.5px;
		}
		.wumc-month-link:hover {
			background: #e2e8f0;
			color: #0f172a;
		}
		.wumc-month-link.is-active {
			background: #1e293b;
			color: #ffffff;
			font-weight: 600;
		}
		.wumc-badge {
			font-size: 11px;
			padding: 1px 6px;
			border-radius: 3px;
			background: rgba(15, 23, 42, 0.08);
		}
		.wumc-month-link.is-active .wumc-badge {
			background: rgba(255, 255, 255, 0.2);
			color: #fff;
		}

		/* Right Main Area */
		.wumc-main {
			display: flex;
			flex-direction: column;
			background: #ffffff;
			min-width: 0;
		}
		.wumc-addressbar {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 9px 16px;
			border-bottom: 1px solid #cbd5e1;
			background: #f8fafc;
			gap: 10px;
			flex-wrap: wrap;
		}
		.wumc-breadcrumb {
			font-size: 12.5px;
			font-weight: 600;
			color: #334155;
			display: flex;
			align-items: center;
			gap: 10px;
			flex-wrap: wrap;
		}
		.wumc-count-pill {
			background: #0f172a;
			color: #fff;
			font-size: 11.5px;
			padding: 2px 8px;
			border-radius: 4px;
		}
		.wumc-view-controls {
			display: flex;
			align-items: center;
			gap: 10px;
			flex-wrap: wrap;
		}
		.wumc-select-all-box {
			display: inline-flex;
			align-items: center;
			gap: 5px;
			font-size: 12.5px;
			font-weight: 600;
			cursor: pointer;
		}
		.wumc-selected-counter {
			font-size: 12px;
			color: #1d4ed8;
			font-weight: 700;
			background: #eff6ff;
			padding: 2px 8px;
			border-radius: 4px;
			border: 1px solid #bfdbfe;
		}
		.wumc-view-btn.is-active {
			background: #1e293b !important;
			color: #fff !important;
			border-color: #1e293b !important;
		}

		/* Viewport */
		.wumc-viewport {
			padding: 16px;
			flex: 1;
			overflow-y: auto;
			background: #f8fafc;
		}
		.wumc-empty-state {
			text-align: center;
			padding: 80px 20px;
			color: #64748b;
		}
		.wumc-empty-state h2 {
			font-size: 16px;
			color: #1e293b;
			margin: 0 0 8px;
		}

		/* Grid & List Toggle */
		.wumc-viewport[data-view-mode="list"] .wumc-grid-container { display: none; }
		.wumc-viewport[data-view-mode="grid"] .wumc-list-container { display: none; }

		.wumc-grid-container {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
			gap: 12px;
		}
		.wumc-file-card {
			background: #ffffff;
			border: 1px solid #cbd5e1;
			border-radius: 6px;
			overflow: hidden;
			display: flex;
			flex-direction: column;
			cursor: pointer;
		}
		.wumc-file-card:hover {
			border-color: #64748b;
		}
		.wumc-file-card.is-selected {
			border: 2px solid #2563eb;
			background: #f8fafc;
		}
		.wumc-file-card.is-processing {
			opacity: 0.6;
			pointer-events: none;
		}
		.wumc-file-card.is-deleted {
			opacity: 0.4;
			pointer-events: none;
			background: #f1f5f9;
		}
		.wumc-card-top {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 6px 10px;
			background: #f1f5f9;
			border-bottom: 1px solid #e2e8f0;
			font-size: 12px;
		}
		.wumc-card-check-label {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			cursor: pointer;
			font-weight: 700;
			color: #1e293b;
		}
		.wumc-card-links {
			display: flex;
			gap: 8px;
			font-size: 11.5px;
		}
		.wumc-card-links a {
			color: #2563eb;
			text-decoration: none;
			font-weight: 600;
		}
		.wumc-card-links a:hover {
			text-decoration: underline;
		}
		.wumc-card-thumb {
			height: 120px;
			background: repeating-conic-gradient(#f1f5f9 0% 25%, #ffffff 0% 50%) 50% / 14px 14px;
			display: flex;
			align-items: center;
			justify-content: center;
			overflow: hidden;
			border-bottom: 1px solid #f1f5f9;
		}
		.wumc-thumb-img {
			max-width: 100%;
			max-height: 100%;
			width: auto;
			height: auto;
			object-fit: contain;
			display: block;
		}
		.wumc-card-body {
			padding: 8px 10px;
			display: flex;
			flex-direction: column;
			gap: 4px;
			font-size: 12px;
			flex: 1;
		}
		.wumc-info-row {
			display: grid;
			grid-template-columns: 34px 1fr;
			gap: 4px;
			line-height: 1.35;
			align-items: baseline;
		}
		.wumc-info-label {
			color: #64748b;
			font-size: 11px;
			font-weight: 600;
		}
		.wumc-info-val {
			color: #1e293b;
			word-break: break-all;
			font-size: 12px;
		}
		.wumc-val-filename {
			font-weight: 700;
			color: #0f172a;
		}
		.wumc-card-status {
			margin-top: auto;
			padding-top: 6px;
		}

		/* Status Tags */
		.wumc-tag {
			display: inline-block;
			font-size: 11.5px;
			font-weight: 600;
			padding: 3px 8px;
			border-radius: 3px;
			line-height: 1.3;
			width: 100%;
			text-align: center;
		}
		.wumc-tag-ok {
			background: #dcfce7;
			color: #166534;
			border: 1px solid #bbf7d0;
		}
		.wumc-tag-warn {
			background: #fef3c7;
			color: #92400e;
			border: 1px solid #fde68a;
		}
		.wumc-tag-info {
			background: #e0f2fe;
			color: #075985;
			border: 1px solid #bae6fd;
		}
		.wumc-tag-deleted {
			background: #e2e8f0;
			color: #475569;
			border: 1px solid #cbd5e1;
		}

		/* List View */
		.wumc-list-thumb {
			width: 44px;
			height: 44px;
			object-fit: cover;
			border-radius: 3px;
			border: 1px solid #cbd5e1;
		}
		.wumc-list-filename {
			word-break: break-all;
			color: #0f172a;
		}
		.wumc-muted {
			color: #64748b;
			font-size: 11.5px;
			word-break: break-all;
		}

		/* Statusbar & Delete Controls */
		.wumc-statusbar {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 12px 16px;
			background: #ffffff;
			border-top: 2px solid #cbd5e1;
			gap: 14px;
			flex-wrap: wrap;
		}
		.wumc-pagination {
			display: flex;
			align-items: center;
			gap: 8px;
			font-size: 12.5px;
		}
		.wumc-delete-dock {
			display: flex;
			align-items: center;
			gap: 8px;
			flex-wrap: wrap;
		}
		.wumc-confirm-box {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			font-size: 12px;
			background: #fef2f2;
			border: 1px solid #fecaca;
			padding: 4px 10px;
			border-radius: 4px;
			color: #991b1b;
		}
		.wumc-confirm-box input {
			width: 115px;
			height: 28px;
			font-size: 12.5px;
			border: 1px solid #f87171;
			border-radius: 3px;
			padding: 0 6px;
		}
		.button.wumc-btn-danger {
			background: #dc2626;
			border-color: #b91c1c;
			color: #ffffff;
			font-weight: 600;
		}
		.button.wumc-btn-danger:hover {
			background: #b91c1c;
			color: #ffffff;
		}
		.button.wumc-btn-danger-outline {
			border-color: #dc2626;
			color: #dc2626;
			font-weight: 600;
		}
		.button.wumc-btn-danger-outline:hover {
			background: #fef2f2;
			color: #b91c1c;
		}

		/* Progress Area */
		.wumc-progress-drawer {
			padding: 12px 16px;
			background: #0f172a;
			color: #f8fafc;
			border-top: 1px solid #334155;
		}
		.wumc-progress-row {
			display: flex;
			align-items: center;
			gap: 12px;
			margin-bottom: 8px;
			font-size: 13px;
			font-weight: 600;
		}
		#wumc-progress {
			width: 240px;
			height: 14px;
		}
		.wumc-log-title {
			font-size: 12px;
			color: #94a3b8;
			margin-bottom: 4px;
		}
		#wumc-log {
			max-height: 180px;
			overflow-y: auto;
			background: #1e293b;
			padding: 10px;
			border-radius: 4px;
			font-size: 12px;
			line-height: 1.5;
			color: #f1f5f9;
			margin: 0;
			white-space: pre-wrap;
			border: 1px solid #334155;
		}
		/* Common Toolbox presentation; explorer controls and data are unchanged. */
		.wumc-app.wutm-ui-root { margin: 20px 20px 0 0; }
		.wumc-app .wumc-topbar { border-radius: 16px; margin-bottom: 20px; }
		.wumc-app .wumc-toolbar { border-top: 1px solid #d8e2ef; border-radius: 14px; margin-bottom: 20px; padding: 18px; }
		.wumc-app .wumc-explorer { border-top: 1px solid #d8e2ef; border-radius: 14px; grid-template-columns: 230px minmax(0,1fr); }
		.wumc-app .wumc-file-card { border-radius: 12px; }
		.wumc-app .wumc-list-container { overflow-x: auto; }
		.wumc-app .wumc-table { min-width: 960px; }
		.wumc-app .wumc-brand { min-width: 0; }
		.wumc-app .wumc-brand h1 { color: inherit; }
		.wumc-app .wumc-topbar-warning { max-width: 100%; line-height: 1.6; }
		.wumc-app .wumc-pagination,.wumc-app .wumc-progress-row,.wumc-app .wumc-toolbar-actions { flex-wrap: wrap; }
		@media(max-width:960px) {
			.wumc-app .wumc-explorer { grid-template-columns: minmax(0,1fr); }
			.wumc-app .wumc-folder-tree { max-height: 240px; }
		}
		@media(max-width:600px) {
			.wumc-app.wutm-ui-root { margin: 10px 10px 0 0; }
			.wumc-app .wumc-toolbar-group,.wumc-app .wumc-tool-field { width: 100%; min-width: 0; }
			.wumc-app .wumc-tool-field input,.wumc-app .wumc-tool-field select { width: 100%; min-width: 0; }
			.wumc-app .wumc-grid-container { grid-template-columns: minmax(0,1fr); }
			.wumc-app .wumc-confirm-box { flex-wrap: wrap; max-width: 100%; }
			.wumc-app .wumc-empty-state { padding: 30px 10px; }
		}
		';
	}

	private static function get_inline_js() {
		return <<<'JS'
(function() {
	const viewport = document.getElementById('wumc-viewport');
	const checkPage = document.getElementById('wumc-check-page');
	const selectCleanBtn = document.getElementById('wumc-select-clean');
	const selectedCounter = document.getElementById('wumc-selected-counter');
	const dangerZone = document.getElementById('wumc-danger-zone');

	// 1. 切換「網格 / 詳細清單」檢視模式
	const viewBtns = document.querySelectorAll('.wumc-view-btn');
	let savedView = 'grid';
	try { savedView = localStorage.getItem('wutm_mc_view_mode') || 'grid'; } catch (e) {}
	if (viewport && viewBtns.length) {
		setViewMode(savedView);
		viewBtns.forEach(btn => {
			btn.addEventListener('click', () => setViewMode(btn.dataset.view));
		});
	}

	function setViewMode(mode) {
		if (!viewport) return;
		mode = mode === 'list' ? 'list' : 'grid';
		viewport.setAttribute('data-view-mode', mode);
		try { localStorage.setItem('wutm_mc_view_mode', mode); } catch (e) {}
		viewBtns.forEach(b => b.classList.toggle('is-active', b.dataset.view === mode));
	}

	// 2. 勾選狀態同步
	function getPrimaryCheckboxes() {
		return Array.from(document.querySelectorAll('.wumc-image-checkbox:not(:disabled)'));
	}

	function syncSelectionState() {
		const boxes = getPrimaryCheckboxes();
		let checkedCount = 0;
		boxes.forEach(box => {
			const id = box.value;
			const card = document.querySelector(`.wumc-file-card[data-media-row="${id}"]`);
			const mirror = document.querySelector(`.wumc-image-checkbox-mirror[data-id="${id}"]`);
			if (card) card.classList.toggle('is-selected', box.checked);
			if (mirror) mirror.checked = box.checked;
			if (box.checked) checkedCount++;
		});
		if (selectedCounter) {
			selectedCounter.textContent = `已選 ${checkedCount} 張`;
		}
		if (checkPage && boxes.length > 0) {
			checkPage.checked = checkedCount === boxes.length;
		}
	}

	document.querySelectorAll('.wumc-file-card').forEach(card => {
		card.addEventListener('click', (e) => {
			if (e.target.closest('a') || e.target.closest('label') || e.target.classList.contains('wumc-image-checkbox')) return;
			const cb = card.querySelector('.wumc-image-checkbox');
			if (cb && !cb.disabled) {
				cb.checked = !cb.checked;
				syncSelectionState();
			}
		});
	});

	document.querySelectorAll('.wumc-image-checkbox').forEach(cb => {
		cb.addEventListener('change', syncSelectionState);
	});

	document.querySelectorAll('.wumc-image-checkbox-mirror').forEach(mirror => {
		mirror.addEventListener('change', () => {
			const id = mirror.getAttribute('data-id');
			const primary = document.querySelector(`.wumc-image-checkbox[value="${id}"]`);
			if (primary && !primary.disabled) {
				primary.checked = mirror.checked;
				syncSelectionState();
			}
		});
	});

	if (checkPage) {
		checkPage.addEventListener('change', () => {
			getPrimaryCheckboxes().forEach(cb => { cb.checked = checkPage.checked; });
			syncSelectionState();
		});
	}

	// 快速勾選本頁所有「未偵測引用 (可清理)」的圖片
	if (selectCleanBtn) {
		selectCleanBtn.addEventListener('click', () => {
			getPrimaryCheckboxes().forEach(cb => {
				cb.checked = cb.getAttribute('data-clean') === '1';
			});
			syncSelectionState();
		});
	}

	// 3. 批次刪除與即時進度回饋
	if (!dangerZone || typeof window.WUTM_MC === 'undefined') return;

	const token = dangerZone.getAttribute('data-token');
	const totalCount = parseInt(dangerZone.getAttribute('data-total') || '0', 10);
	const confirmInput = document.getElementById('wumc-confirm');
	const btnSelected = document.getElementById('wumc-delete-selected');
	const btnAll = document.getElementById('wumc-delete-all');
	const btnPause = document.getElementById('wumc-pause');
	const progressArea = document.getElementById('wumc-progress-area');
	const progressBar = document.getElementById('wumc-progress');
	const statusText = document.getElementById('wumc-status');
	const logPre = document.getElementById('wumc-log');

	let isRunning = false;
	let pauseRequested = false;

	function appendLog(lines) {
		if (!lines || !lines.length) return;
		logPre.textContent += (logPre.textContent ? '\n' : '') + lines.join('\n');
		logPre.scrollTop = logPre.scrollHeight;
	}

	function markProcessingOnUI(ids) {
		ids.forEach(id => {
			const card = document.querySelector(`.wumc-file-card[data-media-row="${id}"]`);
			if (card) {
				card.classList.add('is-processing');
				const st = card.querySelector('[data-status-cell]');
				if (st) st.innerHTML = '<span class="wumc-tag wumc-tag-info">處理中...</span>';
			}
		});
	}

	function markOutcomeOnUI(outcomes) {
		if (!Array.isArray(outcomes)) return;
		outcomes.forEach(item => {
			const card = document.querySelector(`.wumc-file-card[data-media-row="${item.id}"]`);
			const rowList = document.querySelector(`tr[data-media-row-list="${item.id}"]`);
			const cb = document.querySelector(`.wumc-image-checkbox[value="${item.id}"]`);
			const mirror = document.querySelector(`.wumc-image-checkbox-mirror[data-id="${item.id}"]`);

			const safeNote = document.createElement('span');
			safeNote.textContent = item.note || '';
			const note = safeNote.innerHTML;
			const badgeHtml = item.status === 'deleted'
				? `<span class="wumc-tag wumc-tag-deleted">${note}</span>`
				: `<span class="wumc-tag wumc-tag-warn">${item.status === 'failed' ? '刪除失敗' : '保護略過'}：${note}</span>`;

			if (card) {
				card.classList.remove('is-processing');
				const st = card.querySelector('[data-status-cell]');
				if (st) st.innerHTML = badgeHtml;
				if (item.status === 'deleted') card.classList.add('is-deleted');
			}
			if (rowList) {
				const stList = rowList.querySelector(`[data-status-cell-list="${item.id}"]`);
				if (stList) stList.innerHTML = badgeHtml;
			}
			if (item.status === 'deleted') {
				if (cb) { cb.checked = false; cb.disabled = true; }
				if (mirror) { mirror.checked = false; mirror.disabled = true; }
			}
		});
		syncSelectionState();
	}

	async function sendBatch(payload) {
		const body = new URLSearchParams();
		body.set('action', 'wutm_mc_delete_images');
		body.set('nonce', window.WUTM_MC.nonce);
		body.set('token', token);
		body.set('confirmation', confirmInput.value.trim());
		Object.keys(payload).forEach(k => body.set(k, payload[k]));

		const controller = new AbortController();
		const timer = setTimeout(() => controller.abort(), 45000);
		try {
		const res = await fetch(window.WUTM_MC.ajax, {
			method: 'POST',
			signal: controller.signal,
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		});
		const json = await res.json();
		if (!res.ok && json.success) throw new Error('伺服器回應異常，請重新預覽確認結果。');
		return json;
		} finally { clearTimeout(timer); }
	}
	window.addEventListener('pagehide', () => { pauseRequested = true; });

	function checkUnlocked() {
		if (confirmInput.value.trim() !== window.WUTM_MC.confirm) {
			alert('請先在下方安全鎖定欄輸入「' + window.WUTM_MC.confirm + '」四個字以解鎖刪除功能。');
			confirmInput.focus();
			return false;
		}
		return true;
	}

	if (btnSelected) {
		btnSelected.addEventListener('click', async () => {
			if (isRunning || !checkUnlocked()) return;
			const selectedIds = getPrimaryCheckboxes().filter(cb => cb.checked).map(cb => parseInt(cb.value, 10));
			if (!selectedIds.length) {
				alert('請先勾選至少 1 張要刪除的圖片。');
				return;
			}
			if (!confirm(`確定要永久刪除本頁勾選的 ${selectedIds.length} 張圖片嗎？此動作無法復原。`)) {
				return;
			}

			isRunning = true;
			pauseRequested = false;
			btnSelected.disabled = true;
			btnAll.disabled = true;
			btnPause.hidden = false;
			btnPause.disabled = false;
			progressArea.hidden = false;
			progressBar.value = 0;

			const batchSize = window.WUTM_MC.batch || 20;
			let processed = 0, deleted = 0, skipped = 0, failed = 0;

			for (let i = 0; i < selectedIds.length; i += batchSize) {
				if (pauseRequested) {
					appendLog(['[已暫停] 使用者已手動暫停後續批次。']);
					break;
				}
				const chunk = selectedIds.slice(i, i + batchSize);
				markProcessingOnUI(chunk);
				statusText.textContent = `正在處理勾選圖片 (${processed} / ${selectedIds.length})...`;
				try {
					const json = await sendBatch({ mode: 'selected', ids: JSON.stringify(chunk) });
					if (!json.success) {
						appendLog(['[錯誤] ' + (json.data?.message || '未知錯誤')]);
						break;
					}
					const d = json.data;
					processed += d.processed;
					deleted += d.deleted;
					skipped += d.skipped;
					failed += d.failed;
					progressBar.value = Math.min(100, Math.round((processed / selectedIds.length) * 100));
					statusText.textContent = `完成 ${processed}/${selectedIds.length} 張 · 已刪除 ${deleted} · 保護略過 ${skipped} · 失敗 ${failed}`;
					appendLog(d.messages);
					markOutcomeOnUI(d.outcomes);
				} catch (err) {
					appendLog(['[連線異常] ' + err.message]);
					break;
				}
			}

			isRunning = false;
			btnSelected.disabled = false;
			btnAll.disabled = false;
			btnPause.hidden = true;
		});
	}

	if (btnAll) {
		btnAll.addEventListener('click', async () => {
			if (isRunning || !checkUnlocked()) return;
			if (!confirm(`確定要分批清理此預覽清單全部 ${totalCount} 張圖片嗎？（系統會略過可辨識的引用，未偵測到不代表確定未使用）`)) {
				return;
			}

			isRunning = true;
			pauseRequested = false;
			btnSelected.disabled = true;
			btnAll.disabled = true;
			btnPause.hidden = false;
			btnPause.disabled = false;
			progressArea.hidden = false;
			progressBar.value = 0;

			const batchSize = window.WUTM_MC.batch || 20;
			let processed = 0, deleted = 0, skipped = 0, failed = 0;

			for (let offset = 0; offset < totalCount; offset += batchSize) {
				if (pauseRequested) {
					appendLog(['[已暫停] 使用者已手動暫停後續批次。']);
					break;
				}
				statusText.textContent = `正在分批清理全部清單 (${processed} / ${totalCount})...`;
				try {
					const json = await sendBatch({ mode: 'all', offset: String(offset) });
					if (!json.success) {
						appendLog(['[錯誤] ' + (json.data?.message || '未知錯誤')]);
						break;
					}
					const d = json.data;
					processed += d.processed;
					deleted += d.deleted;
					skipped += d.skipped;
					failed += d.failed;
					progressBar.value = Math.min(100, Math.round((processed / totalCount) * 100));
					statusText.textContent = `進度 ${processed}/${totalCount} 張 · 已刪除 ${deleted} · 保護略過 ${skipped} · 失敗 ${failed}`;
					appendLog(d.messages);
					markOutcomeOnUI(d.outcomes);
				} catch (err) {
					appendLog(['[連線異常] ' + err.message]);
					break;
				}
			}

			isRunning = false;
			btnSelected.disabled = false;
			btnAll.disabled = false;
			btnPause.hidden = true;
		});
	}

	if (btnPause) {
		btnPause.addEventListener('click', () => {
			pauseRequested = true;
			btnPause.disabled = true;
			statusText.textContent += '（正在等待本批完成後暫停...）';
		});
	}
})();
JS;
	}
}

WUTM_Media_Safe_Cleaner::boot();

