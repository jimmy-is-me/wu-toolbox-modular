<?php
/**
 * 商品目錄 XML 模組 - 產生美安爬蟲可讀取的商品目錄。
 * 支援分類篩選（包含/排除）、批次背景建立（WP-Cron）、
 * 排除特定商品類型（如組合商品），避免大型商城逗時。
 *
 * @package WooShopcomIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ShopCom_XML_Feed {

	/** @var ShopCom_Settings */
	private $settings;

	const CRON_HOOK   = 'shopcom_generate_xml_batch';
	const TRANSIENT_XML = 'shopcom_xml_cache';
	const OPTION_LAST_RUN = 'shopcom_xml_last_run';

	public function __construct( ShopCom_Settings $settings ) {
		$this->settings = $settings;

		add_action( 'init', array( $this, 'register_feed' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_batch_generation' ) );

		// 商品新增/更新/上架/從垃垃桑還原時，排程重新產生 XML（沙用舊版邏輯，避免頻繁重算拖慢後台）。
		add_action( 'save_post_product', array( $this, 'maybe_schedule_regeneration' ), 10, 3 );
		add_action( 'untrashed_post', array( $this, 'maybe_schedule_regeneration_by_id' ) );

		add_action( 'admin_post_shopcom_manual_regenerate', array( $this, 'handle_manual_regeneration' ) );
	}

	public function register_feed() {
		add_feed( 'shopcom', array( $this, 'render_xml_feed' ) );
	}

	public function maybe_schedule_regeneration( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		$this->schedule_regeneration();
	}

	public function maybe_schedule_regeneration_by_id( $post_id ) {
		if ( 'product' === get_post_type( $post_id ) ) {
			$this->schedule_regeneration();
		}
	}

	private function schedule_regeneration() {
		delete_transient( self::TRANSIENT_XML );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::CRON_HOOK );
		}
	}

	private function get_product_query_args( $paged = 1 ) {
		$batch_size = max( 1, (int) $this->settings->get( 'xml_batch_size' ) );

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'paged'          => $paged,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'tax_query'      => array(),
		);

		$filter_mode = $this->settings->get( 'xml_filter_mode' );
		$categories  = $this->settings->get( 'xml_filter_categories' );

		if ( 'include' === $filter_mode && ! empty( $categories ) ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $categories,
				'operator' => 'IN',
			);
		} elseif ( 'exclude' === $filter_mode && ! empty( $categories ) ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $categories,
				'operator' => 'NOT IN',
			);
		}

		return $args;
	}

	private function is_excluded_type( $product ) {
		$excluded = (array) $this->settings->get( 'xml_exclude_types' );
		return in_array( $product->get_type(), $excluded, true );
	}

	/**
	 * 依批次數量以 WP-Cron 背景執行，避免商品數量龜大時網頁逗時。
	 * 完成後透過 Notifier 寄送通知信（若設定收件人）。
	 */
	public function run_batch_generation() {
		$xml    = $this->build_xml_document();
		set_transient( self::TRANSIENT_XML, $xml, DAY_IN_SECONDS );
		update_option(
			self::OPTION_LAST_RUN,
			array(
				'start' => current_time( 'mysql' ),
				'end'   => current_time( 'mysql' ),
			)
		);

		do_action( 'shopcom_xml_generated', $xml );
	}

	private function build_xml_document() {
		$paged      = 1;
		$products_xml = '';

		do {
			$args  = $this->get_product_query_args( $paged );
			$query = new WP_Query( $args );

			if ( ! $query->have_posts() ) {
				break;
			}

			while ( $query->have_posts() ) {
				$query->the_post();
				$product = wc_get_product( get_the_ID() );
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				if ( 'hidden' === $product->get_catalog_visibility() ) {
					continue;
				}

				if ( $product->is_type( 'variable' ) ) {
					foreach ( $product->get_children() as $variation_id ) {
						$variation = wc_get_product( $variation_id );
						if ( ! $variation instanceof WC_Product_Variation || ! $variation->variation_is_visible() || '' === $variation->get_price() ) continue;
						$products_xml .= $this->build_product_xml( $variation, $product );
					}
					continue;
				}

				if ( $this->is_excluded_type( $product ) || '' === $product->get_price() ) continue;
				$products_xml .= $this->build_product_xml( $product );
			}

			$paged++;
		} while ( $query->max_num_pages >= $paged );

		wp_reset_postdata();

		return '<?xml version="1.0" encoding="' . get_option( 'blog_charset' ) . '"?>' . "\n" .
			'<products generated="' . esc_xml( current_time( 'mysql' ) ) . '">' . "\n" .
			$products_xml .
			'</products>';
	}

	private function build_product_xml( $product, $parent = null ) {
		$base_product = $parent instanceof WC_Product ? $parent : $product;
		$description_source = $this->settings->get( 'xml_description_source' );
		$description = 'description' === $description_source
			? $product->get_description()
			: $product->get_short_description();
		if ( '' === trim( wp_strip_all_tags( $description ) ) && $parent instanceof WC_Product ) {
			$description = 'description' === $description_source ? $parent->get_description() : $parent->get_short_description();
		}

		$name = $base_product->get_name();
		if ( $product instanceof WC_Product_Variation ) {
			$attributes = wc_get_formatted_variation( $product, true, false, true );
			if ( $attributes ) $name .= '－' . wp_strip_all_tags( $attributes );
		}

		$image_id = $product->get_image_id();
		if ( ! $image_id && $parent instanceof WC_Product ) $image_id = $parent->get_image_id();
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';

		return sprintf(
			"\t<product>\n" .
			"\t\t<id>%s</id>\n" .
			"\t\t<parent_id>%s</parent_id>\n" .
			"\t\t<sku><![CDATA[%s]]></sku>\n" .
			"\t\t<name><![CDATA[%s]]></name>\n" .
			"\t\t<url><![CDATA[%s]]></url>\n" .
			"\t\t<price>%s</price>\n" .
			"\t\t<image><![CDATA[%s]]></image>\n" .
			"\t\t<category><![CDATA[%s]]></category>\n" .
			"\t\t<description><![CDATA[%s]]></description>\n" .
			"\t</product>\n",
			esc_xml( $product->get_id() ),
			esc_xml( $parent instanceof WC_Product ? $parent->get_id() : 0 ),
			esc_xml( $product->get_sku() ),
			esc_xml( $name ),
			esc_url( $product->get_permalink() ),
			esc_xml( $product->get_price() ),
			esc_url( $image_url ),
			esc_xml( $this->get_primary_category_name( $base_product ) ),
			esc_xml( wp_trim_words( wp_strip_all_tags( $description ), 60 ) )
		);
	}

	private function get_primary_category_name( $product ) {
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}
		return $terms[0]->name;
	}

	public function render_xml_feed() {
		$xml = get_transient( self::TRANSIENT_XML );

		if ( false === $xml ) {
			$xml = $this->build_xml_document();
			set_transient( self::TRANSIENT_XML, $xml, DAY_IN_SECONDS );
		}

		header( 'Content-Type: text/xml; charset=' . get_option( 'blog_charset' ), true );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput -- 已於產生階段逐欄位跳脫。
		exit;
	}

	public function handle_manual_regeneration() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( '權限不足。', 'woo-shopcom' ) );
		}
		check_admin_referer( 'shopcom_manual_regenerate' );

		$this->run_batch_generation();

		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=shopcom&shopcom_regenerated=1' ) );
		exit;
	}

	public function get_last_run() {
		return get_option( self::OPTION_LAST_RUN, array() );
	}
}
