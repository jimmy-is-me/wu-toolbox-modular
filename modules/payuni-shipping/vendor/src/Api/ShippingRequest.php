<?php

namespace WuDigital\Payuni\Shipping\Api;

use WuDigital\Payuni\Shipping\PayuniShipping;
use WuDigital\Payuni\Shipping\Utils\LgsType;
use WuDigital\Payuni\Shipping\Utils\ShipType;
use WuDigital\Payuni\Shipping\Utils\OrderMeta;
use WuDigital\Payuni\Shipping\Utils\SingletonTrait;

/**
 * ShippingRequest class file.
 *
 * @package wu-payuni-shipping
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handling PAYUNi shipping request api calls.
 */
class ShippingRequest {

	use SingletonTrait;

	/**
	 * Initialize the class andd add hooks.
	 */
	public static function init() {

		self::get_instance();

		add_action( 'woocommerce_order_status_processing', array( self::get_instance(), 'payuni_create_shipping' ), 10, 1 );
		
		add_action( 'wp_ajax_update_delivery_status', array( self::get_instance(), 'payuni_ajax_query_delivery_status' ), 10, 1 );

		add_action( 'wp_ajax_payuni_shipping_print_label', array( self::get_instance(), 'payuni_print_label' ) );
		add_action( 'wp_ajax_payuni_shipping_download_label', array( self::get_instance(), 'payuni_download_label' ) );

	}

	/**
	 * Create PAYUNi shipping order when order status changed to processing.
	 *
	 *  @param int|\WC_Order $order Order instance or ID.
	 * @return void
	 */
	public static function payuni_create_shipping( $order = 0 ) {
		\wutm_ps_create($order, [self::class, 'perform_create_shipping']);
	}

	public static function perform_create_shipping( $order = 0 ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

        if ( ! $order ) {
            return;
        }

		if ( ! self::can_create_order( $order ) || $order->get_meta( OrderMeta::ShipTradeNo ) ) {
			return;
		}

		try {

			$response = self::create_order( $order );
			$resp_obj = json_decode( wp_remote_retrieve_body( $response ) );
            if ( ! is_object( $resp_obj ) || empty( $resp_obj->EncryptInfo ) ) {
                throw new \Exception( 'Invalid shipping response.' );
            }
			PayuniShipping::log( 'PAYUNi shipping order ' . $order->get_id() . ' response:' . wc_print_r( $resp_obj, true ) );

            $resp_info = PayuniShipping::decrypt( $resp_obj->EncryptInfo );
            PayuniShipping::log( 'PAYUNi shipping order ' . $order->get_id() . ' response decrypted:' . wc_print_r( $resp_info, true ) );

            if ( 'SUCCESS' !== ( $resp_info['Status'] ?? '' ) ) {
				$order->add_order_note( __( 'Create shipping order failed.', 'wu-payuni-shipping' ) . ' ' . wc_print_r( $resp_info, true ) );
				throw new \Exception( $resp_info['Message'] ?? 'Invalid shipping response.' );
			}

			if ( empty( $resp_info['ShipTradeNo'] ) ) { throw new \Exception( 'Missing shipping trade number.' ); }
			$order->update_meta_data( OrderMeta::ShipTradeNo, $resp_info['ShipTradeNo'] );// UNi物流序號.	
            $order->save();

			$order->add_order_note( __( 'Create shipping order successed. ShipTradeNo: ', 'wu-payuni-shipping' ) . $resp_info['ShipTradeNo'] );

			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} catch ( \Exception $e ) {
			PayuniShipping::log( __( 'Create shipping order failed.', 'wu-payuni-shipping' ) . ' ' . $e->getMessage(), 'error' );
		}
	}

