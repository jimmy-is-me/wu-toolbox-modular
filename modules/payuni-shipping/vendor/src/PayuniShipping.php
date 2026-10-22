<?php

namespace WuDigital\Payuni\Shipping;

use Automattic\Jetpack\Constants;
use WuDigital\Payuni\Shipping\Utils\SingletonTrait;
use WuDigital\Payuni\Shipping\Utils\ShipType;
use WuDigital\Payuni\Shipping\Utils\GoodsType;
use WuDigital\Payuni\Shipping\Utils\LgsType;
use WuDigital\Payuni\Shipping\Utils\OrderMeta;

/**
 * PayuniShipping class file.
 *
 * @package wu-payuni-shipping
 */

defined( 'ABSPATH' ) || exit;

/**
 * PayuniShipping main class for handling all checkout related process.
 */
class PayuniShipping {

	use SingletonTrait;

	/**
	 * Whether or not logging is enabled.
	 *
	 * @var boolean
	 */
	public static $log_enabled = false;

	/**
	 * WC_Logger instance.
	 *
	 * @var WC_Logger Logger instance
	 * */
	public static $log = false;

	/**
	 * PAYUNi shipping merchant ID.
	 *
	 * @var string
	 */
	public static $mer_id;

	/**
	 * PAYUNi shipping hash key.
	 *
	 * @var string
	 */
	public static $hashkey;

	/**
	 * PAYUNi shipping hash iv.
	 *
	 * @var string
	 */
	public static $hashiv;

	/**
	 * Whether or not test mode is enabled.
	 *
	 * @var boolean
	 */
	public static $testmode;

	/**
	 * The API url for PAYUNi api calls.
	 *
	 * @var string
	 */
	public static $api_url;

	/**
	 * The shipping status update callback url.
	 *
	 * @var string
	 */
	public static $shipping_status_url;


	/**
	 * Change to this order status when product is ready to ship.
	 * @var string
	 */
	public static $order_status_ready_shipping;

	/**
	 * Change to this order status when product is located at logistic center.
	 * @var string
	 */
	public static $order_status_at_logistic_center;

	/**
	 * Change to this order status when product is delivering
	 * @var string
	 */
	public static $order_status_delivering;

	/**
	 * Change to this order status when product is located at receiver's cvs.
	 *
	 * @var string
	 */
	public static $order_status_at_receiver_cvs;

	/**
	 * Change to this order status when product is picked up by customer.
	 * @var string
	 */
	public static $order_status_pickuped;

	private static $tcat_delivery_time;

	/**
	 * The shipping data saved in javascript array.
	 *
	 * @var array
	 */
	protected static $js_data;

	/**
	 * CVS shipping methods
	 *
	 * @var array
	 */
	public static $cvs_methods = array(
		'payuni_shipping_711_c2c_normal'           => 'WuDigital\Payuni\Shipping\Providers\SevenEleven\C2CNormal',
		'payuni_shipping_711_c2c_frozen'           => 'WuDigital\Payuni\Shipping\Providers\SevenEleven\C2CFrozen',
	);

	/**
	 * Home delivery methods (TCAT)
	 *
	 * @var array
	 */
	public static $hd_methods = array(
		'payuni_shipping_tcat_normal'       => 'WuDigital\Payuni\Shipping\Providers\TCat\HDNormal',
		'payuni_shipping_tcat_frozen'       => 'WuDigital\Payuni\Shipping\Providers\TCat\HDFrozen',
		'payuni_shipping_tcat_refrigerated' => 'WuDigital\Payuni\Shipping\Providers\TCat\HDRefrigerated',
	);

