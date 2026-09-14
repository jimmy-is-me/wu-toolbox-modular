<?php
/**
 * WU Toolbox Modular：商品限制物流。
 *
 * 讓主商品與各個商品規格限制可用物流方式，並提供集中檢視、前台提示及
 * 購物車物流交集判斷。依使用者提供的 WC Product Shipping Restrict 整合。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 網站若已另外啟用原始外掛，沿用原有類別以避免重複註冊功能。
if ( class_exists( 'WC_Product_Shipping_Restrict' ) ) {
	return;
}

class WC_Product_Shipping_Restrict {

	private static $shipping_meta   = '_wcpsr_allowed_shipping_methods';
	private static $settings_option = 'wcpsr_settings';

	public static function init() {
		// Meta Box (主商品)
		add_action( 'add_meta_boxes', [ __CLASS__, 'register_meta_box' ] );
		add_action( 'save_post_product', [ __CLASS__, 'save_meta_box_data' ] );

		// 變體商品 (Variation)
		add_action( 'woocommerce_product_after_variable_attributes', [ __CLASS__, 'render_variation_options' ], 10, 3 );
		add_action( 'woocommerce_save_product_variation', [ __CLASS__, 'save_variation_options' ], 10, 2 );

		// 結帳物流過濾
		add_filter( 'woocommerce_package_rates', [ __CLASS__, 'filter_package_rates' ], 100, 2 );

		// 購物車內容變動時，重新判斷限制狀態
		add_action( 'woocommerce_check_cart_items', [ __CLASS__, 'reset_empty_notice_flags' ] );

		// 商品單一頁顯示限制提示
		add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'display_product_page_notice' ], 25 );

		// 購物車/結帳頁固定顯示通知
		add_action( 'woocommerce_before_cart', [ __CLASS__, 'display_cart_checkout_notice' ] );
		add_action( 'woocommerce_before_checkout_form', [ __CLASS__, 'display_cart_checkout_notice' ], 5 );

		// 後台管理面板 + 設定頁
		add_action( 'admin_menu', [ __CLASS__, 'add_admin_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
	}

	// ============================================================
	// 0. 外掛設定
	// ============================================================
	public static function get_default_settings() {
		return [
			'empty_shipping_message' => '購物車內商品的配送方式無法同時滿足，請分開結帳或聯繫客服協助。',
			'cart_notice_message'    => '購物車內有商品限定配送方式，結帳時物流選項將依商品設定顯示。',
		];
	}

	public static function get_settings() {
		$defaults = self::get_default_settings();
		$saved    = get_option( self::$settings_option, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		return wp_parse_args( $saved, $defaults );
	}

	public static function register_settings() {
		register_setting( self::$settings_option, self::$settings_option, [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
			'default'           => self::get_default_settings(),
		] );
	}

	public static function sanitize_settings( $input ) {
		$defaults = self::get_default_settings();
		$clean    = [];

		$clean['empty_shipping_message'] = isset( $input['empty_shipping_message'] ) && trim( $input['empty_shipping_message'] ) !== ''
			? sanitize_textarea_field( wp_unslash( $input['empty_shipping_message'] ) )
			: $defaults['empty_shipping_message'];

		$clean['cart_notice_message'] = isset( $input['cart_notice_message'] ) && trim( $input['cart_notice_message'] ) !== ''
			? sanitize_textarea_field( wp_unslash( $input['cart_notice_message'] ) )
			: $defaults['cart_notice_message'];

		return $clean;
	}

	// ============================================================
	// 1. 工具函式：取得所有已啟用物流方式 (加入靜態快取)
	// ============================================================
	public static function get_enabled_shipping_methods() {
		static $methods = null;
		if ( $methods !== null ) {
			return $methods;
		}

		global $wpdb;
		$methods = [];

		if ( ! class_exists( 'WC_Shipping_Zone' ) ) {
			return $methods;
		}

		$zone_ids = [];
		$rows     = $wpdb->get_results( "SELECT zone_id, zone_name FROM {$wpdb->prefix}woocommerce_shipping_zones ORDER BY zone_order ASC" );

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$zone_ids[ (int) $row->zone_id ] = $row->zone_name;
			}
		}

		$zone_ids[0] = '其餘地區';

		foreach ( $zone_ids as $zone_id => $zone_name ) {
			$zone = new WC_Shipping_Zone( (int) $zone_id );
			if ( ! $zone instanceof WC_Shipping_Zone ) {
				continue;
			}

			if ( empty( $zone_name ) ) {
				$zone_name = $zone->get_zone_name();
			}
			if ( empty( $zone_name ) ) {
				$zone_name = ( 0 === (int) $zone_id ) ? '其餘地區' : 'Zone ' . $zone_id;
			}

			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				$rate_id             = $method->id . ':' . $method->instance_id;
				$methods[ $rate_id ] = [
					'label' => $method->get_title(),
					'zone'  => $zone_name,
				];
			}
		}

		uasort( $methods, function ( $a, $b ) {
			return strnatcasecmp( $a['label'], $b['label'] );
		} );

		return $methods;
	}

	// ============================================================
	// 2. Meta Box 註冊與渲染
	// ============================================================
	public static function register_meta_box() {
		add_meta_box(
			'wcpsr_shipping_methods',
			'限制允許的物流方式',
			[ __CLASS__, 'render_meta_box' ],
			'product',
			'side',
			'default'
		);
	}

	public static function render_meta_box( $post ) {
		$selected_shipping = get_post_meta( $post->ID, self::$shipping_meta, true );
		$selected_shipping = is_array( $selected_shipping ) ? $selected_shipping : [];

		$shipping_methods = self::get_enabled_shipping_methods();

		wp_nonce_field( 'wcpsr_save_meta_box', 'wcpsr_nonce' );

		echo '<p style="font-size:12px; color:#555; margin:0 0 12px; line-height:1.6;">';
		echo '勾選後，結帳時<strong>只顯示已勾選的方式</strong>。<br>全部不勾選 = 不限制。</p>';

		if ( empty( $shipping_methods ) ) {
			echo '<p style="color:#b32d2e; font-size:12px;">目前沒有已啟用物流。</p>';
			return;
		}

		$prev_zone = '';
		echo '<div style="display:flex; flex-direction:column; gap:6px;">';
		foreach ( $shipping_methods as $rate_id => $data ) {
			$zone_label = $data['zone'];

			if ( $zone_label !== $prev_zone ) {
				if ( $prev_zone !== '' ) {
					echo '<div style="border-top:1px solid #e5e5e5; margin:4px 0 2px;"></div>';
				}
				echo '<p style="font-size:10px; font-weight:700; color:#999; margin:0 0 2px; text-transform:uppercase;">' . esc_html( $zone_label ) . '</p>';
				$prev_zone = $zone_label;
			}

			$is_checked = in_array( $rate_id, $selected_shipping, true );
			$bg         = $is_checked ? '#eef4fb' : '#f9f9f9';
			$border     = $is_checked ? '#a8c4e0' : '#e0e0e0';

			printf(
				'<label style="display:flex; align-items:center; gap:8px; font-size:12px; cursor:pointer; padding:7px 10px; background:%s; border:1px solid %s; border-radius:3px;">
				<input type="checkbox" name="wcpsr_allowed_shipping_methods[]" value="%s" %s style="margin:0;">
				<span style="flex:1; line-height:1.4;">%s</span>
				</label>',
				$bg, $border, esc_attr( $rate_id ), checked( $is_checked, true, false ), esc_html( $data['label'] )
			);
		}
		echo '</div>';
	}

	public static function save_meta_box_data( $post_id ) {
		if ( ! isset( $_POST['wcpsr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcpsr_nonce'] ) ), 'wcpsr_save_meta_box' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! empty( $_POST['wcpsr_allowed_shipping_methods'] ) && is_array( $_POST['wcpsr_allowed_shipping_methods'] ) ) {
			$clean = array_map( 'sanitize_text_field', wp_unslash( $_POST['wcpsr_allowed_shipping_methods'] ) );
			update_post_meta( $post_id, self::$shipping_meta, $clean );
		} else {
			delete_post_meta( $post_id, self::$shipping_meta );
		}
	}

	// ============================================================
	// 3. 變體商品 (Variation) 渲染與儲存
	// ============================================================
	public static function render_variation_options( $loop, $variation_data, $variation ) {
		$selected_shipping = get_post_meta( $variation->ID, self::$shipping_meta, true );
		$selected_shipping = is_array( $selected_shipping ) ? $selected_shipping : [];

		$shipping_methods = self::get_enabled_shipping_methods();

		if ( empty( $shipping_methods ) ) {
			return;
		}

		echo '<div class="wcpsr-variation-block form-row form-row-full" style="margin:12px 0; padding:12px; background:#f6f7f7; border:1px solid #c3c4c7; border-left:4px solid #2271b1;">';
		echo '<p style="font-weight:700; font-size:12px; margin:0 0 4px;">限制允許物流方式（此規格）</p>';
		echo '<p style="color:#666; font-size:11px; margin:0 0 8px;">未勾選則 fallback 到主商品設定。</p>';
		echo '<div style="display:flex; flex-direction:column; gap:6px;">';

		foreach ( $shipping_methods as $rate_id => $data ) {
			$is_checked = in_array( $rate_id, $selected_shipping, true );
			printf(
				'<label style="display:flex; align-items:center; gap:7px; font-size:12px; cursor:pointer;">
				<input type="checkbox" name="wcpsr_variation_shipping[%d][]" value="%s" %s> [%s] %s
				</label>',
				(int) $loop, esc_attr( $rate_id ), checked( $is_checked, true, false ), esc_html( $data['zone'] ), esc_html( $data['label'] )
			);
		}

		echo '</div></div>';
	}

	public static function save_variation_options( $variation_id, $loop ) {
		if ( ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}
		if ( ! empty( $_POST['wcpsr_variation_shipping'][ $loop ] ) && is_array( $_POST['wcpsr_variation_shipping'][ $loop ] ) ) {
			$clean = array_map( 'sanitize_text_field', wp_unslash( $_POST['wcpsr_variation_shipping'][ $loop ] ) );
			update_post_meta( $variation_id, self::$shipping_meta, $clean );
		} else {
			delete_post_meta( $variation_id, self::$shipping_meta );
		}
	}

	// ============================================================
	// 4. 商品單一頁顯示限制提示
	// ============================================================
	public static function display_product_page_notice() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$restriction = self::get_effective_restriction_for_product( $product );

		if ( empty( $restriction ) ) {
			return;
		}

		$all_methods = self::get_enabled_shipping_methods();
		$labels      = [];
		foreach ( $restriction as $rate_id ) {
			$labels[] = isset( $all_methods[ $rate_id ] ) ? $all_methods[ $rate_id ]['label'] : $rate_id;
		}

		echo '<div class="wcpsr-product-shipping-notice" style="margin:12px 0; padding:10px 14px; background:#eef4fb; border:1px solid #a8c4e0; border-radius:4px; font-size:13px; color:#1d2327;">';
		echo '<strong>此商品僅支援以下配送方式：</strong> ' . esc_html( implode( '、', $labels ) );
		echo '</div>';
	}

	private static function get_effective_restriction_for_product( $product ) {
		$product_id = $product->get_id();
		$methods    = get_post_meta( $product_id, self::$shipping_meta, true );

		if ( is_array( $methods ) && ! empty( $methods ) ) {
			return $methods;
		}

		if ( $product->is_type( 'variation' ) ) {
			$parent_id      = $product->get_parent_id();
			$parent_methods = get_post_meta( $parent_id, self::$shipping_meta, true );
			if ( is_array( $parent_methods ) && ! empty( $parent_methods ) ) {
				return $parent_methods;
			}
		}

		return [];
	}

	// ============================================================
	// 5. 結帳物流過濾（交集邏輯）
	// ============================================================
	private static function get_item_restriction( $item ) {
		$product_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
		$methods    = get_post_meta( $product_id, self::$shipping_meta, true );

		if ( ! empty( $item['variation_id'] ) && ( empty( $methods ) || ! is_array( $methods ) ) ) {
			$methods = get_post_meta( $item['product_id'], self::$shipping_meta, true );
		}

		return ( is_array( $methods ) && ! empty( $methods ) ) ? $methods : null;
	}

	private static function compute_allowed_intersection( $cart_items ) {
		$intersection    = null;
		$has_restriction = false;

		foreach ( $cart_items as $item ) {
			$restriction = self::get_item_restriction( $item );

			if ( $restriction === null ) {
				continue;
			}

			$has_restriction = true;

			if ( $intersection === null ) {
				$intersection = $restriction;
			} else {
				$intersection = array_intersect( $intersection, $restriction );
			}
		}

		return [
			'has_restriction' => $has_restriction,
			'allowed'         => $intersection === null ? [] : array_values( array_unique( $intersection ) ),
		];
	}

	public static function filter_package_rates( $rates, $package ) {
		if ( empty( $package['contents'] ) ) {
			return $rates;
		}

		$result = self::compute_allowed_intersection( $package['contents'] );

		if ( ! $result['has_restriction'] ) {
			return $rates;
		}

		$allowed_methods = $result['allowed'];
		$original_count  = count( $rates );

		foreach ( $rates as $rate_id => $rate ) {
			if ( ! in_array( $rate_id, $allowed_methods, true ) ) {
				unset( $rates[ $rate_id ] );
			}
		}

		if ( isset( WC()->session ) ) {
			WC()->session->set( 'wcpsr_empty_shipping', ( $original_count > 0 && empty( $rates ) ) );
			WC()->session->set( 'wcpsr_has_restriction', true );
		}

		return $rates;
	}

	public static function reset_empty_notice_flags() {
		if ( ! isset( WC()->session ) || ! isset( WC()->cart ) ) {
			return;
		}

		$result = self::compute_allowed_intersection( WC()->cart->get_cart() );
		WC()->session->set( 'wcpsr_has_restriction', $result['has_restriction'] );

		if ( ! $result['has_restriction'] ) {
			WC()->session->set( 'wcpsr_empty_shipping', false );
		}
	}

	// ============================================================
	// 6. 購物車/結帳頁固定顯示通知
	// ============================================================
	public static function display_cart_checkout_notice() {
		if ( ! isset( WC()->session ) || ! isset( WC()->cart ) ) {
			return;
		}

		if ( WC()->cart->is_empty() ) {
			return;
		}

		$result = self::compute_allowed_intersection( WC()->cart->get_cart() );

		if ( ! $result['has_restriction'] ) {
			return;
		}

		$settings = self::get_settings();
		$is_empty = empty( $result['allowed'] );

		if ( $is_empty ) {
			$text  = $settings['empty_shipping_message'];
			$class = 'woocommerce-error';
		} else {
			$text  = $settings['cart_notice_message'];
			$class = 'woocommerce-info';
		}

		echo '<div class="' . esc_attr( $class ) . ' wcpsr-cart-notice" role="alert" style="margin-bottom:16px;">' . esc_html( $text ) . '</div>';
	}

	// ============================================================
	// 7. 後台管理面板（商品狀態列表 + 設定分頁）
	// ============================================================
	public static function add_admin_menu() {
		add_submenu_page(
			'wu-toolbox-modular',
			'商品限制物流',
			'商品限制物流',
			'manage_woocommerce',
			'wu-product-shipping-restrict',
			[ __CLASS__, 'render_admin_page' ]
		);
	}

	public static function render_admin_page() {
		$current_tab  = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'all';
		$allowed_tabs = [ 'all', 'restricted', 'unrestricted', 'settings' ];
		if ( ! in_array( $current_tab, $allowed_tabs, true ) ) {
			$current_tab = 'all';
		}

		if ( $current_tab === 'settings' ) {
			self::render_settings_tab();
			return;
		}

		self::render_product_list_tab( $current_tab );
	}

	private static function render_settings_tab() {
		if ( isset( $_POST['wcpsr_settings_nonce'] ) && wp_verify_nonce( $_POST['wcpsr_settings_nonce'], 'wcpsr_save_settings' ) && current_user_can( 'manage_woocommerce' ) ) {
			$input = isset( $_POST[ self::$settings_option ] ) ? wp_unslash( $_POST[ self::$settings_option ] ) : [];
			update_option( self::$settings_option, self::sanitize_settings( $input ) );
			echo '<div class="notice notice-success is-dismissible"><p>設定已儲存。</p></div>';
		}

		$settings = self::get_settings();
		$base_url = add_query_arg( [ 'page' => 'wu-product-shipping-restrict' ], admin_url( 'admin.php' ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">商品限制物流</h1>
			<hr class="wp-header-end">
			<?php self::render_nav_tabs( 'settings' ); ?>

			<form method="post" action="<?php echo esc_url( add_query_arg( 'tab', 'settings', $base_url ) ); ?>" style="max-width:720px; margin-top:15px;">
				<?php wp_nonce_field( 'wcpsr_save_settings', 'wcpsr_settings_nonce' ); ?>

				<h2>無可用物流提示</h2>
				<p style="color:#666; font-size:13px;">物流限制衝突、無可用配送方式時顯示，紅色警示樣式。</p>
				<textarea name="<?php echo esc_attr( self::$settings_option ); ?>[empty_shipping_message]" rows="3" style="width:100%;"><?php echo esc_textarea( $settings['empty_shipping_message'] ); ?></textarea>

				<h2 style="margin-top:25px;">一般限制提醒</h2>
				<p style="color:#666; font-size:13px;">購物車內有商品設定配送限制時顯示，藍色提示樣式。</p>
				<textarea name="<?php echo esc_attr( self::$settings_option ); ?>[cart_notice_message]" rows="3" style="width:100%;"><?php echo esc_textarea( $settings['cart_notice_message'] ); ?></textarea>

				<p style="margin-top:20px;">
					<button type="submit" class="button button-primary">儲存設定</button>
				</p>
			</form>
		</div>
		<?php
	}

	private static function render_nav_tabs( $current_tab ) {
		$base_url = admin_url( 'admin.php' );
		$tabs     = [
			'all'          => '全部商品',
			'restricted'   => '已設定限制',
			'unrestricted' => '未設定限制',
			'settings'     => '訊息設定',
		];
		echo '<h2 class="nav-tab-wrapper" style="margin-bottom: 15px;">';
		foreach ( $tabs as $tab_key => $tab_label ) {
			$url    = add_query_arg( [ 'page' => 'wu-product-shipping-restrict', 'tab' => $tab_key ], $base_url );
			$active = ( $current_tab === $tab_key ) ? 'nav-tab-active' : '';
			printf( '<a href="%s" class="nav-tab %s">%s</a>', esc_url( $url ), esc_attr( $active ), esc_html( $tab_label ) );
		}
		echo '</h2>';
	}

	private static function render_product_list_tab( $current_tab ) {
		$paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;

		$all_shipping_raw = self::get_enabled_shipping_methods();
		$all_shipping     = [];
		foreach ( $all_shipping_raw as $rate_id => $data ) {
			$all_shipping[ $rate_id ] = '[' . $data['zone'] . '] ' . $data['label'];
		}

		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'paged'          => $paged,
		];

		if ( $current_tab === 'restricted' ) {
			$args['meta_query'] = [
				[ 'key' => self::$shipping_meta, 'compare' => 'EXISTS' ],
			];
		} elseif ( $current_tab === 'unrestricted' ) {
			$args['meta_query'] = [
				[ 'key' => self::$shipping_meta, 'compare' => 'NOT EXISTS' ],
			];
		}

		$query = new WP_Query( $args );

		$total_pages = $query->max_num_pages;
		$base_url    = add_query_arg( [ 'page' => 'wu-product-shipping-restrict', 'tab' => $current_tab ], admin_url( 'admin.php' ) );
		$page_links  = paginate_links( [
			'base'      => add_query_arg( 'paged', '%#%', $base_url ),
			'format'    => '',
			'prev_text' => '&laquo;',
			'next_text' => '&raquo;',
			'total'     => $total_pages,
			'current'   => $paged,
		] );
		$page_links  = $page_links ? $page_links : '';

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">商品限制物流</h1>
			<hr class="wp-header-end">

			<?php self::render_nav_tabs( $current_tab ); ?>

			<div class="tablenav top">
				<div class="tablenav-pages"><?php echo $page_links; ?></div>
			</div>

			<table class="wp-list-table widefat fixed striped table-view-list">
				<thead>
					<tr>
						<th style="width:60px;">圖片</th>
						<th style="width:25%;">商品名稱</th>
						<th>允許的物流方式</th>
						<th style="width:180px;">操作</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( $query->have_posts() ) : ?>
					<?php
					while ( $query->have_posts() ) :
						$query->the_post();
						$product = wc_get_product( get_the_ID() );
						if ( ! $product ) {
							continue;
						}

						$shipping = get_post_meta( get_the_ID(), self::$shipping_meta, true );
						$shipping = is_array( $shipping ) ? $shipping : [];

						$variation_notice = '';
						if ( $product->is_type( 'variable' ) ) {
							$variations       = $product->get_children();
							$has_var_restrict = false;
							foreach ( $variations as $var_id ) {
								if ( get_post_meta( $var_id, self::$shipping_meta, true ) ) {
									$has_var_restrict = true;
									break;
								}
							}
							if ( $has_var_restrict ) {
								$variation_notice = '<br><span style="color:#b32d2e; font-size:11px; background:#fbebea; padding:2px 6px; border-radius:3px; display:inline-block; margin-top:4px;">子變體有獨立設定</span>';
							}
						}

						$edit_link = get_edit_post_link( get_the_ID() );
						$view_link = get_permalink( get_the_ID() );
						?>
						<tr>
							<td><?php echo $product->get_image( 'thumbnail', [ 'style' => 'width:40px; height:auto; border-radius:4px;' ] ); ?></td>
							<td>
								<strong><a href="<?php echo esc_url( $edit_link ); ?>"><?php the_title(); ?></a></strong>
								<?php echo $variation_notice; ?>
							</td>
							<td>
								<?php if ( empty( $shipping ) ) : ?>
									<span style="color:#646970;">不限制 (所有物流皆可)</span>
								<?php else : ?>
									<div style="display:flex; flex-wrap:wrap; gap:4px;">
										<?php foreach ( $shipping as $m_id ) : $m_name = $all_shipping[ $m_id ] ?? $m_id; ?>
											<span style="background:#eef4fb; border:1px solid #a8c4e0; padding:3px 8px; border-radius:3px; font-size:12px; color:#1d2327;">
												<?php echo esc_html( $m_name ); ?>
											</span>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_url( $edit_link ); ?>" class="button button-small">編輯商品</a>
								<a href="<?php echo esc_url( $view_link ); ?>" class="button button-small" target="_blank" rel="noopener noreferrer">查看商品</a>
							</td>
						</tr>
					<?php endwhile; wp_reset_postdata(); ?>
				<?php else : ?>
					<tr><td colspan="4">目前沒有符合的商品。</td></tr>
				<?php endif; ?>
				</tbody>
			</table>

			<div class="tablenav bottom">
				<div class="tablenav-pages"><?php echo $page_links; ?></div>
			</div>
		</div>
		<?php
	}
}

WC_Product_Shipping_Restrict::init();
