<?php
/**
 * WordPress 內容總覽 v5
 *
 * 單一後台面板：
 * - 頁面 Tab：父子階層樹狀瀏覽
 * - 文章 Tab：依分類瀏覽
 * - 商品 Tab：WooCommerce 啟用時顯示
 *
 * 功能：
 * - 查看 / 編輯
 * - 完整標題換行
 * - RWD
 * - AJAX 載入
 * - 商品欄位設定儲存在使用者帳號
 *
 * 安裝前請停用上一版程式碼。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WUTM_Content_Overview' ) ) {

	class WUTM_Content_Overview {

		const SLUG  = 'wco-content-overview';
		const NONCE = 'wco_content_overview_v5';

		private static $hook = '';

		public static function boot() {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

			add_action(
				'wp_ajax_wco_v5_load',
				array( __CLASS__, 'ajax_load' )
			);

			add_action(
				'wp_ajax_wco_v5_columns',
				array( __CLASS__, 'ajax_columns' )
			);
		}

		private static function capability() {
			return 'manage_options';
		}

		private static function has_wc() {
			return class_exists( 'WooCommerce' )
				&& function_exists( 'wc_get_product' );
		}

		private static function statuses() {
			return array(
				'publish' => '已發佈',
				'draft'   => '草稿',
				'pending' => '待審',
				'private' => '私人',
				'future'  => '已排程',
			);
		}

		public static function menu() {
			self::$hook = add_submenu_page(
				'wu-toolbox-modular',
				'內容總覽',
				'內容總覽',
				self::capability(),
				self::SLUG,
				array( __CLASS__, 'render' )
			);
		}

		private static function guard() {
			if ( ! current_user_can( self::capability() ) ) {
				wp_send_json_error(
					array( 'message' => '你沒有操作這個面板的權限。' ),
					403
				);
			}

			if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
				wp_send_json_error(
					array(
						'message' => '驗證已過期，請重新整理頁面後再試。',
					),
					403
				);
			}
		}

		private static function input( $key, $default = '' ) {
			if (
				! isset( $_POST[ $key ] )
				|| ! is_scalar( $_POST[ $key ] )
			) {
				return $default;
			}

			return sanitize_text_field(
				wp_unslash( $_POST[ $key ] )
			);
		}

		private static function columns() {
			return array(
				array(
					'key' => 'name',
					'label' => '商品名稱',
					'default' => true,
					'sort' => true,
					'locked' => true,
				),
				array(
					'key' => 'thumb',
					'label' => '商品圖片',
					'default' => false,
					'sort' => false,
				),
				array(
					'key' => 'id',
					'label' => 'ID',
					'default' => true,
					'sort' => true,
				),
				array(
					'key' => 'sku',
					'label' => 'SKU',
					'default' => true,
					'sort' => true,
				),
				array(
					'key' => 'price',
					'label' => '價格',
					'default' => true,
					'sort' => true,
				),
				array(
					'key' => 'stock',
					'label' => '庫存狀態',
					'default' => true,
					'sort' => true,
				),
				array(
					'key' => 'qty',
					'label' => '庫存數量',
					'default' => false,
					'sort' => false,
				),
				array(
					'key' => 'category',
					'label' => '商品分類',
					'default' => true,
					'sort' => false,
				),
				array(
					'key' => 'type',
					'label' => '商品類型',
					'default' => false,
					'sort' => false,
				),
				array(
					'key' => 'status',
					'label' => '發佈狀態',
					'default' => true,
					'sort' => false,
				),
				array(
					'key' => 'date',
					'label' => '建立日期',
					'default' => false,
					'sort' => true,
				),
			);
		}

		private static function saved_columns() {
			$definitions = self::columns();
			$allowed = array_column( $definitions, 'key' );

			$saved = get_user_meta(
				get_current_user_id(),
				'wco_v5_product_columns',
				true
			);

			if ( ! is_array( $saved ) ) {
				$saved = array();

				foreach ( $definitions as $column ) {
					if ( ! empty( $column['default'] ) ) {
						$saved[] = $column['key'];
					}
				}
			}

			$saved = array_values(
				array_intersect( $allowed, $saved )
			);

			if ( ! in_array( 'name', $saved, true ) ) {
				array_unshift( $saved, 'name' );
			}

			return $saved;
		}

		public static function assets( $hook ) {
			if ( $hook !== self::$hook ) {
				return;
			}

			wp_register_style(
				'wco-v5-style',
				false,
				array(),
				WUTM_VERSION
			);

			wp_enqueue_style( 'wco-v5-style' );
			wp_add_inline_style( 'wco-v5-style', self::css() );

			wp_register_script(
				'wco-v5-script',
				false,
				array(),
				WUTM_VERSION,
				true
			);

			wp_enqueue_script( 'wco-v5-script' );

			$config = array(
				'ajax' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( self::NONCE ),
				'wc' => self::has_wc(),
				'statuses' => self::statuses(),
				'columns' => self::has_wc() ? self::columns() : array(),
				'visible' => self::has_wc() ? self::saved_columns() : array(),
			);

			wp_add_inline_script(
				'wco-v5-script',
				'window.WCO_V5 = ' . wp_json_encode(
					$config,
					JSON_HEX_TAG
					| JSON_HEX_AMP
					| JSON_HEX_APOS
					| JSON_HEX_QUOT
				) . ';',
				'before'
			);

			wp_add_inline_script(
				'wco-v5-script',
				self::js(),
				'after'
			);
		}

		public static function render() {
			if ( ! current_user_can( self::capability() ) ) {
				wp_die( '權限不足。' );
			}

			$tabs = array(
				'pages' => '頁面',
				'posts' => '文章',
			);

			if ( self::has_wc() ) {
				$tabs['products'] = '商品';
			}
			?>
			<div class="wrap wco-v5" id="wco-v5">
				<header class="wco-header">
					<div>
						<h1>內容總覽</h1>
						<p>集中瀏覽網站內容，快速查看與編輯。</p>
					</div>

					<button
						type="button"
						class="wco-button"
						id="wco-refresh"
					>重新載入</button>
				</header>

				<nav
					class="wco-tabs"
					role="tablist"
					aria-label="內容類型"
				>
					<?php foreach ( $tabs as $key => $label ) : ?>
						<button
							type="button"
							role="tab"
							id="wco-tab-<?php echo esc_attr( $key ); ?>"
							data-tab="<?php echo esc_attr( $key ); ?>"
							aria-controls="wco-panel-<?php echo esc_attr( $key ); ?>"
							aria-selected="<?php echo 'pages' === $key ? 'true' : 'false'; ?>"
							tabindex="<?php echo 'pages' === $key ? '0' : '-1'; ?>"
							class="wco-tab<?php echo 'pages' === $key ? ' is-active' : ''; ?>"
						><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</nav>

				<?php foreach ( array( 'pages', 'posts' ) as $view ) : ?>
					<section
						class="wco-panel"
						id="wco-panel-<?php echo esc_attr( $view ); ?>"
						data-view="<?php echo esc_attr( $view ); ?>"
						role="tabpanel"
						aria-labelledby="wco-tab-<?php echo esc_attr( $view ); ?>"
						<?php echo 'pages' !== $view ? 'hidden' : ''; ?>
					>
						<div
							class="wco-statistics"
							data-statistics
						></div>

						<div class="wco-surface">
							<div class="wco-toolbar">
								<label class="wco-search">
									<span class="screen-reader-text">搜尋標題</span>
									<input
										type="search"
										data-tree-search
										placeholder="<?php echo 'pages' === $view ? '搜尋頁面標題' : '搜尋文章標題'; ?>"
									>
								</label>

								<select
									data-tree-status
									aria-label="篩選發佈狀態"
								>
									<option value="">全部狀態</option>
									<?php foreach ( self::statuses() as $k => $v ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>">
											<?php echo esc_html( $v ); ?>
										</option>
									<?php endforeach; ?>
								</select>

								<div class="wco-toolbar-actions">
									<button
										type="button"
										class="wco-button"
										data-tree-expand
									>全部展開</button>

									<button
										type="button"
										class="wco-button"
										data-tree-collapse
									>全部收合</button>
								</div>

								<span
									class="wco-count"
									data-count
									aria-live="polite"
								></span>
							</div>

							<div class="wco-tree-head" aria-hidden="true">
								<span>標題</span>
								<span>ID</span>
								<span>狀態</span>
								<span class="wco-align-right">操作</span>
							</div>

							<div class="wco-message" data-message role="status"></div>
							<div class="wco-tree-body" data-tree></div>
						</div>

						<p class="wco-footnote">
							<?php
							echo 'pages' === $view
								? '依父子階層顯示。搜尋時保留符合項目的上層頁面。'
								: '依分類顯示。多分類文章只列一次，歸在分類 ID 最小的分類。';
							?>
						</p>
					</section>
				<?php endforeach; ?>

				<?php if ( self::has_wc() ) : ?>
					<section
						class="wco-panel"
						id="wco-panel-products"
						data-view="products"
						role="tabpanel"
						aria-labelledby="wco-tab-products"
						hidden
					>
						<div
							class="wco-statistics"
							data-statistics
						></div>

						<div class="wco-surface">
							<div class="wco-toolbar">
								<label class="wco-search">
									<span class="screen-reader-text">搜尋商品</span>
									<input
										type="search"
										data-product-search
										placeholder="搜尋商品名稱、SKU 或 ID"
									>
								</label>

								<select data-product-filter="category" aria-label="商品分類">
									<option value="">全部分類</option>
									<?php
									$terms = get_terms(
										array(
											'taxonomy' => 'product_cat',
											'hide_empty' => false,
											'orderby' => 'name',
										)
									);

									if ( ! is_wp_error( $terms ) ) {
										foreach ( $terms as $term ) {
											echo '<option value="' .
												(int) $term->term_id . '">' .
												esc_html( $term->name ) .
												'</option>';
										}
									}
									?>
								</select>

								<select data-product-filter="stock" aria-label="庫存狀態">
									<option value="">全部庫存</option>
									<option value="instock">有庫存</option>
									<option value="outofstock">缺貨</option>
									<option value="onbackorder">可預訂</option>
								</select>

								<select data-product-filter="status" aria-label="商品發佈狀態">
									<option value="">全部狀態</option>
									<?php foreach ( self::statuses() as $k => $v ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>">
											<?php echo esc_html( $v ); ?>
										</option>
									<?php endforeach; ?>
								</select>

								<div class="wco-column-control">
									<button
										type="button"
										class="wco-button"
										data-columns-button
										aria-expanded="false"
										aria-controls="wco-column-menu"
									>顯示欄位</button>

									<div
										class="wco-column-menu"
										id="wco-column-menu"
										hidden
									></div>
								</div>

								<span class="wco-count" data-count aria-live="polite"></span>
							</div>

							<div class="wco-message" data-message role="status"></div>

							<div class="wco-table-scroll">
								<table class="wco-product-table">
									<thead data-product-head></thead>
									<tbody data-product-body></tbody>
								</table>
							</div>

							<div class="wco-pagination" data-pagination></div>
						</div>

						<p class="wco-footnote">
							欄位設定儲存在你的帳號。變體 SKU 搜尋會顯示其主商品。
						</p>
					</section>
				<?php endif; ?>
			</div>
			<?php
		}

		/* ---------- 頁面 / 文章 ---------- */

		private static function node( $post ) {
			$is_public = in_array(
				$post->post_status,
				array( 'publish', 'private' ),
				true
			);

			$url = $is_public
				? get_permalink( $post )
				: get_preview_post_link( $post );

			return array(
				'id' => (int) $post->ID,
				'title' => $post->post_title ?: '(無標題)',
				'status' => $post->post_status,
				'view' => esc_url_raw( $url ?: get_permalink( $post ) ),
				'edit' => esc_url_raw(
					get_edit_post_link( $post->ID, 'raw' )
				),
				'children' => array(),
			);
		}

		private static function tree_data( $view ) {
			$type = 'pages' === $view ? 'page' : 'post';

			$query = new WP_Query(
				array(
					'post_type' => $type,
					'post_status' => array_keys( self::statuses() ),
					'posts_per_page' => -1,
					'no_found_rows' => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => 'posts' === $view,
					'orderby' => 'pages' === $view
						? array( 'menu_order' => 'ASC', 'title' => 'ASC' )
						: array( 'date' => 'DESC', 'ID' => 'DESC' ),
				)
			);

			$posts = $query->posts;
			$counts = array_fill_keys( array_keys( self::statuses() ), 0 );

			foreach ( $posts as $post ) {
				if ( isset( $counts[ $post->post_status ] ) ) {
					$counts[ $post->post_status ]++;
				}
			}

			if ( 'pages' === $view ) {
				$map = array();
				$children = array();
				$roots = array();

				foreach ( $posts as $post ) {
					$map[ $post->ID ] = $post;
				}

				foreach ( $posts as $post ) {
					$parent = (int) $post->post_parent;

					if (
						$parent
						&& $parent !== (int) $post->ID
						&& isset( $map[ $parent ] )
					) {
						$children[ $parent ][] = (int) $post->ID;
					} else {
						$roots[] = (int) $post->ID;
					}
				}

				$seen = array();

				$build = function ( $id ) use (
					&$build,
					&$seen,
					$map,
					$children
				) {
					if ( isset( $seen[ $id ] ) ) {
						return null;
					}

					$seen[ $id ] = true;
					$node = self::node( $map[ $id ] );

					foreach ( isset( $children[ $id ] ) ? $children[ $id ] : array() as $child ) {
						$item = $build( $child );

						if ( $item ) {
							$node['children'][] = $item;
						}
					}

					return $node;
				};

				$tree = array();

				foreach ( $roots as $id ) {
					$item = $build( $id );
					if ( $item ) {
						$tree[] = $item;
					}
				}

				// 異常父子循環或孤立頁面仍保留，不讓內容消失。
				foreach ( array_keys( $map ) as $id ) {
					if ( ! isset( $seen[ $id ] ) ) {
						$item = $build( $id );
						if ( $item ) {
							$tree[] = $item;
						}
					}
				}
			} else {
				$groups = array();

				foreach ( $posts as $post ) {
					$categories = get_the_category( $post->ID );

					usort(
						$categories,
						function ( $a, $b ) {
							return (int) $a->term_id <=> (int) $b->term_id;
						}
					);

					$category = $categories ? $categories[0] : null;
					$key = $category ? (int) $category->term_id : 0;

					if ( ! isset( $groups[ $key ] ) ) {
						$groups[ $key ] = array(
							'title' => $category ? $category->name : '未分類',
							'children' => array(),
						);
					}

					$groups[ $key ]['children'][] = self::node( $post );
				}

				uasort(
					$groups,
					function ( $a, $b ) {
						return strnatcasecmp( $a['title'], $b['title'] );
					}
				);

				$tree = array_values( $groups );
			}

			return array(
				'tree' => $tree,
				'total' => count( $posts ),
				'counts' => $counts,
			);
		}

		/* ---------- 商品 ---------- */

		private static function product_data() {
			global $wpdb;

			if ( ! self::has_wc() ) {
				wp_send_json_error(
					array( 'message' => 'WooCommerce 尚未啟用。' ),
					400
				);
			}

			$q = self::input( 'q' );
			$category = absint( self::input( 'category' ) );
			$stock = sanitize_key( self::input( 'stock' ) );
			$status = sanitize_key( self::input( 'status' ) );
			$sort = sanitize_key( self::input( 'sort', 'id' ) );
			$order = 'asc' === strtolower( self::input( 'order' ) )
				? 'ASC'
				: 'DESC';

			$page = max( 1, absint( self::input( 'page', 1 ) ) );
			$per_page = 20;

			$statuses = array_keys( self::statuses() );

			$args = array(
				'post_type' => 'product',
				'post_status' => in_array( $status, $statuses, true )
					? array( $status )
					: $statuses,
				'posts_per_page' => $per_page,
				'paged' => $page,
				'orderby' => 'ID',
				'order' => $order,
				'wco_v5_product_query' => true,
			);

			if ( 'name' === $sort ) {
				$args['orderby'] = 'title';
			} elseif ( 'date' === $sort ) {
				$args['orderby'] = 'date';
			}

			if ( $category ) {
				$args['tax_query'] = array(
					array(
						'taxonomy' => 'product_cat',
						'field' => 'term_id',
						'terms' => $category,
						'include_children' => true,
					),
				);
			}

			if ( in_array(
				$stock,
				array( 'instock', 'outofstock', 'onbackorder' ),
				true
			) ) {
				$args['meta_query'] = array(
					array(
						'key' => '_stock_status',
						'value' => $stock,
					),
				);
			}

			/*
			 * 僅作用在本次商品查詢。
			 * 名稱 / SKU / ID 使用 OR：
			 * SKU 同時支援主商品與變體商品。
			 */
			$where_filter = function ( $where, $query ) use ( $q, $wpdb ) {
				if (
					! $query->get( 'wco_v5_product_query' )
					|| '' === $q
				) {
					return $where;
				}

				$like = '%' . $wpdb->esc_like( $q ) . '%';

				$parts = array(
					$wpdb->prepare(
						"{$wpdb->posts}.post_title LIKE %s",
						$like
					),
					$wpdb->prepare(
						"EXISTS (
							SELECT 1
							FROM {$wpdb->postmeta} wco_sku
							WHERE wco_sku.post_id = {$wpdb->posts}.ID
							AND wco_sku.meta_key = '_sku'
							AND wco_sku.meta_value LIKE %s
						)",
						$like
					),
					$wpdb->prepare(
						"EXISTS (
							SELECT 1
							FROM {$wpdb->posts} wco_variation
							INNER JOIN {$wpdb->postmeta} wco_vsku
								ON wco_vsku.post_id = wco_variation.ID
							WHERE wco_variation.post_parent = {$wpdb->posts}.ID
							AND wco_variation.post_type = 'product_variation'
							AND wco_variation.post_status NOT IN ('trash', 'auto-draft')
							AND wco_vsku.meta_key = '_sku'
							AND wco_vsku.meta_value LIKE %s
						)",
						$like
					),
				);

				if ( ctype_digit( $q ) ) {
					$parts[] = $wpdb->prepare(
						"{$wpdb->posts}.ID = %d",
						(int) $q
					);

					$parts[] = $wpdb->prepare(
						"EXISTS (
							SELECT 1
							FROM {$wpdb->posts} wco_vid
							WHERE wco_vid.post_parent = {$wpdb->posts}.ID
							AND wco_vid.post_type = 'product_variation'
							AND wco_vid.post_status NOT IN ('trash', 'auto-draft')
							AND wco_vid.ID = %d
						)",
						(int) $q
					);
				}

				return $where . ' AND (' . implode( ' OR ', $parts ) . ')';
			};

			/*
			 * 使用 WooCommerce 商品 lookup table 排序，
			 * LEFT JOIN 保留未填 SKU / 價格的商品。
			 */
			$clauses_filter = function ( $clauses, $query ) use (
				$sort,
				$order,
				$wpdb
			) {
				if ( ! $query->get( 'wco_v5_product_query' ) ) {
					return $clauses;
				}

				$map = array(
					'sku' => 'sku',
					'price' => 'min_price',
					'stock' => 'stock_status',
				);

				if ( ! isset( $map[ $sort ] ) ) {
					return $clauses;
				}

				$table = $wpdb->prefix . 'wc_product_meta_lookup';

				$clauses['join'] .=
					" LEFT JOIN {$table} wco_lookup
						ON wco_lookup.product_id = {$wpdb->posts}.ID ";

				$field = $map[ $sort ];

				$clauses['orderby'] =
					"wco_lookup.{$field} {$order}, {$wpdb->posts}.ID DESC";

				return $clauses;
			};

			add_filter( 'posts_where', $where_filter, 10, 2 );
			add_filter( 'posts_clauses', $clauses_filter, 10, 2 );

			try {
				$query = new WP_Query( $args );
			} finally {
				remove_filter( 'posts_where', $where_filter, 10 );
				remove_filter( 'posts_clauses', $clauses_filter, 10 );
			}

			$rows = array();
			$stock_labels = array(
				'instock' => '有庫存',
				'outofstock' => '缺貨',
				'onbackorder' => '可預訂',
			);
			$type_labels = wc_get_product_types();
			$status_labels = self::statuses();

			foreach ( $query->posts as $post ) {
				$product = wc_get_product( $post->ID );

				if ( ! $product ) {
					continue;
				}

				$image = get_the_post_thumbnail_url(
					$post->ID,
					'thumbnail'
				);

				$category_names = wp_get_post_terms(
					$post->ID,
					'product_cat',
					array( 'fields' => 'names' )
				);

				$stock_status = $product->get_stock_status();
				$product_type = $product->get_type();
				$sku = $product->get_sku();
				$quantity = $product->get_stock_quantity();

				$stock_label = isset( $stock_labels[ $stock_status ] )
					? $stock_labels[ $stock_status ]
					: $stock_status;

				$cells = array(
					'name' => '<span class="wco-product-name">' .
						esc_html( $product->get_name() ?: '(無標題)' ) .
						'</span>',

					'thumb' => $image
						? '<img class="wco-thumbnail" src="' .
							esc_url( $image ) .
							'" alt="" loading="lazy">'
						: '<span class="wco-no-image">無圖片</span>',

					'id' => '#' . (int) $post->ID,

					'sku' => $sku
						? '<code>' . esc_html( $sku ) . '</code>'
						: '—',

					'price' => wp_kses_post(
						$product->get_price_html()
					) ?: '—',

					'stock' => '<span class="wco-badge wco-status-' .
						esc_attr( $stock_status ) . '">' .
						esc_html( $stock_label ) . '</span>',

					'qty' => $product->managing_stock()
						&& null !== $quantity
						? esc_html( wc_stock_amount( $quantity ) )
						: '—',

					'category' => ! is_wp_error( $category_names )
						&& $category_names
						? esc_html( implode( '、', $category_names ) )
						: '—',

					'type' => esc_html(
						isset( $type_labels[ $product_type ] )
							? $type_labels[ $product_type ]
							: $product_type
					),

					'status' => '<span class="wco-badge wco-status-' .
						esc_attr( $post->post_status ) . '">' .
						esc_html(
							isset( $status_labels[ $post->post_status ] )
								? $status_labels[ $post->post_status ]
								: $post->post_status
						) . '</span>',

					'date' => esc_html(
						get_the_date( 'Y-m-d', $post->ID )
					),
				);

				$node = self::node( $post );

				$rows[] = array(
					'id' => (int) $post->ID,
					'view' => $node['view'],
					'edit' => $node['edit'],
					'cells' => $cells,
				);
			}

			$count_object = wp_count_posts( 'product', 'readable' );
			$counts = array();
			$all_total = 0;

			foreach ( self::statuses() as $key => $label ) {
				$value = isset( $count_object->$key )
					? (int) $count_object->$key
					: 0;

				$counts[ $key ] = $value;
				$all_total += $value;
			}

			return array(
				'rows' => $rows,
				'total' => (int) $query->found_posts,
				'pages' => max( 1, (int) $query->max_num_pages ),
				'page' => $page,
				'all_total' => $all_total,
				'counts' => $counts,
			);
		}

		public static function ajax_load() {
			self::guard();

			$view = sanitize_key( self::input( 'view', 'pages' ) );

			if ( in_array( $view, array( 'pages', 'posts' ), true ) ) {
				wp_send_json_success( self::tree_data( $view ) );
			}

			if ( 'products' === $view ) {
				wp_send_json_success( self::product_data() );
			}

			wp_send_json_error(
				array( 'message' => '無效的內容類型。' ),
				400
			);
		}

		public static function ajax_columns() {
			self::guard();

			if ( ! self::has_wc() ) {
				wp_send_json_error(
					array( 'message' => 'WooCommerce 尚未啟用。' ),
					400
				);
			}

			$raw = isset( $_POST['columns'] )
				? wp_unslash( $_POST['columns'] )
				: array();

			$allowed = array_column( self::columns(), 'key' );
			$selected = array();

			if ( is_array( $raw ) ) {
				foreach ( $raw as $key ) {
					if ( is_string( $key ) ) {
						$key = sanitize_key( $key );

						if ( in_array( $key, $allowed, true ) ) {
							$selected[] = $key;
						}
					}
				}
			}

			$selected[] = 'name';
			$selected = array_values( array_unique( $selected ) );

			update_user_meta(
				get_current_user_id(),
				'wco_v5_product_columns',
				$selected
			);

			wp_send_json_success(
				array( 'columns' => $selected )
			);
		}

		/* ---------- 樣式 ---------- */

		private static function css() {
			return <<<'CSS'

/* 獨立命名，避免 .card / .row 等通用 class 被其他外掛干擾。 */
#wco-v5 {
	--wco-text: #182230;
	--wco-muted: #667085;
	--wco-line: #e3e7ee;
	--wco-primary: #2454d6;
	--wco-soft: #eef3ff;

	display: block;
	width: auto !important;
	max-width: none !important;
	margin: 24px 20px 30px 0;
	padding: 0;
	color: var(--wco-text);
	font-size: 14px;
	line-height: 1.6;
	container-type: inline-size;
	container-name: wco;
}