	/**
	 * Handling query status ajax call.
	 *
	 * @return void
	 */
	public static function payuni_ajax_query_delivery_status() {

		if ( ! check_ajax_referer( 'payuni-shipping-order', 'security', false ) ) {
			$return = array(
				'success' => false,
				'result'  => __( 'Invalid security token sent.', 'wu-payuni-shipping' ),
			);
			wp_send_json( $return );
			wp_die();
		}

		if ( ! isset( $_POST['post_id'] ) ) {
			wp_send_json_error( __( 'Missing Ajax Parameter.', 'wu-payuni-shipping' ) );
			wp_die();
		}

		$post_id = wc_clean( wp_unslash( $_POST['post_id'] ) );
		$order   = wc_get_order( absint( $post_id ) );
        if ( ! $order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
            wp_send_json_error( 'Forbidden', 403 );
            return;
        }

		$response = self::query_order( $order );

		if ( is_wp_error( $response ) ) {
			$return = array(
				'success' => false,
				'result'  => $response->get_error_message(),
			);
			wp_send_json( $return );
			wp_die();
		}

		$resp_obj = json_decode( wp_remote_retrieve_body( $response ) );
            if ( ! is_object( $resp_obj ) || empty( $resp_obj->EncryptInfo ) ) {
                wp_send_json_error( 'Invalid shipping response.', 502 );
                return;
            }

        $resp_info = PayuniShipping::decrypt( $resp_obj->EncryptInfo );
        PayuniShipping::log( 'PAYUNi QUERY shipping order ' . $order->get_id() . ' response decrypted:' . wc_print_r( $resp_info, true ) );

		$order->add_order_note(
			__( 'Query shipping order status.', 'wu-payuni-shipping' ) . ' ' . wc_print_r( $resp_info, true ) );

		if ( 'SUCCESS' !== ( $resp_info['Status'] ?? '' ) ) {
			$return = array(
				'success' => false,
				'result'  => $resp_info['Message'],
			);
			wp_send_json( $return );
			wp_die();
		}

		if (($resp_info['ShipTradeNo'] ?? '') !== $order->get_meta(OrderMeta::ShipTradeNo)) {
			wp_send_json_error('物流查詢結果與訂單不符。', 502); return;
		}
		self::update_order_logistic_meta( $order, $resp_info );

		$return = array(
			'success' => true,
			'result'  => $resp_info,
		);
		wp_send_json( $return );
	}

