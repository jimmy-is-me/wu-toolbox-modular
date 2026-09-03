<?php
/**
 * Singleton trait to implements Singleton pattern in any classes where this trait is used.
 *
 * @package payuni
 */

namespace WPBrewer\Payuni\Payment\Utils;

trait SingletonTrait {

	/**
	 * Singleton instance of the class.
	 *
	 * @var object
	 */
	protected static $instance;

	/**
	 * Protected class constructor to prevent direct object creation.
	 */
	protected function __construct() { }

	/**
	 * Prevent object cloning
	 */
	final protected function __clone() { }

	/**
	 * To return new or existing Singleton instance of the class from which it is called.
	 * As it sets to final it can't be overridden.
	 *
	 * @return object Singleton instance of the class.
	 */
	final public static function get_instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}