#wco-v5 *,
#wco-v5 *::before,
#wco-v5 *::after {
	box-sizing: border-box;
}

#wco-v5 [hidden] {
	display: none !important;
}

#wco-v5 .wco-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	margin: 0 0 24px;
    padding: 22px 24px;
    border-radius: 10px;
    background: #1d2327;
}

#wco-v5 .wco-header h1 {
	margin: 0;
	padding: 0;
	color: #fff !important;
	font-size: 25px;
	font-weight: 700;
	line-height: 1.4;
}

#wco-v5 .wco-header p {
	margin: 5px 0 0;
	color: #c3c4c7;
	font-size: 14px;
}

#wco-v5 .wco-tabs {
	display: flex;
	align-items: stretch;
	gap: 6px;
	padding: 0;
	margin: 0 0 20px;
	border-bottom: 1px solid var(--wco-line);
}

#wco-v5 .wco-tab {
	appearance: none;
	position: relative;
	padding: 12px 24px;
	border: 0;
	border-radius: 6px 6px 0 0;
	background: transparent;
	color: var(--wco-muted);
	font: inherit;
	font-weight: 600;
	cursor: pointer;
}

#wco-v5 .wco-tab:hover {
	background: #e9edf4;
	color: var(--wco-text);
}

#wco-v5 .wco-tab.is-active {
	background: #fff;
	color: var(--wco-primary);
}

