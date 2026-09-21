<?php
/**
 * Plugin Name: Wumetax SEO Core
 * Description: 台灣繁中網站用的輕量 SEO 核心：編輯器側欄、SEO 健檢、Title、Meta Description、Canonical、Open Graph、Schema 與 Sitemap 控制。
 * Version: 1.2.0
 * Author: Wumetax
 * Author URI: https://wumetax.com/
 * Plugin URI: https://wumetax.com/
 * Text Domain: wumetax-seo-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wumetax_SEO_Core_v120' ) ) {

	final class Wumetax_SEO_Core_v120 {

		const VERSION        = '1.2.0';
		const OPTION_KEY     = 'wumetax_seo_core_settings_v100';
		const META_NONCE_KEY = 'wumetax_seo_core_nonce_v100';
		const META_NONCE_ACT = 'wumetax_seo_core_save_v100';
		const DASHBOARD_PAGE = 'wumetax-seo-core';
		const SETTINGS_PAGE  = 'wumetax-seo-core-settings';

		private static $settings = null;

		public static function init() {
			add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
			add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
			add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_assets' ] );
			add_action( 'wp_dashboard_setup', [ __CLASS__, 'dashboard_widget_setup' ] );

			add_action( 'init', [ __CLASS__, 'register_post_meta_fields' ], 20 );
			add_action( 'enqueue_block_editor_assets', [ __CLASS__, 'enqueue_block_editor_assets' ] );

			add_action( 'add_meta_boxes', [ __CLASS__, 'add_meta_boxes' ] );
			add_action( 'save_post', [ __CLASS__, 'save_meta_box' ], 10, 2 );

			add_filter( 'pre_get_document_title', [ __CLASS__, 'filter_document_title' ], 50 );
			add_filter( 'wp_robots', [ __CLASS__, 'filter_wp_robots' ], 50 );

			add_action( 'wp_head', [ __CLASS__, 'output_head_meta' ], 1 );
			add_action( 'wp_head', [ __CLASS__, 'output_schema' ], 99 );

			add_filter( 'robots_txt', [ __CLASS__, 'filter_robots_txt' ], 20, 2 );
			add_filter( 'wp_sitemaps_post_types', [ __CLASS__, 'filter_sitemap_post_types' ] );
			add_filter( 'wp_sitemaps_taxonomies', [ __CLASS__, 'filter_sitemap_taxonomies' ] );

			// Core canonical 會與本外掛輸出重複，改由本外掛統一處理。
			remove_action( 'wp_head', 'rel_canonical' );
		}

		/* =========================================================
		 * SETTINGS
		 * ======================================================= */

		private static function defaults() {
			return [
				'organization_name'        => get_bloginfo( 'name' ),
				'organization_alt_name'    => '',
				'organization_email'       => '',
				'default_og_image'         => 0,
				'google_verify'            => '',
				'bing_verify'              => '',
				'social_profiles'          => '',
				'robots_extra'             => '',
				'target_locale'            => 'zh_TW',
				'target_region'            => 'TW',
				'enable_schema'            => 1,
				'enable_og'                => 1,
				'enable_twitter'           => 1,
				'noindex_author_archives'  => 1,
				'noindex_date_archives'    => 1,
				'noindex_tag_archives'     => 0,
			];
		}

		private static function settings() {
			if ( null !== self::$settings ) {
				return self::$settings;
			}

			$saved = get_option( self::OPTION_KEY, [] );
			if ( ! is_array( $saved ) ) {
				$saved = [];
			}

			self::$settings = wp_parse_args( $saved, self::defaults() );
			return self::$settings;
		}

		public static function register_settings() {
			register_setting(
				'wumetax_seo_core_group',
				self::OPTION_KEY,
				[
					'type'              => 'array',
					'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
					'default'           => self::defaults(),
				]
			);
		}

		public static function sanitize_settings( $input ) {
			$defaults = self::defaults();
			$out      = $defaults;

			if ( ! is_array( $input ) ) {
				return $out;
			}

			$out['organization_name'] = isset( $input['organization_name'] )
				? sanitize_text_field( $input['organization_name'] )
				: $defaults['organization_name'];

			$out['organization_alt_name'] = isset( $input['organization_alt_name'] )
				? sanitize_text_field( $input['organization_alt_name'] )
				: '';

			$out['organization_email'] = isset( $input['organization_email'] )
				? sanitize_email( $input['organization_email'] )
				: '';

			$out['default_og_image'] = isset( $input['default_og_image'] )
				? absint( $input['default_og_image'] )
				: 0;

			$out['google_verify'] = isset( $input['google_verify'] )
				? sanitize_text_field( $input['google_verify'] )
				: '';

			$out['bing_verify'] = isset( $input['bing_verify'] )
				? sanitize_text_field( $input['bing_verify'] )
				: '';

			$out['social_profiles'] = isset( $input['social_profiles'] )
				? sanitize_textarea_field( $input['social_profiles'] )
				: '';

			$out['robots_extra'] = isset( $input['robots_extra'] )
				? sanitize_textarea_field( $input['robots_extra'] )
				: '';

			$allowed_locales = [ 'zh_TW' ];
			$locale = isset( $input['target_locale'] ) ? sanitize_text_field( $input['target_locale'] ) : 'zh_TW';
			$out['target_locale'] = in_array( $locale, $allowed_locales, true ) ? $locale : 'zh_TW';

			$region = isset( $input['target_region'] ) ? strtoupper( sanitize_text_field( $input['target_region'] ) ) : 'TW';
			$out['target_region'] = ( 'TW' === $region ) ? 'TW' : 'TW';

			$out['enable_schema']           = ! empty( $input['enable_schema'] ) ? 1 : 0;
			$out['enable_og']               = ! empty( $input['enable_og'] ) ? 1 : 0;
			$out['enable_twitter']          = ! empty( $input['enable_twitter'] ) ? 1 : 0;
			$out['noindex_author_archives'] = ! empty( $input['noindex_author_archives'] ) ? 1 : 0;
			$out['noindex_date_archives']   = ! empty( $input['noindex_date_archives'] ) ? 1 : 0;
			$out['noindex_tag_archives']    = ! empty( $input['noindex_tag_archives'] ) ? 1 : 0;

			self::$settings = null;
			return $out;
		}

		public static function admin_menu() {
			add_submenu_page(
				'wu-toolbox-modular',
				'SEO 健檢',
				'SEO 健檢',
				'manage_options',
				self::DASHBOARD_PAGE,
				[ __CLASS__, 'dashboard_page' ]
			);

			add_submenu_page(
				'wu-toolbox-modular',
				'全站 SEO 設定',
				'全站設定',
				'manage_options',
				self::SETTINGS_PAGE,
				[ __CLASS__, 'settings_page' ]
			);
		}

		public static function admin_assets( $hook ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$is_settings = ( false !== strpos( (string) $hook, self::SETTINGS_PAGE ) );
			$is_editor   = $screen && in_array( $screen->base, [ 'post', 'post-new' ], true );

			if ( $is_settings || $is_editor ) {
				wp_enqueue_media();
			}
		}

		/* =========================================================
		 * TAIWAN SEO DASHBOARD
		 * ======================================================= */

		public static function dashboard_widget_setup() {
			if ( current_user_can( 'manage_options' ) ) {
				wp_add_dashboard_widget(
					'wumetax_seo_health_widget',
					'Wumetax SEO｜台灣繁中健檢',
					[ __CLASS__, 'dashboard_widget' ]
				);
			}
		}

		public static function dashboard_widget() {
			$audit = self::get_site_audit();
			$score = (int) $audit['score'];
			$issues = array_values( array_filter( $audit['checks'], function( $check ) {
				return 'pass' !== $check['status'];
			} ) );
			?>
			<div style="display:flex;align-items:center;gap:16px;margin:6px 0 12px;">
				<div style="width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#f0f7f2;border:5px solid #4fa567;font-size:20px;font-weight:800;color:#171b19;"><?php echo esc_html( $score ); ?></div>
				<div>
					<strong style="font-size:15px;">網站設定完整度 <?php echo esc_html( $score ); ?>/100</strong>
					<p style="margin:4px 0 0;color:#646970;">這是站內健檢，不是 Google 官方排名分數。</p>
				</div>
			</div>
			<?php if ( $issues ) : ?>
				<ul style="margin:0 0 12px 18px;list-style:disc;">
					<?php foreach ( array_slice( $issues, 0, 3 ) as $issue ) : ?>
						<li><?php echo esc_html( $issue['title'] ); ?>：<?php echo esc_html( $issue['message'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p>目前主要 SEO 基礎設定完整。</p>
			<?php endif; ?>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) ); ?>">查看完整 SEO 健檢</a></p>
			<?php
		}

		private static function char_count( $text ) {
			$text = preg_replace( '/\\s+/u', '', wp_strip_all_tags( (string) $text ) );
			if ( function_exists( 'mb_strlen' ) ) {
				return (int) mb_strlen( (string) $text, 'UTF-8' );
			}
			return strlen( (string) $text );
		}

		private static function audit_check( $key, $title, $message, $status, $weight, $action_url = '', $action_label = '' ) {
			return [
				'key'          => $key,
				'title'        => $title,
				'message'      => $message,
				'status'       => in_array( $status, [ 'pass', 'partial', 'fail' ], true ) ? $status : 'partial',
				'weight'       => (float) $weight,
				'action_url'   => $action_url,
				'action_label' => $action_label,
			];
		}

		private static function homepage_id() {
			if ( 'page' === get_option( 'show_on_front' ) ) {
				return absint( get_option( 'page_on_front' ) );
			}
			return 0;
		}

		private static function content_audit_stats() {
			$post_types = self::supported_post_types();
			$ids = get_posts( [
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			] );

			$total            = count( $ids );
			$manual_title     = 0;
			$manual_desc      = 0;
			$has_image        = 0;
			$noindex          = 0;
			$fully_manual     = 0;
			$ready            = 0;
			$issues           = [];
			$type_stats       = [];
			$s                = self::settings();
			$global_image     = ! empty( $s['default_og_image'] ) && self::attachment_url( absint( $s['default_og_image'] ) );

			foreach ( $post_types as $post_type ) {
				$type_stats[ $post_type ] = [
					'type'  => $post_type,
					'label' => self::post_type_label( $post_type ),
					'count' => 0,
				];
			}

			foreach ( $ids as $post_id ) {
				$post_type = get_post_type( $post_id );
				if ( isset( $type_stats[ $post_type ] ) ) {
					$type_stats[ $post_type ]['count']++;
				}

				$manual_title_value = trim( (string) get_post_meta( $post_id, '_wu_seo_title', true ) );
				$manual_desc_value  = trim( (string) get_post_meta( $post_id, '_wu_seo_description', true ) );
				$title_manual       = '' !== $manual_title_value;
				$desc_manual        = '' !== $manual_desc_value;
				$image_ok           = absint( get_post_meta( $post_id, '_wu_seo_og_image', true ) ) > 0 || has_post_thumbnail( $post_id ) || $global_image;
				$is_noindex         = 1 === (int) get_post_meta( $post_id, '_wu_seo_noindex', true );

				$base_title = trim( (string) get_the_title( $post_id ) );
				$effective_title = $title_manual
					? $manual_title_value
					: trim( $base_title . ( $post_id === self::homepage_id() ? '' : '｜' . self::site_name() ) );

				$excerpt = trim( (string) get_post_field( 'post_excerpt', $post_id ) );
				$fallback_desc = $excerpt !== ''
					? self::clean_text( $excerpt, 160 )
					: self::clean_text( get_post_field( 'post_content', $post_id ), 160 );
				$effective_desc = $desc_manual ? $manual_desc_value : $fallback_desc;

				$title_ok = '' !== trim( $effective_title );
				$desc_ok  = '' !== trim( $effective_desc );
				$ready_ok = $title_ok && $desc_ok && $image_ok;

				if ( $title_manual ) { $manual_title++; }
				if ( $desc_manual ) { $manual_desc++; }
				if ( $image_ok ) { $has_image++; }
				if ( $is_noindex ) { $noindex++; }
				if ( $title_manual && $desc_manual && $image_ok ) { $fully_manual++; }
				if ( $ready_ok ) { $ready++; }

				if ( count( $issues ) < 30 ) {
					$reasons = [];
					if ( ! $desc_ok ) { $reasons[] = '缺少可用 Meta Description'; }
					if ( ! $image_ok ) { $reasons[] = '缺少社群分享圖片'; }
					$title_len = self::char_count( $effective_title );
					if ( $title_ok && ( $title_len < 8 || $title_len > 45 ) ) { $reasons[] = '標題長度可再檢查'; }
					if ( $reasons ) {
						$issues[] = [
							'id'         => $post_id,
							'title'      => get_the_title( $post_id ) ?: '(無標題)',
							'type'       => $post_type,
							'type_label' => self::post_type_label( $post_type ),
							'reasons'    => $reasons,
							'edit'       => get_edit_post_link( $post_id, 'raw' ),
						];
					}
				}
			}

			$type_stats = array_values( array_filter( $type_stats, static function( $item ) {
				return ! empty( $item['count'] );
			} ) );
			usort( $type_stats, static function( $a, $b ) {
				return $b['count'] <=> $a['count'];
			} );

			$coverage        = $total > 0 ? round( ( $ready / $total ) * 100 ) : 100;
			$manual_coverage = $total > 0 ? round( ( $fully_manual / $total ) * 100 ) : 0;

			return [
				'total'           => $total,
				'manual_title'    => $manual_title,
				'manual_desc'     => $manual_desc,
				'has_image'       => $has_image,
				'noindex'         => $noindex,
				'fully_manual'    => $fully_manual,
				'ready'           => $ready,
				'coverage'        => $coverage,
				'manual_coverage' => $manual_coverage,
				'type_stats'      => $type_stats,
				'issues'          => $issues,
			];
		}

		private static function seo_plugin_conflicts() {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$known = [
				'seo-by-rank-math/rank-math.php'          => 'Rank Math SEO',
				'wordpress-seo/wp-seo.php'                => 'Yoast SEO',
				'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
				'autodescription/autodescription.php'     => 'The SEO Framework',
				'wp-seopress/seopress.php'                => 'SEOPress',
			];
			$active = [];
			foreach ( $known as $plugin => $label ) {
				if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin ) ) {
					$active[] = $label;
				}
			}
			return $active;
		}

		private static function get_site_audit() {
			$s = self::settings();
			$checks = [];
			$settings_url = admin_url( 'admin.php?page=' . self::SETTINGS_PAGE );
			$general_url  = admin_url( 'options-general.php' );
			$reading_url  = admin_url( 'options-reading.php' );
			$permalink_url= admin_url( 'options-permalink.php' );
			$home_id = self::homepage_id();

			$public = (int) get_option( 'blog_public' ) === 1;
			$checks[] = self::audit_check(
				'visibility', '搜尋引擎可見性',
				$public ? '網站允許搜尋引擎建立索引。' : '目前 WordPress 設定為阻擋搜尋引擎建立索引，正式站應立即確認。',
				$public ? 'pass' : 'fail', 15, $reading_url, '前往閱讀設定'
			);

			$https = 'https' === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
			$checks[] = self::audit_check(
				'https', 'HTTPS',
				$https ? '首頁網址使用 HTTPS。' : '首頁不是 HTTPS，正式網站建議使用有效 SSL。',
				$https ? 'pass' : 'fail', 8, $general_url, '檢查 WordPress 網址'
			);

			$pretty = '' !== (string) get_option( 'permalink_structure' );
			$checks[] = self::audit_check(
				'permalink', '固定網址',
				$pretty ? '已使用可讀性的固定網址結構。' : '目前仍是純數字 / 查詢式網址，建議改為文章名稱等可讀結構。',
				$pretty ? 'pass' : 'fail', 6, $permalink_url, '前往固定網址設定'
			);

			$site_title = trim( self::site_name() );
			$checks[] = self::audit_check(
				'site_title', '網站名稱',
				$site_title ? '網站名稱已設定：' . $site_title : '尚未設定 WordPress 網站名稱。',
				$site_title ? 'pass' : 'fail', 5, $general_url, '前往一般設定'
			);

			$wp_locale = get_locale();
			$locale_ok = ( 'zh_TW' === $wp_locale );
			$checks[] = self::audit_check(
				'locale', '台灣繁中語系',
				$locale_ok ? 'WordPress 網站語言為繁體中文（台灣），SEO Core 會輸出 zh-TW / zh_TW。' : '目前 WordPress locale 為 ' . $wp_locale . '。若主要受眾是台灣，建議網站語言使用繁體中文（台灣）。',
				$locale_ok ? 'pass' : 'partial', 8, $general_url, '檢查網站語言'
			);

			if ( $home_id ) {
				$home_title = trim( (string) get_post_meta( $home_id, '_wu_seo_title', true ) );
				$home_desc  = trim( (string) get_post_meta( $home_id, '_wu_seo_description', true ) );
				$home_edit  = get_edit_post_link( $home_id, 'raw' );
				$checks[] = self::audit_check(
					'home_title', '首頁 SEO Title',
					$home_title ? '首頁已有自訂 SEO Title。' : '首頁目前使用自動標題，建議為首頁手動撰寫更聚焦的品牌＋服務標題。',
					$home_title ? 'pass' : 'partial', 6, $home_edit, '編輯首頁 SEO'
				);
				$checks[] = self::audit_check(
					'home_desc', '首頁 Meta Description',
					$home_desc ? '首頁已有自訂 Meta Description。' : '首頁目前使用自動摘要，建議手動撰寫台灣使用者看得懂的服務說明。',
					$home_desc ? 'pass' : 'partial', 8, $home_edit, '編輯首頁 SEO'
				);
			} else {
				$tagline = trim( (string) get_bloginfo( 'description' ) );
				$checks[] = self::audit_check( 'home_title', '首頁 SEO Title', '目前首頁不是固定頁面，將使用網站名稱作為主要標題。', $site_title ? 'pass' : 'partial', 6, $general_url, '檢查網站名稱' );
				$checks[] = self::audit_check( 'home_desc', '首頁 Meta Description', $tagline ? '目前首頁會使用網站標語作為描述 fallback。' : '首頁沒有固定頁面，也沒有網站標語可當描述。', $tagline ? 'partial' : 'fail', 8, $general_url, '設定網站標語' );
			}

			$og_image = ! empty( $s['default_og_image'] ) && self::attachment_url( absint( $s['default_og_image'] ) );
			$checks[] = self::audit_check(
				'og_image', '預設社群分享圖片',
				$og_image ? '已設定全站 fallback 圖片。' : '尚未設定預設 OG 圖片；沒有精選圖片的頁面分享時可能缺少主視覺。',
				$og_image ? 'pass' : 'partial', 6, $settings_url, '設定預設分享圖片'
			);

			$identity_ok = self::site_logo_url() || get_site_icon_url( 512 );
			$checks[] = self::audit_check(
				'identity', '品牌 Logo / 網站圖示',
				$identity_ok ? '可取得品牌 Logo 或網站圖示供 Schema / 分享 fallback 使用。' : '尚未偵測到自訂 Logo 或網站圖示。',
				$identity_ok ? 'pass' : 'partial', 5, admin_url( 'customize.php' ), '設定網站識別'
			);

			$checks[] = self::audit_check( 'schema', '結構化資料', ! empty( $s['enable_schema'] ) ? 'JSON-LD Schema 已啟用。' : 'Schema 已關閉。', ! empty( $s['enable_schema'] ) ? 'pass' : 'partial', 5, $settings_url, '檢查 Schema 設定' );
			$checks[] = self::audit_check( 'og', 'Open Graph', ! empty( $s['enable_og'] ) ? 'Open Graph 已啟用，分享至 LINE / Facebook 等平台可取得標題與圖片。' : 'Open Graph 已關閉。', ! empty( $s['enable_og'] ) ? 'pass' : 'partial', 4, $settings_url, '檢查 Open Graph' );

			$sitemap_ok = function_exists( 'wp_sitemaps_get_server' ) && $public;
			$checks[] = self::audit_check(
				'sitemap', 'XML Sitemap',
				$sitemap_ok ? 'WordPress 原生 Sitemap 可使用：' . home_url( '/wp-sitemap.xml' ) : '目前無法確認 WordPress Sitemap 可公開使用。',
				$sitemap_ok ? 'pass' : 'fail', 6, home_url( '/wp-sitemap.xml' ), '開啟 Sitemap'
			);

			$google_ok = '' !== trim( (string) $s['google_verify'] );
			$checks[] = self::audit_check(
				'google', 'Google Search Console 驗證',
				$google_ok ? '已填入 Search Console 驗證 token。' : '尚未填入 Google Search Console HTML meta 驗證 token。',
				$google_ok ? 'pass' : 'partial', 5, $settings_url, '設定 Google 驗證'
			);

			$org_ok = '' !== trim( (string) $s['organization_name'] );
			$checks[] = self::audit_check( 'organization', 'Organization 資訊', $org_ok ? '組織名稱已設定：' . $s['organization_name'] : '尚未設定組織名稱。', $org_ok ? 'pass' : 'partial', 3, $settings_url, '補充組織資料' );

			$content  = self::content_audit_stats();
			$coverage = (int) $content['coverage'];
			$status   = $coverage >= 90 ? 'pass' : ( $coverage >= 70 ? 'partial' : 'fail' );
			$checks[] = self::audit_check(
				'content', '公開內容 SEO 可用率',
				$content['total'] > 0
					? sprintf( '只統計真正可在前台獨立開啟的已發佈內容，共 %d 篇；%d 篇已有可用標題、摘要與分享圖片（%d%%）。其中 %d 篇有自訂 SEO Title、%d 篇有自訂 Meta Description。Blocksy Content Blocks、範本與附件不計入。', $content['total'], $content['ready'], $coverage, $content['manual_title'], $content['manual_desc'] )
					: '目前沒有可被搜尋引擎直接開啟的已發佈內容可檢查。',
				$status, 10, '', ''
			);

			$total_weight = 0.0;
			$earned = 0.0;
			foreach ( $checks as $check ) {
				$total_weight += $check['weight'];
				$factor = 'pass' === $check['status'] ? 1 : ( 'partial' === $check['status'] ? 0.5 : 0 );
				if ( 'content' === $check['key'] ) {
					$factor = max( 0, min( 1, $coverage / 90 ) );
				}
				$earned += $check['weight'] * $factor;
			}
			$score = $total_weight > 0 ? (int) round( ( $earned / $total_weight ) * 100 ) : 100;

			return [
				'score'     => max( 0, min( 100, $score ) ),
				'checks'    => $checks,
				'content'   => $content,
				'conflicts' => self::seo_plugin_conflicts(),
			];
		}

		private static function audit_status_label( $status ) {
			if ( 'pass' === $status ) { return '完成'; }
			if ( 'fail' === $status ) { return '需處理'; }
			return '建議補充';
		}

		public static function dashboard_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$audit   = self::get_site_audit();
			$score   = (int) $audit['score'];
			$good    = count( array_filter( $audit['checks'], static fn( $c ) => 'pass' === $c['status'] ) );
			$warn    = count( array_filter( $audit['checks'], static fn( $c ) => 'partial' === $c['status'] ) );
			$bad     = count( array_filter( $audit['checks'], static fn( $c ) => 'fail' === $c['status'] ) );
			$content = $audit['content'];
			?>
			<div class="wrap wutm-module-wrap wu-seo-health">
				<style>
					.wu-seo-health{max-width:1240px;margin-top:24px}.wu-seo-health *{box-sizing:border-box}.wu-seo-health h1{font-size:28px;margin:0 0 7px}.wu-seo-health .lead{margin:0;color:#646970;line-height:1.7;max-width:980px}.wu-seo-top{display:grid;grid-template-columns:270px minmax(0,1fr);gap:18px;margin:24px 0}.wu-seo-card{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:22px;box-shadow:0 5px 22px rgba(0,0,0,.025)}.wu-animate{opacity:0;transform:translateY(8px);transition:opacity .28s ease,transform .28s ease}.wu-animate.is-visible{opacity:1;transform:translateY(0)}.wu-score-card{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;min-height:240px}.wu-score-ring{width:132px;height:132px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:conic-gradient(#4fa567 0%,#e8ece9 0);position:relative;box-shadow:0 12px 34px rgba(52,124,75,.08)}.wu-score-ring:before{content:"";position:absolute;inset:10px;background:#fff;border-radius:50%}.wu-score-number{position:relative;z-index:2;font-size:36px;font-weight:800;color:#171b19}.wu-score-card strong{font-size:16px;margin-top:14px}.wu-score-card small{display:block;color:#72777c;line-height:1.6;margin-top:5px}.wu-overview{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:18px}.wu-metric{background:#f7f9f8;border:1px solid transparent;border-radius:11px;padding:15px;transition:border-color .2s ease,transform .2s ease}.wu-metric:hover{border-color:#dce6df;transform:translateY(-1px)}.wu-metric b{display:block;font-size:25px;color:#171b19}.wu-metric span{font-size:12px;color:#646970}.wu-note{margin-top:16px;padding:12px 14px;border-radius:10px;background:#f0f7f2;color:#355441;line-height:1.65}.wu-conflict{margin:18px 0;padding:14px 16px;border-left:4px solid #d63638;background:#fff2f2;border-radius:8px}.wu-checks{overflow:hidden;padding:0}.wu-check{display:grid;grid-template-columns:112px minmax(0,1fr) auto;gap:15px;align-items:center;padding:17px 20px;border-bottom:1px solid #eee;transition:background .18s ease}.wu-check:hover{background:#fbfcfb}.wu-check:last-child{border-bottom:0}.wu-pill{display:inline-flex;justify-content:center;min-width:78px;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700}.wu-pill.pass{background:#edf8f0;color:#24733d}.wu-pill.partial{background:#fff7e5;color:#8b5e00}.wu-pill.fail{background:#fff0f0;color:#b32d2e}.wu-check h3{font-size:14px;margin:0 0 4px}.wu-check p{margin:0;color:#646970;line-height:1.55}.wu-content-head{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-top:28px}.wu-content-head h2{margin-bottom:5px}.wu-content-head p{margin:0;color:#646970}.wu-type-filters{display:flex;flex-wrap:wrap;gap:7px;justify-content:flex-end}.wu-type-filter{appearance:none;border:1px solid #d7ddd9;background:#fff;color:#425048;padding:6px 10px;border-radius:999px;font-size:12px;cursor:pointer;transition:.18s ease}.wu-type-filter:hover,.wu-type-filter.is-active{border-color:#4fa567;background:#edf8f0;color:#24733d}.wu-content-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin:16px 0 12px}.wu-stat{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:17px;min-height:92px;transition:transform .2s ease,border-color .2s ease}.wu-stat:hover{transform:translateY(-2px);border-color:#cfdad3}.wu-stat b{display:block;font-size:25px;margin-bottom:4px}.wu-stat span{color:#646970;font-size:12px}.wu-scope-note{margin:0 0 22px;color:#646970;font-size:12px;line-height:1.6}.wu-issues{width:100%;border-collapse:collapse}.wu-issues th,.wu-issues td{text-align:left;padding:13px 14px;border-bottom:1px solid #eee;vertical-align:top}.wu-issues th{background:#f7f8f7;font-size:12px}.wu-issues td{background:#fff}.wu-issues tr{transition:opacity .16s ease}.wu-issues .wu-type-badge{display:inline-flex;margin-top:4px;padding:2px 7px;border-radius:999px;background:#f1f4f2;color:#66716b;font-size:10px}.wu-empty-filter{display:none;padding:22px;text-align:center;color:#72777c;background:#fff}.wu-help{display:grid;grid-template-columns:repeat(3,1fr);gap:15px}.wu-help h3{margin:0 0 8px;font-size:15px}.wu-help p{margin:0;color:#646970;line-height:1.7}.wu-help code{font-size:12px}@media(max-width:1050px){.wu-content-grid{grid-template-columns:repeat(3,1fr)}}@media(max-width:900px){.wu-seo-top{grid-template-columns:1fr}.wu-overview,.wu-help{grid-template-columns:1fr}.wu-content-head{align-items:flex-start;flex-direction:column}.wu-type-filters{justify-content:flex-start}.wu-check{grid-template-columns:90px 1fr}.wu-check .button{grid-column:2}.wu-issues{display:block;overflow-x:auto}}@media(max-width:620px){.wu-content-grid{grid-template-columns:repeat(2,1fr)}}@media(prefers-reduced-motion:reduce){.wu-animate,.wu-stat,.wu-metric,.wu-check{transition:none!important;transform:none!important}}
				</style>

				<h1>Wumetax SEO｜台灣繁中健檢</h1>
				<p class="lead">用台灣繁體中文網站的實際設定需求來檢查，不做「關鍵字塞越多分數越高」的玩法。分數代表站內 SEO 設定完整度，不代表 Google 官方排名，也不是流量預測。</p>

				<?php if ( ! empty( $audit['conflicts'] ) ) : ?>
					<div class="wu-conflict"><strong>偵測到其他 SEO 外掛：</strong> <?php echo esc_html( implode( '、', $audit['conflicts'] ) ); ?>。請避免同時輸出 Title、Meta Description、Canonical、OG 或 Schema，以免重複。</div>
				<?php endif; ?>

				<div class="wu-seo-top">
					<div class="wu-seo-card wu-score-card wu-animate">
						<div class="wu-score-ring" id="wu-seo-score-ring" data-score="<?php echo esc_attr( $score ); ?>"><div class="wu-score-number" id="wu-seo-score-number">0</div></div>
						<strong>網站 SEO 設定完整度</strong>
						<small>技術基礎、台灣語系、首頁資訊、分享資料與真正公開內容的 SEO 可用率。</small>
					</div>
					<div class="wu-seo-card wu-animate">
						<h2 style="margin-top:0;">目前狀況</h2>
						<div class="wu-overview">
							<div class="wu-metric"><b class="wu-count" data-count="<?php echo esc_attr( $good ); ?>">0</b><span>已完成</span></div>
							<div class="wu-metric"><b class="wu-count" data-count="<?php echo esc_attr( $warn ); ?>">0</b><span>建議補充</span></div>
							<div class="wu-metric"><b class="wu-count" data-count="<?php echo esc_attr( $bad ); ?>">0</b><span>需要處理</span></div>
						</div>
						<div class="wu-note"><strong>這版已把「公開內容」重新定義清楚：</strong>只統計真正可以在前台獨立開啟、而且已發佈的文章、頁面、Lab、客戶案例、支援文件等內容。Blocksy Content Blocks、範本、附件與內部元件不再計入，因此不會再出現把後台模板也算成 SEO 頁面的情況。</div>
						<p style="margin:16px 0 0;"><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_PAGE ) ); ?>">全站 SEO 設定</a> <a class="button" href="<?php echo esc_url( home_url( '/wp-sitemap.xml' ) ); ?>" target="_blank" rel="noopener">查看 Sitemap</a></p>
					</div>
				</div>

				<h2>網站設定檢查</h2>
				<div class="wu-seo-card wu-checks wu-animate">
					<?php foreach ( $audit['checks'] as $check ) : ?>
						<div class="wu-check">
							<div><span class="wu-pill <?php echo esc_attr( $check['status'] ); ?>"><?php echo esc_html( self::audit_status_label( $check['status'] ) ); ?></span></div>
							<div><h3><?php echo esc_html( $check['title'] ); ?></h3><p><?php echo esc_html( $check['message'] ); ?></p></div>
							<div><?php if ( $check['action_url'] ) : ?><a class="button" href="<?php echo esc_url( $check['action_url'] ); ?>" <?php echo 0 === strpos( $check['action_url'], home_url() ) ? 'target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $check['action_label'] ?: '前往處理' ); ?></a><?php endif; ?></div>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="wu-content-head">
					<div><h2>公開內容 SEO 狀況</h2><p>以下才是實際會被當成 SEO 頁面的已發佈內容。</p></div>
					<div class="wu-type-filters" id="wu-type-filters">
						<button type="button" class="wu-type-filter is-active" data-type="all">全部 <?php echo esc_html( $content['total'] ); ?></button>
						<?php foreach ( $content['type_stats'] as $type ) : ?>
							<button type="button" class="wu-type-filter" data-type="<?php echo esc_attr( $type['type'] ); ?>"><?php echo esc_html( $type['label'] ); ?> <?php echo esc_html( $type['count'] ); ?></button>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="wu-content-grid wu-animate">
					<div class="wu-stat"><b class="wu-count" data-count="<?php echo esc_attr( $content['total'] ); ?>">0</b><span>SEO 公開內容</span></div>
					<div class="wu-stat"><b class="wu-count" data-count="<?php echo esc_attr( $content['ready'] ); ?>">0</b><span>可直接輸出完整 SEO</span></div>
					<div class="wu-stat"><b class="wu-count" data-count="<?php echo esc_attr( $content['manual_title'] ); ?>">0</b><span>自訂 SEO Title</span></div>
					<div class="wu-stat"><b class="wu-count" data-count="<?php echo esc_attr( $content['manual_desc'] ); ?>">0</b><span>自訂 Description</span></div>
					<div class="wu-stat"><b class="wu-count" data-count="<?php echo esc_attr( $content['has_image'] ); ?>">0</b><span>具有分享圖片</span></div>
					<div class="wu-stat"><b class="wu-count" data-count="<?php echo esc_attr( $content['noindex'] ); ?>">0</b><span>手動 noindex</span></div>
				</div>
				<p class="wu-scope-note">SEO 可用率：<?php echo esc_html( $content['coverage'] ); ?>%。自訂欄位不是每一頁都一定要填；只要自動 fallback 已能產生有效標題與摘要，就不會被視為錯誤。重要服務頁、首頁與主要文章仍建議手動優化。</p>

				<?php if ( ! empty( $content['issues'] ) ) : ?>
					<div class="wu-seo-card wu-animate" style="padding:0;overflow:hidden;">
						<table class="wu-issues" id="wu-seo-issues">
							<thead><tr><th>內容</th><th>建議補充</th><th>操作</th></tr></thead>
							<tbody>
							<?php foreach ( $content['issues'] as $item ) : ?>
								<tr data-type="<?php echo esc_attr( $item['type'] ); ?>"><td><strong><?php echo esc_html( $item['title'] ); ?></strong><br><span class="wu-type-badge"><?php echo esc_html( $item['type_label'] ); ?></span></td><td><?php echo esc_html( implode( '、', $item['reasons'] ) ); ?></td><td><?php if ( $item['edit'] ) : ?><a class="button" href="<?php echo esc_url( $item['edit'] ); ?>">編輯 SEO</a><?php endif; ?></td></tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<div class="wu-empty-filter" id="wu-empty-filter">這個內容類型目前沒有需要補充的項目。</div>
					</div>
				<?php else : ?>
					<div class="wu-seo-card wu-animate"><strong>目前沒有明顯缺漏。</strong><p style="margin:6px 0 0;color:#646970;">公開內容都已有可用的標題、摘要與分享圖片。</p></div>
				<?php endif; ?>

				<h2>如何設定</h2>
				<div class="wu-help">
					<div class="wu-seo-card wu-animate"><h3>1. 先完成全站基本設定</h3><p>網站語言使用「繁體中文（台灣）」、確認 HTTPS 與固定網址，再設定品牌名稱、預設分享圖片及 Search Console 驗證。</p></div>
					<div class="wu-seo-card wu-animate"><h3>2. 重要頁面才優先手動優化</h3><p>首頁、網站服務、主機維護、案例、主要文章與 Lab 優先設定。其他頁面可先使用自動 fallback，不需要為了追求 100 分把每頁都硬填一次。</p></div>
					<div class="wu-seo-card wu-animate"><h3>3. 上線後用 Search Console 驗證</h3><p>提交 <code>/wp-sitemap.xml</code>，重要新頁可使用網址檢查要求重新建立索引。實際收錄與排名仍由 Google 判定。</p></div>
				</div>
			</div>

			<script>
			(function(){
				'use strict';
				const reduced=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
				const animated=document.querySelectorAll('.wu-seo-health .wu-animate');
				animated.forEach(function(el,i){window.setTimeout(function(){el.classList.add('is-visible');},reduced?0:i*45);});

				const ring=document.getElementById('wu-seo-score-ring');
				const scoreNumber=document.getElementById('wu-seo-score-number');
				const counters=document.querySelectorAll('.wu-seo-health .wu-count');
				const targetScore=ring?parseInt(ring.dataset.score||'0',10):0;
				const duration=reduced?0:650;
				const start=performance.now();
				function animate(now){
					const progress=duration===0?1:Math.min(1,(now-start)/duration);
					const eased=1-Math.pow(1-progress,3);
					if(ring){const v=Math.round(targetScore*eased);ring.style.background='conic-gradient(#4fa567 '+v+'%,#e8ece9 0)';scoreNumber.textContent=String(v);}
					counters.forEach(function(el){const target=parseInt(el.dataset.count||'0',10);el.textContent=String(Math.round(target*eased));});
					if(progress<1){requestAnimationFrame(animate);}
				}
				requestAnimationFrame(animate);

				const filters=document.querySelectorAll('.wu-type-filter');
				const rows=document.querySelectorAll('#wu-seo-issues tbody tr');
				const empty=document.getElementById('wu-empty-filter');
				filters.forEach(function(btn){btn.addEventListener('click',function(){
					filters.forEach(function(b){b.classList.remove('is-active');});btn.classList.add('is-active');
					const type=btn.dataset.type||'all';let visible=0;
					rows.forEach(function(row){const show=type==='all'||row.dataset.type===type;row.style.display=show?'':'none';if(show){visible++;}});
					if(empty){empty.style.display=visible===0?'block':'none';}
				});});
			})();
			</script>
			<?php
		}

		public static function settings_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$s = self::settings();
			$default_img = self::attachment_url( absint( $s['default_og_image'] ) );
			?>
			<div class="wrap wutm-module-wrap wu-seo-wrap">
				<style>
					.wu-seo-wrap{max-width:980px;margin-top:24px}
					.wu-seo-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:24px 26px;margin:18px 0;box-shadow:0 4px 20px rgba(0,0,0,.025)}
					.wu-seo-grid{display:grid;grid-template-columns:220px minmax(0,1fr);gap:24px;padding:20px 0;border-bottom:1px solid #eee}
					.wu-seo-grid:last-child{border-bottom:0}
					.wu-seo-label strong{display:block;margin-bottom:6px}.wu-seo-label p{margin:0;color:#646970;line-height:1.6}
					.wu-seo-input{width:100%;max-width:620px}.wu-seo-textarea{width:100%;max-width:620px;min-height:110px}
					.wu-seo-image-preview{width:260px;min-height:145px;border:1px dashed #c3c4c7;border-radius:10px;background:#f6f7f7;display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:10px;color:#72777c}
					.wu-seo-image-preview img{max-width:100%;max-height:180px;display:block}
					.wu-seo-toggle{display:flex;gap:10px;align-items:center;margin:0 0 10px}
					@media(max-width:760px){.wu-seo-grid{grid-template-columns:1fr}}
				</style>

				<h1>Wumetax SEO Core</h1>
				<p>台灣繁中網站用的輕量 SEO 核心。前台不載入 JavaScript 或 CSS；設定完成度請至 <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ) ); ?>">SEO 健檢</a> 查看。</p>

				<form method="post" action="options.php">
					<?php settings_fields( 'wumetax_seo_core_group' ); ?>

					<div class="wu-seo-card">
						<h2>全站設定</h2>

						<div class="wu-seo-grid">
							<div class="wu-seo-label"><strong>組織名稱</strong><p>用於 Organization Schema。</p></div>
							<div><input class="regular-text wu-seo-input" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[organization_name]" value="<?php echo esc_attr( $s['organization_name'] ); ?>"></div>
						</div>


						<div class="wu-seo-grid">
							<div class="wu-seo-label"><strong>品牌別名</strong><p>例如中文公司名與英文品牌名不同時可填。用於 Organization alternateName。</p></div>
							<div><input class="regular-text wu-seo-input" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[organization_alt_name]" value="<?php echo esc_attr( $s['organization_alt_name'] ); ?>" placeholder="例如：Wumetax LTD."></div>
						</div>

						<div class="wu-seo-grid">
							<div class="wu-seo-label"><strong>公開聯絡 Email</strong><p>若要在 Organization Schema 中提供聯絡方式可填；沒有需求可留空。</p></div>
							<div><input class="regular-text wu-seo-input" type="email" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[organization_email]" value="<?php echo esc_attr( $s['organization_email'] ); ?>" placeholder="contact@example.com"></div>
						</div>

						<div class="wu-seo-grid">
							<div class="wu-seo-label"><strong>主要市場與語系</strong><p>此版本以台灣繁體中文網站為主要用途。</p></div>
							<div>
								<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[target_locale]" value="zh_TW">
								<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[target_region]" value="TW">
								<strong>台灣（繁體中文）</strong><br><span style="color:#646970">Schema：zh-TW / Open Graph：zh_TW / 區域：TW。WordPress「網站語言」仍建議設為「繁體中文」。</span>
							</div>
						</div>

						<div class="wu-seo-grid">
							<div class="wu-seo-label"><strong>預設社群分享圖片</strong><p>文章沒有個別 OG 圖或精選圖片時使用。</p></div>
							<div>
								<input type="hidden" id="wu-seo-default-image-id" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_og_image]" value="<?php echo esc_attr( absint( $s['default_og_image'] ) ); ?>">
								<div class="wu-seo-image-preview" id="wu-seo-default-image-preview">
									<?php if ( $default_img ) : ?><img src="<?php echo esc_url( $default_img ); ?>" alt=""><?php else : ?>尚未設定圖片<?php endif; ?>
								</div>
								<button type="button" class="button" id="wu-seo-default-image-select">選擇圖片</button>
								<button type="button" class="button" id="wu-seo-default-image-remove">移除</button>
							</div>
						</div>

						<div class="wu-seo-grid">
							<div class="wu-seo-label"><strong>網站驗證</strong><p>只填 verification token，不要貼整段 meta tag。</p></div>
							<div>
								<p><label>Google Search Console<br><input class="regular-text wu-seo-input" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[google_verify]" value="<?php echo esc_attr( $s['google_verify'] ); ?>"></label></p>
								<p><label>Bing Webmaster Tools<br><input class="regular-text wu-seo-input" type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bing_verify]" value="<?php echo esc_attr( $s['bing_verify'] ); ?>"></label></p>
							</div>
						</div>

						<div class="wu-seo-grid">
							<div class="wu-seo-label"><strong>社群網址</strong><p>Organization Schema 的 sameAs，每行一個完整網址。</p></div>
							<div><textarea class="wu-seo-textarea" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[social_profiles]" placeholder="https://www.facebook.com/...&#10;https://www.instagram.com/..."> <?php echo esc_textarea( $s['social_profiles'] ); ?></textarea></div>
						</div>

						<div class="wu-seo-grid">
							<div class="wu-seo-label"><strong>robots.txt 額外規則</strong><p>只會附加到 WordPress robots.txt 後方。沒有需求可留空。</p></div>
							<div><textarea class="wu-seo-textarea" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[robots_extra]" placeholder="Disallow: /private/"> <?php echo esc_textarea( $s['robots_extra'] ); ?></textarea></div>
						</div>

						<div class="wu-seo-grid">
							<div class="wu-seo-label"><strong>輸出功能</strong><p>一般建議全部開啟。</p></div>
							<div>
								<label class="wu-seo-toggle"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_og]" value="1" <?php checked( 1, $s['enable_og'] ); ?>> Open Graph</label>
								<label class="wu-seo-toggle"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_twitter]" value="1" <?php checked( 1, $s['enable_twitter'] ); ?>> Twitter Card</label>
								<label class="wu-seo-toggle"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_schema]" value="1" <?php checked( 1, $s['enable_schema'] ); ?>> JSON-LD Schema</label>
							</div>
						</div>


						<div class="wu-seo-grid">
							<div class="wu-seo-label"><strong>低價值彙整頁索引</strong><p>一人公司或內容作者單一時，作者與日期彙整頁常與文章分類內容重複；預設 noindex，但不會禁止 Google 爬取文章本身。</p></div>
							<div>
								<label class="wu-seo-toggle"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[noindex_author_archives]" value="1" <?php checked( 1, $s['noindex_author_archives'] ); ?>> 作者彙整頁 noindex</label>
								<label class="wu-seo-toggle"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[noindex_date_archives]" value="1" <?php checked( 1, $s['noindex_date_archives'] ); ?>> 日期彙整頁 noindex</label>
								<label class="wu-seo-toggle"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[noindex_tag_archives]" value="1" <?php checked( 1, $s['noindex_tag_archives'] ); ?>> 標籤彙整頁 noindex（有經營標籤頁就不要勾）</label>
							</div>
						</div>
					</div>

					<?php submit_button( '儲存 SEO 設定' ); ?>
				</form>
			</div>

			<script>
			(function(){
				'use strict';
				const selectBtn=document.getElementById('wu-seo-default-image-select');
				const removeBtn=document.getElementById('wu-seo-default-image-remove');
				const idInput=document.getElementById('wu-seo-default-image-id');
				const preview=document.getElementById('wu-seo-default-image-preview');
				if(!selectBtn||!removeBtn||!idInput||!preview||typeof wp==='undefined'||!wp.media){return;}
				let frame=null;
				selectBtn.addEventListener('click',function(e){
					e.preventDefault();
					if(frame){frame.open();return;}
					frame=wp.media({title:'選擇預設社群分享圖片',button:{text:'使用這張圖片'},library:{type:'image'},multiple:false});
					frame.on('select',function(){
						const a=frame.state().get('selection').first().toJSON();
						if(!a||!a.id){return;}
						idInput.value=a.id;
						const url=(a.sizes&&a.sizes.medium_large)?a.sizes.medium_large.url:a.url;
						preview.innerHTML='';
						const img=document.createElement('img');img.src=url;img.alt='';preview.appendChild(img);
					});
					frame.open();
				});
				removeBtn.addEventListener('click',function(e){e.preventDefault();idInput.value='0';preview.textContent='尚未設定圖片';});
			})();
			</script>
			<?php
		}

		/* =========================================================
		 * META BOX
		 * ======================================================= */

		private static function excluded_post_types() {
			return [
				'attachment',
				'ct_content_block',
				'wp_block',
				'wp_template',
				'wp_template_part',
				'wp_navigation',
				'wp_global_styles',
				'wp_font_family',
				'wp_font_face',
				'custom_css',
				'customize_changeset',
				'oembed_cache',
				'elementor_library',
				'e-landing-page',
				'nav_menu_item',
			];
		}

		private static function supported_post_types() {
			$types   = [];
			$deny    = self::excluded_post_types();
			$objects = get_post_types( [], 'objects' );

			foreach ( $objects as $name => $object ) {
				if ( in_array( $name, $deny, true ) ) {
					continue;
				}

				if ( ! $object->show_ui ) {
					continue;
				}

				// 只把真正能在前台獨立開啟的內容算進 SEO。
				if ( 'post' !== $name && 'page' !== $name && ! is_post_type_viewable( $object ) ) {
					continue;
				}

				$types[] = $name;
			}

			$types = array_values( array_unique( $types ) );
			return apply_filters( 'wumetax_seo_supported_post_types', $types );
		}

		private static function post_type_label( $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( $object && ! empty( $object->labels->singular_name ) ) {
				return $object->labels->singular_name;
			}
			return $post_type;
		}

		public static function register_post_meta_fields() {
			$definitions = [
				'_wu_seo_title'       => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
				'_wu_seo_description' => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
				'_wu_seo_canonical'   => [ 'type' => 'string',  'sanitize_callback' => 'esc_url_raw',          'default' => '' ],
				'_wu_seo_noindex'     => [ 'type' => 'integer', 'sanitize_callback' => 'absint',               'default' => 0 ],
				'_wu_seo_nofollow'    => [ 'type' => 'integer', 'sanitize_callback' => 'absint',               'default' => 0 ],
				'_wu_seo_og_title'    => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
				'_wu_seo_og_desc'     => [ 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
				'_wu_seo_og_image'    => [ 'type' => 'integer', 'sanitize_callback' => 'absint',               'default' => 0 ],
			];

			foreach ( self::supported_post_types() as $post_type ) {
				if ( ! post_type_supports( $post_type, 'custom-fields' ) ) {
					add_post_type_support( $post_type, 'custom-fields' );
				}

				foreach ( $definitions as $key => $definition ) {
					register_post_meta(
						$post_type,
						$key,
						[
							'type'              => $definition['type'],
							'single'            => true,
							'show_in_rest'      => true,
							'default'           => $definition['default'],
							'sanitize_callback' => $definition['sanitize_callback'],
							'auth_callback'     => static function( $allowed, $meta_key, $post_id ) {
								return current_user_can( 'edit_post', $post_id );
							},
						]
					);
				}
			}
		}

		public static function enqueue_block_editor_assets() {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || empty( $screen->post_type ) || ! in_array( $screen->post_type, self::supported_post_types(), true ) ) {
				return;
			}

			if ( ! function_exists( 'use_block_editor_for_post_type' ) || ! use_block_editor_for_post_type( $screen->post_type ) ) {
				return;
			}

			wp_enqueue_media();
			wp_enqueue_script( 'wp-edit-post' );
			wp_enqueue_style( 'wp-edit-blocks' );

			$settings = self::settings();
			$config   = [
				'siteName'        => self::site_name(),
				'frontPageId'     => self::homepage_id(),
				'hasDefaultImage' => ! empty( $settings['default_og_image'] ) && (bool) self::attachment_url( absint( $settings['default_og_image'] ) ),
				'dashboardUrl'    => admin_url( 'admin.php?page=' . self::DASHBOARD_PAGE ),
				'settingsUrl'     => admin_url( 'admin.php?page=' . self::SETTINGS_PAGE ),
			];

			$css = <<<'CSS'
.wu-seo-toolbar-label{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:24px;padding:0 7px;border-radius:999px;background:#edf8f0;color:#24733d;font-size:11px;font-weight:800;letter-spacing:.02em}.wu-seo-editor-sidebar .components-panel__body{border-top:1px solid #e6e8e7}.wu-seo-side-score{margin:14px 16px 18px;padding:16px;border:1px solid #dfe7e1;border-radius:12px;background:linear-gradient(135deg,#f8fbf9,#fff)}.wu-seo-side-score-top{display:flex;align-items:center;gap:12px}.wu-seo-side-ring{--wu-score:0;width:60px;height:60px;border-radius:50%;background:conic-gradient(#4fa567 calc(var(--wu-score)*1%),#e7ece8 0);display:grid;place-items:center;position:relative;flex:0 0 auto}.wu-seo-side-ring:before{content:"";position:absolute;inset:6px;border-radius:50%;background:#fff}.wu-seo-side-ring b{position:relative;z-index:2;font-size:18px;color:#171b19}.wu-seo-side-score h3{margin:0 0 4px;font-size:14px}.wu-seo-side-score p{margin:0;color:#646970;font-size:12px;line-height:1.55}.wu-seo-side-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}.wu-seo-side-chip{font-size:10px;font-weight:700;padding:3px 7px;border-radius:999px;background:#f1f3f2;color:#5d6762}.wu-seo-side-chip.ok{background:#edf8f0;color:#24733d}.wu-seo-side-chip.warn{background:#fff7e5;color:#8b5e00}.wu-seo-side-preview{margin:0 16px 16px;padding:14px;border:1px solid #e3e6e4;border-radius:10px;background:#fff}.wu-seo-side-preview small{display:block;color:#347c4b;font-weight:700;margin-bottom:7px}.wu-seo-side-url{font-size:11px;color:#4d5156;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:4px}.wu-seo-side-title{font-size:16px;line-height:1.35;color:#1a0dab;margin-bottom:5px}.wu-seo-side-desc{font-size:12px;line-height:1.55;color:#4d5156}.wu-seo-side-image{width:100%;aspect-ratio:1.91/1;border:1px dashed #c8ceca;border-radius:8px;background:#f7f8f7;display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:8px;color:#72777c;font-size:12px}.wu-seo-side-image img{width:100%;height:100%;object-fit:cover}.wu-seo-side-row{display:flex;gap:8px;flex-wrap:wrap}.wu-seo-side-links{display:flex;gap:8px;padding:0 16px 18px}.wu-seo-side-links .components-button{flex:1;justify-content:center}.wu-seo-editor-sidebar .components-base-control__help{font-size:11px;line-height:1.5}.wu-seo-editor-sidebar .components-text-control__input,.wu-seo-editor-sidebar textarea{font-size:13px}
CSS;
			wp_add_inline_style( 'wp-edit-blocks', $css );

			$script = <<<'JS'
(function(config){
'use strict';
if(!window.wp||!wp.plugins||!wp.element||!wp.data||!wp.components){return;}
const PluginSidebar=(wp.editor&&wp.editor.PluginSidebar)||(wp.editPost&&wp.editPost.PluginSidebar);
if(!PluginSidebar){return;}
const e=wp.element.createElement;
const Fragment=wp.element.Fragment;
const useMemo=wp.element.useMemo;
const useSelect=wp.data.useSelect;
const useDispatch=wp.data.useDispatch;
const PanelBody=wp.components.PanelBody;
const TextControl=wp.components.TextControl;
const TextareaControl=wp.components.TextareaControl;
const ToggleControl=wp.components.ToggleControl;
const Button=wp.components.Button;
const Notice=wp.components.Notice;
function stripHtml(value){const div=document.createElement('div');div.innerHTML=String(value||'');return (div.textContent||div.innerText||'').replace(/\s+/g,' ').trim();}
function truncate(value,max){const a=Array.from(String(value||''));return a.length>max?a.slice(0,max-1).join('')+'…':a.join('');}
function chars(value){return Array.from(String(value||'').replace(/\s+/g,'')).length;}
function App(){
 const editor=useSelect(function(select){const store=select('core/editor');return {meta:store.getEditedPostAttribute('meta')||{},title:store.getEditedPostAttribute('title')||'',excerpt:store.getEditedPostAttribute('excerpt')||'',content:store.getEditedPostAttribute('content')||'',featured:parseInt(store.getEditedPostAttribute('featured_media')||0,10),permalink:store.getPermalink?store.getPermalink():'',postId:store.getCurrentPostId?store.getCurrentPostId():0};},[]);
 const media=useSelect(function(select){const id=parseInt((editor.meta&&editor.meta._wu_seo_og_image)||0,10);return id?select('core').getMedia(id):null;},[editor.meta&&editor.meta._wu_seo_og_image]);
 const dispatchEditor=useDispatch('core/editor');
 const meta=editor.meta||{};
 function setMeta(key,value){const next=Object.assign({},meta);next[key]=value;dispatchEditor.editPost({meta:next});}
 const computed=useMemo(function(){
   const manualTitle=String(meta._wu_seo_title||'').trim();
   const baseTitle=stripHtml(editor.title)||config.siteName||'';
   const isFront=parseInt(editor.postId||0,10)===parseInt(config.frontPageId||0,10)&&parseInt(config.frontPageId||0,10)>0;
   const fallbackTitle=baseTitle+(baseTitle&&!isFront&&config.siteName?'｜'+config.siteName:'');
   const title=(manualTitle||fallbackTitle).trim();
   const manualDesc=String(meta._wu_seo_description||'').trim();
   const fallbackDesc=truncate(stripHtml(editor.excerpt)||stripHtml(editor.content),160);
   const desc=(manualDesc||fallbackDesc).trim();
   const tl=chars(title),dl=chars(desc);
   const ogImage=parseInt(meta._wu_seo_og_image||0,10);
   const hasImage=ogImage>0||editor.featured>0||!!config.hasDefaultImage;
   const indexable=parseInt(meta._wu_seo_noindex||0,10)!==1;
   let score=0;
   if(title){score+=20;} if(tl>=12&&tl<=32){score+=15;}else if(tl>=8&&tl<=45){score+=8;}
   if(desc){score+=25;} if(dl>=45&&dl<=90){score+=15;}else if(dl>=30&&dl<=120){score+=8;}
   if(hasImage){score+=15;} if(indexable){score+=5;} if(/[\u3400-\u9FFF]/.test(title+desc)){score+=5;}
   score=Math.max(0,Math.min(100,score));
   return {title:title,desc:desc,tl:tl,dl:dl,hasImage:hasImage,indexable:indexable,score:score};
 },[meta,editor.title,editor.excerpt,editor.content,editor.featured,editor.postId]);
 function chooseImage(){if(!window.wp.media){return;}const frame=wp.media({title:'選擇 OG Image',button:{text:'使用這張圖片'},library:{type:'image'},multiple:false});frame.on('select',function(){const a=frame.state().get('selection').first().toJSON();if(a&&a.id){setMeta('_wu_seo_og_image',parseInt(a.id,10));}});frame.open();}
 const tone=computed.score>=80?'ok':'warn';
 const scoreBox=e('div',{className:'wu-seo-side-score'},e('div',{className:'wu-seo-side-score-top'},e('div',{className:'wu-seo-side-ring',style:{'--wu-score':computed.score}},e('b',null,String(computed.score))),e('div',null,e('h3',null,'台灣繁中內容健檢'),e('p',null,'這是設定完整度，不是 Google 官方分數。'))),e('div',{className:'wu-seo-side-chips'},e('span',{className:'wu-seo-side-chip '+(computed.tl>=8&&computed.tl<=45?'ok':'warn')},'標題'),e('span',{className:'wu-seo-side-chip '+(computed.dl>=30&&computed.dl<=120?'ok':'warn')},'摘要'),e('span',{className:'wu-seo-side-chip '+(computed.hasImage?'ok':'warn')},'分享圖'),e('span',{className:'wu-seo-side-chip '+(computed.indexable?'ok':'warn')},'索引')));
 const preview=e('div',{className:'wu-seo-side-preview'},e('small',null,'GOOGLE 搜尋預覽（台灣繁中）'),e('div',{className:'wu-seo-side-url'},editor.permalink||window.location.href),e('div',{className:'wu-seo-side-title'},computed.title||'尚未設定標題'),e('div',{className:'wu-seo-side-desc'},computed.desc||'尚無摘要，建議補充 Meta Description。'));
 const imageUrl=media&&media.source_url?media.source_url:'';
 const sidebar=e(PluginSidebar,{name:'sidebar',title:'Wumetax SEO',icon:e('span',{className:'wu-seo-toolbar-label '+tone},'SEO'),className:'wu-seo-editor-sidebar'},scoreBox,preview,
   e(PanelBody,{title:'搜尋結果設定',initialOpen:true},
     e(TextControl,{label:'SEO Title',value:String(meta._wu_seo_title||''),help:'留空會自動使用頁面標題。繁中預覽參考約 12–32 字，目前有效 '+computed.tl+' 字。',onChange:function(v){setMeta('_wu_seo_title',v);}}),
     e(TextareaControl,{label:'Meta Description',value:String(meta._wu_seo_description||''),help:'留空會從摘要／內容產生。繁中預覽參考約 45–90 字，目前有效 '+computed.dl+' 字。',onChange:function(v){setMeta('_wu_seo_description',v);}}),
     e(TextControl,{label:'Canonical URL',value:String(meta._wu_seo_canonical||''),help:'一般留空即可，系統會使用目前正式網址。',onChange:function(v){setMeta('_wu_seo_canonical',v);}}),
     e(ToggleControl,{label:'noindex',checked:parseInt(meta._wu_seo_noindex||0,10)===1,help:'只有不希望出現在搜尋結果時才開啟。',onChange:function(v){setMeta('_wu_seo_noindex',v?1:0);}}),
     e(ToggleControl,{label:'nofollow',checked:parseInt(meta._wu_seo_nofollow||0,10)===1,onChange:function(v){setMeta('_wu_seo_nofollow',v?1:0);}})
   ),
   e(PanelBody,{title:'社群分享',initialOpen:false},
     e(TextControl,{label:'OG Title',value:String(meta._wu_seo_og_title||''),help:'留空使用 SEO Title。',onChange:function(v){setMeta('_wu_seo_og_title',v);}}),
     e(TextareaControl,{label:'OG Description',value:String(meta._wu_seo_og_desc||''),help:'留空使用 Meta Description。',onChange:function(v){setMeta('_wu_seo_og_desc',v);}}),
     e('div',{className:'wu-seo-side-image'},imageUrl?e('img',{src:imageUrl,alt:''}):(computed.hasImage?'目前會使用精選圖片／全站預設分享圖':'尚未設定分享圖片')),
     e('div',{className:'wu-seo-side-row'},e(Button,{variant:'secondary',onClick:chooseImage},'選擇圖片'),parseInt(meta._wu_seo_og_image||0,10)>0?e(Button,{variant:'tertiary',onClick:function(){setMeta('_wu_seo_og_image',0);}},'移除'):null)
   ),
   e(Notice,{status:'info',isDismissible:false},'SEO 欄位會跟文章一起儲存；不用另外按一次 SEO 儲存。'),
   e('div',{className:'wu-seo-side-links'},e(Button,{variant:'secondary',href:config.dashboardUrl},'SEO 健檢'),e(Button,{variant:'secondary',href:config.settingsUrl},'全站設定'))
 );
 return sidebar;
}
wp.plugins.registerPlugin('wumetax-seo-core-editor',{render:App,icon:'search'});
})(__WU_CONFIG__);
JS;
			$script = str_replace( '__WU_CONFIG__', wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $script );
			wp_add_inline_script( 'wp-edit-post', $script, 'after' );
		}

		public static function add_meta_boxes() {

			foreach ( self::supported_post_types() as $post_type ) {
				if ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $post_type ) ) {
					continue;
				}

				add_meta_box(
					'wumetax-seo-core-box',
					'Wumetax SEO',
					[ __CLASS__, 'render_meta_box' ],
					$post_type,
					'normal',
					'high'
				);
			}
		}

		private static function get_post_meta_data( $post_id ) {
			return [
				'title'       => (string) get_post_meta( $post_id, '_wu_seo_title', true ),
				'description' => (string) get_post_meta( $post_id, '_wu_seo_description', true ),
				'canonical'   => (string) get_post_meta( $post_id, '_wu_seo_canonical', true ),
				'noindex'     => (int) get_post_meta( $post_id, '_wu_seo_noindex', true ),
				'nofollow'    => (int) get_post_meta( $post_id, '_wu_seo_nofollow', true ),
				'og_title'    => (string) get_post_meta( $post_id, '_wu_seo_og_title', true ),
				'og_desc'     => (string) get_post_meta( $post_id, '_wu_seo_og_desc', true ),
				'og_image'    => absint( get_post_meta( $post_id, '_wu_seo_og_image', true ) ),
			];
		}

		public static function render_meta_box( $post ) {
			$m = self::get_post_meta_data( $post->ID );
			$og_img = self::attachment_url( $m['og_image'] );
			$s = self::settings();
			$fallback_title = get_the_title( $post ) . ( is_front_page() ? '' : '｜' . self::site_name() );
			$fallback_desc = '';
			$excerpt = get_post_field( 'post_excerpt', $post->ID );
			if ( trim( (string) $excerpt ) !== '' ) {
				$fallback_desc = self::clean_text( $excerpt, 160 );
			} else {
				$fallback_desc = self::clean_text( get_post_field( 'post_content', $post->ID ), 160 );
			}
			$effective_image = (bool) ( $og_img || has_post_thumbnail( $post->ID ) || ( ! empty( $s['default_og_image'] ) && self::attachment_url( absint( $s['default_og_image'] ) ) ) );
			wp_nonce_field( self::META_NONCE_ACT, self::META_NONCE_KEY );
			?>
			<style>
				.wu-seo-local-audit{display:grid;grid-template-columns:120px minmax(0,1fr);gap:18px;padding:18px;border:1px solid #dfe7e1;background:#f7faf8;border-radius:12px;margin:0 0 18px}.wu-seo-local-score{width:92px;height:92px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#fff;border:7px solid #4fa567;font-size:25px;font-weight:800;color:#171b19}.wu-seo-local-audit h3{margin:4px 0 6px;font-size:15px}.wu-seo-local-audit p{margin:0;color:#646970;line-height:1.65}.wu-seo-mini-checks{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}.wu-seo-mini{padding:4px 9px;border-radius:999px;background:#fff;border:1px solid #dfe4e1;font-size:11px;color:#50575e}.wu-seo-mini.ok{background:#edf8f0;border-color:#cde7d4;color:#24733d}.wu-seo-mini.warn{background:#fff7e5;border-color:#f2dfad;color:#8b5e00}.wu-seo-serp{padding:16px 18px;border:1px solid #e3e6e4;border-radius:10px;background:#fff;margin:0 0 18px}.wu-seo-serp-label{font-size:11px;font-weight:700;color:#347c4b;letter-spacing:.04em;margin-bottom:9px}.wu-seo-serp-url{font-size:12px;color:#4d5156;margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.wu-seo-serp-title{font-size:19px;color:#1a0dab;line-height:1.35;margin-bottom:5px}.wu-seo-serp-desc{font-size:13px;color:#4d5156;line-height:1.55}.wu-seo-meta-grid{display:grid;grid-template-columns:190px minmax(0,1fr);gap:18px;padding:16px 0;border-bottom:1px solid #eee}.wu-seo-meta-grid:last-child{border-bottom:0}.wu-seo-meta-grid label strong{display:block;margin-bottom:5px}.wu-seo-meta-grid label span{color:#646970;font-size:12px;line-height:1.55}.wu-seo-meta-grid input[type=text],.wu-seo-meta-grid input[type=url],.wu-seo-meta-grid textarea{width:100%}.wu-seo-meta-grid textarea{min-height:86px}.wu-seo-counter{margin-top:5px;font-size:12px;color:#646970}.wu-seo-counter.good{color:#24733d}.wu-seo-counter.warn{color:#8b5e00}.wu-seo-counter.bad{color:#b32d2e}.wu-seo-meta-image{width:240px;min-height:120px;border:1px dashed #c3c4c7;border-radius:8px;background:#f6f7f7;display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:8px;color:#72777c}.wu-seo-meta-image img{max-width:100%;max-height:160px}.wu-seo-checks{display:flex;gap:22px;flex-wrap:wrap}@media(max-width:700px){.wu-seo-local-audit,.wu-seo-meta-grid{grid-template-columns:1fr}.wu-seo-local-score{width:78px;height:78px}}
			</style>

			<div class="wu-seo-local-audit" id="wu-seo-local-audit"
				data-fallback-title="<?php echo esc_attr( $fallback_title ); ?>"
				data-fallback-desc="<?php echo esc_attr( $fallback_desc ); ?>"
				data-has-image="<?php echo $effective_image ? '1' : '0'; ?>">
				<div class="wu-seo-local-score" id="wu-seo-local-score">--</div>
				<div>
					<h3>台灣繁中內容健檢</h3>
					<p>這是本頁 SEO 設定完整度，不是 Google 官方分數。重點檢查繁中標題、摘要、分享圖片與是否允許索引，不做關鍵字密度灌分。</p>
					<div class="wu-seo-mini-checks">
						<span class="wu-seo-mini" id="wu-check-title">標題</span>
						<span class="wu-seo-mini" id="wu-check-desc">摘要</span>
						<span class="wu-seo-mini" id="wu-check-image">分享圖片</span>
						<span class="wu-seo-mini" id="wu-check-index">索引</span>
						<span class="wu-seo-mini" id="wu-check-lang">繁中內容</span>
					</div>
				</div>
			</div>

			<div class="wu-seo-serp">
				<div class="wu-seo-serp-label">GOOGLE 搜尋預覽（台灣繁中）</div>
				<div class="wu-seo-serp-url"><?php echo esc_html( get_permalink( $post ) ?: home_url( '/' ) ); ?></div>
				<div class="wu-seo-serp-title" id="wu-serp-title"><?php echo esc_html( $m['title'] ?: $fallback_title ); ?></div>
				<div class="wu-seo-serp-desc" id="wu-serp-desc"><?php echo esc_html( $m['description'] ?: ( $fallback_desc ?: '尚無可用摘要，建議手動撰寫 Meta Description。' ) ); ?></div>
			</div>

			<div class="wu-seo-meta-grid">
				<label><strong>SEO Title</strong><span>留空會使用頁面標題＋網站名稱。繁中頁面可先以約 12–32 個字作為搜尋畫面預覽參考，但不是 Google 硬性限制。</span></label>
				<div><input type="text" id="wu-seo-title" name="wu_seo_title" value="<?php echo esc_attr( $m['title'] ); ?>" placeholder="<?php echo esc_attr( $fallback_title ); ?>"><div class="wu-seo-counter" id="wu-seo-title-count"></div></div>
			</div>

			<div class="wu-seo-meta-grid">
				<label><strong>Meta Description</strong><span>留空會從摘要或內容自動產生。繁中頁面可先以約 45–90 個字作為預覽參考；Google 仍可能依查詢重寫摘要。</span></label>
				<div><textarea id="wu-seo-description" name="wu_seo_description" placeholder="留空會依摘要或內容自動產生。"><?php echo esc_textarea( $m['description'] ); ?></textarea><div class="wu-seo-counter" id="wu-seo-desc-count"></div></div>
			</div>

			<div class="wu-seo-meta-grid">
				<label><strong>Canonical URL</strong><span>通常留空即可，由外掛自動使用此頁正式網址。只有重複內容或特殊整併需求才手動設定。</span></label>
				<input type="url" name="wu_seo_canonical" value="<?php echo esc_attr( $m['canonical'] ); ?>" placeholder="<?php echo esc_attr( get_permalink( $post ) ); ?>">
			</div>

			<div class="wu-seo-meta-grid">
				<label><strong>搜尋引擎</strong><span>一般公開頁面不要勾 noindex。只有不希望出現在搜尋結果中的頁面才使用。</span></label>
				<div class="wu-seo-checks">
					<label><input type="checkbox" id="wu-seo-noindex" name="wu_seo_noindex" value="1" <?php checked( 1, $m['noindex'] ); ?>> noindex</label>
					<label><input type="checkbox" name="wu_seo_nofollow" value="1" <?php checked( 1, $m['nofollow'] ); ?>> nofollow</label>
				</div>
			</div>

			<div class="wu-seo-meta-grid">
				<label><strong>OG Title</strong><span>LINE、Facebook 等社群分享標題；留空使用 SEO Title。</span></label>
				<input type="text" name="wu_seo_og_title" value="<?php echo esc_attr( $m['og_title'] ); ?>">
			</div>

			<div class="wu-seo-meta-grid">
				<label><strong>OG Description</strong><span>社群分享描述；留空使用 Meta Description。</span></label>
				<textarea name="wu_seo_og_desc"><?php echo esc_textarea( $m['og_desc'] ); ?></textarea>
			</div>

			<div class="wu-seo-meta-grid">
				<label><strong>OG Image</strong><span>留空會依序使用精選圖片、全站預設社群圖、網站圖示。</span></label>
				<div>
					<input type="hidden" class="wu-seo-og-image-id" name="wu_seo_og_image" value="<?php echo esc_attr( $m['og_image'] ); ?>">
					<div class="wu-seo-meta-image wu-seo-og-image-preview"><?php if ( $og_img ) : ?><img src="<?php echo esc_url( $og_img ); ?>" alt=""><?php else : ?>尚未設定個別圖片<?php endif; ?></div>
					<button type="button" class="button wu-seo-og-image-select">選擇圖片</button>
					<button type="button" class="button wu-seo-og-image-remove">移除</button>
				</div>
			</div>

			<script>
			(function(){
				'use strict';
				const box=document.getElementById('wumetax-seo-core-box');
				if(!box){return;}
				const audit=document.getElementById('wu-seo-local-audit');
				const titleInput=document.getElementById('wu-seo-title');
				const descInput=document.getElementById('wu-seo-description');
				const noindex=document.getElementById('wu-seo-noindex');
				const scoreEl=document.getElementById('wu-seo-local-score');
				const titleCount=document.getElementById('wu-seo-title-count');
				const descCount=document.getElementById('wu-seo-desc-count');
				const serpTitle=document.getElementById('wu-serp-title');
				const serpDesc=document.getElementById('wu-serp-desc');
				const imageInput=box.querySelector('.wu-seo-og-image-id');
				const imagePreview=box.querySelector('.wu-seo-og-image-preview');
				const selectBtn=box.querySelector('.wu-seo-og-image-select');
				const removeBtn=box.querySelector('.wu-seo-og-image-remove');
				const fallbackTitle=audit?audit.dataset.fallbackTitle:'';
				const fallbackDesc=audit?audit.dataset.fallbackDesc:'';
				let hasFallbackImage=audit&&audit.dataset.hasImage==='1';
				function chars(v){return Array.from(String(v||'').replace(/\\s+/g,'')).length;}
				function mark(id,ok,warn){const el=document.getElementById(id);if(!el)return;el.classList.remove('ok','warn');el.classList.add(ok?'ok':'warn');if(warn&&!ok)el.title=warn;}
				function update(){
					const title=(titleInput.value.trim()||fallbackTitle).trim();
					const desc=(descInput.value.trim()||fallbackDesc).trim();
					const tl=chars(title), dl=chars(desc);
					let score=0;
					if(title){score+=20;}
					if(tl>=12&&tl<=32){score+=15;}else if(tl>=8&&tl<=45){score+=8;}
					if(desc){score+=20;}
					if(dl>=45&&dl<=90){score+=15;}else if(dl>=30&&dl<=120){score+=8;}
					const hasImage=(imageInput&&parseInt(imageInput.value||'0',10)>0)||hasFallbackImage;
					if(hasImage){score+=15;}
					const indexable=!(noindex&&noindex.checked);if(indexable){score+=10;}
					const hasHan=/[\\u3400-\\u9FFF]/.test(title+desc);if(hasHan){score+=5;}
					score=Math.max(0,Math.min(100,score));
					scoreEl.textContent=score;
					scoreEl.style.borderColor=score>=80?'#4fa567':(score>=60?'#dba617':'#d63638');
					titleCount.textContent='目前 '+tl+' 字｜繁中預覽參考：12–32 字';titleCount.className='wu-seo-counter '+(tl>=12&&tl<=32?'good':(tl>=8&&tl<=45?'warn':'bad'));
					descCount.textContent='目前 '+dl+' 字｜繁中預覽參考：45–90 字';descCount.className='wu-seo-counter '+(dl>=45&&dl<=90?'good':(dl>=30&&dl<=120?'warn':'bad'));
					serpTitle.textContent=title||'尚未設定標題';serpDesc.textContent=desc||'尚無可用摘要，建議手動撰寫 Meta Description。';
					mark('wu-check-title',!!title&&tl>=8&&tl<=45,'標題過短或過長');
					mark('wu-check-desc',!!desc&&dl>=30&&dl<=120,'摘要建議補充');
					mark('wu-check-image',hasImage,'缺少分享圖片');
					mark('wu-check-index',indexable,'此頁為 noindex');
					mark('wu-check-lang',hasHan,'標題與摘要未偵測到中文');
				}
				[titleInput,descInput,noindex].forEach(el=>{if(el){el.addEventListener('input',update);el.addEventListener('change',update);}});
				let frame=null;
				if(selectBtn&&removeBtn&&imageInput&&imagePreview&&typeof wp!=='undefined'&&wp.media){
					selectBtn.addEventListener('click',function(e){e.preventDefault();if(frame){frame.open();return;}frame=wp.media({title:'選擇 OG Image',button:{text:'使用這張圖片'},library:{type:'image'},multiple:false});frame.on('select',function(){const a=frame.state().get('selection').first().toJSON();if(!a||!a.id){return;}imageInput.value=a.id;const url=(a.sizes&&a.sizes.medium_large)?a.sizes.medium_large.url:a.url;imagePreview.innerHTML='';const img=document.createElement('img');img.src=url;img.alt='';imagePreview.appendChild(img);hasFallbackImage=true;update();});frame.open();});
					removeBtn.addEventListener('click',function(e){e.preventDefault();imageInput.value='0';imagePreview.textContent='尚未設定個別圖片';hasFallbackImage=<?php echo ( has_post_thumbnail( $post->ID ) || ( ! empty( $s['default_og_image'] ) && self::attachment_url( absint( $s['default_og_image'] ) ) ) ) ? 'true' : 'false'; ?>;update();});
				}
				update();
			})();
			</script>
			<?php
		}

		public static function save_meta_box( $post_id, $post ) {
			if ( ! $post || ! in_array( $post->post_type, self::supported_post_types(), true ) ) {
				return;
			}
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}
			if ( wp_is_post_revision( $post_id ) ) {
				return;
			}
			if ( ! isset( $_POST[ self::META_NONCE_KEY ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::META_NONCE_KEY ] ) ), self::META_NONCE_ACT ) ) {
				return;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			$text_fields = [
				'_wu_seo_title'       => 'wu_seo_title',
				'_wu_seo_description' => 'wu_seo_description',
				'_wu_seo_og_title'    => 'wu_seo_og_title',
				'_wu_seo_og_desc'     => 'wu_seo_og_desc',
			];

			foreach ( $text_fields as $meta_key => $field ) {
				$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
				self::update_or_delete_meta( $post_id, $meta_key, $value );
			}

			$canonical = isset( $_POST['wu_seo_canonical'] ) ? esc_url_raw( wp_unslash( $_POST['wu_seo_canonical'] ) ) : '';
			self::update_or_delete_meta( $post_id, '_wu_seo_canonical', $canonical );

			$og_image = isset( $_POST['wu_seo_og_image'] ) ? absint( $_POST['wu_seo_og_image'] ) : 0;
			self::update_or_delete_meta( $post_id, '_wu_seo_og_image', $og_image );

			update_post_meta( $post_id, '_wu_seo_noindex', ! empty( $_POST['wu_seo_noindex'] ) ? 1 : 0 );
			update_post_meta( $post_id, '_wu_seo_nofollow', ! empty( $_POST['wu_seo_nofollow'] ) ? 1 : 0 );
		}

		private static function update_or_delete_meta( $post_id, $key, $value ) {
			if ( '' === $value || 0 === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		/* =========================================================
		 * SEO DATA
		 * ======================================================= */

		private static function current_post_id() {
			return is_singular() ? get_queried_object_id() : 0;
		}

		private static function site_name() {
			return wp_strip_all_tags( get_bloginfo( 'name' ) );
		}

		private static function clean_text( $text, $limit = 160 ) {
			$text = strip_shortcodes( (string) $text );
			$text = wp_strip_all_tags( $text, true );
			$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
			$text = preg_replace( '/\s+/u', ' ', $text );
			$text = trim( (string) $text );

			if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > $limit ) {
				return rtrim( mb_substr( $text, 0, $limit - 1, 'UTF-8' ) ) . '…';
			}
			if ( strlen( $text ) > $limit ) {
				return rtrim( substr( $text, 0, $limit - 1 ) ) . '…';
			}
			return $text;
		}

		private static function seo_title() {
			$site = self::site_name();
			$post_id = self::current_post_id();

			if ( $post_id ) {
				$manual = trim( (string) get_post_meta( $post_id, '_wu_seo_title', true ) );
				if ( '' !== $manual ) {
					return $manual;
				}
				$title = get_the_title( $post_id );
				return is_front_page() ? ( $title ?: $site ) : trim( $title . '｜' . $site, '｜' );
			}

			if ( is_home() ) {
				$page_for_posts = (int) get_option( 'page_for_posts' );
				if ( $page_for_posts ) {
					return get_the_title( $page_for_posts ) . '｜' . $site;
				}
			}

			if ( is_category() || is_tag() || is_tax() ) {
				return single_term_title( '', false ) . '｜' . $site;
			}

			if ( is_post_type_archive() ) {
				return post_type_archive_title( '', false ) . '｜' . $site;
			}

			if ( is_search() ) {
				return '搜尋：' . get_search_query() . '｜' . $site;
			}

			if ( is_404() ) {
				return '找不到頁面｜' . $site;
			}

			return wp_get_document_title();
		}

		private static function seo_description() {
			$post_id = self::current_post_id();
			if ( $post_id ) {
				$manual = trim( (string) get_post_meta( $post_id, '_wu_seo_description', true ) );
				if ( '' !== $manual ) {
					return self::clean_text( $manual, 160 );
				}
				$excerpt = get_post_field( 'post_excerpt', $post_id );
				if ( trim( (string) $excerpt ) !== '' ) {
					return self::clean_text( $excerpt, 160 );
				}
				$content = get_post_field( 'post_content', $post_id );
				return self::clean_text( $content, 160 );
			}

			if ( is_category() || is_tag() || is_tax() ) {
				$desc = term_description();
				if ( $desc ) {
					return self::clean_text( $desc, 160 );
				}
			}

			$tagline = get_bloginfo( 'description' );
			return self::clean_text( $tagline, 160 );
		}

		private static function canonical_url() {
			$post_id = self::current_post_id();
			if ( $post_id ) {
				$manual = trim( (string) get_post_meta( $post_id, '_wu_seo_canonical', true ) );
				if ( $manual ) {
					return $manual;
				}
				return get_permalink( $post_id );
			}

			if ( is_front_page() ) {
				return home_url( '/' );
			}

			if ( is_home() ) {
				$page_for_posts = (int) get_option( 'page_for_posts' );
				$base = $page_for_posts ? get_permalink( $page_for_posts ) : home_url( '/' );
				$paged = max( 1, (int) get_query_var( 'paged' ) );
				return $paged > 1 ? get_pagenum_link( $paged ) : $base;
			}

			if ( is_category() || is_tag() || is_tax() ) {
				$term = get_queried_object();
				if ( $term && ! is_wp_error( $term ) ) {
					$base = get_term_link( $term );
					if ( is_wp_error( $base ) ) {
						return '';
					}
					$paged = max( 1, (int) get_query_var( 'paged' ) );
					return $paged > 1 ? get_pagenum_link( $paged ) : $base;
				}
			}

			if ( is_post_type_archive() ) {
				$obj = get_queried_object();
				if ( $obj && ! empty( $obj->name ) ) {
					$base = get_post_type_archive_link( $obj->name );
					$paged = max( 1, (int) get_query_var( 'paged' ) );
					return $paged > 1 ? get_pagenum_link( $paged ) : $base;
				}
			}

			return '';
		}

		private static function og_image_url() {
			$post_id = self::current_post_id();
			if ( $post_id ) {
				$manual_id = absint( get_post_meta( $post_id, '_wu_seo_og_image', true ) );
				if ( $manual_id ) {
					$url = self::attachment_url( $manual_id );
					if ( $url ) {
						return $url;
					}
				}
				if ( has_post_thumbnail( $post_id ) ) {
					$url = get_the_post_thumbnail_url( $post_id, 'full' );
					if ( $url ) {
						return $url;
					}
				}
			}

			$s = self::settings();
			if ( ! empty( $s['default_og_image'] ) ) {
				$url = self::attachment_url( absint( $s['default_og_image'] ) );
				if ( $url ) {
					return $url;
				}
			}

			$site_icon = get_site_icon_url( 512 );
			return $site_icon ? $site_icon : '';
		}

		private static function attachment_url( $attachment_id ) {
			if ( ! $attachment_id ) {
				return '';
			}
			$url = wp_get_attachment_image_url( $attachment_id, 'full' );
			return $url ? $url : '';
		}

		private static function og_title() {
			$post_id = self::current_post_id();
			if ( $post_id ) {
				$manual = trim( (string) get_post_meta( $post_id, '_wu_seo_og_title', true ) );
				if ( $manual ) {
					return $manual;
				}
			}
			return self::seo_title();
		}

		private static function og_description() {
			$post_id = self::current_post_id();
			if ( $post_id ) {
				$manual = trim( (string) get_post_meta( $post_id, '_wu_seo_og_desc', true ) );
				if ( $manual ) {
					return self::clean_text( $manual, 200 );
				}
			}
			return self::seo_description();
		}

		/* =========================================================
		 * TITLE / ROBOTS / HEAD
		 * ======================================================= */

		public static function filter_document_title( $title ) {
			if ( is_admin() || is_feed() ) {
				return $title;
			}
			$new_title = self::seo_title();
			return $new_title ? $new_title : $title;
		}

		public static function filter_wp_robots( $robots ) {
			if ( is_search() || is_404() ) {
				$robots['noindex'] = true;
			}

			$post_id = self::current_post_id();
			if ( $post_id ) {
				if ( (int) get_post_meta( $post_id, '_wu_seo_noindex', true ) === 1 ) {
					$robots['noindex'] = true;
				}
				if ( (int) get_post_meta( $post_id, '_wu_seo_nofollow', true ) === 1 ) {
					$robots['nofollow'] = true;
				}
			}

			return $robots;
		}

		public static function output_head_meta() {
			if ( is_admin() || is_feed() ) {
				return;
			}

			$s           = self::settings();
			$description = self::seo_description();
			$canonical   = self::canonical_url();
			$title       = self::og_title();
			$og_desc     = self::og_description();
			$image       = self::og_image_url();
			$url         = $canonical ?: home_url( add_query_arg( [], $GLOBALS['wp']->request ?? '' ) );
			$type        = is_singular( 'post' ) || is_singular( 'skb_doc' ) ? 'article' : 'website';

			if ( $description ) {
				echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
			}

			if ( $canonical && ! is_404() && ! is_search() ) {
				echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
			}

			if ( ! empty( $s['google_verify'] ) ) {
				echo '<meta name="google-site-verification" content="' . esc_attr( $s['google_verify'] ) . '">' . "\n";
			}

			if ( ! empty( $s['bing_verify'] ) ) {
				echo '<meta name="msvalidate.01" content="' . esc_attr( $s['bing_verify'] ) . '">' . "\n";
			}

			if ( ! empty( $s['enable_og'] ) ) {
				echo '<meta property="og:locale" content="zh_TW">' . "\n";
				echo '<meta property="og:type" content="' . esc_attr( $type ) . '">' . "\n";
				echo '<meta property="og:site_name" content="' . esc_attr( self::site_name() ) . '">' . "\n";
				echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
				if ( $og_desc ) {
					echo '<meta property="og:description" content="' . esc_attr( $og_desc ) . '">' . "\n";
				}
				if ( $url ) {
					echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
				}
				if ( $image ) {
					echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
				}
			}

			if ( ! empty( $s['enable_twitter'] ) ) {
				echo '<meta name="twitter:card" content="' . esc_attr( $image ? 'summary_large_image' : 'summary' ) . '">' . "\n";
				echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
				if ( $og_desc ) {
					echo '<meta name="twitter:description" content="' . esc_attr( $og_desc ) . '">' . "\n";
				}
				if ( $image ) {
					echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
				}
			}
		}

		/* =========================================================
		 * SCHEMA
		 * ======================================================= */

		public static function output_schema() {
			$s = self::settings();
			if ( is_admin() || is_feed() || is_404() || is_search() || empty( $s['enable_schema'] ) ) {
				return;
			}

			$graph = [];
			$site_url = home_url( '/' );
			$site_name = self::site_name();
			$org_id = trailingslashit( $site_url ) . '#organization';
			$website_id = trailingslashit( $site_url ) . '#website';
			$canonical = self::canonical_url() ?: $site_url;
			$image = self::og_image_url();

			$org = [
				'@type' => 'Organization',
				'@id'   => $org_id,
				'name'  => $s['organization_name'] ?: $site_name,
				'url'   => $site_url,
			];
			$logo = self::site_logo_url();
			if ( $logo ) {
				$org['logo'] = [ '@type' => 'ImageObject', 'url' => $logo ];
			}
			$same_as = self::social_profiles();
			if ( $same_as ) {
				$org['sameAs'] = $same_as;
			}
			$graph[] = $org;

			$graph[] = [
				'@type'     => 'WebSite',
				'@id'       => $website_id,
				'url'       => $site_url,
				'name'      => $site_name,
				'publisher' => [ '@id' => $org_id ],
				'inLanguage'=> 'zh-TW',
			];

			if ( is_singular() ) {
				$post_id = get_queried_object_id();
				$post_type = get_post_type( $post_id );
				$schema_type = 'WebPage';
				if ( 'post' === $post_type ) {
					$schema_type = 'Article';
				} elseif ( 'skb_doc' === $post_type ) {
					$schema_type = 'TechArticle';
				} elseif ( 'wumetax_portfolio' === $post_type ) {
					$schema_type = 'CreativeWork';
				} elseif ( 'wumetax_lab' === $post_type ) {
					$schema_type = 'SoftwareApplication';
				}

				$item = [
					'@type'      => $schema_type,
					'@id'        => $canonical . '#primary',
					'url'        => $canonical,
					'name'       => get_the_title( $post_id ),
					'description'=> self::seo_description(),
					'inLanguage' => 'zh-TW',
					'isPartOf'   => [ '@id' => $website_id ],
				];

				if ( in_array( $schema_type, [ 'Article', 'TechArticle' ], true ) ) {
					$item['headline'] = get_the_title( $post_id );
					$item['datePublished'] = get_the_date( DATE_W3C, $post_id );
					$item['dateModified']  = get_the_modified_date( DATE_W3C, $post_id );
					$item['publisher'] = [ '@id' => $org_id ];
				}

				if ( 'SoftwareApplication' === $schema_type ) {
					$item['applicationCategory'] = 'WebApplication';
					$item['operatingSystem'] = 'Web';
				}

				if ( $image ) {
					$item['image'] = $image;
				}

				$graph[] = $item;

				$breadcrumbs = self::breadcrumb_schema();
				if ( $breadcrumbs ) {
					$graph[] = $breadcrumbs;
				}
			}

			$data = [
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			];

			echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
		}

		private static function site_logo_url() {
			$logo_id = get_theme_mod( 'custom_logo' );
			if ( $logo_id ) {
				$url = wp_get_attachment_image_url( $logo_id, 'full' );
				if ( $url ) {
					return $url;
				}
			}
			return get_site_icon_url( 512 ) ?: '';
		}

		private static function social_profiles() {
			$s = self::settings();
			$lines = preg_split( '/\r\n|\r|\n/', (string) $s['social_profiles'] );
			$out = [];
			foreach ( (array) $lines as $line ) {
				$url = esc_url_raw( trim( $line ) );
				if ( $url ) {
					$out[] = $url;
				}
			}
			return array_values( array_unique( $out ) );
		}

		private static function breadcrumb_schema() {
			if ( ! is_singular() || is_front_page() ) {
				return [];
			}

			$post_id = get_queried_object_id();
			$items = [];
			$pos = 1;
			$items[] = [
				'@type'    => 'ListItem',
				'position' => $pos++,
				'name'     => '首頁',
				'item'     => home_url( '/' ),
			];

			$post_type = get_post_type( $post_id );
			if ( 'post' === $post_type ) {
				$page_for_posts = (int) get_option( 'page_for_posts' );
				if ( $page_for_posts ) {
					$items[] = [
						'@type'    => 'ListItem',
						'position' => $pos++,
						'name'     => get_the_title( $page_for_posts ),
						'item'     => get_permalink( $page_for_posts ),
					];
				}
			} else {
				$obj = get_post_type_object( $post_type );
				if ( $obj && ! empty( $obj->has_archive ) ) {
					$archive = get_post_type_archive_link( $post_type );
					if ( $archive ) {
						$items[] = [
							'@type'    => 'ListItem',
							'position' => $pos++,
							'name'     => $obj->labels->name,
							'item'     => $archive,
						];
					}
				}
			}

			$items[] = [
				'@type'    => 'ListItem',
				'position' => $pos,
				'name'     => get_the_title( $post_id ),
				'item'     => get_permalink( $post_id ),
			];

			return [
				'@type'           => 'BreadcrumbList',
				'@id'             => get_permalink( $post_id ) . '#breadcrumb',
				'itemListElement' => $items,
			];
		}

		/* =========================================================
		 * ROBOTS.TXT / SITEMAP
		 * ======================================================= */

		public static function filter_robots_txt( $output, $public ) {
			$s = self::settings();
			$extra = trim( (string) $s['robots_extra'] );
			if ( $extra ) {
				$output = rtrim( $output ) . "\n\n# Wumetax SEO Core\n" . $extra . "\n";
			}
			return $output;
		}

		public static function filter_sitemap_post_types( $post_types ) {
			$deny = self::excluded_post_types();

			foreach ( $deny as $type ) {
				if ( isset( $post_types[ $type ] ) ) {
					unset( $post_types[ $type ] );
				}
			}

			return $post_types;
		}

		public static function filter_sitemap_taxonomies( $taxonomies ) {
			$deny = [ 'post_format' ];
			foreach ( $deny as $tax ) {
				if ( isset( $taxonomies[ $tax ] ) ) {
					unset( $taxonomies[ $tax ] );
				}
			}
			return $taxonomies;
		}
	}

	Wumetax_SEO_Core_v120::init();
}
