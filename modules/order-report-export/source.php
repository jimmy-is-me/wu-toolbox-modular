<?php
/**
 * WooCommerce 中文訂單報表匯出 V3
 *
 * Code Snippets → PHP 程式碼 → 僅在後台執行
 * 不需要加入 PHP 開頭標籤。請先停用舊版。
 *
 * 輸出：UTF-8 BOM CSV（可由 Excel 開啟）
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! class_exists( 'WUTM_Order_Report_Export_V3' ) ) {

	final class WUTM_Order_Report_Export_V3 {

		const SLUG      = 'wu-order-report-export';
		const META      = '_wctre_export_v3_settings';
		const BATCH     = 100;
		const SCAN_SIZE = 100;

		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
			add_action( 'admin_post_wctre_v3_submit', array( __CLASS__, 'submit' ) );
			add_action( 'wp_ajax_wctre_v3_scan', array( __CLASS__, 'ajax_scan' ) );
			add_action( 'wp_ajax_wctre_v3_preview', array( __CLASS__, 'ajax_preview' ) );
		}

		/* ------------------------------------------------------------
		 * 基本檢查
		 * ---------------------------------------------------------- */

		private static function allowed() {
			return current_user_can( 'manage_woocommerce' )
				|| current_user_can( 'manage_options' );
		}

		private static function check_access() {
			if ( ! self::allowed() ) {
				wp_die(
					'您沒有操作訂單報表的權限。',
					'權限不足',
					array( 'response' => 403 )
				);
			}

			if ( ! function_exists( 'wc_get_orders' ) ) {
				wp_die( '請先啟用 WooCommerce。' );
			}
		}

		public static function menu() {
			if ( class_exists( 'WCTRE_Order_Export_V2' ) || class_exists( 'WCTRE_Simple_Order_Export' ) ) {
				add_action(
					'admin_notices',
					static function() {
						echo '<div class="notice notice-warning"><p><strong>訂單報表匯出 V3：</strong>偵測到舊版匯出程式仍在執行。請先停用舊版 Snippet，再使用 V3。</p></div>';
					}
				);
				return;
			}

			if ( ! function_exists( 'wc_get_orders' ) ) {
				return;
			}

			$capability = current_user_can( 'manage_woocommerce' )
				? 'manage_woocommerce'
				: 'manage_options';

			add_submenu_page(
				'wu-toolbox-modular',
				'訂單報表匯出',
				'訂單報表匯出',
				$capability,
				self::SLUG,
				array( __CLASS__, 'page' )
			);
		}

		/* ------------------------------------------------------------
		 * 欄位定義
		 * ---------------------------------------------------------- */

		private static function definitions() {
			return array(
				'order_number'     => array( 'label' => '訂單編號', 'level' => 'order', 'type' => 'text', 'common' => 1 ),
				'order_date'       => array( 'label' => '訂單建立日期', 'level' => 'order', 'type' => 'text', 'common' => 1 ),
				'order_status'     => array( 'label' => '訂單狀態', 'level' => 'order', 'type' => 'text', 'common' => 1 ),
				'payment_method'   => array( 'label' => '付款方式', 'level' => 'order', 'type' => 'text', 'common' => 1 ),
				'shipping_method'  => array( 'label' => '配送方式', 'level' => 'order', 'type' => 'text', 'common' => 1 ),
				'billing_name'     => array( 'label' => '訂購人姓名', 'level' => 'order', 'type' => 'text', 'common' => 1 ),
				'billing_phone'    => array( 'label' => '電話', 'level' => 'order', 'type' => 'code', 'common' => 1 ),
				'shipping_address' => array( 'label' => '收件地址', 'level' => 'order', 'type' => 'text', 'common' => 1 ),
				'item_name'        => array( 'label' => '商品名稱', 'level' => 'item', 'type' => 'text', 'common' => 1 ),
				'item_qty'         => array( 'label' => '商品數量', 'level' => 'item', 'type' => 'number', 'common' => 1 ),
				'order_total'      => array( 'label' => '訂單總額（含稅／退款前）', 'level' => 'order_money', 'type' => 'money', 'common' => 1 ),

				'billing_email'    => array( 'label' => 'Email', 'level' => 'order', 'type' => 'text' ),
				'shipping_name'    => array( 'label' => '收件人', 'level' => 'order', 'type' => 'text' ),
				'currency'         => array( 'label' => '幣別', 'level' => 'order', 'type' => 'text' ),
				'item_sku'         => array( 'label' => '商品 SKU', 'level' => 'item', 'type' => 'code' ),
				'item_variation'   => array( 'label' => '商品規格／項目資訊', 'level' => 'item', 'type' => 'text' ),
				'item_total'       => array( 'label' => '商品折扣後金額（含稅／退款前）', 'level' => 'item', 'type' => 'money' ),
				'discount_total'   => array( 'label' => '訂單折扣（含稅）', 'level' => 'order_money', 'type' => 'money' ),
				'shipping_total'   => array( 'label' => '運費（含稅）', 'level' => 'order_money', 'type' => 'money' ),
				'refund_total'     => array( 'label' => '累計退款金額（含稅）', 'level' => 'order_money', 'type' => 'money' ),
				'net_total'        => array( 'label' => '訂單總額扣除退款', 'level' => 'order_money', 'type' => 'money' ),
				'customer_note'    => array( 'label' => '顧客備註', 'level' => 'order', 'type' => 'text' ),
			);
		}

		private static function default_fields() {
			$fields = array();

			foreach ( self::definitions() as $key => $field ) {
				if ( ! empty( $field['common'] ) ) {
					unset( $field['common'] );
					$field['enabled'] = 1;
					$fields[ $key ]   = $field;
				}
			}

			return $fields;
		}

		private static function defaults() {
			return array(
				'date_from'    => '',
				'date_to'      => '',
				'statuses'     => array_keys( wc_get_order_statuses() ),
				'mode'         => 'order',
				'repeat_order' => 'repeat',
				'text_guard'   => 1,
				'fields'       => self::default_fields(),
			);
		}

		private static function settings() {
			$defaults = self::defaults();
			$saved    = get_user_meta( get_current_user_id(), self::META, true );

			if ( ! is_array( $saved ) ) {
				return $defaults;
			}

			$s = array_merge( $defaults, $saved );

			if ( ! is_array( $s['fields'] ) ) {
				$s['fields'] = $defaults['fields'];
			}
			if ( ! is_array( $s['statuses'] ) ) {
				$s['statuses'] = $defaults['statuses'];
			}

			return $s;
		}

		/* ------------------------------------------------------------
		 * 中文欄名建議
		 * ---------------------------------------------------------- */

		private static function suggest_label( $key ) {
			$k = strtolower( (string) $key );

			$rules = array(
				'/(love_?code|donat)/'                                   => '捐贈碼',
				'/carrier/'                                              => '發票載具',
				'/(invoice.*(no|number)|(no|number).*invoice)/'          => '發票號碼',
				'/(tax_?id|vat|ubn|company_?(no|number|id)|buyer_?id)/'  => '統一編號',
				'/invoice/'                                              => '發票資訊',
				'/(cvs|store|pickup).*addr/'                             => '超商門市地址',
				'/(cvs|store|pickup).*name/'                             => '超商門市名稱',
				'/(cvs|store|pickup).*(id|code|no|num)/'                 => '超商門市代號',
				'/cvs/'                                                  => '超商資訊',
				'/(tracking|track|shipment_?no|logistics_?id|booking)/'  => '物流單號',
				'/(transaction|trade_?no|payment_?id)/'                  => '交易編號',
				'/(point|reward)/'                                       => '點數',
				'/company/'                                              => '公司名稱',
			);

			foreach ( $rules as $pattern => $label ) {
				if ( preg_match( $pattern, $k ) ) {
					return array( $label, true );
				}
			}

			$label = trim( preg_replace( '/[_\-]+/', ' ', ltrim( (string) $key, '_' ) ) );

			return array( '' !== $label ? $label : (string) $key, false );
		}

		private static function hidden_meta( $scope, $key ) {
			$common = array(
				'_edit_lock', '_edit_last', '_new_order_email_sent', '_order_stock_reduced',
				'_recorded_sales', '_recorded_coupon_usage_counts', '_download_permissions_granted',
				'_cart_hash', '_billing_address_index', '_shipping_address_index', '_order_version',
				'is_vat_exempt', '_wp_old_slug',
			);

			$item = array(
				'_product_id', '_variation_id', '_qty', '_tax_class', '_line_subtotal',
				'_line_subtotal_tax', '_line_total', '_line_tax', '_line_tax_data',
				'_reduced_stock', '_sku',
			);

			$shipping = array( 'Items', 'items' );

			$hidden = $common;

			if ( 'item' === $scope ) {
				$hidden = array_merge( $hidden, $item );
			} elseif ( 'shipping' === $scope ) {
				$hidden = array_merge( $hidden, $shipping );
			}

			$hidden = apply_filters( 'wctre_hidden_meta_keys', $hidden, $scope );

			if ( in_array( $key, $hidden, true ) ) {
				return true;
			}

			return 0 === strpos( $key, '_wc_order_attribution' )
				|| 0 === strpos( $key, '_oembed_' );
		}

		/* ------------------------------------------------------------
		 * 篩選
		 * ---------------------------------------------------------- */

		private static function post_text( $key, $default = '' ) {
			if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
				return $default;
			}

			return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
		}

		private static function post_array( $key ) {
			if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
				return array();
			}

			return wp_unslash( $_POST[ $key ] );
		}

		private static function array_text( $array, $key ) {
			if ( ! isset( $array[ $key ] ) || ! is_scalar( $array[ $key ] ) ) {
				return '';
			}

			return sanitize_text_field( (string) $array[ $key ] );
		}

		private static function valid_date( $value ) {
			if ( '' === $value ) {
				return true;
			}

			$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );

			return $date && $date->format( 'Y-m-d' ) === $value;
		}

		private static function clean_statuses( $list ) {
			$statuses = array();

			foreach ( (array) $list as $status ) {
				if ( is_scalar( $status ) ) {
					$statuses[] = sanitize_text_field( (string) $status );
				}
			}

			return array_values(
				array_intersect(
					array_unique( $statuses ),
					array_keys( wc_get_order_statuses() )
				)
			);
		}

		private static function parse_settings() {
			$definitions = self::definitions();

			$from = self::post_text( 'date_from' );
			$to   = self::post_text( 'date_to' );

			if ( ! self::valid_date( $from ) || ! self::valid_date( $to ) ) {
				wp_die( '日期格式錯誤，請重新選擇日期。', '日期錯誤', array( 'back_link' => true ) );
			}

			if ( $from && $to && $from > $to ) {
				wp_die( '開始日期不能晚於結束日期。', '日期錯誤', array( 'back_link' => true ) );
			}

			$order   = self::post_array( 'field_order' );
			$enabled = self::post_array( 'field_enabled' );
			$labels  = self::post_array( 'field_label' );
			$scopes  = self::post_array( 'custom_scope' );
			$metas   = self::post_array( 'custom_meta' );
			$fields  = array();
			$seen    = array();

			foreach ( $order as $raw_key ) {
				if ( ! is_scalar( $raw_key ) ) {
					continue;
				}

				$key = sanitize_key( (string) $raw_key );

				if ( '' === $key || isset( $fields[ $key ] ) ) {
					continue;
				}

				$label = trim( self::array_text( $labels, $key ) );

				if ( isset( $definitions[ $key ] ) ) {
					$field = $definitions[ $key ];
					unset( $field['common'] );

				} elseif ( 0 === strpos( $key, 'custom_' ) ) {
					$meta_key = trim( self::array_text( $metas, $key ) );
					$scope    = self::array_text( $scopes, $key );

					if ( ! in_array( $scope, array( 'order', 'item', 'shipping' ), true ) ) {
						$scope = 'order';
					}

					if ( '' === $meta_key || strlen( $meta_key ) > 191 ) {
						continue;
					}

					$unique = $scope . '|' . $meta_key;

					if ( isset( $seen[ $unique ] ) ) {
						continue;
					}

					$seen[ $unique ] = true;
					$suggest         = self::suggest_label( $meta_key );

					$field = array(
						'label'      => $suggest[0],
						'level'      => 'item' === $scope ? 'item' : 'order',
						'type'       => 'code',
						'is_custom'  => 1,
						'meta_scope' => $scope,
						'meta_key'   => $meta_key,
					);

				} else {
					continue;
				}

				if ( '' !== $label ) {
					$field['label'] = $label;
				}

				$field['enabled'] = ! empty( $enabled[ $key ] ) ? 1 : 0;
				$fields[ $key ]   = $field;
			}

			return array(
				'date_from'    => $from,
				'date_to'      => $to,
				'statuses'     => self::clean_statuses( self::post_array( 'statuses' ) ),
				'mode'         => 'item' === self::post_text( 'mode' ) ? 'item' : 'order',
				'repeat_order' => 'first' === self::post_text( 'repeat_order' ) ? 'first' : 'repeat',
				'text_guard'   => '1' === self::post_text( 'text_guard' ) ? 1 : 0,
				'fields'       => $fields,
			);
		}

		private static function query_args( $s ) {
			$args = array(
				'type'    => 'shop_order',
				'status'  => $s['statuses'],
				'return'  => 'ids',
				'limit'   => self::BATCH,
				'orderby' => 'ID',
				'order'   => 'DESC',
			);

			if ( $s['date_from'] && $s['date_to'] ) {
				$args['date_created'] = $s['date_from'] . '...' . $s['date_to'];
			} elseif ( $s['date_from'] ) {
				$args['date_created'] = '>=' . $s['date_from'];
			} elseif ( $s['date_to'] ) {
				$args['date_created'] = '<=' . $s['date_to'];
			}

			return $args;
		}

		private static function count_orders( $s ) {
			$args             = self::query_args( $s );
			$args['limit']    = 1;
			$args['page']     = 1;
			$args['paginate'] = true;

			$result = wc_get_orders( $args );

			if ( ! is_object( $result ) || ! isset( $result->total ) ) {
				throw new RuntimeException( '無法取得訂單數量。' );
			}

			return (int) $result->total;
		}

		private static function period_label( $s ) {
			if ( ! $s['date_from'] && ! $s['date_to'] ) {
				return '全部期間';
			}

			return ( $s['date_from'] ? $s['date_from'] : '不限起日' )
				. ' ～ '
				. ( $s['date_to'] ? $s['date_to'] : '不限迄日' );
		}

		/* ------------------------------------------------------------
		 * 自動偵測 Meta Key（AJAX）
		 * ---------------------------------------------------------- */

		private static function collect_meta( &$found, $scope, $meta_list, &$order_seen ) {
			foreach ( (array) $meta_list as $meta ) {
				if ( ! is_object( $meta ) || ! method_exists( $meta, 'get_data' ) ) {
					continue;
				}

				$data = $meta->get_data();
				$key  = isset( $data['key'] ) ? (string) $data['key'] : '';
				$val  = isset( $data['value'] ) ? $data['value'] : '';

				if ( '' === $key || self::hidden_meta( $scope, $key ) ) {
					continue;
				}

				$is_complex = is_array( $val ) || is_object( $val );

				if ( $is_complex ) {
					if ( empty( $val ) ) {
						continue;
					}
					$sample = '（資料陣列，將以 JSON 匯出）';
				} else {
					$text = trim( wp_strip_all_tags( (string) $val ) );

					if ( '' === $text ) {
						continue;
					}

					$sample = function_exists( 'mb_substr' )
						? mb_substr( $text, 0, 40 )
						: substr( $text, 0, 40 );
				}

				$id = $scope . '|' . $key;

				if ( ! isset( $found[ $id ] ) ) {
					$suggest      = self::suggest_label( $key );
					$found[ $id ] = array(
						'scope'      => $scope,
						'key'        => $key,
						'label'      => $suggest[0],
						'recognized' => $suggest[1],
						'count'      => 0,
						'sample'     => $sample,
					);
				}

				if ( empty( $order_seen[ $id ] ) ) {
					$order_seen[ $id ] = true;
					$found[ $id ]['count']++;
				}
			}
		}

		public static function ajax_scan() {
			if ( ! self::allowed() || ! function_exists( 'wc_get_orders' ) ) {
				wp_send_json_error( array( 'message' => '權限不足。' ), 403 );
			}

			check_ajax_referer( 'wctre_v3_scan', 'nonce' );

			$from = self::post_text( 'date_from' );
			$to   = self::post_text( 'date_to' );

			if ( ! self::valid_date( $from ) || ! self::valid_date( $to ) || ( $from && $to && $from > $to ) ) {
				wp_send_json_error( array( 'message' => '日期條件有誤，無法偵測欄位。' ) );
			}

			$statuses = self::clean_statuses( self::post_array( 'statuses' ) );

			if ( empty( $statuses ) ) {
				$statuses = array_keys( wc_get_order_statuses() );
			}

			$s = array(
				'date_from' => $from,
				'date_to'   => $to,
				'statuses'  => $statuses,
			);

			try {
				$args             = self::query_args( $s );
				$args['limit']    = self::SCAN_SIZE;
				$args['page']     = 1;
				$args['paginate'] = false;
				$ids              = wc_get_orders( $args );
				$found            = array();
				$scanned          = 0;

				foreach ( $ids as $id ) {
					$order = wc_get_order( $id );

					if ( ! $order instanceof WC_Order ) {
						continue;
					}

					$scanned++;
					$order_seen = array();

					self::collect_meta( $found, 'order', $order->get_meta_data(), $order_seen );

					foreach ( $order->get_items( 'line_item' ) as $item ) {
						self::collect_meta( $found, 'item', $item->get_meta_data(), $order_seen );
					}

					foreach ( $order->get_items( 'shipping' ) as $item ) {
						self::collect_meta( $found, 'shipping', $item->get_meta_data(), $order_seen );
					}

					unset( $order );
				}

				$list = array_values( $found );

				usort(
					$list,
					static function( $a, $b ) {
						if ( $a['recognized'] !== $b['recognized'] ) {
							return $a['recognized'] ? -1 : 1;
						}
						if ( $a['count'] !== $b['count'] ) {
							return $b['count'] - $a['count'];
						}
						return strcmp( $a['key'], $b['key'] );
					}
				);

				wp_send_json_success(
					array(
						'scanned' => $scanned,
						'fields'  => $list,
					)
				);

			} catch ( Throwable $e ) {
				wp_send_json_error( array( 'message' => '偵測失敗，請稍後再試或手動輸入欄位。' ) );
			}
		}

		/* ------------------------------------------------------------
		 * 表單送出
		 * ---------------------------------------------------------- */

		private static function redirect_notice( $type, $text ) {
			set_transient(
				'wctre_v3_notice_' . get_current_user_id(),
				array( 'type' => $type, 'text' => $text ),
				120
			);

			wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) );
			exit;
		}

		public static function submit() {
			self::check_access();
			check_admin_referer( 'wctre_v3_action', 'wctre_v3_nonce' );

			$op = self::post_text( 'operation', 'export' );

			if ( 'reset' === $op ) {
				delete_user_meta( get_current_user_id(), self::META );
				self::redirect_notice( 'success', '已還原預設：全部期間、全部訂單狀態、常用欄位。' );
			}

			if ( ! in_array( $op, array( 'save', 'count', 'export' ), true ) ) {
				wp_die( '不支援的操作。' );
			}

			$s = self::parse_settings();

			update_user_meta( get_current_user_id(), self::META, $s );

			if ( 'save' === $op ) {
				self::redirect_notice( 'success', '目前設定已儲存。' );
			}

			if ( empty( $s['statuses'] ) ) {
				wp_die( '請至少勾選一個訂單狀態。', '篩選條件錯誤', array( 'back_link' => true ) );
			}

			try {
				$count = self::count_orders( $s );
			} catch ( Throwable $e ) {
				wp_die( '訂單查詢失敗，請檢查 WooCommerce 狀態或網站錯誤紀錄。', '查詢失敗', array( 'back_link' => true ) );
			}

			if ( 'count' === $op ) {
				self::redirect_notice(
					$count ? 'success' : 'warning',
					sprintf(
						'查詢期間：%s。符合目前條件的訂單共有 %s 筆（訂單數，不是商品拆列後的列數）。',
						self::period_label( $s ),
						number_format_i18n( $count )
					)
				);
			}

			if ( 0 === $count ) {
				self::redirect_notice( 'warning', '沒有符合條件的訂單。請按「全部期間」並確認訂單狀態，再重新檢查。' );
			}

			self::export_csv( $s );
		}

		/* ------------------------------------------------------------
		 * 資料轉換
		 * ---------------------------------------------------------- */

		private static function meta_value( $object, $key ) {
			if ( ! is_object( $object ) || ! method_exists( $object, 'get_meta' ) ) {
				return '';
			}

			$value = $object->get_meta( $key, true );

			if ( is_array( $value ) || is_object( $value ) ) {
				return wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
			}

			return (string) $value;
		}

		private static function shipping_meta_value( $order, $key ) {
			$values = array();

			foreach ( $order->get_items( 'shipping' ) as $item ) {
				$value = self::meta_value( $item, $key );

				if ( '' !== $value ) {
					$values[] = $value;
				}
			}

			return implode( "\n", $values );
		}

		private static function address( $order, $type ) {
			$address = $order->get_address( $type );

			if ( ! is_array( $address ) ) {
				return '';
			}

			$country = isset( $address['country'] ) ? $address['country'] : '';
			$state   = isset( $address['state'] ) ? $address['state'] : '';

			if ( $country && $state && function_exists( 'WC' ) && WC()->countries ) {
				$states = WC()->countries->get_states( $country );

				if ( is_array( $states ) && isset( $states[ $state ] ) ) {
					$state = $states[ $state ];
				}
			}

			$parts = array(
				isset( $address['postcode'] ) ? $address['postcode'] : '',
				$state,
				isset( $address['city'] ) ? $address['city'] : '',
				isset( $address['address_1'] ) ? $address['address_1'] : '',
				isset( $address['address_2'] ) ? $address['address_2'] : '',
			);

			return trim(
				implode(
					' ',
					array_filter(
						$parts,
						static function( $value ) {
							return '' !== (string) $value;
						}
					)
				)
			);
		}

		private static function order_data( $order ) {
			$date = $order->get_date_created();

			$billing_name = trim( $order->get_billing_last_name() . ' ' . $order->get_billing_first_name() );
			$ship_name    = trim( $order->get_shipping_last_name() . ' ' . $order->get_shipping_first_name() );

			$address = self::address( $order, 'shipping' );

			if ( '' === $address ) {
				$address = self::address( $order, 'billing' );
			}

			$total  = (float) $order->get_total();
			$refund = abs( (float) $order->get_total_refunded() );

			return array(
				'order_number'     => (string) $order->get_order_number(),
				'order_date'       => $date ? wp_date( 'Y-m-d H:i:s', $date->getTimestamp(), wp_timezone() ) : '',
				'order_status'     => wc_get_order_status_name( $order->get_status() ),
				'currency'         => $order->get_currency(),
				'payment_method'   => $order->get_payment_method_title(),
				'shipping_method'  => $order->get_shipping_method(),
				'billing_name'     => $billing_name,
				'billing_email'    => $order->get_billing_email(),
				'billing_phone'    => $order->get_billing_phone(),
				'shipping_name'    => $ship_name ? $ship_name : $billing_name,
				'shipping_address' => $address,
				'discount_total'   => (float) $order->get_discount_total() + (float) $order->get_discount_tax(),
				'shipping_total'   => (float) $order->get_shipping_total() + (float) $order->get_shipping_tax(),
				'refund_total'     => $refund,
				'order_total'      => $total,
				'net_total'        => $total - $refund,
				'customer_note'    => $order->get_customer_note(),
			);
		}

		private static function item_data( $item ) {
			$sku = (string) $item->get_meta( '_sku', true );

			if ( '' === $sku ) {
				$product = $item->get_product();

				if ( $product ) {
					$sku = (string) $product->get_sku();
				}
			}

			$spec = array();

			foreach ( $item->get_formatted_meta_data( '_', true ) as $meta ) {
				$key   = trim( wp_strip_all_tags( (string) $meta->display_key ) );
				$value = trim( wp_strip_all_tags( (string) $meta->display_value ) );

				if ( '' !== $key && '' !== $value ) {
					$spec[] = $key . '：' . $value;
				}
			}

			return array(
				'item_name'      => $item->get_name(),
				'item_sku'       => $sku,
				'item_variation' => implode( ' / ', $spec ),
				'item_qty'       => (float) $item->get_quantity(),
				'item_total'     => (float) $item->get_total() + (float) $item->get_total_tax(),
				'object'         => $item,
			);
		}

		private static function csv_cell( $value, $type, $guard ) {
			if ( null === $value || '' === $value ) {
				return '';
			}

			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
			}

			if ( 'money' === $type ) {
				return is_numeric( $value )
					? number_format( (float) $value, wc_get_price_decimals(), '.', '' )
					: '';
			}

			if ( 'number' === $type ) {
				return is_numeric( $value ) ? (string) $value : '';
			}

			$value = (string) $value;

			if ( preg_match( '/^[\x00-\x20]*[=+\-@]/', $value ) ) {
				return "'" . $value;
			}

			if ( $guard && 'code' === $type ) {
				$compact = preg_replace( '/[\s\-]/', '', $value );

				if ( preg_match( '/^0\d+$/', $compact ) || preg_match( '/^\d{12,}$/', $compact ) ) {
					return "'" . $value;
				}
			}

			return $value;
		}

		private static function write_csv( $out, $row ) {
			if ( false === fputcsv( $out, $row, ',', '"', '' ) ) {
				throw new RuntimeException( 'CSV 寫入失敗。' );
			}
		}

		private static function custom_value_for_order_row( $field, $order, $items ) {
			if ( 'order' === $field['meta_scope'] ) {
				return self::meta_value( $order, $field['meta_key'] );
			}

			if ( 'shipping' === $field['meta_scope'] ) {
				return self::shipping_meta_value( $order, $field['meta_key'] );
			}

			$lines = array();

			foreach ( $items as $item ) {
				$value = self::meta_value( $item['object'], $field['meta_key'] );

				if ( '' !== $value ) {
					$lines[] = $item['item_name'] . '：' . $value;
				}
			}

			return implode( "\n", $lines );
		}

		private static function order_row_value( $key, $field, $order, $base, $items ) {
			if ( ! empty( $field['is_custom'] ) ) {
				return self::custom_value_for_order_row( $field, $order, $items );
			}

			if ( array_key_exists( $key, $base ) ) {
				return $base[ $key ];
			}

			if ( 'item_qty' === $key || 'item_total' === $key ) {
				return array_sum( array_column( $items, $key ) );
			}

			$lines = array();

			foreach ( $items as $item ) {
				if ( 'item_name' === $key ) {
					$lines[] = $item['item_name'] . ' × ' . $item['item_qty'];
				} else {
					$lines[] = isset( $item[ $key ] ) ? $item[ $key ] : '';
				}
			}

			return implode( "\n", $lines );
		}

		/* ------------------------------------------------------------
		 * 匯出
		 * ---------------------------------------------------------- */


		// CSV 與預覽共用，包含文字保護、金額及自訂 Meta。
		private static function export_rows( $order, $s, $fields ) {
						$base  = self::order_data( $order );
						$items = array();

						foreach ( $order->get_items( 'line_item' ) as $item ) {
							if ( $item instanceof WC_Order_Item_Product ) {
								$items[] = self::item_data( $item );
							}
						}

						if ( 'order' === $s['mode'] ) {
							$row = array();

							foreach ( $fields as $key => $field ) {
								$row[] = self::csv_cell(
									self::order_row_value( $key, $field, $order, $base, $items ),
									$field['type'],
									$s['text_guard']
								);
							}

							yield $row;

						} else {
							if ( empty( $items ) ) {
								$items[] = array(
									'item_name'      => '（無商品項目）',
									'item_sku'       => '',
									'item_variation' => '',
									'item_qty'       => 0,
									'item_total'     => 0,
									'object'         => null,
								);
							}

							foreach ( $items as $index => $item ) {
								$row = array();

								foreach ( $fields as $key => $field ) {
									$hide_money = ( $index > 0 && 'order_money' === $field['level'] );
									$hide_order = ( $index > 0 && 'first' === $s['repeat_order'] && 'order' === $field['level'] );

									if ( $hide_money || $hide_order ) {
										$row[] = '';
										continue;
									}

									$value = '';

									if ( ! empty( $field['is_custom'] ) ) {
										if ( 'order' === $field['meta_scope'] ) {
											$value = self::meta_value( $order, $field['meta_key'] );
										} elseif ( 'shipping' === $field['meta_scope'] ) {
											$value = self::shipping_meta_value( $order, $field['meta_key'] );
										} else {
											$value = self::meta_value( $item['object'], $field['meta_key'] );
										}
									} elseif ( array_key_exists( $key, $base ) ) {
										$value = $base[ $key ];
									} elseif ( array_key_exists( $key, $item ) ) {
										$value = $item[ $key ];
									}

									$row[] = self::csv_cell( $value, $field['type'], $s['text_guard'] );
								}

								yield $row;
							}
						}

		}

		public static function ajax_preview() {
			if ( ! self::allowed() || ! function_exists( 'wc_get_orders' ) ) {
				wp_send_json_error( array( 'message' => '權限不足，或 WooCommerce 尚未啟用。' ), 403 );
			}
			if ( ! check_ajax_referer( 'wctre_v3_preview', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => '安全驗證已過期，請重新整理頁面。' ), 403 );
			}
			nocache_headers();
			try {
				$from = self::post_text( 'date_from' );
				$to = self::post_text( 'date_to' );
				if ( ! self::valid_date( $from ) || ! self::valid_date( $to ) || ( $from && $to && $from > $to ) ) {
					wp_send_json_error( array( 'message' => '日期條件錯誤，請重新選擇。' ), 400 );
				}
				$s = self::parse_settings();
				$fields = array_filter( $s['fields'], static function( $field ) { return ! empty( $field['enabled'] ); } );
				if ( empty( $s['statuses'] ) || empty( $fields ) ) {
					wp_send_json_error( array( 'message' => '請至少勾選一個訂單狀態與一個匯出欄位。' ), 400 );
				}
				$headers = array();
				foreach ( $fields as $field ) {
					$headers[] = self::csv_cell( $field['label'], 'text', false );
				}
				$args = self::query_args( $s );
				$args['limit'] = 30;
				$args['paginate'] = false;
				$rows = array();
				$seen = array();
				$ceiling = null;
				$complete = false;
				// 最多讀取 300 張訂單；正常情況第一批即取得前 30 列。
				for ( $page = 1; $page <= 10; $page++ ) {
					$args['page'] = $page;
					$ids = wc_get_orders( $args );
					if ( empty( $ids ) ) { $complete = true; break; }
					if ( null === $ceiling ) { $ceiling = max( array_map( 'intval', $ids ) ); }
					foreach ( $ids as $id ) {
						$id = (int) $id;
						if ( $id > $ceiling || isset( $seen[ $id ] ) ) { continue; }
						$seen[ $id ] = true;
						$order = wc_get_order( $id );
						if ( ! $order instanceof WC_Order ) { continue; }
						foreach ( self::export_rows( $order, $s, $fields ) as $row ) {
							$rows[] = $row;
							if ( count( $rows ) > 30 ) { break 3; }
						}
					}
					if ( count( $ids ) < 30 ) { $complete = true; break; }
				}
				$labels = wc_get_order_statuses();
				$status_names = array();
				foreach ( $s['statuses'] as $status ) { $status_names[] = $labels[ $status ]; }
				wp_send_json_success( array(
					'headers' => $headers,
					'rows' => array_slice( $rows, 0, 30 ),
					'limited' => count( $rows ) > 30 || ! $complete,
					'period' => self::period_label( $s ),
					'statuses' => $status_names,
					'mode' => 'item' === $s['mode'] ? '每個商品項目一列' : '每張訂單一列',
				) );
			} catch ( Throwable $e ) {
				wp_send_json_error( array( 'message' => '預覽讀取失敗，請稍後再試；若持續失敗，請檢查網站錯誤紀錄。' ), 500 );
			}
		}

		private static function export_csv( $s ) {
			$fields = array_filter(
				$s['fields'],
				static function( $field ) {
					return ! empty( $field['enabled'] );
				}
			);

			if ( empty( $fields ) ) {
				wp_die( '請至少勾選一個匯出欄位。', '欄位設定錯誤', array( 'back_link' => true ) );
			}

			$args             = self::query_args( $s );
			$args['paginate'] = false;
			$args['page']     = 1;

			try {
				$ids = wc_get_orders( $args );
			} catch ( Throwable $e ) {
				wp_die( '訂單讀取失敗，請查看網站錯誤紀錄。', '匯出失敗', array( 'back_link' => true ) );
			}

			if ( empty( $ids ) ) {
				self::redirect_notice( 'warning', '目前沒有符合條件的訂單。' );
			}

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 );
			}

			while ( ob_get_level() > 0 ) {
				if ( ! @ob_end_clean() ) {
					break;
				}
			}

			if ( headers_sent() ) {
				wp_die( '網頁已先輸出內容，無法開始下載。請檢查其他程式碼是否有多餘輸出。' );
			}

			$filename = 'woocommerce_' . ( 'item' === $s['mode'] ? 'items_' : 'orders_' ) . wp_date( 'Ymd_His' ) . '.csv';

			nocache_headers();
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'X-Content-Type-Options: nosniff' );

			$out = fopen( 'php://output', 'w' );

			if ( false === $out ) {
				exit;
			}

			fwrite( $out, "\xEF\xBB\xBF" );

			try {
				$headers = array();

				foreach ( $fields as $field ) {
					$headers[] = self::csv_cell( $field['label'], 'text', false );
				}

				self::write_csv( $out, $headers );

				$ceiling = max( array_map( 'intval', $ids ) );
				$seen    = array();

				while ( ! empty( $ids ) ) {
					foreach ( $ids as $id ) {
						$id = (int) $id;

						if ( $id > $ceiling || isset( $seen[ $id ] ) ) {
							continue;
						}

						$seen[ $id ] = true;
						$order       = wc_get_order( $id );

						if ( ! $order instanceof WC_Order ) {
							continue;
						}

						foreach ( self::export_rows( $order, $s, $fields ) as $row ) {
							self::write_csv( $out, $row );
						}
						unset( $order, $base, $items );
					}

					fflush( $out );

					if ( connection_aborted() || count( $ids ) < self::BATCH ) {
						break;
					}

					$args['page']++;
					$ids = wc_get_orders( $args );
				}

			} catch ( Throwable $e ) {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->error(
						'報表匯出中斷。例外類型：' . get_class( $e ),
						array( 'source' => 'wctre-export' )
					);
				}

				fputcsv(
					$out,
					array( '【匯出中斷】此檔案可能不完整，請查看 WooCommerce 記錄後重新匯出。' ),
					',',
					'"',
					''
				);
			}

			fclose( $out );
			exit;
		}

		/* ------------------------------------------------------------
		 * 後台頁面
		 * ---------------------------------------------------------- */

		private static function scope_label( $scope ) {
			if ( 'item' === $scope ) {
				return '商品項目 Meta：';
			}
			if ( 'shipping' === $scope ) {
				return '運送項目 Meta：';
			}
			return '訂單 Meta：';
		}

		private static function field_row( $key, $field, $definitions ) {
			$custom = ! empty( $field['is_custom'] );

			$source = $custom
				? self::scope_label( $field['meta_scope'] ) . $field['meta_key']
				: ( isset( $definitions[ $key ] ) ? $definitions[ $key ]['label'] . '（內建）' : $key );

			echo '<tr data-kind="' . ( $custom ? 'custom' : 'builtin' ) . '" data-key="' . esc_attr( $key ) . '"';

			if ( $custom ) {
				echo ' data-scope="' . esc_attr( $field['meta_scope'] ) . '" data-meta="' . esc_attr( $field['meta_key'] ) . '"';
			}

			echo '><td>';
			echo '<input type="hidden" name="field_order[]" value="' . esc_attr( $key ) . '">';
			echo '<input type="checkbox" class="wctre-field-check" name="field_enabled[' . esc_attr( $key ) . ']" value="1" ' . checked( ! empty( $field['enabled'] ), true, false ) . '>';

			if ( $custom ) {
				echo '<input type="hidden" name="custom_scope[' . esc_attr( $key ) . ']" value="' . esc_attr( $field['meta_scope'] ) . '">';
				echo '<input type="hidden" name="custom_meta[' . esc_attr( $key ) . ']" value="' . esc_attr( $field['meta_key'] ) . '">';
			}

			echo '</td><td>' . esc_html( $source ) . '</td>';
			echo '<td><input class="wctre-label" type="text" name="field_label[' . esc_attr( $key ) . ']" value="' . esc_attr( $field['label'] ) . '"></td>';
			echo '<td class="wctre-actions">';
			echo '<button type="button" class="button wctre-up" title="上移">↑</button> ';
			echo '<button type="button" class="button wctre-down" title="下移">↓</button> ';
			echo '<button type="button" class="button wctre-remove" title="移除此欄位">移除</button>';
			echo '</td></tr>';
		}

		public static function page() {
			self::check_access();

			$s           = self::settings();
			$definitions = self::definitions();
			$statuses    = wc_get_order_statuses();

			$notice_key = 'wctre_v3_notice_' . get_current_user_id();
			$notice     = get_transient( $notice_key );

			if ( $notice ) {
				delete_transient( $notice_key );
			}

			wutm_admin_page_open('訂單報表匯出', '未設定日期就是全部期間。欄位預設只顯示常用項目，其餘可從下拉選單加入。');
			echo '<div class="wctre-v3">';

			if ( is_array( $notice ) ) {
				$type = in_array( $notice['type'], array( 'success', 'warning', 'error', 'info' ), true ) ? $notice['type'] : 'info';
				echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['text'] ) . '</p></div>';
			}

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="wctre-form">';
			echo '<input type="hidden" name="action" value="wctre_v3_submit">';
			wp_nonce_field( 'wctre_v3_action', 'wctre_v3_nonce' );

			echo '<div class="wctre-layout"><div>';

			// 1. 篩選
			echo '<div class="wctre-card"><h2>1. 訂單篩選</h2>';
			echo '<p class="wctre-hint">日期依網站時區：' . esc_html( wp_timezone_string() ) . '；依訂單建立日期篩選。</p>';
			echo '<div class="wctre-dates">';
			echo '<label>開始日期<input type="date" id="wctre-from" name="date_from" value="' . esc_attr( $s['date_from'] ) . '"></label>';
			echo '<label>結束日期<input type="date" id="wctre-to" name="date_to" value="' . esc_attr( $s['date_to'] ) . '"></label>';
			echo '</div><p class="wctre-date-buttons">';
			echo '<button type="button" class="button" data-range="all">全部期間</button>';
			echo '<button type="button" class="button" data-range="today">今天</button>';
			echo '<button type="button" class="button" data-range="month">本月</button>';
			echo '<button type="button" class="button" data-range="lastmonth">上個月</button>';
			echo '</p><p id="wctre-period"></p><hr>';
			echo '<p><strong>訂單狀態</strong> <button type="button" class="button button-small" id="wctre-status-all">全選</button> <button type="button" class="button button-small" id="wctre-status-none">全不選</button></p>';
			echo '<div class="wctre-statuses">';

			foreach ( $statuses as $key => $label ) {
				echo '<label><input type="checkbox" name="statuses[]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $s['statuses'], true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
			}

			echo '</div></div>';

			// 2. 匯出方式
			echo '<div class="wctre-card"><h2>2. 匯出方式</h2>';
			echo '<label class="wctre-radio"><input type="radio" name="mode" value="order" ' . checked( $s['mode'], 'order', false ) . '> 每張訂單一列</label>';
			echo '<p class="wctre-hint">多個商品集中在同一儲存格，以換行分隔。</p>';
			echo '<label class="wctre-radio"><input type="radio" name="mode" value="item" ' . checked( $s['mode'], 'item', false ) . '> 每個商品項目一列</label>';
			echo '<p class="wctre-hint">訂單金額、折扣、運費及退款只在該訂單第一列輸出，避免重複加總。</p>';
			echo '<p><label>商品拆列時，訂單基本資料：</label></p>';
			echo '<select name="repeat_order" class="wctre-full">';
			echo '<option value="repeat" ' . selected( $s['repeat_order'], 'repeat', false ) . '>每列重複顯示</option>';
			echo '<option value="first" ' . selected( $s['repeat_order'], 'first', false ) . '>只在第一列顯示</option>';
			echo '</select>';
			echo '<p><label><input type="checkbox" name="text_guard" value="1" ' . checked( ! empty( $s['text_guard'] ), true, false ) . '> 電話、SKU 與長數字加入文字保護前綴</label></p>';
			echo '<p class="wctre-hint">CSV 無法指定儲存格格式，文字保護採單引號前綴，部分 Excel 開啟方式會顯示單引號。</p></div>';

			// 送出
			echo '<div class="wctre-card wctre-submit">';
			echo '<button type="submit" name="operation" value="count" class="button button-secondary button-large">檢查符合訂單數</button>';
			echo '<button type="submit" name="operation" value="export" class="button button-primary button-large">儲存設定並匯出 CSV</button>';
			echo '<div><button type="submit" name="operation" value="save" class="button">僅儲存設定</button> ';
			echo '<button type="submit" name="operation" value="reset" class="button" onclick="return confirm(\'還原預設設定？日期將改為全部期間，欄位恢復常用項目。\');">還原預設</button></div>';
			echo '</div>';

			echo '</div><div class="wctre-card">';

			// 3. 欄位
			echo '<h2>3. 匯出欄位</h2>';
			echo '<div class="wctre-add">';
			echo '<strong>新增欄位</strong>';
			echo '<select id="wctre-add-select" class="wctre-add-select"></select>';
			echo '<button type="button" class="button button-primary" id="wctre-add-btn">加入欄位</button>';
			echo '<button type="button" class="button" id="wctre-rescan">重新偵測訂單欄位</button>';
			echo '<span id="wctre-scan-status" class="wctre-hint">準備偵測中⋯</span>';
			echo '<details><summary>找不到欄位？手動輸入 Meta Key</summary>';
			echo '<div class="wctre-manual">';
			echo '<select id="wctre-manual-scope"><option value="order">訂單 Meta</option><option value="item">商品項目 Meta</option><option value="shipping">運送項目 Meta</option></select>';
			echo '<input type="text" id="wctre-manual-key" placeholder="Meta Key，如 _billing_tax_id">';
			echo '<input type="text" id="wctre-manual-label" placeholder="匯出欄名（可留空，自動建議）">';
			echo '<button type="button" class="button" id="wctre-manual-add">加入</button>';
			echo '</div></details></div>';

			echo '<p><button type="button" class="button" id="wctre-fields-all">全選欄位</button> <button type="button" class="button" id="wctre-fields-none">全不選</button></p>';
			echo '<div class="wctre-table-wrap"><table class="widefat striped"><thead><tr><th>匯出</th><th>來源</th><th>匯出欄名</th><th>排序／移除</th></tr></thead><tbody id="wctre-fields">';

			foreach ( $s['fields'] as $key => $field ) {
				self::field_row( $key, $field, $definitions );
			}

			echo '</tbody></table></div>';
			echo '<p class="wctre-hint">偵測只會讀取符合目前日期與狀態的最近 ' . (int) self::SCAN_SIZE . ' 筆訂單。商品成交金額取自訂單；退款為截至匯出時的累計退款。</p>';
			echo '</div></div>'; // 結束欄位卡片與雙欄設定區

			// 預覽不提交表單、不儲存設定、不下載。
			echo '<div class="wctre-card wctre-preview-card"><h2>匯出前預覽</h2><button type="button" id="wctre-preview-btn" class="button button-secondary button-large">預覽前 30 列</button><p class="wctre-hint">依目前表單與欄位排序預覽，訂單依 ID 由新到舊。最多顯示 30 列，可能在同一張訂單的商品中途截斷；樣本不代表全部資料。文字保護前綴與儲存格換行會如實顯示。預覽與匯出各自即時讀取，期間資料異動可能造成差異。</p><p id="wctre-preview-status" role="status" aria-live="polite">尚未預覽。</p><div id="wctre-preview-result" aria-busy="false"></div></div>';

			echo '</form></div>';

			self::print_assets( $definitions );
			wutm_admin_page_close();
		}

		private static function print_assets( $definitions ) {
			echo '<style>
				.wctre-v3{max-width:1500px}
                .wctre-preview-card{width:100%;box-sizing:border-box;min-width:0}
                .wctre-preview-scroll{overflow:auto;max-height:650px;border:1px solid #dcdcde;margin-top:12px}
                .wctre-preview-scroll table{border-collapse:collapse;width:100%}
                .wctre-preview-scroll th,.wctre-preview-scroll td{padding:8px;border:1px solid #dcdcde;white-space:pre-wrap;overflow-wrap:anywhere;min-width:120px;max-width:420px;vertical-align:top}
                .wctre-preview-scroll th{position:sticky;top:0;background:#f0f0f1;z-index:1}
                #wctre-preview-status[data-error="1"]{color:#b32d2e}
                .wctre-layout>div{min-width:0}
				.wctre-layout{display:grid;grid-template-columns:370px minmax(0,1fr);gap:20px;align-items:start;margin-top:18px}
				.wctre-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin-bottom:18px}
				.wctre-card h2{margin-top:0;font-size:17px}
				.wctre-hint{color:#646970;font-size:12px}
				.wctre-dates{display:grid;grid-template-columns:1fr 1fr;gap:10px}
				.wctre-dates input{display:block;width:100%;margin-top:6px}
				.wctre-date-buttons{display:flex;flex-wrap:wrap;gap:6px}
				#wctre-period{padding:10px;background:#f0f6fc;border-left:3px solid #2271b1}
				.wctre-statuses{display:grid;grid-template-columns:1fr 1fr;gap:12px}
				.wctre-radio{display:block;font-weight:600;margin-top:16px}
				.wctre-full{width:100%;max-width:100%}
				.wctre-submit{display:flex;flex-direction:column;gap:12px}
				.wctre-add{background:#f6f7f7;border:1px solid #dcdcde;padding:12px;display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:16px}
				.wctre-add>strong{width:100%}
				.wctre-add-select{min-width:360px;max-width:100%}
				.wctre-add details{width:100%;margin-top:6px}
				.wctre-manual{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
				.wctre-table-wrap{overflow:auto}
				.wctre-v3 table td,.wctre-v3 table th{vertical-align:middle}
				.wctre-label{width:100%;min-width:150px}
				.wctre-actions{white-space:nowrap}
				@media(max-width:1100px){.wctre-layout{grid-template-columns:1fr}.wctre-add-select{min-width:0;width:100%}}
			</style>';

			$builtin = array();

			foreach ( $definitions as $key => $field ) {
				$builtin[ $key ] = array( 'label' => $field['label'] );
			}

			$config = array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'previewNonce' => wp_create_nonce( 'wctre_v3_preview' ),
				'nonce'   => wp_create_nonce( 'wctre_v3_scan' ),
				'builtin' => $builtin,
				'today'   => array(
					'y' => (int) wp_date( 'Y' ),
					'm' => (int) wp_date( 'n' ),
					'd' => (int) wp_date( 'j' ),
				),
				'scopes'  => array(
					'order'    => '訂單 Meta',
					'item'     => '商品項目 Meta',
					'shipping' => '運送項目 Meta',
				),
			);

			echo '<script>window.WCTRE_V3=' . wp_json_encode( $config ) . ';</script>';

			$js = <<<'JS'
(function(){
'use strict';
var C = window.WCTRE_V3;
var form = document.getElementById('wctre-form');
var from = document.getElementById('wctre-from');
var to = document.getElementById('wctre-to');
var period = document.getElementById('wctre-period');
var tbody = document.getElementById('wctre-fields');
var select = document.getElementById('wctre-add-select');
var scanStatus = document.getElementById('wctre-scan-status');
var scanned = {fields: [], scanned: 0, done: false};

function pad(n){ return String(n).padStart(2, '0'); }
function fmt(d){ return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

function showPeriod(){
	period.textContent = (!from.value && !to.value)
		? '目前查詢：全部期間，不限制訂單日期。'
		: '目前查詢：' + (from.value || '不限起日') + ' ～ ' + (to.value || '不限迄日');
}

document.querySelectorAll('[data-range]').forEach(function(btn){
	btn.addEventListener('click', function(){
		var t = btn.getAttribute('data-range');
		var y = C.today.y, m = C.today.m, d = C.today.d;
		var today = new Date(y, m - 1, d, 12);
		if (t === 'all') { from.value = ''; to.value = ''; }
		else if (t === 'today') { from.value = fmt(today); to.value = fmt(today); }
		else if (t === 'month') { from.value = fmt(new Date(y, m - 1, 1, 12)); to.value = fmt(today); }
		else if (t === 'lastmonth') { from.value = fmt(new Date(y, m - 2, 1, 12)); to.value = fmt(new Date(y, m - 1, 0, 12)); }
		showPeriod();
	});
});
from.addEventListener('change', showPeriod);
to.addEventListener('change', showPeriod);
showPeriod();

function setChecks(sel, v){ document.querySelectorAll(sel).forEach(function(i){ i.checked = v; }); }
document.getElementById('wctre-status-all').addEventListener('click', function(){ setChecks('input[name="statuses[]"]', true); });
document.getElementById('wctre-status-none').addEventListener('click', function(){ setChecks('input[name="statuses[]"]', false); });
document.getElementById('wctre-fields-all').addEventListener('click', function(){ setChecks('.wctre-field-check', true); });
document.getElementById('wctre-fields-none').addEventListener('click', function(){ setChecks('.wctre-field-check', false); });

/* 目前已在清單內的欄位 */
function currentSet(){
	var set = {};
	tbody.querySelectorAll('tr').forEach(function(tr){
		if (tr.dataset.kind === 'builtin') { set['b:' + tr.dataset.key] = true; }
		else { set['c:' + tr.dataset.scope + '|' + tr.dataset.meta] = true; }
	});
	return set;
}

function makeOption(text, data){
	var o = document.createElement('option');
	o.textContent = text;
	Object.keys(data).forEach(function(k){ o.dataset[k] = data[k]; });
	return o;
}

function makeGroup(label){
	var g = document.createElement('optgroup');
	g.label = label;
	return g;
}

function refreshSelect(){
	var set = currentSet();
	select.innerHTML = '';
	select.appendChild(makeOption('— 請選擇要加入的欄位 —', {kind: ''}));

	var gBuiltin = makeGroup('內建欄位');
	Object.keys(C.builtin).forEach(function(key){
		if (set['b:' + key]) return;
		gBuiltin.appendChild(makeOption(C.builtin[key].label, {kind: 'builtin', key: key, label: C.builtin[key].label}));
	});
	if (gBuiltin.children.length) select.appendChild(gBuiltin);

	var names = {order: '偵測到：訂單自訂欄位', item: '偵測到：商品項目自訂欄位', shipping: '偵測到：運送項目自訂欄位（常見於超商物流）'};
	['order', 'item', 'shipping'].forEach(function(scope){
		var g = makeGroup(names[scope]);
		scanned.fields.forEach(function(f){
			if (f.scope !== scope || set['c:' + f.scope + '|' + f.key]) return;
			var text = (f.recognized ? '★ ' : '') + f.label + '（' + f.key + '）｜範例：' + f.sample + '｜' + f.count + ' 筆訂單有資料';
			g.appendChild(makeOption(text, {kind: 'custom', scope: f.scope, meta: f.key, label: f.label}));
		});
		if (g.children.length) select.appendChild(g);
	});
}

function textInput(name, value, cls){
	var i = document.createElement('input');
	i.type = 'text'; i.name = name; i.value = value;
	if (cls) i.className = cls;
	return i;
}
function hiddenInput(name, value){
	var i = document.createElement('input');
	i.type = 'hidden'; i.name = name; i.value = value;
	return i;
}
function btn(cls, text, title){
	var b = document.createElement('button');
	b.type = 'button'; b.className = 'button ' + cls; b.textContent = text; b.title = title;
	return b;
}

function addRow(cfg){
	var set = currentSet();
	var id, source, tr = document.createElement('tr');

	if (cfg.kind === 'builtin') {
		if (set['b:' + cfg.key]) { alert('這個欄位已經在清單內。'); return false; }
		id = cfg.key;
		source = cfg.label + '（內建）';
		tr.dataset.kind = 'builtin';
		tr.dataset.key = id;
	} else {
		if (set['c:' + cfg.scope + '|' + cfg.meta]) { alert('這個自訂欄位已經在清單內。'); return false; }
		id = 'custom_' + Date.now() + '_' + Math.floor(Math.random() * 100000);
		source = C.scopes[cfg.scope] + '：' + cfg.meta;
		tr.dataset.kind = 'custom';
		tr.dataset.key = id;
		tr.dataset.scope = cfg.scope;
		tr.dataset.meta = cfg.meta;
	}

	var td1 = document.createElement('td');
	td1.appendChild(hiddenInput('field_order[]', id));
	var cb = document.createElement('input');
	cb.type = 'checkbox'; cb.className = 'wctre-field-check';
	cb.name = 'field_enabled[' + id + ']'; cb.value = '1'; cb.checked = true;
	td1.appendChild(cb);
	if (cfg.kind === 'custom') {
		td1.appendChild(hiddenInput('custom_scope[' + id + ']', cfg.scope));
		td1.appendChild(hiddenInput('custom_meta[' + id + ']', cfg.meta));
	}

	var td2 = document.createElement('td');
	td2.textContent = source;

	var td3 = document.createElement('td');
	td3.appendChild(textInput('field_label[' + id + ']', cfg.label, 'wctre-label'));

	var td4 = document.createElement('td');
	td4.className = 'wctre-actions';
	td4.appendChild(btn('wctre-up', '↑', '上移'));
	td4.appendChild(document.createTextNode(' '));
	td4.appendChild(btn('wctre-down', '↓', '下移'));
	td4.appendChild(document.createTextNode(' '));
	td4.appendChild(btn('wctre-remove', '移除', '移除此欄位'));

	tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3); tr.appendChild(td4);
	tbody.appendChild(tr);
	refreshSelect();
	return true;
}

tbody.addEventListener('click', function(e){
	var b = e.target.closest('button');
	if (!b) return;
	var row = b.closest('tr');
	if (!row) return;
	if (b.classList.contains('wctre-up') && row.previousElementSibling) {
		tbody.insertBefore(row, row.previousElementSibling);
	} else if (b.classList.contains('wctre-down') && row.nextElementSibling) {
		tbody.insertBefore(row.nextElementSibling, row);
	} else if (b.classList.contains('wctre-remove')) {
		row.remove();
		refreshSelect();
	}
});

document.getElementById('wctre-add-btn').addEventListener('click', function(){
	var o = select.options[select.selectedIndex];
	if (!o || !o.dataset.kind) { alert('請先從下拉選單選擇要加入的欄位。'); return; }
	var ok = addRow({kind: o.dataset.kind, key: o.dataset.key, scope: o.dataset.scope, meta: o.dataset.meta, label: o.dataset.label});
	if (ok) select.selectedIndex = 0;
});

document.getElementById('wctre-manual-add').addEventListener('click', function(){
	var keyEl = document.getElementById('wctre-manual-key');
	var labelEl = document.getElementById('wctre-manual-label');
	var scope = document.getElementById('wctre-manual-scope').value;
	var key = keyEl.value.trim();
	if (!key) { alert('請輸入 Meta Key。'); keyEl.focus(); return; }
	var known = null;
	scanned.fields.forEach(function(f){ if (f.scope === scope && f.key === key) known = f; });
	var label = labelEl.value.trim() || (known ? known.label : key);
	if (addRow({kind: 'custom', scope: scope, meta: key, label: label})) {
		keyEl.value = ''; labelEl.value = '';
	}
});

/* 自動偵測 */
function scan(){
	scanStatus.textContent = '偵測中⋯';
	var data = new FormData();
	data.append('action', 'wctre_v3_scan');
	data.append('nonce', C.nonce);
	data.append('date_from', from.value);
	data.append('date_to', to.value);
	document.querySelectorAll('input[name="statuses[]"]:checked').forEach(function(i){
		data.append('statuses[]', i.value);
	});

	fetch(C.ajaxUrl, {method: 'POST', credentials: 'same-origin', body: data})
		.then(function(r){ return r.json(); })
		.then(function(res){
			if (!res || !res.success) {
				scanStatus.textContent = (res && res.data && res.data.message) ? res.data.message : '偵測失敗，可手動輸入 Meta Key。';
				scanned = {fields: [], scanned: 0, done: true};
			} else {
				scanned = {fields: res.data.fields, scanned: res.data.scanned, done: true};
				scanStatus.textContent = res.data.scanned
					? '已檢查最近 ' + res.data.scanned + ' 筆訂單，找到 ' + res.data.fields.length + ' 個可加入的自訂欄位。'
					: '目前條件下沒有訂單可偵測，請調整日期或狀態。';
			}
			refreshSelect();
		})
		.catch(function(){
			scanStatus.textContent = '偵測失敗，可手動輸入 Meta Key。';
			scanned.done = true;
			refreshSelect();
		});
}

document.getElementById('wctre-rescan').addEventListener('click', scan);

form.addEventListener('submit', function(e){
	var op = e.submitter ? e.submitter.value : '';
	if (op === 'reset') return;

	if (from.value && to.value && from.value > to.value) {
		e.preventDefault(); alert('開始日期不能晚於結束日期。'); return;
	}
	if (op === 'count' || op === 'export') {
		if (!document.querySelector('input[name="statuses[]"]:checked')) {
			e.preventDefault(); alert('請至少勾選一個訂單狀態。'); return;
		}
	}
	if (op === 'export') {
		if (!document.querySelector('.wctre-field-check:checked')) {
			e.preventDefault(); alert('請至少勾選一個匯出欄位。'); return;
		}
	}
});


/* 匯出前預覽：只用 textContent 顯示訂單資料。 */
var previewBtn = document.getElementById('wctre-preview-btn');
var previewStatus = document.getElementById('wctre-preview-status');
var previewResult = document.getElementById('wctre-preview-result');
var previewVersion = 0, previewController = null, previewUsed = false;
function invalidatePreview(){
    previewVersion++;
    if (previewController) previewController.abort();
    previewController = null;
    previewBtn.disabled = false;
    previewResult.setAttribute('aria-busy', 'false');
    if (previewUsed) {
        previewResult.replaceChildren();
        previewStatus.dataset.error = '0';
        previewStatus.textContent = '設定已變更，請重新預覽。';
    }
}
form.addEventListener('input', invalidatePreview);
form.addEventListener('change', invalidatePreview);
form.addEventListener('click', function(e){
    if (e.target.closest('[data-range],#wctre-status-all,#wctre-status-none,#wctre-fields-all,#wctre-fields-none')) invalidatePreview();
});
new MutationObserver(invalidatePreview).observe(tbody, {childList:true});
previewBtn.addEventListener('click', function(){
    invalidatePreview();
    previewUsed = true;
    var version = previewVersion;
    var controller = new AbortController();
    previewController = controller;
    var data = new FormData(form);
    data.set('action', 'wctre_v3_preview');
    data.set('nonce', C.previewNonce);
    previewBtn.disabled = true;
    previewResult.replaceChildren();
    previewResult.setAttribute('aria-busy', 'true');
    previewStatus.dataset.error = '0';
    previewStatus.textContent = '正在讀取前 30 列實際匯出資料⋯';
    var timedOut = false;
    var timer = setTimeout(function(){ timedOut = true; controller.abort(); }, 45000);
    fetch(C.ajaxUrl, {method:'POST', credentials:'same-origin', cache:'no-store', body:data, signal:controller.signal})
    .then(function(r){ return r.json(); })
    .then(function(res){
        if (version !== previewVersion) return;
        if (!res || !res.success) throw new Error(res && res.data && res.data.message || '預覽失敗，請重新整理後再試。');
        var d = res.data;
        previewStatus.textContent = d.period + '｜狀態：' + d.statuses.join('、') + '｜' + d.mode + '｜' + (d.rows.length ? '顯示 ' + d.rows.length + ' 列' + (d.limited ? '（限定樣本，非完整報表）' : '（目前條件下全部列）') : '沒有符合條件的資料。');
        if (!d.rows.length) return;
        var wrap = document.createElement('div');
        wrap.className = 'wctre-preview-scroll';
        wrap.tabIndex = 0;
        wrap.setAttribute('role', 'region');
        wrap.setAttribute('aria-label', 'CSV 預覽，可左右及上下捲動');
        var table = document.createElement('table');
        var caption = document.createElement('caption');
        caption.textContent = 'CSV 匯出樣本（欄序與儲存格內容與匯出相同）';
        table.appendChild(caption);
        var head = table.createTHead().insertRow();
        d.headers.forEach(function(value){ var th=document.createElement('th'); th.scope='col'; th.textContent=value; head.appendChild(th); });
        var body = table.createTBody();
        d.rows.forEach(function(row){ var tr=body.insertRow(); row.forEach(function(value){ var td=tr.insertCell(); td.textContent=value == null ? '' : String(value); }); });
        wrap.appendChild(table);
        previewResult.appendChild(wrap);
    })
    .catch(function(err){
        if (version !== previewVersion) return;
        previewStatus.dataset.error = '1';
        previewStatus.textContent = timedOut ? '預覽逾時，請縮小日期範圍後重試。' : (err.name === 'AbortError' ? '預覽已取消。' : (err instanceof SyntaxError ? '伺服器回應格式異常，請重新整理並檢查網站錯誤紀錄。' : err.message));
    })
    .finally(function(){
        clearTimeout(timer);
        if (version !== previewVersion) return;
        previewController = null;
        previewBtn.disabled = false;
        previewResult.setAttribute('aria-busy', 'false');
    });
});

refreshSelect();
scan();
})();
JS;

			echo '<script>' . $js . '</script>';
		}
	}

	WUTM_Order_Report_Export_V3::init();
}