#wco-v5 .wco-tab.is-active::after {
	position: absolute;
	right: 16px;
	bottom: -1px;
	left: 16px;
	height: 3px;
	border-radius: 3px;
	background: var(--wco-primary);
	content: "";
}

#wco-v5 .wco-tab:focus-visible,
#wco-v5 .wco-button:focus-visible,
#wco-v5 .wco-tree-toggle:focus-visible {
	outline: 2px solid var(--wco-primary);
	outline-offset: 3px;
}

#wco-v5 .wco-panel {
	display: block;
	float: none;
	width: 100% !important;
	max-width: none !important;
	margin: 0;
	padding: 0;
	border: 0;
	background: none;
}

#wco-v5 .wco-statistics {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	margin: 0 0 18px;
}

#wco-v5 .wco-stat {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 9px 16px;
	border: 1px solid var(--wco-line);
	border-radius: 8px;
	background: #fff;
	color: var(--wco-muted);
	font-size: 13px;
}

#wco-v5 .wco-stat strong {
	color: var(--wco-text);
	font-size: 16px;
	font-weight: 700;
}

#wco-v5 .wco-surface {
	display: block;
	width: 100% !important;
	max-width: none !important;
	padding: 0;
	border: 1px solid var(--wco-line);
	border-radius: 10px;
	background: #fff;
	box-shadow: 0 2px 4px rgba(16, 24, 40, .025);
}

