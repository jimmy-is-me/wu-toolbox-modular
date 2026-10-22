<?php

namespace WuDigital\Payuni\Shipping\Utils;

class GoodsType {
    
    const NORMAL          = '1'; // 常溫
	const FROZEN          = '2'; // 冷凍
    const REFRIGERATED    = '3'; // 冷藏

	public static function get_name( $goods_type_id ) {
		switch ( $goods_type_id ) {
			case self::NORMAL:
				return '常溫';
				break;
			case self::FROZEN:
				return '冷凍';
				break;
            case self::REFRIGERATED:
                return '冷藏';
                break;
			default:
				return '未知的 GoodsType:' . $goods_type_id;
		}
	}
	
}
