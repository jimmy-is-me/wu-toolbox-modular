<?php

namespace WPBrewer\ESun\Payment\Utils;

class InstallmentNumbers {

    const INSTALLMENT_3 = '0100103';
    const INSTALLMENT_6 = '0100106';

    const INSTALLMENT_LIST = array(
        '3' => self::INSTALLMENT_3,
        '6' => self::INSTALLMENT_6,
    );

    public static function get_installment( $number ) {
        return self::INSTALLMENT_LIST[$number];
    }    
}