#wco-v5 .wco-toolbar {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 10px;
	width: 100%;
	padding: 18px;
	border-bottom: 1px solid var(--wco-line);
}

#wco-v5 .wco-search {
	display: block;
	flex: 1 1 300px;
	min-width: 180px;
	margin: 0;
}

#wco-v5 .wco-search input {
	width: 100%;
	margin: 0;
}

#wco-v5 input[type="search"],
#wco-v5 select {
	min-height: 39px;
	height: 39px;
	padding: 0 12px;
	border: 1px solid #d9dfe9;
	border-radius: 6px;
	background-color: #fff;
	color: var(--wco-text);
	font: inherit;
	box-shadow: none;
}

#wco-v5 select {
	max-width: 220px;
	padding-right: 28px;
}

#wco-v5 input[type="search"]:focus,
#wco-v5 select:focus {
	border-color: var(--wco-primary);
	outline: none;
	box-shadow: 0 0 0 3px var(--wco-soft);
}

#wco-v5 .wco-button {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-height: 39px;
	margin: 0;
	padding: 8px 14px;
	border: 1px solid #d9dfe9;
	border-radius: 6px;
	background: #fff;
	color: #344054;
	font: inherit;
	font-size: 13px;
	font-weight: 600;
	line-height: 1.4;
	text-decoration: none;
	white-space: nowrap;
	cursor: pointer;
	box-shadow: none;
}

#wco-v5 .wco-button:hover:not(:disabled) {
	border-color: #b5c4e7;
	background: #f7f9fe;
	color: var(--wco-primary);
}

#wco-v5 .wco-button:disabled {
	opacity: .45;
	cursor: default;
}

#wco-v5 .wco-toolbar-actions {
	display: flex;
	gap: 8px;
}

#wco-v5 .wco-count {
	margin-left: auto;
	color: var(--wco-muted);
	font-size: 13px;
	white-space: nowrap;
}

#wco-v5 .wco-tree-head,
#wco-v5 .wco-tree-row {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 76px 108px 142px;
	align-items: center;
	gap: 14px;
}

#wco-v5 .wco-tree-head {
	padding: 12px 18px;
	border-bottom: 1px solid var(--wco-line);
	background: #f8fafc;
	color: var(--wco-muted);
	font-size: 12px;
	font-weight: 600;
}