	/**
	 * Class init function. Hook needed actions and filters.
	 *
	 * @return void
	 */
	public static function init() {

		self::get_instance();

		load_plugin_textdomain( 'wu-payuni-shipping', false, dirname( PAYUNI_SHIPPING_BASENAME ) . '/languages/' );

		self::$mer_id	   = get_option( 'payuni_payment_merchant_id' );
		self::$hashkey     = get_option( 'payuni_payment_hashkey' );
		self::$hashiv      = get_option( 'payuni_payment_hashiv' );

		self::$log_enabled  = wc_string_to_bool( get_option( 'payuni_shipping_debug_log_enabled', 'no' ) );
		self::$testmode     = wc_string_to_bool( get_option( 'payuni_shipping_testmode_enabled', 'yes' ) );
		self::$api_url      = ( self::$testmode ) ? 'https://sandbox-api.payuni.com.tw/api' : 'https://api.payuni.com.tw/api';

		self::$shipping_status_url = add_query_arg( 'wc-api', 'shipping_status_callback', home_url( '/' ) );

		self::$order_status_ready_shipping     = get_option( 'payuni_shipping_order_status_ready_shipping' );
		self::$order_status_at_logistic_center = get_option( 'payuni_shipping_order_status_at_logistic_center' );
		self::$order_status_delivering         = get_option( 'payuni_shipping_order_status_delivering' );
		self::$order_status_at_receiver_cvs    = get_option( 'payuni_shipping_order_status_at_receiver_cvs' );
		self::$order_status_pickuped           = get_option( 'payuni_shipping_order_status_pickuped' );

		self::$tcat_delivery_time              = get_option( 'payuni_shipping_tcat_delivery_time', '04' );

		add_filter( 'plugin_action_links_' . PAYUNI_SHIPPING_BASENAME, array( self::get_instance(), 'payuni_add_action_links' ) );

		// 顯示結帳欄位.
		add_filter( 'woocommerce_checkout_fields', array( self::get_instance(), 'payuni_shpping_cvs_field' ), 9999 );

		// 顯示選擇超商按鈕.
		add_action( 'woocommerce_review_order_after_shipping', array( self::get_instance(), 'payuni_after_shipping_rate' ) );

		// 設定選擇超商 API 所需要的資料.
		add_action( 'woocommerce_review_order_after_shipping', array( self::get_instance(), 'payuni_setup_shipping_info' ) );

		// 當 shipping method 改變時，回傳 cvs 資料.
		add_filter( 'woocommerce_update_order_review_fragments', array( self::get_instance(), 'shipping_choose_cvs_info' ) );

		// 將不必要的運送欄位取消.
		add_action( 'woocommerce_checkout_process', array( self::get_instance(), 'payuni_shipping_fields_validation' ) );

		// 結帳時將超商資料儲存至訂單 meta.
		add_action( 'woocommerce_checkout_create_order', array( self::get_instance(), 'payuni_save_order_shipping_meta' ), 20, 2 );

		// 在結帳頁載入 js.
		add_action( 'wp_enqueue_scripts', array( self::get_instance(), 'payuni_checkout_enqueue_scripts' ), 9 );

		// 改變地址的顯示方式.
		add_filter( 'woocommerce_order_formatted_shipping_address', array( self::get_instance(), 'payuni_raw_shipping_address' ), 10, 2 );
		add_filter( 'woocommerce_localisation_address_formats', array( self::get_instance(), 'payuni_address_format' ) );
		add_filter( 'woocommerce_formatted_address_replacements', array( self::get_instance(), 'payuni_shipping_address_replacements' ), 10, 2 );

		// add store info for google map.
		add_filter( 'woocommerce_shipping_address_map_url_parts', array( self::get_instance(), 'payuni_shipping_address_map' ), 10, 2 );

		add_action( 'woocommerce_order_details_after_order_table', array( self::get_instance(), 'payuni_shipping_detail_after_order_table' ), 10, 1 );

		add_action( 'admin_enqueue_scripts', array( self::get_instance(), 'payuni_enqueue_admin_script' ) );
		add_action( 'woocommerce_thankyou', array( self::get_instance(), 'payuni_thank_you_script' ) );

	}

	/**
	 * 顯示超商運送欄位
	 *
	 * @param array $fields Shipping fields.
	 * @return array Shipping fields
	 */
	public static function payuni_shpping_cvs_field( $fields ) {
		return self::get_shipping_cvs_field( $fields );
	}

	/**
	 * 顯示選擇超商按鈕。僅在結帳頁面且需要超商運送時顯示，黑貓宅配不需要
	 */
	public static function payuni_after_shipping_rate() {
		if (!WC()->session) return;

		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
		$chosen_method           = explode(':', $chosen_shipping_methods[0] ?? '')[0];

		if ( is_checkout() && self::needs_cvs( $chosen_method ) ) {
			wc_get_template( 'cart/choose-cvs.php', array(), '', PAYUNI_SHIPPING_TEMPLATE_DIR );
		}

	}

	/**
	 * 設定 CVS 需要的資料
	 *
	 * @return void
	 */
	public static function payuni_setup_shipping_info() {
		self::$js_data = array();
		if (!WC()->session || !WC()->cart || !\wutm_ps_configured()) return;

		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
		$chosen_method_id        = explode(':', $chosen_shipping_methods[0] ?? '')[0];
		if (!self::is_payuni_shipping($chosen_method_id)) return;

		$encrypt_info =  array(
			'MerID'      => get_option( 'payuni_payment_merchant_id' ),
			'Timestamp'  => time(),
			'MerKeyNo'   => '1234567890',
			'GoodsType'  => $chosen_method_id === 'payuni_shipping_711_c2c_frozen' ? '2' : '1', //1常溫, 2冷凍
			'LgsType'    => 'C2C',
			'ShipType'   => '1',//1=Seven
			'MapType'    => '2',
			'MapReturnURL' => esc_url( WC()->api_request_url( 'payuni_choose_cvs_callback' ) . '?cid=' . WC()->cart->get_cart_hash() ),
			'Tag'        => '2',
			'MobileTag'  => wp_is_mobile()? 'Y' : 'N',
		);

		PayuniShipping::log( 'request encrypt info:' . wc_print_r( $encrypt_info, true ) );

		self::$js_data['shipping_data']['methods']   = $chosen_method_id;
		self::$js_data['shipping_data']['ShipType'] = ShipType::get_ship_type( $chosen_method_id );

		$encrypted_info = PayuniShipping::encrypt( $encrypt_info );

		if ( self::is_payuni_shipping_cvs( $chosen_method_id ) ) {
			self::$js_data['shipping_data']['source']        = 'shipping_choose_cvs';
			self::$js_data['shipping_data']['is_payuni_cvs'] = true;
			self::$js_data['shipping_data']['MerID']         = get_option( 'payuni_payment_merchant_id' );
			self::$js_data['shipping_data']['Version']       = '1.1';
			self::$js_data['shipping_data']['EncryptInfo']   = $encrypted_info;
			self::$js_data['shipping_data']['HashInfo']      = PayuniShipping::hash_info( $encrypted_info );
			self::$js_data['shipping_data']['ajax_url']      = self::$api_url . '/logistics/ship_map';
		}

	}