	/**
	 * Print shipping label
	 */
	public static function payuni_print_label() {
        check_ajax_referer( 'payuni-shipping-order', 'security' );
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( 'Forbidden', '', array( 'response' => 403 ) );
        }
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['orderids'] ) || ! isset( $_GET['service'] ) ) {
			esc_html_e( 'Missing Ajax Parameter.', 'wu-payuni-shipping' );
			wp_die();
		}

		$order_ids = wc_clean( wp_unslash( $_GET['orderids'] ) );
		$service   = wc_clean( wp_unslash( $_GET['service'] ) );
		
		$api_url = '';
		if ( ShipType::SEVEN === $service ) {
			$api_url = PayuniShipping::$api_url . '/logistics/print_label';
		} elseif ( ShipType::TCAT === $service ) {
			$api_url = PayuniShipping::$api_url . '/home_delivery/get_obt_number_pdf';
		} else {
			esc_html_e( 'Unsupported shipping service.', 'wu-payuni-shipping' );
			wp_die();
		}

		$ship_trade_nos = array();
		$order_ids      = explode( ',', $order_ids );
			
		//暫時只取第一筆
		$orders = wc_get_orders( array( 'include' => $order_ids[0], 'limit' => -1 ) );

		if ( empty( $orders ) ) {
			esc_html_e( 'No such Orders', 'wu-payuni-shipping' );
			wp_die();
		}

		$order = $orders[0];
		$goods_type = $order->get_meta( OrderMeta::GoodsType );
		// foreach ( $orders as $order ) {
			$ship_trade_no = $order->get_meta( OrderMeta::ShipTradeNo );
			if ( $ship_trade_no ) {
				$ship_trade_nos[] = $ship_trade_no;
			}
		// }
		
		$ship_trade_nos_str = implode( ',', $ship_trade_nos );

		// Dates use Taipei without changing the entire request's timezone.


		if ( $service === ShipType::SEVEN ) {

			// SEVEN
			$print_request_args = array(
				'MerID'  => PayuniShipping::get_mer_id(),
				'Timestamp' => time(),
				'ShipTradeNo' => $ship_trade_nos_str,
				'GoodsType' => $goods_type,
				'LgsType' => LgsType::C2C,
				'ShipType' => ShipType::SEVEN,
				'ShipDate' => \wutm_ps_date('+1 day'),
			);

			PayuniShipping::log( 'label request args:' . wc_print_r( $print_request_args, true ) );

			$encrypted_args = PayuniShipping::encrypt( $print_request_args );

			$request_arggs = array(
				'MerID'      => PayuniShipping::get_mer_id(),
				'Version'    => '1.0',
				'EncryptInfo' => $encrypted_args,
				'HashInfo'   => PayuniShipping::hash_info( $encrypted_args ),
			);

			?>
			<html>
				<?php wp_head(); ?>
				<body>
					<script>
						(function ($) {
							'use strict';

							$( document ).ready(function() {

								var label_request_args = <?php echo json_encode( $request_arggs ); ?>;

								var html = '<form id="payuni-c2c-label-form" action="<?php echo $api_url; ?>" method="post">';

								for (const [key, value] of Object.entries(label_request_args) ) {
									html += '<input type="hidden" name="' + key + '" value="' + value + '">';
								}
								html += '</form>';

								document.body.innerHTML += html;
								document.getElementById('payuni-c2c-label-form').submit();

							});

						})(jQuery);
					</script>
		        </body>
		    </html>

<?php

			wp_die();	

		} elseif ( $service === ShipType::TCAT ) {
			// tcat、seven bulk、family bulk、family frozen and seven frozen.

			$shipping_date          = get_option('payuni_shipping_tcat_estimate_shipping_date','1');
			$delivery_date          = intval( $shipping_date ) + 1;
			$estimate_shipping_date = '+' . $shipping_date . ' day';
			$estimate_delivery_date = '+' . $delivery_date . ' day';
			
			$print_request_args = array(
				'MerID'  => PayuniShipping::get_mer_id(),
				'Timestamp' => time(),
				'PostType' => '1',
				'PrintType' => '1',
				'ShipTradeNo' => $ship_trade_nos_str,
				'GoodsType' => $goods_type,
				'LgsType'  => LgsType::HOME,
				'ShipType' => ShipType::TCAT,
				'ShipDate' => \wutm_ps_date($estimate_shipping_date),
				'DeliveryDate' => \wutm_ps_date($estimate_delivery_date),
				'Spec' => $order->get_meta( OrderMeta::PackageSpec )
			);

			PayuniShipping::log( 'label request args:' . wc_print_r( $print_request_args, true ) );

			$encrypted_args = PayuniShipping::encrypt( $print_request_args );

			$request_arggs = array(
				'MerID'      => PayuniShipping::get_mer_id(),
				'Version'    => '1.0',
				'EncryptInfo' => $encrypted_args,
				'HashInfo'   => PayuniShipping::hash_info( $encrypted_args ),
			);
			?>
			<html>
				<?php wp_head(); ?>
				<body>
					<script>
						(function ($) {
							'use strict';

							$( document ).ready(function() {

								var label_request_args = <?php echo json_encode( $request_arggs ); ?>;

								var html = '<form id="payuni-hd-label-form" action="<?php echo $api_url; ?>" method="post">';

								for (const [key, value] of Object.entries(label_request_args) ) {
									html += '<input type="hidden" name="' + key + '" value="' + value + '">';
								}
								html += '</form>';

								document.body.innerHTML += html;
								document.getElementById('payuni-hd-label-form').submit();

							});

						})(jQuery);
					</script>
		        </body>
		    </html>

            <?php
			wp_die();
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	public static function payuni_download_label() {
        check_ajax_referer( 'payuni-shipping-order', 'security' );
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( 'Forbidden', '', array( 'response' => 403 ) );
        }
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['orderids'] ) || ! isset( $_GET['service'] ) ) {
			esc_html_e( 'Missing Ajax Parameter.', 'wu-payuni-shipping' );
			wp_die();
		}
		$order_ids = wc_clean( wp_unslash( $_GET['orderids'] ) );
		$service   = wc_clean( wp_unslash( $_GET['service'] ) );

		if ( ShipType::TCAT !== $service ) {
			esc_html_e( 'Unsupported Ship Type', 'wu-payuni-shipping' );
			wp_die();
		}
		

		$api_url = PayuniShipping::$api_url . '/home_delivery/download_pdf';
		
		//暫時只取第一筆
		$order = wc_get_order( $order_ids );

		if ( ! $order ) {
			esc_html_e( 'Cant find Order.', 'wu-payuni-shipping' );
			wp_die();
		}

		$ship_trade_no = $order->get_meta( OrderMeta::ShipTradeNo );
		$file_no       = $order->get_meta( OrderMeta::FileNo );

		$download_request_args = array(
			'MerID'       => PayuniShipping::get_mer_id(),
			'Timestamp'   => time(),
			'FileNo'      => $file_no,
			'ShipTradeNo' => $ship_trade_no,
		);

		PayuniShipping::log( 'label download request args:' . wc_print_r( $download_request_args, true ) );

		$encrypted_args = PayuniShipping::encrypt( $download_request_args );

		$response = wp_remote_post(
			$api_url,
			array(
				'timeout'     => 45,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'User-Agent'   => 'WordPress',
				),
				'body'        => array(
					'MerID'      => PayuniShipping::get_mer_id(),
					'Version'    => '1.0',
					'EncryptInfo' => $encrypted_args,
					'HashInfo'   => PayuniShipping::hash_info( $encrypted_args ),
				),
			)
		);
		PayuniShipping::log( 'label:' . wc_print_r( $response, true ) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$header = ['content-type'=>(string)wp_remote_retrieve_header($response, 'content-type')];
		$data           = wp_remote_retrieve_body( $response );
		if ( array_key_exists( 'content-type', $header ) ) {
			if ( strpos(strtolower($header['content-type']), 'application/pdf') === 0 ) {
				header( 'Content-type: application/pdf' );
				header( 'Content-disposition: inline;filename=payuni-tcat-' . date( 'Y-m-d' ) . '.pdf' );
				echo $data;
				wp_die();
			} else {
				wp_die('物流標籤尚未取得，請先查詢物流單狀態。', '', ['response'=>502]);
			}
		}
	
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * PAYUNi add order api call.
	 *
	 * @param  \WC_Order $order The order object.
	 * @return \WP_Error|array
	 * @throws \Exception Throws exception when create order failed.
	 */
    public static function can_create_order( $order ) {
        if ( ! $order instanceof \WC_Order ) {
            return false;
        }
        $type = (string) $order->get_meta( OrderMeta::ShipType );
        if ( ShipType::SEVEN === $type ) {
            return 'yes' === get_option( 'payuni_shipping_711_create_order_enabled', 'no' );
        }
        return ShipType::TCAT === $type;
    }
	public static function create_order( $order ) {
        if ( ! self::can_create_order( $order ) || $order->get_meta( OrderMeta::ShipTradeNo ) ) {
            throw new \Exception( 'Shipping order creation is unavailable.' );
        }

		$request_args   = self::build_request_args( $order );
		PayuniShipping::log( 'Create PAYUNi shipping order request args:' . wc_print_r( $request_args, true ), 'info' );
		$encrypted_args = PayuniShipping::encrypt( $request_args );
		$url            = self::get_api_url( $order, 'trade' );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 45,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'User-Agent'   => 'WordPress',
				),
				'body'        => array(
					'MerID' => PayuniShipping::get_mer_id(),
                    'Version' => self::get_api_version( $order, 'trade' ),
                    'EncryptInfo' => $encrypted_args,
                    'HashInfo' => PayuniShipping::hash_info( $encrypted_args ),
				),
			)
		);

		PayuniShipping::log( 'PAYUNi CREATE shipping order response:' . wc_print_r( $response, true ), 'info' );

		if ( is_wp_error( $response ) ) {
			$order->add_order_note( __( 'Create shipping order failed.', 'wu-payuni-shipping' ) . ' ' . $response->get_error_message() );
			PayuniShipping::log( 'Create PAYUNi shipping order:' . wc_print_r( $response, true ), 'error' );
			throw new \Exception( $response->get_error_message() );
		}

		return $response;

	}

	/**
	 * PAYUNi query order api call.
	 *
	 * @param \WC_Order $order The order object.
	 * @return \WP_Error|array
	 */
	public static function query_order( \WC_Order $order ) {

		$request_args   = self::build_request_args( $order, 'query' );
		PayuniShipping::log( 'PAYUNi query shipping order request args:' . wc_print_r( $request_args, true ), 'info' );
		$encrypted_args = PayuniShipping::encrypt( $request_args );
		$url            = self::get_api_url( $order, 'query' );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 45,
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'User-Agent'   => 'WordPress',
				),
				'body'        => array(
					'MerID' => PayuniShipping::get_mer_id(),
                    'Version' => self::get_api_version( $order, 'query' ),
                    'EncryptInfo' => $encrypted_args,
                    'HashInfo' => PayuniShipping::hash_info( $encrypted_args ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$order->add_order_note( __( 'Query shipping order failed.', 'wu-payuni-shipping' ) . ' ' . $response->get_error_message() );
			PayuniShipping::log( 'Query PAYUNi shipping order:' . wc_print_r( $response, true ), 'error' );
			throw new \Exception( $response->get_error_message() );
		}

		return $response;
	}

	/**
	 * Build API call needed arguments.
	 *
	 * @param \WC_Order $order The order object.
	 * @return array
	 */
	private static function build_request_args( \WC_Order $order, $action = 'trade' ) {

		$args = array();

		$args['MerID']            = PayuniShipping::get_mer_id();
        $args['Timestamp']        = time();

		if ( $action === 'trade' ) {

			$args['MerTradeNo']       = self::get_prefixed_order_no( $order );
			$args['GoodsType']        = $order->get_meta( OrderMeta::GoodsType );//溫層
			$args['LgsType']          = $order->get_meta( OrderMeta::LgsType );//運送方式
			$args['ShipType']         = $order->get_meta( OrderMeta::ShipType ); //1=Seven
			$args['TradeAmt']         = $order->get_total();// 超取金額不得大於20,000，宅配宅配金額不大於100,000.
			$args['ServiceType']      = ( 'cod' === $order->get_payment_method() ) ? '1' : '3';// 1:取貨付款 3:取貨不付款.
			$args['StoreID']          = $order->get_meta( OrderMeta::StoreId );

			$args['Consignee']        = $order->get_shipping_last_name() . $order->get_shipping_first_name();
			$args['ConsigneeMail']    = $order->get_billing_email();
			$args['ConsigneeMobile']  = PayuniShipping::payuni_get_shipping_phone( $order );
			
			$args['RefundStoreID']    = '';
			$args['SenderName']       = get_option( 'payuni_shipping_sender_name' );
			$args['SenderMobile']     = get_option( 'payuni_shipping_sender_phone' );

			if ( $order->get_meta( OrderMeta::ShipType ) === ShipType::TCAT ) {
				$args['StoreID']    = '';
				$args['DeliveryTimeTag'] = PayuniShipping::get_tcat_delivery_time();
				$args['ConsigneeAddress'] = self::payuni_get_api_order_address( $order );
				$args['ProdDesc']         = self::get_items_infos( $order );
				$args['NotifyURL']        = wc()->api_request_url( 'payuni_shipping_tcat_notify' );
			} else {
				$args['NotifyURL']        = wc()->api_request_url( 'payuni_shipping_711_notify' );
			}

		} elseif ( $action === 'query' ) {
			
			$args['LgsType']     = $order->get_meta( OrderMeta::LgsType );
			$args['ShipTradeNo'] = $order->get_meta( OrderMeta::ShipTradeNo );
			
		}

		return apply_filters( 'payuni_shipping_order_request_args', $args, $order );
	}

	/**
	 * Update order logistic meta_box_prefs( $screen:WP_Screen )
	 *
	 * @param \WC_Order $order The order object.
	 * @param array   $resp_obj The PAYUNi query order response object.
	 * @return void
	 */
	private static function update_order_logistic_meta( $order, $resp_obj ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$order->update_meta_data( OrderMeta::ShipTradeNo, $resp_obj['ShipTradeNo'] ); // UNi物流序號 .
		
		if ( array_key_exists('Odno', $resp_obj )) {
			$order->update_meta_data( OrderMeta::Odno, $resp_obj['Odno'] ); // 物流單序號.
		}

		if ( array_key_exists('PartnerId', $resp_obj )) {
			$order->update_meta_data( OrderMeta::PartnerId, $resp_obj['PartnerId'] );
		}

		if ( array_key_exists('ValidationNo', $resp_obj )) {
			$order->update_meta_data( OrderMeta::ValidationNo, $resp_obj['ValidationNo'] );
			$order->update_meta_data( OrderMeta::ShipNo, $resp_obj['Odno'].$resp_obj['ValidationNo']);
		}

		//黑貓
		if ( array_key_exists( 'FileNo', $resp_obj )) {
			$order->update_meta_data( OrderMeta::FileNo, $resp_obj['FileNo'] ); // 物流單序號.
		}
		
		$order->update_meta_data( OrderMeta::ShipStatus, $resp_obj['ShipStatus'] ); // 流程狀態描述.
		$order->update_meta_data( OrderMeta::ShipStatusDesc, $resp_obj['ShipStatusDesc'] ); // 物流代碼詳細資訊.
		$order->update_meta_data( OrderMeta::ShipStatusTime, $resp_obj['ShipStatusTime'] );

		$order->save();
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Build order address for api call.
	 *
	 * @param \WC_Order $order The order object.
	 * @return string
	 */
	private static function payuni_get_api_order_address( $order ) {
		$address  = '';
		$address .= $order->get_shipping_state();
		$address .= $order->get_shipping_city();
		$address .= $order->get_shipping_address_1();
		$address .= $order->get_shipping_address_2();
		return $address;
	}

	/**
	 * Get order number with prefix. Allow developer to use filter to change the prefix. Defaut prefix is empty.
	 *
	 * @param \WC_Order $order The order object.
	 * @return string
	 */
	private static function get_prefixed_order_no( $order ) {
		$prefix = apply_filters( 'payuni_shipping_order_prefix', '' );
		return $prefix . $order->get_order_number();
	}

	private static function get_api_url( $order, $action ) {
		$api_url = '';

		if ( $action === 'query' ) {
			$api_url = PayuniShipping::$api_url . '/logistics/' . $action;
			return $api_url;
		}

		if ( ! empty( $order->get_meta( OrderMeta::ShipType ) ) && $order->get_meta( OrderMeta::ShipType ) !== ShipType::TCAT ) {
			$api_url = PayuniShipping::$api_url . '/logistics/' . $action;
		} else {
			$api_url = PayuniShipping::$api_url . '/home_delivery/' . $action;
		}
		return $api_url;
	}

	private static function get_api_version( $order, $action ) {
		if ( $action === 'query' ) {
			return '1.1';
		}

		if ( ! empty( $order->get_meta( OrderMeta::ShipType ) ) && $order->get_meta( OrderMeta::ShipType ) !== ShipType::TCAT ) {
			return '1.1';
		} else {
			return '1.0';
		}
	}

	/**
	 * Build items as string
	 *
	 * @param \WC_Order $order The order object.
	 * @return string
	 */
	private static function get_items_infos( $order ) {
		$items     = $order->get_items();
		$item_name = '';
		foreach ( $items as $item ) {
			$item_name .= $item['name'];
			if ( end( $items )['name'] !== $item['name'] ) {
				$item_name .= ',';
			}
		}

		$cleaned_item_name = mb_substr( $item_name, 0, 20 );
		return $cleaned_item_name;
	}

}
