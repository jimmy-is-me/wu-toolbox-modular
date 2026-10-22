<?php

namespace WuDigital\Payuni\Shipping\Utils;

class LgsType {
    
    const HOME = 'HOME'; // 宅配
	const B2C  = 'B2C'; // 大宗寄倉
    const C2C  = 'C2C'; // 店到店

	public static function get_name( $lgs_type ) {
		switch ( $lgs_type ) {
			case self::HOME:
				return '宅配';
				break;
			case self::B2C:
				return '大宗寄倉';
				break;
            case self::C2C:
                return '店到店';
                break;
			default:
				return '未知的 LgsType:' . $lgs_type;
		}
	}
	
}