	/**
	 * Setup shipping info for js, please refer to /assets/js/scripts-public.js
	 *
	 * @param array $fragments The fragments array.
	 * @return array
	 */
	public static function shipping_choose_cvs_info( $fragments ) {
		if (!WC()->session) return $fragments;

		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
		$chosen_method_id        = explode(':', $chosen_shipping_methods[0] ?? '')[0];

		if ( ! empty( self::$js_data ) ) {

			$ship_type = ShipType::get_ship_type( $chosen_method_id );
			$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
			$chosen_method_id        = explode(':', $chosen_shipping_methods[0] ?? '')[0];
			self::log('chosen_method_id:' . $chosen_method_id );

			if ( self::is_payuni_shipping_cvs( $chosen_method_id ) ) {
				self::$js_data['shipping_data']['ShipType'] = $ship_type;
				if ( array_key_exists( 'methods', self::$js_data['shipping_data'] ) ) {
					self::$js_data['shipping_data']['methods'] = $chosen_method_id;
				}
			} elseif ( self::is_payuni_shipping_hd( $chosen_method_id ) ) {
				self::$js_data['shipping_data']              = array();
				self::$js_data['shipping_data']['ShipType'] = $ship_type;
				self::$js_data['shipping_data']['methods']   = $chosen_method_id;
			} else {
				self::$js_data['shipping_data'] = array();
			}

			$fragments['payuni_shipping_info'] = apply_filters( 'payuni_setup_cvs_data', self::$js_data, $chosen_shipping_methods );

		}

		return $fragments;
	}

	/**
	 * 如果運送方式需要超商資訊，檢查是否有選擇超商
	 *
	 * @return void
	 */
	public static function payuni_shipping_fields_validation() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$shipping_methods = isset( $_POST['shipping_method'] ) ? wc_clean( wp_unslash( $_POST['shipping_method'] ) ) : array();

		$need_cvs       = false;
		$need_hd        = false;

		// 是否需要超商資訊.
		PayuniShipping::log( 'shipping_methods:' . wc_print_r( $shipping_methods, true ) );
		foreach ( $shipping_methods as $method ) {
			$method = strstr( $method, ':', true );
			if ( array_key_exists( $method, self::$cvs_methods ) ) {
				$need_cvs = true;
				break;
			}
		}
		

		// 是否需要宅配手機資訊.
		foreach ( $shipping_methods as $method ) {
			$method = strstr( $method, ':', true );
			if ( array_key_exists( $method, self::$hd_methods ) ) {
				$need_hd = true;
				break;
			}
		}

		if (!$need_cvs && !$need_hd) return;
		PayuniShipping::log( 'need_cvs:' . $need_cvs );
		PayuniShipping::log( 'need_hd:' . $need_hd );
		PayuniShipping::log( 'payuni_storeid:' . wc_clean( wp_unslash( $_POST['payuni_storeid'] ?? '' ) ) );
		if ( $need_cvs && empty( wc_clean( wp_unslash( $_POST['payuni_storeid'] ?? '' ) ) ) ) {
			wc_add_notice( __( 'Please select a CVS store.', 'wu-payuni-shipping' ), 'error' );
		}

		$shipping_phone = isset( $_POST['shipping_phone'] ) ? wc_clean( wp_unslash( $_POST['shipping_phone'] ) ) : '';

		if ( $need_cvs || $need_hd ) {
			if ( ! preg_match( '/^[0][1-9]{1,3}[0-9]{6,8}$/', $shipping_phone ) || strlen( $shipping_phone ) < 10 || strlen( $shipping_phone ) > 11 ) {
				wc_add_notice( __( 'Shipping Phone format is invalid', 'wu-payuni-shipping' ), 'error' );
			}
		}

		// avoid empty country error.
		if ( empty( $_POST['shipping_country'] ) ) {
			$_POST['shipping_country'] = 'TW';
		}