#wco-v5 .wco-tree-head > span:first-child {
	padding-left: 52px;
}

#wco-v5 .wco-tree-list {
	margin: 0;
	padding: 0;
	list-style: none;
}

#wco-v5 .wco-tree-list li {
	margin: 0;
	padding: 0;
}

#wco-v5 .wco-tree-row {
	min-height: 65px;
	padding: 12px 18px;
	border-bottom: 1px solid #eef1f5;
}

#wco-v5 .wco-tree-row:hover {
	background: #fafbff;
}

#wco-v5 .wco-tree-category > .wco-tree-row {
	background: #f7f9fc;
}

#wco-v5 .wco-tree-title-cell {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	min-width: 0;
	padding-left: calc(var(--wco-depth, 0) * 18px);
}

#wco-v5 .wco-tree-toggle,
#wco-v5 .wco-tree-spacer {
	flex: 0 0 42px;
	width: 42px;
	min-height: 27px;
}

#wco-v5 .wco-tree-toggle {
	padding: 2px 0;
	border: 1px solid #e0e6ef;
	border-radius: 5px;
	background: #fff;
	color: #667085;
	font-size: 11px;
	line-height: 21px;
	cursor: pointer;
}

#wco-v5 .wco-tree-title {
	display: block;
	flex: 1;
	min-width: 0;
	margin: 1px 0 0;
	padding: 0;
	color: var(--wco-text);
	font-size: 14px;
	font-weight: 600;
	line-height: 1.75;

	/* 不截斷完整標題。 */
	white-space: normal !important;
	overflow: visible !important;
	text-overflow: clip !important;
	overflow-wrap: anywhere;
	word-break: normal;

	text-decoration: none;
}

#wco-v5 a.wco-tree-title:hover {
	color: var(--wco-primary);
}

#wco-v5 .wco-tree-id {
	color: #8a96a8;
	font-size: 12px;
}

#wco-v5 .wco-badge {
	display: inline-block;
	max-width: 100%;
	padding: 3px 10px;
	border-radius: 5px;
	background: #eef1f5;
	color: #475467;
	font-size: 12px;
	font-weight: 600;
	line-height: 1.6;
	white-space: nowrap;
}

#wco-v5 .wco-status-publish,
#wco-v5 .wco-status-instock {
	background: #eaf7ef;
	color: #087443;
}

#wco-v5 .wco-status-draft,
#wco-v5 .wco-status-pending,
#wco-v5 .wco-status-onbackorder {
	background: #fff4dc;
	color: #8b5c0c;
}

#wco-v5 .wco-status-private {
	background: #f0eafa;
	color: #7041a5;
}

#wco-v5 .wco-status-future {
	background: #eaf0ff;
	color: #2454d6;
}

#wco-v5 .wco-status-outofstock {
	background: #fdecea;
	color: #b42318;
}

#wco-v5 .wco-row-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

#wco-v5 .wco-row-actions .wco-button {
	min-height: 33px;
	padding: 6px 12px;
	font-size: 12px;
}

#wco-v5 .wco-edit {
	border-color: #ccd9fb;
	background: var(--wco-soft);
	color: var(--wco-primary);
}

#wco-v5 .wco-align-right {
	text-align: right;
}

#wco-v5 .wco-message {
	display: none;
	padding: 24px 18px;
	color: var(--wco-muted);
	text-align: center;
}

#wco-v5 .wco-message.is-visible {
	display: block;
}

#wco-v5 .wco-message.is-error {
	color: #b42318;
}

#wco-v5 .wco-empty {
	padding: 38px 18px;
	color: var(--wco-muted);
	text-align: center;
}

#wco-v5 .wco-footnote {
	margin: 12px 0 0;
	color: #8590a1;
	font-size: 12px;
}

#wco-v5 .wco-column-control {
	position: relative;
}

#wco-v5 .wco-column-menu {
	position: absolute;
	top: calc(100% + 8px);
	right: 0;
	z-index: 100;
	width: 220px;
	padding: 10px;
	border: 1px solid var(--wco-line);
	border-radius: 9px;
	background: #fff;
	box-shadow: 0 15px 35px rgba(16, 24, 40, .14);
}

#wco-v5 .wco-column-menu h3 {
	margin: 4px 8px 8px;
	color: var(--wco-muted);
	font-size: 12px;
}

#wco-v5 .wco-column-menu label {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 7px 8px;
	border-radius: 5px;
	cursor: pointer;
}

#wco-v5 .wco-column-menu label:hover {
	background: #f4f6fa;
}

#wco-v5 .wco-column-menu input {
	margin: 0;
}

#wco-v5 .wco-column-menu .wco-button {
	width: 100%;
	margin-top: 8px;
}

#wco-v5 .wco-save-status {
	margin: 8px 5px 0;
	color: var(--wco-muted);
	font-size: 11px;
	text-align: center;
}

#wco-v5 .wco-table-scroll {
	width: 100%;
	overflow-x: auto;
}

#wco-v5 .wco-product-table {
	width: 100%;
	min-width: 720px;
	margin: 0;
	border: 0;
	border-collapse: collapse;
	table-layout: auto;
	background: #fff;
}

#wco-v5 .wco-product-table th,
#wco-v5 .wco-product-table td {
	padding: 14px 16px;
	border: 0;
	border-bottom: 1px solid #eef1f5;
	color: var(--wco-text);
	font-size: 13px;
	text-align: left;
	vertical-align: middle;
	overflow-wrap: anywhere;
}

#wco-v5 .wco-product-table th {
	border-bottom-color: var(--wco-line);
	background: #f8fafc;
	color: var(--wco-muted);
	font-size: 12px;
	white-space: nowrap;
}

#wco-v5 .wco-product-table td[data-key="name"] {
	min-width: 220px;
}

#wco-v5 .wco-product-table th button {
	padding: 0;
	border: 0;
	background: none;
	color: inherit;
	font: inherit;
	font-weight: 600;
	cursor: pointer;
}

#wco-v5 .wco-product-table tbody tr:hover {
	background: #fafbff;
}

#wco-v5 .wco-product-name {
	font-weight: 600;
	white-space: normal;
	overflow-wrap: anywhere;
}

#wco-v5 .wco-product-table code {
	padding: 3px 6px;
	border-radius: 4px;
	background: #f1f4f8;
	font-size: 12px;
}

#wco-v5 .wco-thumbnail {
	display: block;
	width: 44px;
	height: 44px;
	border: 1px solid var(--wco-line);
	border-radius: 6px;
	object-fit: cover;
}

#wco-v5 .wco-no-image {
	color: #98a2b3;
	font-size: 11px;
}

#wco-v5 .wco-product-table del {
	color: #98a2b3;
}

#wco-v5 .wco-product-table ins {
	background: none;
	color: #b42318;
	font-weight: 600;
	text-decoration: none;
}

#wco-v5 .wco-pagination {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 12px;
	padding: 14px 18px;
	color: var(--wco-muted);
	font-size: 13px;
}

#wco-v5 .wco-pagination-actions {
	display: flex;
	gap: 8px;
}

