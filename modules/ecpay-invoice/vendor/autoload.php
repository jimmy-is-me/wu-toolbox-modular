<?php
// Minimal loader for the vendored runtime packages listed in dependencies.json.
defined( 'ABSPATH' ) || exit;
spl_autoload_register( static function ( $class ) {
    $prefixes = [
        'ODS\\' => 'oberonlai/wp-metabox/src/',
    ];
    foreach ( $prefixes as $prefix => $directory ) {
        if ( strpos( $class, $prefix ) !== 0 ) { continue; }
        $file = __DIR__ . '/' . $directory . '/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
        if ( is_file( $file ) ) { require_once $file; return; }
    }
} );

