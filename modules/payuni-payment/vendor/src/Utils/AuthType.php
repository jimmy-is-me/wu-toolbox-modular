<?php
/**
 * AuthType class file.
 *
 * @package payuni
 */

namespace WPBrewer\Payuni\Payment\Utils;

/**
 * Class AuthType
 */
class AuthType {

	const ONE         = '1'; // 一次性支付.
	const INSTALLMENT = '2'; // 分期支付.
	const POINT       = '3'; // 點數支付.
	const APPLEPAY    = '4'; // Apple Pay.
	const GOOGLEPAY   = '5'; // Google Pay.
	const SAMSUNGPAY  = '6'; // Samsung Pay.
	const UNIPAY      = '7'; // 銀聯

    public static function get_type( $type ) {
        switch ( $type ) {
            case self::ONE:
                return '一次支付';
            case self::INSTALLMENT:
                return '分期付款';
            case self::POINT:
                return '紅利點數';
            case self::APPLEPAY:
                return 'Apple Pay';
            case self::GOOGLEPAY:
                return 'Google Pay';
            case self::SAMSUNGPAY:
                return 'Samsung Pay';
            case self::UNIPAY:
                return '銀聯';
            default:
                return '未知的授權類型';
        }
    }
}