/* 依面板自身寬度切換，不只看螢幕寬度。 */
@container wco (max-width: 760px) {
	#wco-v5 .wco-tree-head {
		display: none;
	}

	#wco-v5 .wco-tree-row {
		grid-template-columns: minmax(0, 1fr) auto;
		gap: 10px;
	}

	#wco-v5 .wco-tree-title-cell {
		grid-column: 1 / -1;
		padding-left: calc(var(--wco-depth, 0) * 10px);
	}

	#wco-v5 .wco-tree-id {
		grid-column: 1;
		grid-row: 2;
		margin-left: calc(52px + var(--wco-depth, 0) * 10px);
	}

	#wco-v5 .wco-tree-status {
		grid-column: 1;
		grid-row: 3;
		margin-left: calc(52px + var(--wco-depth, 0) * 10px);
	}

	#wco-v5 .wco-tree-row > .wco-row-actions {
		grid-column: 2;
		grid-row: 2 / 4;
	}

	#wco-v5 .wco-tree-category .wco-tree-status {
		grid-row: 2;
	}

	#wco-v5 .wco-toolbar {
		padding: 14px;
	}

	#wco-v5 .wco-count {
		width: 100%;
		margin-left: 0;
	}

	#wco-v5 .wco-table-scroll {
		overflow: visible;
	}

	#wco-v5 .wco-product-table,
	#wco-v5 .wco-product-table tbody,
	#wco-v5 .wco-product-table tr,
	#wco-v5 .wco-product-table td {
		display: block;
		width: 100%;
		min-width: 0;
	}

	#wco-v5 .wco-product-table thead {
		display: none;
	}

	#wco-v5 .wco-product-table tbody tr {
		padding: 16px;
		border-bottom: 1px solid var(--wco-line);
	}

	#wco-v5 .wco-product-table td {
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: 16px;
		padding: 6px 0;
		border: 0;
		text-align: right;
	}

	#wco-v5 .wco-product-table td::before {
		flex: 0 0 85px;
		content: attr(data-label);
		color: var(--wco-muted);
		font-size: 12px;
		text-align: left;
	}

	#wco-v5 .wco-product-table td[data-key="name"] {
		display: block;
		margin-bottom: 8px;
		font-size: 15px;
		text-align: left;
	}

	#wco-v5 .wco-product-table td[data-key="name"]::before,
	#wco-v5 .wco-product-table td[data-key="actions"]::before {
		display: none;
	}

	#wco-v5 .wco-product-table td[data-key="actions"] {
		justify-content: flex-end;
		margin-top: 9px;
		padding-top: 12px;
		border-top: 1px solid #eef1f5;
	}
}

/* 不支援 container queries 時的備援。 */
@supports not (container-type: inline-size) {
	@media (max-width: 1000px) {
		#wco-v5 .wco-tree-head {
			display: none;
		}

		#wco-v5 .wco-tree-row {
			grid-template-columns: minmax(0, 1fr) auto;
		}

		#wco-v5 .wco-tree-title-cell {
			grid-column: 1 / -1;
		}

		#wco-v5 .wco-tree-id {
			display: none;
		}

		#wco-v5 .wco-tree-status {
			grid-column: 1;
		}

		#wco-v5 .wco-tree-row > .wco-row-actions {
			grid-column: 2;
		}
	}
}

@media (max-width: 600px) {
	#wco-v5 {
		margin: 18px 10px 25px 0;
	}

	#wco-v5 .wco-header {
		align-items: flex-start;
        flex-wrap: wrap;
	}

	#wco-v5 .wco-header h1 {
		font-size: 22px;
	}

	#wco-v5 .wco-header p {
		font-size: 12px;
	}

	#wco-v5 .wco-tab {
		flex: 1;
		padding: 12px;
	}

	#wco-v5 .wco-search {
		flex-basis: 100%;
		min-width: 0;
	}

	#wco-v5 .wco-toolbar > select {
		flex: 1 1 140px;
		max-width: none;
		min-width: 0;
	}

	#wco-v5 .wco-column-control {
		flex: 1 1 140px;
	}

	#wco-v5 .wco-column-control > .wco-button {
		width: 100%;
	}

	#wco-v5 .wco-column-menu {
		right: 0;
		width: min(220px, 75vw);
	}

	#wco-v5 .wco-tree-title-cell {
		padding-left: calc(var(--wco-depth, 0) * 8px);
	}

	#wco-v5 .wco-stat {
		padding: 7px 12px;
	}

	#wco-v5 .wco-product-table,
	#wco-v5 .wco-product-table tbody,
	#wco-v5 .wco-product-table tr,
	#wco-v5 .wco-product-table td {
		display: block;
		width: 100%;
		min-width: 0;
	}

	#wco-v5 .wco-product-table thead {
		display: none;
	}

	#wco-v5 .wco-product-table tr {
		padding: 16px;
		border-bottom: 1px solid var(--wco-line);
	}

	#wco-v5 .wco-product-table td {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
		gap: 12px;
		padding: 6px 0;
		border: 0;
		text-align: right;
	}

	#wco-v5 .wco-product-table td::before {
		flex: 0 0 85px;
		content: attr(data-label);
		color: var(--wco-muted);
		font-size: 12px;
		text-align: left;
	}

	#wco-v5 .wco-product-table td[data-key="name"] {
		display: block;
		margin-bottom: 8px;
		font-size: 15px;
		text-align: left;
	}

	#wco-v5 .wco-product-table td[data-key="name"]::before,
	#wco-v5 .wco-product-table td[data-key="actions"]::before {
		display: none;
	}

	#wco-v5 .wco-product-table td[data-key="actions"] {
		justify-content: flex-end;
		margin-top: 9px;
		padding-top: 12px;
		border-top: 1px solid #eef1f5;
	}

	#wco-v5 .wco-table-scroll {
		overflow: visible;
	}
}

