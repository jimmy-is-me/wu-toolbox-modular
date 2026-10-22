<?php
final class WU_NP_Shipping {

	public static function init() {
		add_filter( 'wc_order_statuses', [ __CLASS__, 'add_order_statuses' ] );
		add_filter( 'woocommerce_reports_order_statuses', [ __CLASS__, 'add_reports_order_statuses' ] );
		add_filter( 'woocommerce_order_is_paid_statuses', [ __CLASS__, 'add_order_is_paid_statuses' ] );
		self::register_order_statuses();

		add_filter( 'woocommerce_get_order_address', [ __CLASS__, 'show_store_in_address' ], 10, 3 );
		add_filter( 'woocommerce_formatted_address_replacements', [ __CLASS__, 'add_cvs_address_replacements' ], 10, 2 );

		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', [ __CLASS__, 'add_scripts' ] );
			add_action( 'woocommerce_update_order', [ __CLASS__, 'save_order_update' ] );

			add_filter( 'woocommerce_shipping_address_map_url_parts', [ __CLASS__, 'fix_cvs_map_address' ] );
			add_filter( 'woocommerce_admin_order_actions', [ __CLASS__, 'add_admin_order_actions' ], 10, 2 );
		} else {
            add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_frontend_script' ] );
		}
	}

    public static function register_frontend_script() {
        wp_register_script( 'wu-np-shipping', WU_NP_WT_PLUGIN_URL . 'style/js/wu_np_shipping.js', [ 'jquery' ], WUTM_VERSION, true );
    }

	public static function add_order_statuses( $order_statuses ) {
		$order_statuses['wc-wu-np-at-cvs']  = _x( 'Wait pickup (cvs)', 'Order status', 'wu-newebpay-tools' );
		$order_statuses['wc-wu-np-out-cvs'] = _x( 'Overdue return (cvs)', 'Order status', 'wu-newebpay-tools' );

		return $order_statuses;
	}

	public static function add_reports_order_statuses( $order_statuses ) {
		$order_statuses[] = 'wu-np-at-cvs';
		$order_statuses[] = 'wu-np-out-cvs';

		return $order_statuses;
	}

	public static function add_order_is_paid_statuses( $statuses ) {
		$statuses[] = 'wu-np-at-cvs';
		$statuses[] = 'wu-np-out-cvs';

		return $statuses;
	}

	public static function register_order_statuses() {
		register_post_status(
			'wc-wu-np-at-cvs',
			[
				'label'                     => _x( 'Wait pickup (cvs)', 'Order status', 'wu-newebpay-tools' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Wait pickup (cvs) <span class="count">(%s)</span>', 'Wait pickup (cvs) <span class="count">(%s)</span>', 'wu-newebpay-tools' ),
			]
		);

		register_post_status(
			'wc-wu-np-out-cvs',
			[
				'label'                     => _x( 'Overdue return (cvs)', 'Order status', 'wu-newebpay-tools' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Overdue return (cvs) <span class="count">(%s)</span>', 'Overdue return (cvs) <span class="count">(%s)</span>', 'wu-newebpay-tools' ),
			]
		);
	}

	public static function show_store_in_address( $address, $type, $order ) {
		$show_cvs_address = false;

		if ( $type == 'shipping' ) {
			if ( ! empty( $order->get_meta( '_shipping_cvs_store_ID' ) ) ) {
				$items_shipping = $order->get_items( 'shipping' );
				$items_shipping = array_shift( $items_shipping );
				if ( $items_shipping ) {
					$items_shipping = $items_shipping->get_method_id();

					if ( 'yes' == WU_NP_WT::get_option( 'enabled_ecpay_shipping', 'no' ) ) {
						if ( array_key_exists( $items_shipping, WU_NP_ECPay_Shipping::$support_methods ) ) {
							$show_cvs_address = true;
						}
					}

					if ( 'yes' == WU_NP_WT::get_option( 'enabled_newebpay_shipping', 'no' ) ) {
						if ( array_key_exists( $items_shipping, WU_NP_NewebPay_Shipping::$support_methods ) ) {
							$show_cvs_address = true;
						}
					}

					if ( 'yes' == WU_NP_WT::get_option( 'enabled_smilepay_shipping', 'no' ) ) {
						if ( array_key_exists( $items_shipping, WU_NP_SmilePay_Shipping::$support_methods ) ) {
							$show_cvs_address = true;
						}
					}
				}
			}
		}

		if ( $show_cvs_address ) {
			$shipping_methods = WC()->shipping->get_shipping_methods();
			if ( isset( $shipping_methods[ $items_shipping ] ) ) {
				$address['shipping_type'] = $shipping_methods[ $items_shipping ]->get_method_title();
			} else {
				$address['shipping_type'] = (string) $items_shipping;
			}

			$address['cvs_store_ID']   = $order->get_meta( '_shipping_cvs_store_ID' );
			$address['cvs_store_name'] = $order->get_meta( '_shipping_cvs_store_name' );
			$address['cvs_address']    = $order->get_meta( '_shipping_cvs_store_address' );
			$address['cvs_telephone']  = $order->get_meta( '_shipping_cvs_store_telephone' );
			if ( version_compare( WC_VERSION, '5.6.0', '>=' ) ) {
				$address['phone'] = $order->get_shipping_phone();
			} else {
				$address['phone'] = $order->get_meta( '_shipping_phone' );
			}
			$address['country'] = 'CVS';
		}

		return $address;
	}

	public static function add_cvs_address_replacements( $replacements, $args ) {
		if ( isset( $args['cvs_store_ID'] ) ) {
			if ( isset( $args['shipping_type'] ) ) {
				$replacements['{shipping_type}'] = $args['shipping_type'];
			}
			$replacements['{cvs_store_ID}']        = $args['cvs_store_ID'];
			$replacements['{cvs_store_name}']      = $args['cvs_store_name'];
			$replacements['{cvs_store_address}']   = $args['cvs_address'];
			$replacements['{cvs_store_telephone}'] = $args['cvs_telephone'];
			$replacements['{phone}']               = $args['phone'];
		}
		return $replacements;
	}

	public static function add_scripts() {
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';

		// HPOS 相容：同時支援傳統和 HPOS screen ID
		$allowed_screens = array_merge( [ 'shop_order', 'edit-shop_order' ], WU_NP_HPOS_Helper::get_order_screen_ids() );
		if ( in_array( $screen_id, array_unique( $allowed_screens ), true ) ) {
			wp_enqueue_style( 'wu-np-shipping-admin-style', WU_NP_WT_PLUGIN_URL . 'style/admin/wu_np_shipping.css', [], WU_NP_WT_VERSION );
			wp_enqueue_script( 'wu-np-shipping-admin', WU_NP_WT_PLUGIN_URL . 'style/js/admin/wu_np_shipping.js', [ 'jquery' ], WU_NP_WT_VERSION );
		}
	}

	public static function save_order_update( $order_id ) {
		// 防遞迴保護：當 $order->save() 觸發 woocommerce_update_order 時，避免本函式重入造成無限遞迴。
		// 主要對應 HPOS 環境下訂單 #53817 反覆寫入 _shipping_address_index 的問題。
		static $processing = [];
		if ( isset( $processing[ $order_id ] ) ) {
			return;
		}
		$processing[ $order_id ] = true;

		try {
			if ( $order = wc_get_order( $order_id ) ) {
				foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
					$shipping_method = false;
					if ( class_exists( 'WU_NP_ECPay_Shipping' ) ) {
						$shipping_method = WU_NP_ECPay_Shipping::get_order_support_shipping( $item );
					}
					if ( class_exists( 'WU_NP_NewebPay_Shipping' ) ) {
						$shipping_method = WU_NP_NewebPay_Shipping::get_order_support_shipping( $item );
					}
					if ( $shipping_method ) {
						if ( version_compare( WC_VERSION, '5.6.0', '<' ) ) {
							if ( isset( $_POST['_shipping_phone'] ) ) {
								$order->update_meta_data( '_shipping_phone', wc_clean( wp_unslash( $_POST['_shipping_phone'] ) ) );
								$order->save_meta_data();
							}
						}

						if ( strpos( $shipping_method, 'cvs' ) !== false ) {
							if ( isset( $_POST['_shipping_cvs_store_ID'] ) ) {
								$order->update_meta_data( '_shipping_cvs_store_ID', wc_clean( wp_unslash( $_POST['_shipping_cvs_store_ID'] ) ) );
								$order->update_meta_data( '_shipping_cvs_store_name', wc_clean( wp_unslash( $_POST['_shipping_cvs_store_name'] ) ) );
								$order->update_meta_data( '_shipping_cvs_store_address', wc_clean( wp_unslash( $_POST['_shipping_cvs_store_address'] ) ) );
								$order->update_meta_data( '_shipping_cvs_store_telephone', wc_clean( wp_unslash( $_POST['_shipping_cvs_store_telephone'] ) ) );
								$order->save_meta_data();

								// HPOS：_shipping_address_1 對應 wc_order_addresses.address_1，屬訂單核心欄位，
								// 在 HPOS 下透過 update_meta_data 寫入會被靜默丟棄，必須改用 setter；_shipping_address_index 才是 meta。
								// setter 更新的是物件 prop，需 $order->save() 才會持久化（save_meta_data() 只寫 meta、不寫核心欄位）。
								// 遞迴由本函式頂端的 $processing 守衛阻擋，此處呼叫 save() 安全。
								$shipping_address = $order->get_address( 'shipping' );
								$order->set_shipping_address_1( wc_clean( wp_unslash( $_POST['_shipping_cvs_store_address'] ) ) );
								$order->update_meta_data( '_shipping_address_index', implode( ' ', $shipping_address ) );
								$order->save();
							}
						}
					}
				}
			}
		} finally {
			unset( $processing[ $order_id ] );
		}
	}

	public static function fix_cvs_map_address( $address ) {
		if ( isset( $address['cvs_address'] ) ) {
			$address = [
				$address['cvs_address'],
			];
		}
		return $address;
	}

	public static function add_admin_order_actions( $actions, $object ) {
		if ( $object->has_status( [ 'wu-np-at-cvs' ] ) ) {
			$actions['complete'] = [
				'url'    => wp_nonce_url( admin_url( 'admin-ajax.php?action=woocommerce_mark_order_status&status=completed&order_id=' . $object->get_id() ), 'woocommerce-mark-order-status' ),
				'name'   => __( 'Complete', 'woocommerce' ),
				'action' => 'complete',
			];
		}

		return $actions;
	}
}

WU_NP_Shipping::init();
