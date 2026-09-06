<?php
/**
 * Singleton trait to implements Singleton pattern in any classes where this trait is used.
 *
 * @package wpbr-esun-payment
 */

namespace WPBrewer\ESun\Payment\Utils;

/**
 * Trait Singleton
 *
 * Implements the singleton pattern for classes
 */
trait SingletonTrait {

	/**
	 * Stores the instance of the class
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Protected constructor to prevent creating a new instance of the
	 * class via the `new` operator from outside of this class.
	 */
	protected function __construct() {
	}

	/**
	 * Returns the singleton instance of this class.
	 *
	 * @return self The singleton instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new static();
		}

		return self::$instance;
	}

	/**
	 * Prevent cloning of the instance
	 *
	 * @return void
	 */
	private function __clone() {
	}

	/**
	 * Prevent unserializing of the instance
	 *
	 * @return void
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}
} 