		if ( $need_cvs ) {
			add_filter( 'woocommerce_checkout_fields', array( self::get_instance(), 'setup_cvs_shipping_fields_requirements' ), 9999 );
		} else {
			add_filter( 'woocommerce_checkout_fields', array( self::get_instance(), 'setup_hd_shipping_fields_requirements' ), 9999 );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Remove unrequired shipping fields on checkout for Family frozen shipping methods
	 *
	 * @param array $fields The checkout shipping fields.
	 * @return array
	 */
	public static function setup_family_frozen_shipping_fields_requirements( $fields ) {
		$fields['shipping']['shipping_country']['required']   = false;
		$fields['shipping']['shipping_address_1']['required'] = false;
		$fields['shipping']['shipping_address_2']['required'] = false;
		$fields['shipping']['shipping_city']['required']      = false;
		$fields['shipping']['shipping_state']['required']     = false;
		$fields['shipping']['shipping_postcode']['required']  = false;

		$fields['shipping']['shipping_phone']['required']      = true;
		$fields['shipping']['payuni_storeid']['required']      = true;
		$fields['shipping']['payuni_storename']['required']    = true;
		$fields['shipping']['payuni_storeaddress']['required'] = true;

		return $fields;
	}

	/**
	 * Remove unrequired shipping fields on checkout for CVS shipping methods
	 *
	 * @param array $fields The checkout shipping fields.
	 * @return array
	 */
	public static function setup_cvs_shipping_fields_requirements( $fields ) {
		$fields['shipping']['shipping_country']['required']   = false;
		$fields['shipping']['shipping_address_1']['required'] = false;
		$fields['shipping']['shipping_address_2']['required'] = false;
		$fields['shipping']['shipping_city']['required']      = false;
		$fields['shipping']['shipping_state']['required']     = false;
		$fields['shipping']['shipping_postcode']['required']  = false;

		$fields['shipping']['shipping_phone']['required']      = true;
		$fields['shipping']['payuni_storeid']['required']      = true;
		$fields['shipping']['payuni_storename']['required']    = true;
		$fields['shipping']['payuni_storeaddress']['required'] = true;
		
		return $fields;
	}

 /**
 * Get PAYUNi shipping cvs checkout fields.
 *
 * @param array $fields The checkout fields.
 * @return array
 */
public static function get_shipping_cvs_field( $fields ) {
	$existing_phone = $fields['shipping']['shipping_phone'] ?? null;
	$fields['shipping']['shipping_phone']      = array(
		'label'    => __( 'Shipping Phone', 'wu-payuni-shipping' ),
		'required' => true,
		'type'     => 'tel',
		'validate' => array( 'phone' ),
		'class'    => array( 'form-row-wide', 'payuni-shipping-field' ),
		'priority' => 100,
	);
	$fields['shipping']['payuni_shiptype']      = array(
		'required' => false,
		'label'    => __( 'Ship Type', 'wu-payuni-shipping' ),
		'type'     => 'text',
		'class'    => array( 'form-row-wide', 'payuni-shipping-field' ),
	);
	$fields['shipping']['payuni_storename']    = array(
		'required'          => false,
		'label'             => __( 'Store Name', 'wu-payuni-shipping' ),
		'type'              => 'text',
		'custom_attributes' => array(
			'readonly' => true,
		),
		'class'             => array( 'form-row-wide', 'payuni-shipping-field' ),
		'priority'          => 120,
	);
	$fields['shipping']['payuni_storeid']      = array(
		'required'          => false,
		'label'             => __( 'Store ID', 'wu-payuni-shipping' ),
		'type'              => 'text',
		'custom_attributes' => array(
			'readonly' => true,
		),
		'class'             => array( 'form-row-wide', 'payuni-shipping-field' ),
		'priority'          => 121,
	);
	$fields['shipping']['payuni_storeaddress'] = array(
		'required'          => false,
		'label'             => __( 'Store Address', 'wu-payuni-shipping' ),
		'type'              => 'text',
		'custom_attributes' => array(
			'readonly' => true,
		),
		'class'             => array( 'form-row-wide', 'payuni-shipping-field' ),
		'priority'          => 122,
	);

	if ($existing_phone !== null) $fields['shipping']['shipping_phone'] = $existing_phone;
	else $fields['shipping']['shipping_phone']['required'] = false;
	return apply_filters( 'payuni_shipping_cvs_fields', $fields );
}


	/**
	 * If tcat shipping, set required for shipping_phone field.
	 *
	 * @param array $fields The checkout shipping fields.
	 * @return array
	 */
	public static function setup_hd_shipping_fields_requirements( $fields ) {
		$fields['shipping']['shipping_phone']['required'] = true;
		return $fields;
	}

	/**
	 * If not CVS shipping, remove required for shipping_phone field.
	 *
	 * @param array $fields The checkout shipping fields.
	 * @return array
	 */
	public static function remove_shipping_phone_required( $fields ) {
		$fields['shipping']['shipping_phone']['required'] = false;
		return $fields;
	}

	/**
	 * 訂單成立時，儲存超商資訊
	 *
	 * @param \WC_Order $order the order to save shipping info.
	 * @param array    $data the order data.
	 */
	public static function payuni_save_order_shipping_meta( $order, $data ) {
		if (!\wutm_ps_owned($order)) return;

		// 如果沒有超商資訊，則不儲存.
		if ( ! empty( $data['payuni_storeid'] ) ) {

			$order->set_shipping_company( '' );
			$order->set_shipping_address_2( '' );
			$order->set_shipping_city( '' );
			$order->set_shipping_state( '' );
			$order->set_shipping_postcode( '' );

			$order->update_meta_data( OrderMeta::ShipType, $data['payuni_shiptype'] ); // service id.
			$order->update_meta_data( OrderMeta::StoreId, $data['payuni_storeid'] );
			$order->update_meta_data( OrderMeta::StoreName, $data['payuni_storename'] );
			$order->update_meta_data( OrderMeta::StoreAddr, $data['payuni_storeaddress'] );

			// 將 shipping_address_1 設定為超商地址.
			$order->set_shipping_address_1( $data['payuni_storeaddress'] );

			if ( $order->has_shipping_method('payuni_shipping_711_c2c_normal' ) ) {
				$order->update_meta_data( OrderMeta::GoodsType, GoodsType::NORMAL ); //常溫
			} elseif ( $order->has_shipping_method('payuni_shipping_711_c2c_frozen' ) ) {
				$order->update_meta_data( OrderMeta::GoodsType, GoodsType::FROZEN ); //冷凍
			} 

			$order->update_meta_data( OrderMeta::LgsType, 'C2C' ); // 宅配.
			$order->update_meta_data( OrderMeta::ShipType, ShipType::SEVEN );; // 通路類別 1=Seven.

			do_action( 'payuni_shipping_save_cvs_order_meta', $order, $data );

		}

		// 如果是黑貓宅急便，要儲存 payuni_shiptype = 06.
		if ( $order->has_shipping_method( 'payuni_shipping_tcat_normal' ) || $order->has_shipping_method( 'payuni_shipping_tcat_frozen' ) || $order->has_shipping_method( 'payuni_shipping_tcat_refrigerated' ) ) {
			
			$order->update_meta_data( OrderMeta::LgsType, LgsType::HOME ); // 宅配.
			$order->update_meta_data( OrderMeta::ShipType, ShipType::TCAT ); // 通路類別

			// Get the shipping methods for the order
			$shipping_methods = $order->get_shipping_methods();
			$shipping_method_id = '';
			foreach( $shipping_methods as $method ) {
				$shipping_method_id = $method->get_method_id();
				break;
			}

			$shipping_zones = \WC_Shipping_Zones::get_zones();

			$package_spec = '60';
			foreach ($shipping_zones as $shipping_zone) {
				foreach ($shipping_zone['shipping_methods'] as $shipping_method) {
					// Check if the shipping method ID matches
					if ($shipping_method->id === $shipping_method_id) {
						// Access the custom field value
						$package_spec = $shipping_method->get_option('package_spec');
						$order->update_meta_data( OrderMeta::PackageSpec, $package_spec );
						
						// Break out of the loop once found (if you have multiple zones/methods with the same ID)
						break 2;
					}
				}
			}



			if ( $order->has_shipping_method( 'payuni_shipping_tcat_normal' ) ) {
				$order->update_meta_data( OrderMeta::GoodsType, GoodsType::NORMAL ); // 常溫.
			}

			if ( $order->has_shipping_method( 'payuni_shipping_tcat_frozen' ) ) {
				$order->update_meta_data( OrderMeta::GoodsType, GoodsType::FROZEN );// 冷凍.
			}

			if ( $order->has_shipping_method( 'payuni_shipping_tcat_refrigerated' ) ) {
				$order->update_meta_data( OrderMeta::GoodsType, GoodsType::REFRIGERATED ); // 冷藏.
			}
		}

		if ( isset( $data['shipping_phone'] ) ) {
			if ( version_compare(  Constants::get_constant( 'WC_VERSION' ), '5.6.0', '<' ) ) {
				$order->update_meta_data( '_shipping_phone', $data['shipping_phone'] );
			}
		}

		if ($order->get_meta(OrderMeta::ShipType) === ShipType::TCAT) $order->update_meta_data(OrderMeta::PackageSpec, \wutm_ps_package_spec($order));
		$order->save();
	}

	/**
	 * Enqueue JS/CSS on checkout page
	 *
	 * @return void
	 */
	public static function payuni_checkout_enqueue_scripts() {

		if ( ! is_checkout() || !WC()->session || !WC()->cart || !\wutm_ps_configured() ) {
			return;
		}

		wc_setcookie( 'payuni_checkout_url', get_permalink( get_the_ID() ), 0 ); // expire = 0, 使用者關掉瀏覽器時 cookie 才會清空.

		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
		$chosen_method_id        = explode(':', $chosen_shipping_methods[0] ?? '')[0];

		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );

		$encrypt_info =  array(
				'MerID'      => get_option( 'payuni_payment_merchant_id' ),
				'Timestamp'  => time(),
				// 'MerKeyNo'   => Payuni_Payment::build_payuni_order_no( $order->get_id() ),
				'MerKeyNo'   => '1234567890',
				'GoodsType'  => $chosen_method_id === 'payuni_shipping_711_c2c_frozen' ? '2' : '1', //1常溫, 2冷凍
				'LgsType'    => 'C2C',// C2C, B2C
				'ShipType'   => '1',//1=Seven
				'MapType'    => '2',
				'MapReturnURL' => esc_url( WC()->api_request_url( 'payuni_choose_cvs_callback' ) . '?cid=' . WC()->cart->get_cart_hash() ),
				'Tag'        => '2',
				'MobileTag'  => wp_is_mobile()? 'Y' : 'N',
				
			);

		PayuniShipping::log( 'request encrypt info:' . wc_print_r( $encrypt_info, true ) );

		$encrypted_info = PayuniShipping::encrypt( $encrypt_info );

		// @todo need to check if selected shipping method is cvs
		$cvs_data = array(
			'source'             => 'payuni_checkout_enqueue_scripts',
			'methods'            => $chosen_method_id,
			'is_payuni_cvs'      => (bool) self::is_payuni_shipping_cvs($chosen_method_id),
			'MerID'              => get_option( 'payuni_payment_merchant_id' ),
			'Version'            => '1.1',
			'EncryptInfo'        => $encrypted_info,
			'HashInfo'           => PayuniShipping::hash_info( $encrypted_info ),
			'ShipType'           => ShipType::get_ship_type( $chosen_method_id ),
			'returnUrl'          => esc_url( WC()->api_request_url( 'payuni_choose_cvs_callback' ) . '?cid=' . WC()->cart->get_cart_hash() ),
			'ajax_url'           => self::$api_url . '/logistics/ship_map',
		);

		wp_register_script( 'payuni-shipping', WUTM_URL . 'modules/payuni-shipping/assets/checkout.js', array( 'jquery' ), PAYUNI_SHIPPING_VERSION, true );
		wp_localize_script( 'payuni-shipping', 'payuni_shipping_object', $cvs_data );
		wp_enqueue_script( 'payuni-shipping' );

		// wp_enqueue_script( 'payuni-shipping-save-fields', PAYUNI_SHIPPING_PLUGIN_URL . 'assets/js/save-fields.js', array( 'jquery' ), time(), true );

		wp_enqueue_style( 'payuni-shipping', PAYUNI_SHIPPING_PLUGIN_URL . 'assets/css/styles-public.css', array(), time(), 'all' );
	}

