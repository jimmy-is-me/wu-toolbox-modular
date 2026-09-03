<?php

namespace WPBrewer\Payuni\Payment\Utils;

/**
 * Class PayType
 */
class PayType {

    const CREDIT_CARD = 1; // 信用卡
    const ATM         = 2; // ATM轉帳
    const CVS_CODE    = 3; // 代碼
    const C2C         = 5; // 貨到付款(超商取貨付款)
    const ICASH       = 6; // 愛金卡 (ICash)
    const AFTEE       = 7; // 後支付 (Aftee)
    const LINEPAY     = 9; // LinePay
    const DELIVERY    = 10; // 宅配到付

    public static function get_name( $pay_type ) {
        switch ( $pay_type ) {
            case self::CREDIT_CARD:
                return __( 'Credit Card', 'wpbr-payuni-payment' );
            case self::ATM:
                return __( 'ATM', 'wpbr-payuni-payment' );
            case self::CVS_CODE:
                return __( 'CVS Code', 'wpbr-payuni-payment' );
            case self::C2C:
                return __( 'C2C', 'wpbr-payuni-payment' );
            case self::ICASH:
                return __( 'ICash', 'wpbr-payuni-payment' );
            case self::AFTEE:
                return __( 'AFTEE', 'wpbr-payuni-payment' );
            case self::LINEPAY:
                return __( 'LINE Pay', 'wpbr-payuni-payment' );
            case self::DELIVERY:
                return __( 'Delivery', 'wpbr-payuni-payment' );
            default:
                return __( 'Unknown Payment Type', 'wpbr-payuni-payment' );
        }
    }
    
}
