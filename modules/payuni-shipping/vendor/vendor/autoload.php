<?php
/** Production autoloader; this plugin has no third-party runtime dependencies. */
defined( 'ABSPATH' ) || exit;
spl_autoload_register( static function ( $class ) {
    $prefix = 'WuDigital\\Payuni\\Shipping\\';
    if ( 0 !== strpos( $class, $prefix ) ) { return; }
    $path = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
    if ( is_file( $path ) ) { require_once $path; }
} );