	/**
	 * Eneuque admin js
	 *
	 * @return void
	 */
	public static function payuni_enqueue_admin_script() {
		if (!\wutm_ps_admin_screen() || !current_user_can('edit_shop_orders')) return;

		wp_enqueue_style( 'payuni-shipping-admin', PAYUNI_SHIPPING_PLUGIN_URL . 'assets/css/styles-admin.css', array(), time(), 'all' );
		wp_enqueue_script( 'payuni-shipping-admin', PAYUNI_SHIPPING_PLUGIN_URL . 'assets/js/scripts-admin.js', array( 'jquery' ), time(), false );
		wp_localize_script(
			'payuni-shipping-admin',
			'payuni_shipping',
			array(
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'security'     => wp_create_nonce( 'payuni-shipping-order' ),
				'translations' => array(
					'shipping_status_update_failed' => __( 'Shipping status update failed.', 'wu-payuni-shipping' ),
					'cancel_shipping_failed'        => __( 'Shipping order cancel failed.', 'wu-payuni-shipping' ),
				),
			)
		);

	}

	/**
	 * Return the raw address for payuni shipping order, non-formatted way.
	 *
	 * @param array    $raw_address The raw address.
	 * @param \WC_Order $order The order.
	 * @return array
	 */
	public static function payuni_raw_shipping_address( $raw_address, $order ) {
		if (!\wutm_ps_owned($order)) return $raw_address;

		if ( $order->get_meta( OrderMeta::StoreId ) ) {

			$raw_address['payuni_storeid']      = $order->get_meta( OrderMeta::StoreId );
			$raw_address['payuni_storename']    = $order->get_meta( OrderMeta::StoreName );
			$raw_address['payuni_storeaddress'] = $order->get_meta( OrderMeta::StoreAddr );
			$raw_address['phone']               = self::payuni_get_shipping_phone( $order );
			$raw_address['country']             = 'PNCVS';

		} else {

			if ( self::payuni_get_shipping_phone( $order ) ) {
				$raw_address['phone'] = self::payuni_get_shipping_phone( $order );
			}

			if ( $order->get_meta( OrderMeta::ShipType ) === ShipType::TCAT ) {
				$raw_address['country'] = 'PNHD';
			}
		}

		return $raw_address;
	}

