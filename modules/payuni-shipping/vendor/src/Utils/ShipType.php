<?php

namespace WuDigital\Payuni\Shipping\Utils;

class ShipType {
    
    const SEVEN           = '1'; // 7-11
	const TCAT            = '2'; // 黑貓

    /**
	 * Check if the service need cvs
	 *
	 * @param string $service_id The logistic service id.
	 * @return boolean
	 */
	public static function is_cvs( $service_id ) {

		if ( self::TCAT !== $service_id ) {
			return true;
		} else {
			return false;
		}

	}

	public static function get_name( $ship_type_id ) {
		switch ( $ship_type_id ) {
			case self::SEVEN:
				return '7-Eleven';
				break;
			case self::TCAT:
				return '黑貓宅配';
				break;
			default:
				return '';
		}
	}

	/**
	 * Get logistic service id by shipping method id
	 *
	 * @param string $method_id shipping method id.
	 * @return string
	 */
	public static function get_ship_type( $method_id ) {

		switch ( $method_id ) {
			case 'payuni_shipping_711_c2c_normal':
				return self::SEVEN;
				break;
			case 'payuni_shipping_711_c2c_frozen':
            case 'payuni_shipping_711_c2c_forzen':
				return self::SEVEN;
				break;
			case 'payuni_shipping_tcat_normal':
				return self::TCAT;
				break;
			case 'payuni_shipping_tcat_frozen':
				return self::TCAT;
				break;
			case 'payuni_shipping_tcat_refrigerated':
				return self::TCAT;
				break;
			default:
				return '';
		}
	}
	
}
