<?php
/**
 * Lightweight SPL autoloader for this plugin's namespaced classes.
 *
 * Ships in the plugin zip (Composer's autoloader does not — vendor/ and
 * composer.json are excluded from the distributed build). Maps
 * `CMS_Tree_Page_View\Sub\Some_Class` to `inc/Sub/class-some-class.php`.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'CMS_Tree_Page_View\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative   = substr( $class_name, strlen( $prefix ) );
		$parts      = explode( '\\', $relative );
		$class_part = array_pop( $parts );
		$file_name  = 'class-' . str_replace( '_', '-', strtolower( $class_part ) ) . '.php';

		$path = __DIR__;
		foreach ( $parts as $part ) {
			$path .= '/' . $part;
		}
		$path .= '/' . $file_name;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