	/**
	 * PAYUNi address format.
	 *
	 * @param array $address_formats The address formats.
	 * @return array
	 */
	public static function payuni_address_format( $address_formats ) {

		$address_formats['TW'] = "{postcode}\n{country} {state} {city}\n{address_1} {address_2}\n{company} {last_name} {first_name}";
		if ( is_admin() ) {
			// 超商.
			$address_formats['PNCVS'] = "{payuni_storename} ({payuni_storeid})\n{payuni_storeaddress}\n{last_name} {first_name}\n";
			// 宅配.
			$address_formats['PNHD'] = "{postcode}\n {state} {city}\n{address_1} {address_2}\n{company} {last_name} {first_name}\n";
		} else {
			// 超商.
			$address_formats['PNCVS'] = "{payuni_storename} ({payuni_storeid})\n{payuni_storeaddress}\n{last_name} {first_name}\n"
				. '<p class="woocommerce-customer-details--phone">{phone}</p>';

			// 宅配.
			$address_formats['PNHD'] = "{postcode}\n {state} {city}\n{address_1} {address_2}\n{company} {last_name} {first_name}\n"
				. '<p class="woocommerce-customer-details--phone">{phone}</p>';
		}
		return $address_formats;
	}

	/**
	 * Replace address placeholders
	 *
	 * @param array $replacements The replacements.
	 * @param array $args The data to replace the placeholders.
	 * @return array
	 */
	public static function payuni_shipping_address_replacements( $replacements, $args ) {
		if ( isset( $args['payuni_storeid'] ) ) {
			if ( isset( $args['payuni_storeid'] ) ) {
				$replacements['{payuni_storeid}'] = $args['payuni_storeid'];
			}

			if ( isset( $args['payuni_storename'] ) ) {
				$replacements['{payuni_storename}'] = $args['payuni_storename'];
			}

			if ( isset( $args['payuni_storeaddress'] ) ) {
				$replacements['{payuni_storeaddress}'] = $args['payuni_storeaddress'];
			}
		}
		if ( isset( $args['phone'] ) ) {
			$replacements['{phone}'] = $args['phone'];
		}
		return $replacements;
	}

