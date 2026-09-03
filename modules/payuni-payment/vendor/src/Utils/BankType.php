<?php

namespace WPBrewer\Payuni\Payment\Utils;

class BankType {

    const TWBank   = '004';
    const CTBCBank = '822';
    const CATHAYBank = '013';

    public static function get_name( $bank_type ) {
        switch ( $bank_type ) {
            case self::TWBank:
                return __( 'Taiwan Bank', 'wpbr-payuni-payment' );
            case self::CTBCBank:
                return __( 'CTBC Bank', 'wpbr-payuni-payment' );
            case self::CATHAYBank:
                return __( 'Cathay Bank', 'wpbr-payuni-payment' );
            default:
                return __( 'Unknown Bank', 'wpbr-payuni-payment' );
        }
    }
}
