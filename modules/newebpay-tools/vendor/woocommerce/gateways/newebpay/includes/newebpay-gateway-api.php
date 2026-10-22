<?php
class WU_NP_NewebPay_Gateway_Api extends WU_NP_NewebPay {

	public static $api_test_url = [
		'checkout' => 'https://ccore.newebpay.com/MPG/mpg_gateway',
	];
	public static $api_url      = [
		'checkout' => 'https://core.newebpay.com/MPG/mpg_gateway',
	];

	public static function checkout_form( $order, $gateway ) {
        if(!wutm_newebpay_configured()||WU_NP_WT::get_option('enabled_newebpay_gateway','no')!=='yes'||$order->get_currency()!=='TWD') { wc_add_notice('目前無法使用藍新付款，請聯絡商店確認設定。','error'); return; }
		WU_NP_NewebPay_Gateway::log( 'Generating payment form by ' . $gateway->id . ' for #' . $order->get_order_number() );

		$notify_url = WC()->api_request_url( 'wu_np_newebpay_callback', true );
		$return_url = $gateway->get_return_url( $order );

		// 使用 get_item_name 取得商品名稱
		$item_name = self::get_item_name( WU_NP_WT::get_option( 'payment_item_name', '' ), $order );
		$item_name = mb_substr( $item_name, 0, 40 );

		list($MerchantID, $HashKey, $HashIV) = WU_NP_NewebPay_Gateway::get_newebpay_api_info();

		$args              = [
			'MerchantID'      => $MerchantID,
			'RespondType'     => 'JSON',
			'TimeStamp'       => new DateTime( '', new DateTimeZone( 'Asia/Taipei' ) ),
			'Version'         => '1.5',
			'MerchantOrderNo' => self::generate_trade_no( $order->get_id(), WU_NP_WT::get_option( 'newebpay_gateway_order_prefix' ) ),
			'Amt'             => (int) ceil( $order->get_total() ),
			'ItemDesc'        => $item_name, // 商品名稱第一個
			'ReturnURL'       => $return_url,
			'NotifyURL'       => $notify_url,
			'CustomerURL'     => $return_url,
			'Email'           => $order->get_billing_email(),
			'EmailModify'     => 0,
			'LoginType'       => 0,
			'CREDIT'          => 0,
			'ANDROIDPAY'      => 0,
			'SAMSUNGPAY'      => 0,
			'InstFlag'        => 0,
			'CreditRed'       => 0,
			'UNIONPAY'        => 0,
			'WEBATM'          => 0,
			'VACC'            => 0,
			'CVS'             => 0,
			'BARCODE'         => 0,
			'P2G'             => 0,
			'CVSCOM'          => 0,
		];
		$args['TimeStamp'] = $args['TimeStamp']->format( 'U' );
		switch ( get_locale() ) {
			case 'zh_HK':
			case 'zh_TW':
				break;
			case 'en_US':
			case 'en_AU':
			case 'en_CA':
			case 'en_GB':
			default:
				$args['LangType'] = 'en';
				break;
		}

		$args                  = self::add_type_info( $args, $order, $gateway );
		$form_data             = [
			'MerchantID' => $MerchantID,
			'TradeInfo'  => self::args_encrypt( $args, $HashKey, $HashIV ),
			'Version'    => '1.5',
		];
		$form_data['TradeSha'] = self::generate_hash_value( $form_data['TradeInfo'], $HashKey, $HashIV );

        WU_NP_NewebPay_Gateway::log('Checkout form generated');

		$order->update_meta_data( '_newebpay_MerchantOrderNo', $args['MerchantOrderNo'] );
        $attempts=$order->get_meta('_wu_np_newebpay_attempts'); $attempts=is_array($attempts)?$attempts:[];
        $attempts[$args['MerchantOrderNo']]=['merchant'=>(string)$MerchantID,'amount'=>$args['Amt']];
        $order->update_meta_data('_wu_np_newebpay_attempts',array_slice($attempts,-10,null,true));
		$order->save_meta_data();

		if ( 'yes' === WU_NP_WT::get_option( 'newebpay_gateway_testmode', 'yes' ) ) {
			$url = self::$api_test_url['checkout'];
		} else {
			$url = self::$api_url['checkout'];
		}

		echo '<form method="post" id="wu-np-newebpay-form" action="' . esc_url( $url ) . '" style="display:none;">';
		foreach ( $form_data as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}
		echo '</form>';

		wc_enqueue_js(
			'$.blockUI({
    message: "' . __( 'Please wait.<br>Getting checkout info.', 'wu-newebpay-tools' ) . '",
    baseZ: 99999,
    overlayCSS: {
        background: "#000",
        opacity: 0.4
    },
    css: {
        "font-size": "1.5em",
        padding: "1.5em",
        textAlign: "center",
        border: "3px solid #aaa",
        backgroundColor: "#fff",
    }
});
$("#wu-np-newebpay-form").submit();'
		);

		do_action( 'wu_np_newebpay_gateway_checkout', $args, $order, $gateway );
	}

	protected static function add_type_info( $args, $order, $gateway ) {
		if ( isset( $gateway->payment_type ) ) {
			if ( isset( $args[ $gateway->payment_type ] ) ) {
				$args[ $gateway->payment_type ] = 1;
			}
			switch ( $gateway->payment_type ) {
				case 'VACC':
				case 'CVS':
				case 'BARCODE':
					$now = new DateTime( '', new DateTimeZone( 'Asia/Taipei' ) );
					$now->add( new DateInterval( 'P' . $gateway->expire_date . 'D' ) );
					$args['ExpireDate'] = $now->format( 'Ymd' );
					break;
				case 'InstFlag':
					if ( isset( $gateway->number_of_periods ) && ! empty( $gateway->number_of_periods ) ) {
						if ( is_array( $gateway->number_of_periods ) ) {
							$number_of_periods = (int) $order->get_meta( '_newebpay_payment_number_of_periods', true );
							if ( ! in_array( $number_of_periods, $gateway->number_of_periods ) ) {
								$number_of_periods = 0;
							}
						} else {
							$number_of_periods = (int) $gateway->number_of_periods;
						}
						if ( in_array( $number_of_periods, [ 3, 6, 12, 18, 24, 30 ] ) ) {
							$args['InstFlag'] = $number_of_periods;
							$order->add_order_note(
								sprintf(
								/* translators: %d number of periods */
									__( 'Credit installment to %d', 'wu-newebpay-tools' ),
									$number_of_periods
								)
							);
							$order->save();
						}
					}
					break;
			}
		}

		$items_shipping = $order->get_items( 'shipping' );
		$items_shipping = array_shift( $items_shipping );
		if ( $items_shipping ) {
			if ( $items_shipping->get_method_id() == 'wu_np_newebpay_shipping_cvs' ) {
				if ( $gateway->id == 'cod' ) {
					$args['CVSCOM'] = 2;
				} else {
					$args['CVSCOM'] = 1;
				}
			}
		}

		return $args;
	}
}