	/**
	 * Add shipping address info for google map
	 *
	 * @param array    $address The shipping address array.
	 * @param \WC_Order $order The order.
	 * @return array
	 */
	public static function payuni_shipping_address_map( $address, $order ) {

		if ( $order->get_meta( OrderMeta::StoreName ) ) {
			$address['storename'] = $order->get_meta( OrderMeta::StoreName );
			unset( $address['address_2'] );
			unset( $address['country'] );
			unset( $address['city'] );
			unset( $address['state'] );
			unset( $address['postcode'] );
			unset( $address['company'] );
		}

		return $address;
	}

	/**
	 * Display shipping detail for PAYUNi shipping.
	 *
	 * @param \WC_Order $order The order to display shipping detail.
	 * @return void
	 */
	public static function payuni_shipping_detail_after_order_table( $order ) {
		
		$table_title      = '<h2>' . __( 'PAYUNi Shipping Detail', 'wu-payuni-shipping' ) . '</h2><table class="shop_table payuni_shipping_details"><tbody>';
		$table_html       = '';
		$shipping_methods = $order->get_items( 'shipping' );

		foreach ( $shipping_methods as $item_id => $item ) {

			if ( self::is_payuni_shipping( $item->get_method_id() ) ) {

				if ( empty( $table_html ) ) {
					$table_html .= $table_title;
				}
				$table_html .= '<tr><td><strong>' . __( 'ShipTradeNo', 'wu-payuni-shipping' ) . '</strong></td>';
				$table_html .= '<td>' . $order->get_meta( OrderMeta::ShipTradeNo ) . '</td></tr>';

				$table_html .= '<tr><td><strong>' . __( 'Ship Type', 'wu-payuni-shipping' ) . '</strong></td>';
				$table_html .= '<td>' . ShipType::get_name( $order->get_meta( OrderMeta::ShipType ) ). '</td></tr>';

				$ship_status       = $order->get_meta( OrderMeta::ShipStatus );
				$ship_sttatus_desc = $order->get_meta( OrderMeta::ShipStatusDesc );
				$ship_sttatus_desc_txt  = ( ! empty( $ship_status ) ) ? '(' . $ship_status . ')' . $ship_sttatus_desc : 'N/A';
				$table_html        .= '<tr><td><strong>' . __( 'Detail Status Desc', 'wu-payuni-shipping' ) . '</strong></td>';
				$table_html        .= '<td>' . $ship_sttatus_desc_txt . '</td></tr>';

				$status_time       = $order->get_meta( OrderMeta::ShipStatusTime );
				$table_html        .= '<tr><td><strong>' . __( 'Ship Status Time', 'wu-payuni-shipping' ) . '</strong></td>';
				$table_html        .= '<td>' . $status_time . '</td></tr>';

				$table_html .= '<tr><td><strong>' . __( 'ShipNo', 'wu-payuni-shipping' ) . '</strong></td>';

				$validation_no = $order->get_meta( OrderMeta::ValidationNo );
				$ship_type     = $order->get_meta( OrderMeta::ShipType );
				
				$shipping_no = $order->get_meta( OrderMeta::ShipNo );
				$provider_query_url = ( $ship_type === ShipType::SEVEN ) ? 'https://tracking.shopmore.com.tw/' : 'https://www.t-cat.com.tw/inquire/trace.aspx';
				$provider_query_html = ( empty( $shipping_no ) ) ? '' : '<a href="' . esc_url( $provider_query_url ) . '" target="_blank"><span class="dashicons dashicons-search"></span></a>' ;
				$table_html .= '<td>' . $shipping_no  . ' ' . $provider_query_html . '</td></tr>';

			}
		}

		if ( ! empty( $table_html ) ) {
			$table_html .= '</tbody></table>';
		}

		if ( ! empty( $table_html ) ) {
			echo $table_html;
		}
	}

	/**
	 * Remove checkout form localstorage data
	 *
	 * @param string $order_id The order id.
	 * @return void
	 */
	public static function payuni_thank_you_script( $order_id ) {
		?>
		<script type="text/javascript">
			jQuery(function ($) {
				window.localStorage.removeItem( 'payuni_woo_form' );
			});

		</script>
		<?php
	}


	/**
	 * Get the totle weight of the order
	 *
	 * @param \WC_Order $order The order to get total weight.
	 * @return int
	 */
	public static function get_order_total_weight( $order ) {
		$total_weight = 0;
		
		foreach ( $order->get_items() as $item_id => $product_item ) {
			$quantity       = $product_item->get_quantity(); // get quantity.
			$product        = $product_item->get_product(); 
			$product_weight = $product->get_weight(); // get the product weight.

			$total_weight += floatval( $product_weight * $quantity );
		}
		return $total_weight;
	}