CSS;
		}

		/* ---------- JavaScript ---------- */

		private static function js() {
			return <<<'JS'

(function () {
	'use strict';

	function init() {
		var app = document.getElementById('wco-v5');
		var config = window.WCO_V5;

		if (!app || !config) {
			return;
		}

		function esc(value) {
			return String(value == null ? '' : value).replace(
				/[&<>"']/g,
				function (char) {
					return {
						'&': '&amp;',
						'<': '&lt;',
						'>': '&gt;',
						'"': '&quot;',
						"'": '&#039;'
					}[char];
				}
			);
		}

		function api(action, values) {
			var form = new FormData();

			form.append('action', action);
			form.append('nonce', config.nonce);

			Object.keys(values || {}).forEach(function (key) {
				var value = values[key];

				if (Array.isArray(value)) {
					value.forEach(function (item) {
						form.append(key + '[]', item);
					});
				} else {
					form.append(key, value);
				}
			});

			return fetch(config.ajax, {
				method: 'POST',
				credentials: 'same-origin',
				body: form
			})
			.then(function (response) {
				return response.text().then(function (text) {
                    var result;
                    try { result = JSON.parse(text); }
                    catch (error) {
                        throw new Error('伺服器未回傳有效資料（HTTP ' + response.status + '），請檢查登入狀態或稍後重試。');
                    }
                    if (!response.ok) {
                        throw new Error(result && result.data && result.data.message ? result.data.message : '請求失敗（HTTP ' + response.status + '）。');
                    }
                    return result;
                });
			})
			.then(function (result) {
				if (!result || !result.success) {
					throw new Error(
						result &&
						result.data &&
						result.data.message
							? result.data.message
							: '載入失敗，請重新整理後再試。'
					);
				}

				return result.data;
			});
		}

		var active = 'pages';
		var panels = {};
		var treeState = {
			pages: {
				data: null,
				token: 0
			},
			posts: {
				data: null,
				token: 0
			}
		};

		app.querySelectorAll('[data-view]').forEach(function (panel) {
			panels[panel.dataset.view] = panel;
		});

		function message(panel, text, error) {
			var element = panel.querySelector('[data-message]');

			element.textContent = text || '';
			element.classList.toggle('is-visible', Boolean(text));
			element.classList.toggle('is-error', Boolean(error));
		}

		function statistics(panel, total, counts) {
			var html =
				'<div class="wco-stat">' +
				'<span>全部</span><strong>' +
				esc(total) +
				'</strong></div>';

			Object.keys(config.statuses).forEach(function (key) {
				if (counts && counts[key]) {
					html +=
						'<div class="wco-stat"><span>' +
						esc(config.statuses[key]) +
						'</span><strong>' +
						esc(counts[key]) +
						'</strong></div>';
				}
			});

			panel.querySelector('[data-statistics]').innerHTML = html;
		}

		function actionLinks(item) {
			var html = '<div class="wco-row-actions">';

			if (item.view) {
				html +=
					'<a class="wco-button" href="' +
					esc(item.view) +
					'" target="_blank" rel="noopener">查看</a>';
			}

			if (item.edit) {
				html +=
					'<a class="wco-button wco-edit" href="' +
					esc(item.edit) +
					'">編輯</a>';
			}

			return html + '</div>';
		}

		/* ===== 頁面 / 文章樹狀 ===== */

		function drawTree(view) {
			var panel = panels[view];
			var data = treeState[view].data;

			if (!data) {
				return;
			}

			var query = panel
				.querySelector('[data-tree-search]')
				.value.trim()
				.toLowerCase();

			var status = panel
				.querySelector('[data-tree-status]')
				.value;

			var matched = 0;

			function node(item, depth) {
				var children = (item.children || [])
					.map(function (child) {
						return node(child, depth + 1);
					})
					.join('');

				var hit =
					(!query ||
						item.title.toLowerCase().indexOf(query) !== -1) &&
					(!status || item.status === status);

				if (hit) {
					matched++;
				}

				if (!hit && !children) {
					return '';
				}

				var toggle = children
					? '<button type="button" class="wco-tree-toggle" ' +
						'data-toggle-tree aria-expanded="true">收合</button>'
					: '<span class="wco-tree-spacer" aria-hidden="true"></span>';

				var title = item.view
					? '<a class="wco-tree-title" href="' +
						esc(item.view) +
						'" target="_blank" rel="noopener">' +
						esc(item.title) +
						'</a>'
					: '<span class="wco-tree-title">' +
						esc(item.title) +
						'</span>';

				return (
					'<li class="wco-tree-item" ' +
					'style="--wco-depth:' + Math.min(depth, 5) + '">' +
					'<div class="wco-tree-row">' +
					'<div class="wco-tree-title-cell">' +
					toggle + title +
					'</div>' +
					'<span class="wco-tree-id">#' + esc(item.id) + '</span>' +
					'<div class="wco-tree-status">' +
					'<span class="wco-badge wco-status-' +
					esc(item.status) +
					'">' +
					esc(config.statuses[item.status] || item.status) +
					'</span></div>' +
					actionLinks(item) +
					'</div>' +
					(children
						? '<ul class="wco-tree-list">' + children + '</ul>'
						: '') +
					'</li>'
				);
			}

			var html = '';

			if (view === 'pages') {
				html = data.tree.map(function (item) {
					return node(item, 0);
				}).join('');
			} else {
				data.tree.forEach(function (group) {
					var before = matched;

					var items = group.children.map(function (item) {
						return node(item, 1);
					}).join('');

					var count = matched - before;

					if (!items) {
						return;
					}

					html +=
						'<li class="wco-tree-item wco-tree-category">' +
						'<div class="wco-tree-row">' +
						'<div class="wco-tree-title-cell">' +
						'<button type="button" class="wco-tree-toggle" ' +
						'data-toggle-tree aria-expanded="true">收合</button>' +
						'<span class="wco-tree-title">' +
						esc(group.title) +
						'</span></div>' +
						'<span class="wco-tree-id"></span>' +
						'<div class="wco-tree-status">' +
						'<span class="wco-badge">' +
						count + ' 篇</span></div>' +
						'<div></div></div>' +
						'<ul class="wco-tree-list">' +
						items +
						'</ul></li>';
				});
			}

			panel.querySelector('[data-tree]').innerHTML = html
				? '<ul class="wco-tree-list">' + html + '</ul>'
				: '<div class="wco-empty">沒有符合條件的項目。</div>';

			panel.querySelector('[data-count]').textContent =
				query || status
					? '符合 ' + matched + ' 筆'
					: '共 ' + data.total + ' 筆';
		}

		function loadTree(view) {
			var panel = panels[view];
			var state = treeState[view];
            // Fast repeated tab switches reuse the in-flight read.
            if (state.pending) return state.pending;
			var token = ++state.token;

			message(panel, '正在載入內容…', false);

			state.pending = api('wco_v5_load', {
				view: view
			})
			.then(function (data) {
				if (token !== state.token) {
					return;
				}

				state.data = data;

				message(panel, '', false);
				statistics(panel, data.total, data.counts);
				drawTree(view);
			})
			.catch(function (error) {
				if (token === state.token) {
					message(panel, error.message, true);
				}
			}).finally(function () { state.pending = null; });
            return state.pending;
		}

		['pages', 'posts'].forEach(function (view) {
			var panel = panels[view];

			panel.querySelector('[data-tree-search]')
				.addEventListener('input', function () {
					drawTree(view);
				});

			panel.querySelector('[data-tree-status]')
				.addEventListener('change', function () {
					drawTree(view);
				});

			panel.addEventListener('click', function (event) {
				var toggle = event.target.closest('[data-toggle-tree]');

				if (toggle) {
					var item = toggle.closest('.wco-tree-item');
					var list = Array.from(item.children).find(function (child) {
						return child.tagName === 'UL';
					});

					if (list) {
						list.hidden = !list.hidden;

						toggle.setAttribute(
							'aria-expanded',
							String(!list.hidden)
						);

						toggle.textContent = list.hidden ? '展開' : '收合';
					}
				}

				var expand = event.target.closest('[data-tree-expand]');
				var collapse = event.target.closest('[data-tree-collapse]');

				if (expand || collapse) {
					panel.querySelectorAll('[data-toggle-tree]')
						.forEach(function (button) {
							var item = button.closest('.wco-tree-item');
							var list = Array.from(item.children).find(function (child) {
								return child.tagName === 'UL';
							});

							if (list) {
								list.hidden = Boolean(collapse);
								button.textContent = collapse ? '展開' : '收合';

								button.setAttribute(
									'aria-expanded',
									String(!collapse)
								);
							}
						});
				}
			});
		});

		/* ===== 商品 ===== */

		var productPanel = panels.products;

		var productState = {
			q: '',
			category: '',
			stock: '',
			status: '',
			sort: 'id',
			order: 'desc',
			page: 1,
			rows: [],
			loaded: false,
			token: 0
		};

		var visible = config.visible.slice();
		var saveQueue = Promise.resolve();
		var searchTimer = null;

		function selectedColumns() {
			return config.columns.filter(function (column) {
				return visible.indexOf(column.key) !== -1;
			});
		}

		function drawProducts() {
			if (!productPanel) {
				return;
			}

			var columns = selectedColumns();

			var head = columns.map(function (column) {
				var label = esc(column.label);

				if (column.sort) {
					var arrow = productState.sort === column.key
						? (productState.order === 'asc' ? ' ↑' : ' ↓')
						: '';

					label =
						'<button type="button" data-sort="' +
						esc(column.key) +
						'">' +
						label +
						arrow +
						'</button>';
				}

				return '<th scope="col">' + label + '</th>';
			}).join('');

			productPanel.querySelector('[data-product-head]').innerHTML =
				'<tr>' + head +
				'<th scope="col" class="wco-align-right">操作</th></tr>';

			var rows = productState.rows.map(function (item) {
				var cells = columns.map(function (column) {
					return (
						'<td data-key="' + esc(column.key) +
						'" data-label="' + esc(column.label) + '">' +
						(item.cells[column.key] || '—') +
						'</td>'
					);
				}).join('');

				return (
					'<tr>' + cells +
					'<td data-key="actions" data-label="操作">' +
					actionLinks(item) +
					'</td></tr>'
				);
			}).join('');

			productPanel.querySelector('[data-product-body]').innerHTML = rows;
		}

		function loadProducts() {
			if (!productPanel) {
				return;
			}

			var token = ++productState.token;

			message(productPanel, '正在查詢商品…', false);

			return api('wco_v5_load', {
				view: 'products',
				q: productState.q,
				category: productState.category,
				stock: productState.stock,
				status: productState.status,
				sort: productState.sort,
				order: productState.order,
				page: productState.page
			})
			.then(function (data) {
				if (token !== productState.token) {
					return;
				}

				productState.rows = data.rows;
				productState.loaded = true;

				drawProducts();
				statistics(productPanel, data.all_total, data.counts);

				productPanel.querySelector('[data-count]').textContent =
					'共 ' + data.total + ' 件';

				message(
					productPanel,
					data.rows.length ? '' : '沒有符合條件的商品。',
					false
				);

				productPanel.querySelector('[data-pagination]').innerHTML =
					'<span>第 ' + data.page +
					' / ' + data.pages + ' 頁</span>' +
					'<div class="wco-pagination-actions">' +
					'<button type="button" class="wco-button" data-page="-1" ' +
					(data.page <= 1 ? 'disabled' : '') +
					'>上一頁</button>' +
					'<button type="button" class="wco-button" data-page="1" ' +
					(data.page >= data.pages ? 'disabled' : '') +
					'>下一頁</button></div>';
			})
			.catch(function (error) {
				if (token === productState.token) {
					message(productPanel, error.message, true);
				}
			});
		}

		function closeColumnMenu() {
			if (!productPanel) {
				return;
			}

			productPanel.querySelector('#wco-column-menu').hidden = true;

			productPanel.querySelector('[data-columns-button]')
				.setAttribute('aria-expanded', 'false');
		}

		function columnMenu() {
			var menu = productPanel.querySelector('#wco-column-menu');

			menu.innerHTML =
				'<h3>選擇要顯示的欄位</h3>' +
				config.columns.map(function (column) {
					return (
						'<label><input type="checkbox" data-column="' +
						esc(column.key) + '" ' +
						(visible.indexOf(column.key) !== -1 ? 'checked ' : '') +
						(column.locked ? 'disabled ' : '') +
						'>' + esc(column.label) +
						'</label>'
					);
				}).join('') +
				'<button type="button" class="wco-button" data-reset-columns>' +
				'恢復預設</button>' +
				'<p class="wco-save-status" data-save-status>' +
				'設定會儲存在你的帳號</p>';
		}

		function saveColumns() {
			var snapshot = visible.slice();
			var status = productPanel.querySelector('[data-save-status]');

			status.textContent = '儲存中…';

			// 排隊儲存，避免快速連續勾選時舊請求覆蓋新設定。
			saveQueue = saveQueue.catch(function () {})
				.then(function () {
					return api('wco_v5_columns', {
						columns: snapshot
					});
				})
				.then(function () {
					status.textContent = '設定已儲存';
				})
				.catch(function (error) {
					status.textContent = error.message;
				});
		}

		if (productPanel) {
			columnMenu();
			drawProducts();

			productPanel.querySelector('[data-product-search]')
				.addEventListener('input', function (event) {
					productState.q = event.target.value.trim();
					productState.page = 1;

					clearTimeout(searchTimer);

					searchTimer = setTimeout(function () {
						loadProducts();
					}, 300);
				});

			productPanel.querySelectorAll('[data-product-filter]')
				.forEach(function (select) {
					select.addEventListener('change', function () {
						productState[select.dataset.productFilter] = select.value;
						productState.page = 1;
						loadProducts();
					});
				});

			productPanel.querySelector('[data-columns-button]')
				.addEventListener('click', function () {
					var menu = productPanel.querySelector('#wco-column-menu');

					menu.hidden = !menu.hidden;

					this.setAttribute(
						'aria-expanded',
						String(!menu.hidden)
					);
				});

			productPanel.querySelector('#wco-column-menu')
				.addEventListener('change', function (event) {
					var key = event.target.dataset.column;

					if (!key) {
						return;
					}

					if (event.target.checked) {
						if (visible.indexOf(key) === -1) {
							visible.push(key);
						}
					} else {
						visible = visible.filter(function (item) {
							return item !== key;
						});
					}

					if (visible.indexOf('name') === -1) {
						visible.unshift('name');
					}

					drawProducts();
					saveColumns();
				});

			productPanel.addEventListener('click', function (event) {
				var reset = event.target.closest('[data-reset-columns]');
				var sort = event.target.closest('[data-sort]');
				var page = event.target.closest('[data-page]');

				if (reset) {
					visible = config.columns.filter(function (column) {
						return column.default || column.locked;
					}).map(function (column) {
						return column.key;
					});

					columnMenu();
					drawProducts();
					saveColumns();
				}

				if (sort) {
					var key = sort.dataset.sort;

					if (productState.sort === key) {
						productState.order =
							productState.order === 'asc' ? 'desc' : 'asc';
					} else {
						productState.sort = key;
						productState.order = 'asc';
					}

					productState.page = 1;
					loadProducts();
				}

				if (page && !page.disabled) {
					productState.page += Number(page.dataset.page);
					loadProducts();
				}
			});

			document.addEventListener('click', function (event) {
				if (!event.target.closest('.wco-column-control')) {
					closeColumnMenu();
				}
			});

			document.addEventListener('keydown', function (event) {
				if (event.key === 'Escape') {
					closeColumnMenu();
				}
			});
		}

		/* ===== Tab 切換 ===== */

		var tabs = Array.from(app.querySelectorAll('[data-tab]'));

		function activate(view, focus) {
			if (!panels[view]) {
				return;
			}

			active = view;
			closeColumnMenu();

			tabs.forEach(function (tab) {
				var selected = tab.dataset.tab === view;

				tab.classList.toggle('is-active', selected);
				tab.setAttribute('aria-selected', String(selected));
				tab.tabIndex = selected ? 0 : -1;

				if (selected && focus) {
					tab.focus();
				}
			});

			Object.keys(panels).forEach(function (key) {
				panels[key].hidden = key !== view;
			});

			if (view === 'products') {
				if (!productState.loaded) {
					loadProducts();
				}
			} else if (!treeState[view].data) {
				loadTree(view);
			}
		}

		tabs.forEach(function (tab, index) {
			tab.addEventListener('click', function () {
				activate(tab.dataset.tab, false);
			});

			tab.addEventListener('keydown', function (event) {
				var next = null;

				if (event.key === 'ArrowRight') {
					next = (index + 1) % tabs.length;
				} else if (event.key === 'ArrowLeft') {
					next = (index - 1 + tabs.length) % tabs.length;
				} else if (event.key === 'Home') {
					next = 0;
				} else if (event.key === 'End') {
					next = tabs.length - 1;
				}

				if (next !== null) {
					event.preventDefault();
					activate(tabs[next].dataset.tab, true);
				}
			});
		});

		app.querySelector('#wco-refresh').addEventListener('click', function () {
			if (active === 'products') {
				clearTimeout(searchTimer);
				loadProducts();
			} else {
				loadTree(active);
			}
		});

		activate('pages', false);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

JS;
		}
	}

	WUTM_Content_Overview::boot();
}
