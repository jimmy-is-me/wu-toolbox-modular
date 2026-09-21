<?php
/**
 * Plugin Name: Wumetax Quick Support
 * Description: 輕量快速支援面板：搜尋客戶支援文件、網站知識與 WooCommerce 商品，可自訂預設內容與底部導覽。
 * Version: 1.3.1
 * Author: Wumetax
 * Author URI: https://wumetax.com/
 * Text Domain: wumetax-quick-support
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Wumetax_Quick_Support_v131' ) ) {

	class Wumetax_Quick_Support_v131 {

		const OPTION_SETTINGS = 'wumetax_qs_settings_v130';
		const OPTION_IDS      = 'wumetax_qs_featured_ids_v130';
		const NONCE_ACTION    = 'wumetax_quick_support_v130_nonce';
		const ADMIN_SLUG      = 'wumetax-quick-support';

		public static function init() {
			add_action( 'wp_ajax_wumetax_quick_support_search', [ __CLASS__, 'ajax_search' ] );
			add_action( 'wp_ajax_nopriv_wumetax_quick_support_search', [ __CLASS__, 'ajax_search' ] );
			add_action( 'wp_footer', [ __CLASS__, 'render_frontend' ], 9999 );
			add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
			add_action( 'admin_post_wumetax_qs_save_v130', [ __CLASS__, 'admin_save' ] );
		}

		/* =====================================================
		 * DEFAULTS / SETTINGS
		 * ===================================================== */

		public static function defaults() {
			return [
				'panel_title'    => '立即獲得協助',
				'panel_subtitle' => '搜尋客戶支援文件與網站知識文章，快速找到需要的資訊。',
				'panel_color'    => '#4fa567',
				'icon_color'     => '#ffffff',
				'show_back_to_top' => 1,
				'source_docs'    => 1,
				'source_posts'   => 1,
				'source_products'=> post_type_exists( 'product' ) ? 1 : 0,
				'display_limit'  => 10,
				'search_limit'   => 12,
				'bottom_1_text'  => '首頁',
				'bottom_1_url'   => home_url( '/' ),
				'bottom_1_icon'  => 'home',
				'bottom_2_text'  => '聯絡我們',
				'bottom_2_url'   => home_url( '/contact/' ),
				'bottom_2_icon'  => 'chat',
				'bottom_3_text'  => '支援中心',
				'bottom_3_url'   => home_url( '/docs/' ),
				'bottom_3_icon'  => 'book',
			];
		}

		public static function get_settings() {
			$saved = get_option( self::OPTION_SETTINGS, [] );
			if ( ! is_array( $saved ) ) {
				$saved = [];
			}

			/* 從 v1.2 自動帶入舊的顯示筆數。 */
			if ( ! isset( $saved['display_limit'] ) ) {
				$old_limit = absint( get_option( 'wumetax_qs_display_limit_v120', 0 ) );
				if ( $old_limit ) {
					$saved['display_limit'] = $old_limit;
				}
			}

			return wp_parse_args( $saved, self::defaults() );
		}

		public static function get_featured_ids() {
			$ids = get_option( self::OPTION_IDS, null );

			/* 第一次升級時沿用 v1.2 精選內容。 */
			if ( null === $ids ) {
				$ids = get_option( 'wumetax_qs_featured_ids_v120', [] );
			}

			if ( ! is_array( $ids ) ) {
				return [];
			}

			return array_values(
				array_unique(
					array_filter(
						array_map( 'absint', $ids )
					)
				)
			);
		}

		public static function icon_choices() {
			return [
				'home'  => '首頁',
				'chat'  => '對話',
				'book'  => '文件',
				'mail'  => 'Email',
				'link'  => '連結',
				'cart'  => '購物車',
				'user'  => '使用者',
				'help'  => '問號',
				'phone' => '電話',
				'globe' => '網站',
			];
		}

		public static function icon_svg( $icon ) {
			$icons = [
				'home'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10.5V20h13v-9.5"/><path d="M9.5 20v-6h5v6"/></svg>',
				'chat'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 11.5a8 8 0 0 1-8 8 8 8 0 0 1-3.7-.9L4 20l1.4-4.4a8 8 0 1 1 15.1-4.1Z"/><path d="M9 11.5h.01"/><path d="M12 11.5h.01"/><path d="M15 11.5h.01"/></svg>',
				'book'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4.5h10.5A3.5 3.5 0 0 1 19 8v11.5H8.5A3.5 3.5 0 0 1 5 16Z"/><path d="M8 8h7"/><path d="M8 11h7"/><path d="M8 14h4"/></svg>',
				'mail'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>',
				'link'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.1.1l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1"/><path d="M14 11a5 5 0 0 0-7.1-.1l-2 2A5 5 0 0 0 12 20l1.1-1.1"/></svg>',
				'cart'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L20 8H6"/><circle cx="10" cy="19" r="1"/><circle cx="17" cy="19" r="1"/></svg>',
				'user'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg>',
				'help'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.8 9a2.3 2.3 0 0 1 4.4 1c0 1.5-2.2 1.8-2.2 3.2"/><path d="M12 17h.01"/></svg>',
				'phone' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.5 3.8 9 8.4 6.9 10a15.3 15.3 0 0 0 7.1 7.1l1.6-2.1 4.6 2.5c.5.3.7.8.5 1.3-.5 1.3-1.8 2.2-3.2 2.2C9.6 21 3 14.4 3 6.5c0-1.4.9-2.7 2.2-3.2.5-.2 1 .1 1.3.5Z"/></svg>',
				'globe' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18"/><path d="M12 3a15 15 0 0 0 0 18"/></svg>',
			];

			return $icons[ $icon ] ?? $icons['link'];
		}

		/* =====================================================
		 * CONTENT SOURCES
		 * ===================================================== */

		public static function get_post_types() {
			$s = self::get_settings();
			$types = [];

			if ( ! empty( $s['source_docs'] ) && post_type_exists( 'skb_doc' ) ) {
				$types[] = 'skb_doc';
			}
			if ( ! empty( $s['source_posts'] ) && post_type_exists( 'post' ) ) {
				$types[] = 'post';
			}
			if ( ! empty( $s['source_products'] ) && post_type_exists( 'product' ) ) {
				$types[] = 'product';
			}

			if ( empty( $types ) ) {
				$types[] = 'post';
			}

			return array_values( array_unique( $types ) );
		}

		private static function first_term_name( $post_id, $taxonomy, $fallback ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return $fallback;
			}
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				return $terms[0]->name;
			}
			return $fallback;
		}

		public static function get_result_meta( $post_id ) {
			$type = get_post_type( $post_id );

			if ( 'skb_doc' === $type ) {
				return [
					'type'       => 'doc',
					'type_label' => '支援文件',
					'category'   => self::first_term_name( $post_id, 'skb_category', '文件' ),
				];
			}

			if ( 'post' === $type ) {
				$cats = get_the_category( $post_id );
				return [
					'type'       => 'article',
					'type_label' => '網站知識',
					'category'   => ! empty( $cats ) ? $cats[0]->name : '網站知識',
				];
			}

			if ( 'product' === $type ) {
				return [
					'type'       => 'product',
					'type_label' => '商品',
					'category'   => self::first_term_name( $post_id, 'product_cat', '商品' ),
				];
			}

			return [
				'type'       => 'other',
				'type_label' => '內容',
				'category'   => '內容',
			];
		}

		public static function get_excerpt_text( $post_id, $words = 30 ) {
			$post_type = get_post_type( $post_id );
			$excerpt   = '';

			if ( 'product' === $post_type && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post_id );
				if ( $product ) {
					$excerpt = $product->get_short_description();
					if ( ! $excerpt ) {
						$excerpt = $product->get_description();
					}
				}
			}

			if ( ! $excerpt ) {
				$excerpt = get_the_excerpt( $post_id );
			}
			if ( ! $excerpt ) {
				$excerpt = get_post_field( 'post_content', $post_id );
			}

			$excerpt = self::clean_display_text( $excerpt );

			return wp_trim_words( trim( $excerpt ), $words, '…' );
		}

		/** Decode legacy and double-encoded entities before showing plain text. */
		public static function clean_display_text( $text ) {
			$text = strip_shortcodes( (string) $text );
			for ( $i = 0; $i < 3; $i++ ) {
				$decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( $decoded === $text ) break;
				$text = $decoded;
			}
			$text = wp_strip_all_tags( $text );
			$text = str_replace( "\xC2\xA0", ' ', $text );
			return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		}

		public static function build_result( $post_id ) {
			$meta = self::get_result_meta( $post_id );
			$data = [
				'id'         => $post_id,
				'title'      => get_the_title( $post_id ),
				'url'        => get_permalink( $post_id ),
				'excerpt'    => self::get_excerpt_text( $post_id, 30 ),
				'type'       => $meta['type'],
				'type_label' => $meta['type_label'],
				'category'   => $meta['category'],
				'date'       => get_the_modified_date( 'Y-m-d', $post_id ),
				'price'      => '',
			];

			if ( 'product' === get_post_type( $post_id ) && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post_id );
				if ( $product ) {
					$data['price'] = self::clean_display_text( $product->get_price_html() );
				}
			}

			return $data;
		}

		public static function get_context_items( $keyword, $limit = 4 ) {
			$keyword = trim( sanitize_text_field( (string) $keyword ) );
			$limit   = max( 1, min( 8, absint( $limit ) ) );
			if ( '' === $keyword ) {
				return [];
			}

			$q = new WP_Query([
				'post_type'           => self::get_post_types(),
				'post_status'         => 'publish',
				'posts_per_page'      => $limit,
				's'                   => $keyword,
				'orderby'             => 'relevance',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			]);

			$items = [];
			while ( $q->have_posts() ) {
				$q->the_post();
				$id      = get_the_ID();
				$content = get_post_field( 'post_content', $id );
				$content = wp_strip_all_tags( strip_shortcodes( (string) $content ) );
				$content = preg_replace( '/\s+/u', ' ', $content );
				if ( function_exists( 'mb_substr' ) ) {
					$content = mb_substr( trim( $content ), 0, 2200, 'UTF-8' );
				} else {
					$content = substr( trim( $content ), 0, 2200 );
				}
				$meta = self::get_result_meta( $id );
				$items[] = [
					'id'       => $id,
					'title'    => get_the_title( $id ),
					'url'      => get_permalink( $id ),
					'type'     => $meta['type_label'],
					'category' => $meta['category'],
					'content'  => $content,
				];
			}
			wp_reset_postdata();

			return $items;
		}

		/* =====================================================
		 * AJAX SEARCH
		 * ===================================================== */

		public static function ajax_search() {
			check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			$s       = self::get_settings();
			$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
			$keyword = trim( $keyword );
			$results = [];
			$mode    = 'latest';
			$types   = self::get_post_types();

			if ( '' !== $keyword ) {
				$mode = 'search';
				$query = new WP_Query([
					'post_type'           => $types,
					'post_status'         => 'publish',
					'posts_per_page'      => max( 1, min( 30, absint( $s['search_limit'] ) ) ),
					's'                   => $keyword,
					'orderby'             => 'relevance',
					'order'               => 'DESC',
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
				]);
			} else {
				$featured = self::get_featured_ids();
				$limit    = max( 1, min( 30, absint( $s['display_limit'] ) ) );

				if ( ! empty( $featured ) ) {
					$featured = array_values( array_filter( $featured, function( $id ) use ( $types ) {
						return 'publish' === get_post_status( $id ) && in_array( get_post_type( $id ), $types, true );
					} ) );
				}

				if ( ! empty( $featured ) ) {
					$mode = 'featured';
					$featured = array_slice( $featured, 0, $limit );
					$query = new WP_Query([
						'post_type'           => $types,
						'post_status'         => 'publish',
						'post__in'            => $featured,
						'orderby'             => 'post__in',
						'posts_per_page'      => $limit,
						'ignore_sticky_posts' => true,
						'no_found_rows'       => true,
					]);
				} else {
					$query = new WP_Query([
						'post_type'           => $types,
						'post_status'         => 'publish',
						'posts_per_page'      => $limit,
						'orderby'             => 'modified',
						'order'               => 'DESC',
						'ignore_sticky_posts' => true,
						'no_found_rows'       => true,
					]);
				}
			}

			if ( isset( $query ) && $query->have_posts() ) {
				while ( $query->have_posts() ) {
					$query->the_post();
					$results[] = self::build_result( get_the_ID() );
				}
			}
			wp_reset_postdata();

			wp_send_json_success([
				'results' => $results,
				'keyword' => $keyword,
				'mode'    => $mode,
			]);
		}

		/* =====================================================
		 * ADMIN
		 * ===================================================== */

		public static function admin_menu() {
			add_submenu_page(
				'wu-toolbox-modular',
				'Wumetax 快速支援',
				'Wumetax 快速支援',
				'manage_options',
				self::ADMIN_SLUG,
				[ __CLASS__, 'admin_page' ]
			);
		}

		private static function clean_url( $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				return '#';
			}
			if ( 0 === strpos( $url, '/' ) ) {
				return home_url( $url );
			}
			return esc_url_raw( $url );
		}

		public static function admin_save() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( '你沒有權限進行此操作。' );
			}
			check_admin_referer( 'wumetax_qs_save_v130' );

			$defaults = self::defaults();
			$icons    = array_keys( self::icon_choices() );
			$input    = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : [];

			$settings = [
				'panel_title'     => sanitize_text_field( $input['panel_title'] ?? $defaults['panel_title'] ),
				'panel_subtitle'  => sanitize_text_field( $input['panel_subtitle'] ?? $defaults['panel_subtitle'] ),
				'panel_color'     => sanitize_hex_color( $input['panel_color'] ?? '' ) ?: $defaults['panel_color'],
				'icon_color'      => sanitize_hex_color( $input['icon_color'] ?? '' ) ?: $defaults['icon_color'],
				'show_back_to_top'=> ! empty( $input['show_back_to_top'] ) ? 1 : 0,
				'source_docs'     => ! empty( $input['source_docs'] ) ? 1 : 0,
				'source_posts'    => ! empty( $input['source_posts'] ) ? 1 : 0,
				'source_products' => ! empty( $input['source_products'] ) ? 1 : 0,
				'display_limit'   => max( 1, min( 30, absint( $input['display_limit'] ?? 10 ) ) ),
				'search_limit'    => max( 1, min( 30, absint( $input['search_limit'] ?? 12 ) ) ),
			];

			for ( $i = 1; $i <= 3; $i++ ) {
				$settings[ "bottom_{$i}_text" ] = sanitize_text_field( $input[ "bottom_{$i}_text" ] ?? $defaults[ "bottom_{$i}_text" ] );
				$settings[ "bottom_{$i}_url" ]  = self::clean_url( $input[ "bottom_{$i}_url" ] ?? $defaults[ "bottom_{$i}_url" ] );
				$icon = sanitize_key( $input[ "bottom_{$i}_icon" ] ?? $defaults[ "bottom_{$i}_icon" ] );
				$settings[ "bottom_{$i}_icon" ] = in_array( $icon, $icons, true ) ? $icon : $defaults[ "bottom_{$i}_icon" ];
			}

			update_option( self::OPTION_SETTINGS, $settings, false );

			$raw_ids = isset( $_POST['featured_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['featured_ids'] ) ) : '';
			$ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) ) ) );
			$valid_types = self::get_post_types();
			$valid_ids = [];

			foreach ( $ids as $id ) {
				if ( 'publish' === get_post_status( $id ) && in_array( get_post_type( $id ), $valid_types, true ) ) {
					$valid_ids[] = $id;
				}
			}
			update_option( self::OPTION_IDS, $valid_ids, false );

			wp_safe_redirect( add_query_arg( [ 'page' => self::ADMIN_SLUG, 'updated' => 1 ], admin_url( 'admin.php' ) ) );
			exit;
		}

		public static function admin_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$s            = self::get_settings();
			$selected_ids = self::get_featured_ids();
			$all_items    = get_posts([
				'post_type'   => self::get_post_types(),
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'modified',
				'order'       => 'DESC',
			]);
			$selected_items = [];
			foreach ( $selected_ids as $id ) {
				$p = get_post( $id );
				if ( $p && 'publish' === $p->post_status ) {
					$selected_items[] = $p;
				}
			}
			$icons = self::icon_choices();
			?>
			<style>
			.wuqs-admin{max-width:1320px;margin:28px 20px 60px 0}.wuqs-admin *{box-sizing:border-box}.wuqs-head{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;margin-bottom:22px}.wuqs-head h1{margin:0 0 7px;font-size:28px}.wuqs-head p{margin:0;color:#646970}.wuqs-save{min-height:40px!important;padding:0 20px!important}.wuqs-notice{padding:13px 16px;margin-bottom:18px;background:#fff;border-left:4px solid #4fa567}.wuqs-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;overflow:hidden;margin-bottom:20px}.wuqs-card-head{padding:18px 20px;background:#f7f8f7;border-bottom:1px solid #e6e8e7}.wuqs-card-head h2{margin:0 0 5px;font-size:17px}.wuqs-card-head p{margin:0;color:#72777c;font-size:12px}.wuqs-setting-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;padding:20px}.wuqs-field label{display:block;font-weight:650;margin-bottom:7px}.wuqs-field input[type=text],.wuqs-field input[type=url],.wuqs-field input[type=number],.wuqs-field select{width:100%;min-height:40px}.wuqs-help{display:block;margin-top:6px;color:#777;font-size:12px;line-height:1.5}.wuqs-source-row{display:flex;gap:18px;flex-wrap:wrap;padding:20px}.wuqs-source{display:flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid #dfe4e1;border-radius:9px;background:#fbfcfb}.wuqs-bottom-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;padding:20px}.wuqs-bottom-card{padding:16px;border:1px solid #e2e5e3;border-radius:10px;background:#fbfcfb}.wuqs-bottom-card h3{margin:0 0 12px;font-size:14px}.wuqs-bottom-card .wuqs-field{margin-bottom:11px}.wuqs-picker{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px}.wuqs-panel{background:#fff;border:1px solid #dcdcde;border-radius:12px;overflow:hidden}.wuqs-panel-head{padding:18px 20px;background:#f7f8f7;border-bottom:1px solid #e5e5e5}.wuqs-panel-head h2{margin:0 0 5px;font-size:17px}.wuqs-panel-head p{margin:0;color:#72777c;font-size:12px}.wuqs-searchbox{padding:14px 16px;border-bottom:1px solid #e5e5e5}.wuqs-searchbox input{width:100%;min-height:40px}.wuqs-list{max-height:580px;overflow:auto}.wuqs-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:center;padding:13px 16px;border-bottom:1px solid #ededed}.wuqs-item:last-child{border-bottom:0}.wuqs-item:hover{background:#fafcfa}.wuqs-title-sm{font-weight:650;color:#1d2327;margin-bottom:6px}.wuqs-meta{display:flex;gap:6px;flex-wrap:wrap;align-items:center;color:#8a8f94;font-size:11px}.wuqs-badge{display:inline-flex;align-items:center;min-height:22px;padding:0 8px;border-radius:999px;background:#eef7f1;color:#347c4b;font-size:10px;font-weight:700}.wuqs-badge.article{background:#f0f2f1;color:#59635d}.wuqs-badge.product{background:#fff5e9;color:#9a5a00}.wuqs-selected-row{display:grid;grid-template-columns:34px minmax(0,1fr) auto;gap:11px;align-items:center;padding:13px 16px;border-bottom:1px solid #ededed}.wuqs-num{display:flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:8px;background:#eef7f1;color:#347c4b;font-size:11px;font-weight:700}.wuqs-actions-mini{display:flex;gap:5px}.wuqs-mini{width:30px;height:30px;border:1px solid #c3c4c7;border-radius:5px;background:#fff;cursor:pointer}.wuqs-mini:hover{border-color:#4fa567;color:#347c4b}.wuqs-remove:hover{border-color:#d63638;color:#d63638}.wuqs-empty{padding:45px 20px;text-align:center;color:#8a8f94}.wuqs-status{display:flex;align-items:center;gap:8px;padding:14px 20px;border-top:1px solid #eceeed;color:#646970;font-size:12px}.wuqs-dot{width:8px;height:8px;border-radius:50%;background:#4fa567}.wuqs-dot.off{background:#c3c4c7}@media(max-width:900px){.wuqs-setting-grid,.wuqs-bottom-grid,.wuqs-picker{grid-template-columns:1fr}.wuqs-head{flex-direction:column}}
			</style>
			<style>.wuqs-admin{max-width:1240px;margin:20px 0 0!important}.wuqs-admin>.wutm-module-subtitle{margin-bottom:18px}.wuqs-actions-bar{display:flex;justify-content:flex-end;margin:0 0 18px}.wuqs-card,.wuqs-panel{border-radius:8px}.wuqs-admin input[type=color]{width:72px;height:40px;padding:3px;border:1px solid #8c8f94;border-radius:4px;background:#fff}@media(max-width:782px){.wuqs-actions-bar{justify-content:stretch}.wuqs-actions-bar .button{width:100%}}</style>

			<div class="wrap wutm-module-wrap sac-tools-page wuqs-admin">
				<h1>Wumetax 快速支援</h1>
				<p class="wutm-module-subtitle">設定搜尋來源、預設內容、面板色彩與底部三個快捷連結；AI 功能由獨立擴充模組加入。</p>
				<?php if ( isset( $_GET['updated'] ) ) : ?>
					<div class="wuqs-notice"><strong>已儲存。</strong> 前台快速支援已套用最新設定。</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wuqs-form">
					<input type="hidden" name="action" value="wumetax_qs_save_v130">
					<?php wp_nonce_field( 'wumetax_qs_save_v130' ); ?>
					<input type="hidden" name="featured_ids" id="wuqs-featured-ids" value="<?php echo esc_attr( implode( ',', $selected_ids ) ); ?>">

					<div class="wuqs-actions-bar"><button type="submit" class="button button-primary wuqs-save">儲存設定</button></div>

					<div class="wuqs-card">
						<div class="wuqs-card-head"><h2>面板內容</h2><p>控制訪客打開快速支援時看到的標題與文字。</p></div>
						<div class="wuqs-setting-grid">
							<div class="wuqs-field"><label>標題</label><input type="text" name="settings[panel_title]" value="<?php echo esc_attr( $s['panel_title'] ); ?>"></div>
							<div class="wuqs-field"><label>說明</label><input type="text" name="settings[panel_subtitle]" value="<?php echo esc_attr( $s['panel_subtitle'] ); ?>"></div>
							<div class="wuqs-field"><label>面板顏色</label><input type="color" name="settings[panel_color]" value="<?php echo esc_attr( $s['panel_color'] ); ?>"><span class="wuqs-help">套用於面板標題區、搜尋按鈕與主要強調色。</span></div>
							<div class="wuqs-field"><label>圖示顏色</label><input type="color" name="settings[icon_color]" value="<?php echo esc_attr( $s['icon_color'] ); ?>"><span class="wuqs-help">套用於右下角快速支援按鈕的圖示。</span></div>
							<div class="wuqs-field"><label>預設顯示筆數</label><input type="number" min="1" max="30" name="settings[display_limit]" value="<?php echo esc_attr( $s['display_limit'] ); ?>"><span class="wuqs-help">若有手動挑選內容，會依下方順序顯示；沒有則顯示最新內容。</span></div>
							<div class="wuqs-field"><label>搜尋結果上限</label><input type="number" min="1" max="30" name="settings[search_limit]" value="<?php echo esc_attr( $s['search_limit'] ); ?>"></div>
							<div class="wuqs-field"><label><input type="checkbox" name="settings[show_back_to_top]" value="1" <?php checked( ! empty( $s['show_back_to_top'] ) ); ?>> 顯示「回到最上」按鈕</label><span class="wuqs-help">訪客向下捲動後顯示；關閉時只保留快速支援按鈕。</span></div>
						</div>
						<div class="wuqs-source-row">
							<label class="wuqs-source"><input type="checkbox" name="settings[source_docs]" value="1" <?php checked( ! empty( $s['source_docs'] ) ); ?> <?php disabled( ! post_type_exists( 'skb_doc' ) ); ?>> 客戶支援文件 <code>skb_doc</code></label>
							<label class="wuqs-source"><input type="checkbox" name="settings[source_posts]" value="1" <?php checked( ! empty( $s['source_posts'] ) ); ?>> 網站知識文章 <code>post</code></label>
							<label class="wuqs-source"><input type="checkbox" name="settings[source_products]" value="1" <?php checked( ! empty( $s['source_products'] ) ); ?> <?php disabled( ! post_type_exists( 'product' ) ); ?>> WooCommerce 商品 <?php echo post_type_exists( 'product' ) ? '<code>product</code>' : '<small>（目前未偵測到）</small>'; ?></label>
						</div>
					</div>

					<div class="wuqs-card">
						<div class="wuqs-card-head"><h2>底部快捷連結</h2><p>三個位置都可以修改文字、網址與圖示；預設維持現在的「首頁 / 聯絡我們 / 支援中心」。</p></div>
						<div class="wuqs-bottom-grid">
							<?php for ( $i = 1; $i <= 3; $i++ ) : ?>
								<div class="wuqs-bottom-card">
									<h3>位置 <?php echo esc_html( $i ); ?></h3>
									<div class="wuqs-field"><label>文字</label><input type="text" name="settings[bottom_<?php echo esc_attr( $i ); ?>_text]" value="<?php echo esc_attr( $s[ "bottom_{$i}_text" ] ); ?>"></div>
									<div class="wuqs-field"><label>連結</label><input type="url" name="settings[bottom_<?php echo esc_attr( $i ); ?>_url]" value="<?php echo esc_attr( $s[ "bottom_{$i}_url" ] ); ?>"></div>
									<div class="wuqs-field"><label>圖示</label><select name="settings[bottom_<?php echo esc_attr( $i ); ?>_icon]">
										<?php foreach ( $icons as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s[ "bottom_{$i}_icon" ], $key ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select></div>
								</div>
							<?php endfor; ?>
						</div>
					</div>

					<div class="wuqs-picker">
						<div class="wuqs-panel">
							<div class="wuqs-panel-head"><h2>可選內容</h2><p>來自目前啟用的搜尋來源。按「加入」即可固定在預設內容。</p></div>
							<div class="wuqs-searchbox"><input type="search" id="wuqs-admin-search" placeholder="搜尋標題、類型、分類..."></div>
							<div class="wuqs-list" id="wuqs-available-list">
								<?php foreach ( $all_items as $item ) :
									$meta = self::get_result_meta( $item->ID );
									$is_selected = in_array( $item->ID, $selected_ids, true );
									$search = strtolower( wp_strip_all_tags( $item->post_title . ' ' . $meta['type_label'] . ' ' . $meta['category'] ) );
									$badge_class = 'article' === $meta['type'] ? 'article' : ( 'product' === $meta['type'] ? 'product' : '' );
								?>
									<div class="wuqs-item" data-search="<?php echo esc_attr( $search ); ?>">
										<div><div class="wuqs-title-sm"><?php echo esc_html( $item->post_title ); ?></div><div class="wuqs-meta"><span class="wuqs-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $meta['type_label'] ); ?></span><span><?php echo esc_html( $meta['category'] ); ?></span><span>#<?php echo esc_html( $item->ID ); ?></span></div></div>
										<button type="button" class="button wuqs-add" data-id="<?php echo esc_attr( $item->ID ); ?>" data-title="<?php echo esc_attr( $item->post_title ); ?>" data-type="<?php echo esc_attr( $meta['type'] ); ?>" data-type-label="<?php echo esc_attr( $meta['type_label'] ); ?>" data-category="<?php echo esc_attr( $meta['category'] ); ?>" <?php disabled( $is_selected ); ?>><?php echo $is_selected ? '已加入' : '加入'; ?></button>
									</div>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="wuqs-panel">
							<div class="wuqs-panel-head"><h2>快速支援預設內容</h2><p>最上方優先顯示；可上下調整順序。若全部移除，前台會自動抓最新內容。</p></div>
							<div class="wuqs-list" id="wuqs-selected-list">
								<?php if ( empty( $selected_items ) ) : ?><div class="wuqs-empty" id="wuqs-empty">目前沒有指定內容。<br>將自動顯示最新內容。</div><?php endif; ?>
								<?php foreach ( $selected_items as $index => $item ) :
									$meta = self::get_result_meta( $item->ID );
									$badge_class = 'article' === $meta['type'] ? 'article' : ( 'product' === $meta['type'] ? 'product' : '' );
								?>
									<div class="wuqs-selected-row" data-id="<?php echo esc_attr( $item->ID ); ?>">
										<span class="wuqs-num"><?php echo esc_html( $index + 1 ); ?></span>
										<div><div class="wuqs-title-sm"><?php echo esc_html( $item->post_title ); ?></div><div class="wuqs-meta"><span class="wuqs-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $meta['type_label'] ); ?></span><span><?php echo esc_html( $meta['category'] ); ?></span></div></div>
										<div class="wuqs-actions-mini"><button type="button" class="wuqs-mini wuqs-up" title="往上">↑</button><button type="button" class="wuqs-mini wuqs-down" title="往下">↓</button><button type="button" class="wuqs-mini wuqs-remove" title="移除">×</button></div>
									</div>
								<?php endforeach; ?>
							</div>
							<div class="wuqs-status"><span class="wuqs-dot"></span> 搜尋會自動涵蓋目前勾選的來源；手動挑選只影響「打開面板時預設顯示」的內容。</div>
						</div>
					</div>
				</form>
			</div>

			<script>
			(function(){
				'use strict';
				const hidden=document.getElementById('wuqs-featured-ids');
				const selected=document.getElementById('wuqs-selected-list');
				const search=document.getElementById('wuqs-admin-search');
				function rows(){return Array.from(selected.querySelectorAll('.wuqs-selected-row'));}
				function sync(){
					const list=rows(); hidden.value=list.map(r=>r.dataset.id).join(',');
					list.forEach((r,i)=>{const n=r.querySelector('.wuqs-num');if(n)n.textContent=String(i+1);});
					const empty=document.getElementById('wuqs-empty');
					if(!list.length&&!empty){const e=document.createElement('div');e.id='wuqs-empty';e.className='wuqs-empty';e.innerHTML='目前沒有指定內容。<br>將自動顯示最新內容。';selected.appendChild(e);}else if(list.length&&empty){empty.remove();}
					const ids=list.map(r=>r.dataset.id);document.querySelectorAll('.wuqs-add').forEach(b=>{const yes=ids.includes(b.dataset.id);b.disabled=yes;b.textContent=yes?'已加入':'加入';});
				}
				function createRow(b){
					const row=document.createElement('div');row.className='wuqs-selected-row';row.dataset.id=b.dataset.id;
					const cls=b.dataset.type==='article'?'article':(b.dataset.type==='product'?'product':'');
					row.innerHTML='<span class="wuqs-num"></span><div><div class="wuqs-title-sm"></div><div class="wuqs-meta"><span class="wuqs-badge '+cls+'"></span><span class="wuqs-cat"></span></div></div><div class="wuqs-actions-mini"><button type="button" class="wuqs-mini wuqs-up" title="往上">↑</button><button type="button" class="wuqs-mini wuqs-down" title="往下">↓</button><button type="button" class="wuqs-mini wuqs-remove" title="移除">×</button></div>';
					row.querySelector('.wuqs-title-sm').textContent=b.dataset.title;row.querySelector('.wuqs-badge').textContent=b.dataset.typeLabel;row.querySelector('.wuqs-cat').textContent=b.dataset.category;return row;
				}
				document.addEventListener('click',function(e){
					const add=e.target.closest('.wuqs-add');if(add&&!add.disabled){selected.appendChild(createRow(add));sync();return;}
					const row=e.target.closest('.wuqs-selected-row');if(!row)return;
					if(e.target.closest('.wuqs-remove')){row.remove();sync();return;}
					if(e.target.closest('.wuqs-up')){const p=row.previousElementSibling;if(p&&p.classList.contains('wuqs-selected-row')){selected.insertBefore(row,p);sync();}return;}
					if(e.target.closest('.wuqs-down')){const n=row.nextElementSibling;if(n&&n.classList.contains('wuqs-selected-row')){selected.insertBefore(n,row);sync();}}
				});
				search.addEventListener('input',function(){const k=search.value.trim().toLowerCase();document.querySelectorAll('#wuqs-available-list .wuqs-item').forEach(i=>{i.style.display=!k||String(i.dataset.search||'').toLowerCase().includes(k)?'':'none';});});
				sync();
			})();
			</script>
			<?php
		}

		/* =====================================================
		 * FRONTEND
		 * ===================================================== */

		public static function render_frontend() {
			if ( is_admin() ) {
				return;
			}

			$s            = self::get_settings();
			$ajax_url     = admin_url( 'admin-ajax.php' );
			$nonce        = wp_create_nonce( self::NONCE_ACTION );
			$ai_available = (bool) apply_filters( 'wumetax_qs_ai_available', false );
			?>
			<style id="wumetax-quick-support-v130-css">
			:root{--wuqs-green:#4fa567;--wuqs-green-dark:#347c4b;--wuqs-green-soft:#eef7f1;--wuqs-dark:#171b19;--wuqs-text:#39423d;--wuqs-muted:#758079;--wuqs-line:#e3e8e5}.wuqs-actions{position:fixed;z-index:99990;right:24px;bottom:24px;display:flex;flex-direction:column;align-items:flex-end;gap:11px}.wuqs-float{position:relative;display:flex;align-items:center;justify-content:center;padding:0;border-radius:50%;cursor:pointer;-webkit-tap-highlight-color:transparent;transition:.22s ease}.wuqs-float svg{width:21px;height:21px}.wuqs-float--top{width:48px;height:48px;border:1px solid rgba(52,124,75,.18);background:rgba(255,255,255,.97);color:var(--wuqs-green-dark);box-shadow:0 8px 25px rgba(27,52,36,.10)}.wuqs-float--top:hover{background:var(--wuqs-green-soft);transform:translateY(-2px)}#wuwqs-top{opacity:0;pointer-events:none;transform:translateY(8px) scale(.92)}#wuwqs-top.is-visible{opacity:1;pointer-events:auto;transform:none}.wuqs-float--support{width:58px;height:58px;border:4px solid rgba(255,255,255,.94);background:linear-gradient(145deg,#59b971,#3d9858 58%,#347c4b);color:#fff;box-shadow:0 12px 32px rgba(52,124,75,.28)}.wuqs-float--support:hover{transform:translateY(-3px) scale(1.025)}.wuqs-float--support.is-open{background:var(--wuqs-dark)}.wuqs-float--support::before{content:"";position:absolute;top:5px;right:5px;width:7px;height:7px;border:2px solid #fff;border-radius:50%;background:#8fe0a1}.wuqs-backdrop{position:fixed;z-index:99991;inset:0;background:rgba(17,22,19,.14);backdrop-filter:blur(3px);opacity:0;visibility:hidden;transition:.22s ease}.wuqs-backdrop.is-open{opacity:1;visibility:visible}.wuqs-panel{position:fixed;z-index:99992;right:24px;bottom:96px;display:flex;flex-direction:column;width:min(460px,calc(100vw - 32px));height:min(680px,calc(100dvh - 130px));max-height:calc(100dvh - 120px);border:1px solid rgba(23,27,25,.08);border-radius:25px;background:#fff;box-shadow:0 28px 90px rgba(22,35,27,.22);overflow:hidden;opacity:0;visibility:hidden;transform:translateY(18px) scale(.98);transform-origin:bottom right;transition:.24s ease}.wuqs-panel.is-open{opacity:1;visibility:visible;transform:none}.wuqs-header{position:relative;flex:0 0 auto;min-height:152px;padding:29px 30px 54px;overflow:hidden;background:linear-gradient(135deg,#43b66a,#4fa567 54%,#42975a);color:#fff}.wuqs-header::before,.wuqs-header::after{content:"";position:absolute;border-radius:50%;background:rgba(255,255,255,.085)}.wuqs-header::before{width:270px;height:270px;left:-90px;top:-170px}.wuqs-header::after{width:130px;height:130px;right:-24px;top:32px}.wuqs-header-inner{position:relative;z-index:2}.wuqs-title{margin:0 0 9px;color:#fff;font-size:22px;font-weight:750;line-height:1.35}.wuqs-subtitle{max-width:350px;margin:0;color:rgba(255,255,255,.92);font-size:13px;line-height:1.75}.wuqs-close{position:absolute;z-index:5;right:17px;top:17px;display:flex;align-items:center;justify-content:center;width:35px;height:35px;padding:0;border:1px solid rgba(255,255,255,.15);border-radius:50%;background:rgba(17,22,19,.12);color:#fff;cursor:pointer}.wuqs-close svg{width:16px;height:16px}.wuqs-search-wrap{position:relative;z-index:5;flex:0 0 auto;margin:-31px 18px 0}.wuqs-search{display:flex;align-items:center;height:62px;padding-left:21px;border:1px solid rgba(23,27,25,.07);border-radius:999px;background:#fff;box-shadow:0 10px 32px rgba(31,55,40,.10)}.wuqs-search input{flex:1;min-width:0;height:100%;padding:0;border:0!important;outline:0!important;background:transparent!important;box-shadow:none!important;color:var(--wuqs-dark);font-family:inherit;font-size:15px}.wuqs-search input::placeholder{color:#8b948e}.wuqs-search-button{display:flex;align-items:center;justify-content:center;flex:0 0 49px;width:49px;height:49px;margin-right:6px;padding:0;border:0;border-radius:50%;background:linear-gradient(145deg,#59b971,#3d9556);color:#fff;cursor:pointer}.wuqs-search-button svg{width:20px;height:20px}.wuqs-mode-tabs{display:flex;gap:7px;flex:0 0 auto;padding:12px 18px 0;background:#fff}.wuqs-mode-tab{flex:1;min-height:38px;border:1px solid var(--wuqs-line);border-radius:10px;background:#fff;color:var(--wuqs-text);font-size:12px;font-weight:700;cursor:pointer}.wuqs-mode-tab.is-active{border-color:#cce4d3;background:var(--wuqs-green-soft);color:var(--wuqs-green-dark)}.wuqs-view{flex:1 1 auto;min-height:0;display:none;position:relative;overflow:hidden}.wuqs-view.is-active{display:flex;flex-direction:column;min-height:0}.wuqs-content{flex:1;min-height:0;overflow-y:auto;padding:16px 18px 14px;background:#fff;scrollbar-width:thin;overscroll-behavior:contain}.wuqs-section-head{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:7px 18px 13px;border-bottom:1px solid var(--wuqs-line)}.wuqs-section-title{margin:0;color:var(--wuqs-dark);font-size:17px;font-weight:750}.wuqs-section-count{color:var(--wuqs-muted);font-size:10px;font-weight:650}.wuqs-result{position:relative;display:block;padding:17px 50px 17px 18px;border-bottom:1px solid var(--wuqs-line);color:var(--wuqs-dark);text-decoration:none!important;transition:background .18s ease}.wuqs-result:hover{background:#f7faf8}.wuqs-result-meta{display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin-bottom:7px}.wuqs-result-type{display:inline-flex;align-items:center;min-height:23px;padding:0 8px;border-radius:999px;font-size:9px;font-weight:750}.wuqs-result-type--doc{background:var(--wuqs-green-soft);color:var(--wuqs-green-dark)}.wuqs-result-type--article{background:#f0f2f1;color:#59635d}.wuqs-result-type--product{background:#fff4e7;color:#9b5c00}.wuqs-result-category{color:#8a948e;font-size:10px;font-weight:650}.wuqs-result-title{display:block;margin-bottom:6px;color:var(--wuqs-dark);font-size:15px;font-weight:700;line-height:1.55}.wuqs-result-price{display:block;margin:-1px 0 5px;color:#347c4b;font-size:12px;font-weight:700}.wuqs-result-excerpt{display:-webkit-box;overflow:hidden;margin:0;color:var(--wuqs-muted);font-size:13px;line-height:1.65;-webkit-line-clamp:2;-webkit-box-orient:vertical}.wuqs-result-arrow{position:absolute;right:19px;top:50%;display:flex;align-items:center;justify-content:center;width:27px;height:27px;border-radius:50%;background:var(--wuqs-green-soft);color:var(--wuqs-green-dark);font-size:19px;transform:translateY(-50%)}.wuqs-message{padding:45px 22px;text-align:center;color:var(--wuqs-muted);font-size:13px;line-height:1.8}.wuqs-loader{display:flex;align-items:center;justify-content:center;gap:7px;padding:48px 20px}.wuqs-loader span{width:7px;height:7px;border-radius:50%;background:var(--wuqs-green);animation:wuqs-loading 1s infinite ease-in-out}.wuqs-loader span:nth-child(2){animation-delay:.12s}.wuqs-loader span:nth-child(3){animation-delay:.24s}@keyframes wuqs-loading{0%,80%,100%{opacity:.3;transform:scale(.75)}40%{opacity:1;transform:scale(1)}}.wuqs-bottom{position:relative;z-index:10;flex:0 0 76px;width:100%;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));min-height:76px;border-top:1px solid var(--wuqs-line);background:rgba(255,255,255,.98);backdrop-filter:blur(12px)}.wuqs-bottom-link{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;color:var(--wuqs-text)!important;text-decoration:none!important;font-size:11px}.wuqs-bottom-link:hover{color:var(--wuqs-green-dark)!important}.wuqs-bottom-link svg{width:21px;height:21px}.wuqs-mode-tab:focus-visible,.wuqs-search-button:focus-visible,.wuqs-close:focus-visible,.wuqs-bottom-link:focus-visible,.wuqs-float:focus-visible{outline:2px solid #171b19;outline-offset:2px}@media(max-width:700px){.wuqs-actions{right:14px;bottom:calc(14px + env(safe-area-inset-bottom))}.wuqs-float--top{width:46px;height:46px}.wuqs-float--support{width:56px;height:56px}.wuqs-panel{left:8px;right:8px;bottom:calc(78px + env(safe-area-inset-bottom));width:auto;height:min(700px,calc(100dvh - 98px));max-height:calc(100dvh - 98px);border-radius:22px}.wuqs-header{min-height:146px;padding:27px 25px 50px}.wuqs-title{font-size:21px}.wuqs-search-wrap{margin:-30px 13px 0}.wuqs-mode-tabs{padding-left:13px;padding-right:13px}.wuqs-content{padding-left:13px;padding-right:13px}}
			.wuqs-actions,#wuqs-panel{--wuqs-green:<?php echo esc_html( $s['panel_color'] ); ?>;--wuqs-green-dark:<?php echo esc_html( $s['panel_color'] ); ?>;--wuqs-icon:<?php echo esc_html( $s['icon_color'] ); ?>}.wuqs-float--support{background:var(--wuqs-green);color:var(--wuqs-icon)}.wuqs-header{background:var(--wuqs-green)}.wuqs-search-button{background:var(--wuqs-green);color:var(--wuqs-icon)}.wuqs-result-price{color:var(--wuqs-green-dark)}
			</style>

			<div class="wuqs-actions">
				<?php if ( ! empty( $s['show_back_to_top'] ) ) : ?><button type="button" id="wuwqs-top" class="wuqs-float wuqs-float--top" aria-label="回到頁面最上方"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6.5 14.5 5.5-5 5.5 5"/></svg></button><?php endif; ?>
				<button type="button" id="wuwqs-support" class="wuqs-float wuqs-float--support" aria-label="開啟快速支援" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 11.5a8 8 0 0 1-8 8 8.1 8.1 0 0 1-3.7-.9L4 20l1.4-4.4a8 8 0 1 1 15.1-4.1Z"/><path d="M9.6 9.4a2.6 2.6 0 0 1 5 .9c0 1.7-2.4 1.9-2.4 3.4"/><path d="M12.2 16.2h.01"/></svg></button>
			</div>
			<div id="wuqs-backdrop" class="wuqs-backdrop"></div>

			<aside id="wuqs-panel" class="wuqs-panel" aria-hidden="true">
				<div class="wuqs-header">
					<button type="button" id="wuqs-close" class="wuqs-close" aria-label="關閉"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12"/><path d="M18 6 6 18"/></svg></button>
					<div class="wuqs-header-inner"><h2 class="wuqs-title"><?php echo esc_html( $s['panel_title'] ); ?></h2><p class="wuqs-subtitle"><?php echo esc_html( $s['panel_subtitle'] ); ?></p></div>
				</div>

				<div class="wuqs-search-wrap" id="wuqs-search-wrap">
					<div class="wuqs-search"><input type="search" id="wuqs-input" placeholder="搜尋問題、功能或關鍵字..." autocomplete="off"><button type="button" id="wuqs-search-button" class="wuqs-search-button" aria-label="搜尋"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg></button></div>
				</div>

				<?php if ( $ai_available ) : ?>
					<div class="wuqs-mode-tabs"><button type="button" class="wuqs-mode-tab is-active" data-wuqs-mode="search">內容搜尋</button><button type="button" class="wuqs-mode-tab" data-wuqs-mode="ai">AI 助理</button></div>
				<?php endif; ?>

				<div class="wuqs-view wuqs-view--search is-active" id="wuqs-search-view">
					<div class="wuqs-content"><div class="wuqs-section-head"><h3 class="wuqs-section-title" id="wuqs-results-title">精選內容</h3><span class="wuqs-section-count" id="wuqs-result-count"></span></div><div id="wuqs-results"></div></div>
				</div>

				<?php if ( $ai_available ) : ?>
					<div class="wuqs-view wuqs-view--ai" id="wuqs-ai-view" hidden><?php do_action( 'wumetax_qs_ai_panel' ); ?></div>
				<?php endif; ?>

				<nav class="wuqs-bottom">
					<?php for ( $i = 1; $i <= 3; $i++ ) : ?>
						<a href="<?php echo esc_url( $s[ "bottom_{$i}_url" ] ); ?>" class="wuqs-bottom-link"><?php echo self::icon_svg( $s[ "bottom_{$i}_icon" ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $s[ "bottom_{$i}_text" ] ); ?></span></a>
					<?php endfor; ?>
				</nav>
			</aside>

			<script id="wumetax-quick-support-v130-js">
			(function(){
				'use strict';
				const ajaxUrl=<?php echo wp_json_encode( $ajax_url ); ?>,nonce=<?php echo wp_json_encode( $nonce ); ?>;
				const panel=document.getElementById('wuqs-panel'),backdrop=document.getElementById('wuqs-backdrop'),support=document.getElementById('wuwqs-support'),topBtn=document.getElementById('wuwqs-top'),closeBtn=document.getElementById('wuqs-close'),input=document.getElementById('wuqs-input'),searchBtn=document.getElementById('wuqs-search-button'),results=document.getElementById('wuqs-results'),title=document.getElementById('wuqs-results-title'),count=document.getElementById('wuqs-result-count'),searchWrap=document.getElementById('wuqs-search-wrap');
				let timer=null,controller=null,loaded=false;
				window.WumetaxQuickSupport={ajaxUrl:ajaxUrl,nonce:nonce,panel:panel};
				function open(){panel.classList.add('is-open');backdrop.classList.add('is-open');support.classList.add('is-open');support.setAttribute('aria-expanded','true');panel.setAttribute('aria-hidden','false');if(window.innerWidth<=700){document.documentElement.style.overflow='hidden';document.body.style.overflow='hidden';}if(!loaded){runSearch('');loaded=true;}setTimeout(()=>input.focus(),180);}
				function close(){panel.classList.remove('is-open');backdrop.classList.remove('is-open');support.classList.remove('is-open');support.setAttribute('aria-expanded','false');panel.setAttribute('aria-hidden','true');document.documentElement.style.removeProperty('overflow');document.body.style.removeProperty('overflow');}
				window.WumetaxQuickSupport.open=open;window.WumetaxQuickSupport.close=close;
				function loading(){count.textContent='';results.innerHTML='<div class="wuqs-loader"><span></span><span></span><span></span></div>';}
				function render(items,keyword,mode){results.innerHTML='';title.textContent=mode==='featured'?'精選內容':(mode==='search'?'搜尋結果':'最新內容');items=Array.isArray(items)?items:[];count.textContent=items.length?items.length+' 筆':'';if(!items.length){const e=document.createElement('div');e.className='wuqs-message';e.textContent=keyword?'找不到符合「'+keyword+'」的內容。':'目前沒有可顯示的內容。';results.appendChild(e);return;}items.forEach(item=>{const a=document.createElement('a');a.className='wuqs-result';a.href=item.url;const meta=document.createElement('div');meta.className='wuqs-result-meta';const t=document.createElement('span');t.className='wuqs-result-type '+(item.type==='doc'?'wuqs-result-type--doc':(item.type==='product'?'wuqs-result-type--product':'wuqs-result-type--article'));t.textContent=item.type_label||'內容';const c=document.createElement('span');c.className='wuqs-result-category';c.textContent=item.category||'';const h=document.createElement('strong');h.className='wuqs-result-title';h.textContent=item.title;meta.appendChild(t);if(item.category)meta.appendChild(c);a.appendChild(meta);a.appendChild(h);if(item.price){const p=document.createElement('span');p.className='wuqs-result-price';p.textContent=item.price;a.appendChild(p);}if(item.excerpt){const p=document.createElement('p');p.className='wuqs-result-excerpt';p.textContent=item.excerpt;a.appendChild(p);}const ar=document.createElement('span');ar.className='wuqs-result-arrow';ar.textContent='›';a.appendChild(ar);results.appendChild(a);});}
				async function runSearch(keyword){keyword=String(keyword||'').trim();if(controller)controller.abort();controller=new AbortController();loading();const body=new URLSearchParams();body.append('action','wumetax_quick_support_search');body.append('nonce',nonce);body.append('keyword',keyword);try{const r=await fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString(),signal:controller.signal,credentials:'same-origin'});if(!r.ok)throw new Error('HTTP '+r.status);const d=await r.json();if(!d||!d.success)throw new Error('Search failed');render(d.data.results,keyword,d.data.mode);}catch(e){if(e.name==='AbortError')return;count.textContent='';results.innerHTML='<div class="wuqs-message">搜尋暫時無法使用，請稍後再試。</div>';}}
				support.addEventListener('click',()=>panel.classList.contains('is-open')?close():open());closeBtn.addEventListener('click',close);backdrop.addEventListener('click',close);document.addEventListener('keydown',e=>{if(e.key==='Escape'&&panel.classList.contains('is-open'))close();});input.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>runSearch(input.value),300);});input.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();clearTimeout(timer);runSearch(input.value);}});searchBtn.addEventListener('click',()=>{clearTimeout(timer);runSearch(input.value);});
				if(topBtn){function updateTop(){topBtn.classList.toggle('is-visible',window.scrollY>420);}window.addEventListener('scroll',updateTop,{passive:true});updateTop();topBtn.addEventListener('click',()=>window.scrollTo({top:0,behavior:'smooth'}));}
				document.querySelectorAll('.wuqs-mode-tab').forEach(btn=>btn.addEventListener('click',()=>{const mode=btn.dataset.wuqsMode;document.querySelectorAll('.wuqs-mode-tab').forEach(b=>b.classList.toggle('is-active',b===btn));const sv=document.getElementById('wuqs-search-view'),av=document.getElementById('wuqs-ai-view');if(mode==='ai'&&av){sv.classList.remove('is-active');av.hidden=false;av.classList.add('is-active');searchWrap.style.display='none';document.dispatchEvent(new CustomEvent('wumetax:qs-ai-open'));}else{if(av){av.classList.remove('is-active');av.hidden=true;}sv.classList.add('is-active');searchWrap.style.display='block';}}));
			})();
			</script>
			<?php
		}
	}

	Wumetax_Quick_Support_v131::init();
}

/**
 * 穩定公開 helper：提供 AI 擴充或其他外掛取得網站內部相關內容。
 */
if ( ! function_exists( 'wumetax_quick_support_get_context' ) ) {
	function wumetax_quick_support_get_context( $keyword, $limit = 4 ) {
		if ( class_exists( 'Wumetax_Quick_Support_v131' ) ) {
			return Wumetax_Quick_Support_v131::get_context_items( $keyword, $limit );
		}
		return [];
	}
}