	/**
	 * Check if the order need cvs.
	 *
	 * @param string $method_id The shipping method id.
	 * @return boolean
	 */
	public static function needs_cvs( $method_id ) {
		if ( substr( $method_id, 0, 19 ) === 'payuni_shipping_711') {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * 判斷運送方式是否為 PAYUNi 的超商運送(包含 C2C 和 B2C)
	 *
	 * @param string $shipping_method_id The shipping method.
	 * @return boolean
	 */
	public static function is_payuni_shipping_cvs( $shipping_method_id ) {
		foreach ( self::$cvs_methods as $method => $method_class ) {
			if ( strpos( $shipping_method_id, $method ) === 0 ) {
				return $method;
			}
		}

		return false;
	}

	/**
	 * 判斷運送方式是否為 PAYUNi 的運送方式
	 *
	 * @param string $shipping_method_id The shipping method id.
	 * @return boolean
	 */
	public static function is_payuni_shipping( $shipping_method_id ) {
		if ( strpos( $shipping_method_id, 'payuni_shipping' ) === 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * 判斷運送方式是否為 PAYUNi 的宅配運送(黑貓)
	 *
	 * @param string $shipping_method_id The shipping method id.
	 * @return boolean
	 */
	public static function is_payuni_shipping_hd( $shipping_method_id ) {
		foreach ( self::$hd_methods as $method => $method_class ) {
			if ( strpos( $shipping_method_id, $method ) === 0 ) {
				return $method;
			}
		}

		return false;
	}

	/**
	 * WooCommerce 5.6 支援 shipping phone
	 *
	 * @param \WC_Order $order
	 * @return string
	 */
	public static function payuni_get_shipping_phone( $order ) {
		if ( version_compare( Constants::get_constant( 'WC_VERSION' ), '5.6.0', '>=' ) ) {
			return $order->get_shipping_phone();
		} else {
			return $order->get_meta( '_shipping_phone' );
		}
	}

	/**
	 * 加密
	 * 
	 * @param  array $encrypt_info
	 * @return string
	 */
	public static function encrypt( $encrypt_info ) {
		$tag       = '';
		$encrypted = openssl_encrypt( http_build_query( $encrypt_info ), 'aes-256-gcm', trim( get_option( 'payuni_payment_hashkey' ) ), 0, trim( get_option( 'payuni_payment_hashiv' ) ), $tag );
		return trim( bin2hex( $encrypted . ':::' . base64_encode( $tag ) ) );
	}

	/**
	 * 解密
	 */
	public static function decrypt( string $encrypt_str = '' ) {

		$hashkey = get_option( 'payuni_payment_hashkey' );
		$hashiv  = get_option( 'payuni_payment_hashiv' );

        if ( '' === $encrypt_str || strlen( $encrypt_str ) % 2 || ! preg_match('/^[A-Fa-f0-9]+$/D', $encrypt_str) ) {
            return array();
        }
        $parts = explode( ':::', hex2bin( $encrypt_str ), 2 );
        if ( 2 !== count( $parts ) ) { return array(); }
        $tag = base64_decode( $parts[1], true );
        if ( false === $tag || 16 !== strlen( $tag ) ) { return array(); }
        $plain = openssl_decrypt( $parts[0], 'aes-256-gcm', trim( $hashkey ), 0, trim( $hashiv ), $tag );
        if ( false === $plain ) { return array(); }
        parse_str( $plain, $encrypt_arr );
		return $encrypt_arr;
	}

	/**
	 * hash
	 */
	public static function hash_info( string $encrypt_str = '' ) {
		return strtoupper( hash( 'sha256', get_option( 'payuni_payment_hashkey' ) . $encrypt_str . get_option( 'payuni_payment_hashiv' ) ) );
	}

	/**
	 * Plugin action links
	 *
	 * @param array $links The action links array.
	 * @return array
	 */
	public function payuni_add_action_links( $links ) {
		$setting_links = array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=payuni&section=shipping' ) . '">' . __( 'General Settings', 'wu-payuni-shipping' ) . '</a>',
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=shipping' ) . '">' . __( 'Shipping Settings', 'wu-payuni-shipping' ) . '</a>',
		);
		return array_merge( $links, $setting_links );
	}

	/**
	 * Log method.
	 *
	 * @param string $message The message to be logged.
	 * @param string $level The log level. Optional. Default 'info'. Possible values: emergency|alert|critical|error|warning|notice|info|debug.
	 * @return void
	 */
	public static function log( $message, $level = 'info' ) {
		$message = \wutm_ps_log_message($message);
		if ( self::$log_enabled ) {
			if ( empty( self::$log ) ) {
				self::$log = new \WC_Logger();
			}
			self::$log->log( $level, $message, array( 'source' => 'wu-payuni-shipping' ) );
		}
	}

	/**
	 * Get the value of mer_id (商店代號)
	 */ 
	public static function get_mer_id() {
		return self::$mer_id;
	}

	/**
	 * Get the value of tcat_delivery_time
	 */ 
	public static function get_tcat_delivery_time() {
		return self::$tcat_delivery_time;
	}
}
