<?php
// Minimal loader for the vendored runtime packages listed in dependencies.json.
defined( 'ABSPATH' ) || exit;
spl_autoload_register( static function ( $class ) {
    $prefixes = [
        'WUTMInvoiceDeps\\Symfony\\Polyfill\\Intl\\Normalizer\\' => 'symfony/polyfill-intl-normalizer/',
        'WUTMInvoiceDeps\\Symfony\\Polyfill\\Intl\\Idn\\' => 'symfony/polyfill-intl-idn/',
        'WUTMInvoiceDeps\\GuzzleHttp\\Promise\\' => 'guzzlehttp/promises/src/',
        'WUTMInvoiceDeps\\Psr\\Http\\Message\\' => 'psr/http-message/src/',
        'WUTMInvoiceDeps\\GuzzleHttp\\Psr7\\' => 'guzzlehttp/psr7/src/',
        'WUTMInvoiceDeps\\InvoicePorter\\' => 'dennykuo/invoice-porter/src/',
        'WUTMInvoiceDeps\\GuzzleHttp\\' => 'guzzlehttp/guzzle/src/',
        'WUTMInvoiceDeps\\ODS\\' => 'oberonlai/wp-metabox/src/',
    ];
    foreach ( $prefixes as $prefix => $directory ) {
        if ( strpos( $class, $prefix ) !== 0 ) { continue; }
        $file = __DIR__ . '/' . $directory . '/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
        if ( is_file( $file ) ) { require_once $file; return; }
    }
} );
require_once __DIR__ . '/guzzlehttp/guzzle/src/functions_include.php';
require_once __DIR__ . '/guzzlehttp/promises/src/functions_include.php';
require_once __DIR__ . '/guzzlehttp/psr7/src/functions_include.php';
require_once __DIR__ . '/ralouphie/getallheaders/src/getallheaders.php';
require_once __DIR__ . '/symfony/polyfill-intl-idn/bootstrap.php';
require_once __DIR__ . '/symfony/polyfill-intl-normalizer/bootstrap.php';
