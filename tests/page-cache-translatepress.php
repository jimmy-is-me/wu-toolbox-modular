<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/wutm-page-cache-test-' . bin2hex( random_bytes( 5 ) ) );
define( 'MB_IN_BYTES', 1048576 );

class TRP_Translate_Press {}

$trp_settings = array(
	'default-language' => 'zh_TW',
	'publish-languages' => array( 'zh_TW', 'en_US' ),
	'url-slugs' => array( 'zh_TW' => 'zh', 'en_US' => 'en' ),
);
$site_url = 'https://example.test/';

function get_option( $name, $default = false ) {
	global $trp_settings;
	if ( in_array( $name, array( 'wutm_page_cache_device_variants_264', 'wutm_page_cache_safe_gzip_353' ), true ) ) return 1;
	if ( 'trp_settings' === $name ) return $trp_settings;
	return $default;
}
function add_action() {}
function is_admin() { return false; }
function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, $args );
}
function home_url( $path = '' ) {
	global $site_url;
	return rtrim( $site_url, '/' ) . '/' . ltrim( $path, '/' );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function trailingslashit( $path ) {
	return rtrim( $path, '/\\' ) . '/';
}

require __DIR__ . '/../modules/page-cache/module.php';

$method = new ReflectionMethod( 'WUTM_Page_Cache', 'is_translatepress_translation' );
$check = static function ( $uri, $expected ) use ( $method ) {
	$actual = $method->invoke( null, $uri );
	if ( $actual !== $expected ) {
		throw new RuntimeException( $uri . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
};

$check( '/en/', true );
$check( '/en/docs/article/', true );
$check( '/', false );
$check( '/enquiry/', false );

$trp_settings['url-slugs']['en_US'] = 'english';
$check( '/english/', true );
$check( '/en/', false );

$site_url = 'https://example.test/site/';
$check( '/site/english/', true );
$check( '/english/', false );

$TRP_LANGUAGE = 'en_US';
$check( '/site/unknown/', true );

$html_check = new ReflectionMethod( 'WUTM_Page_Cache', 'is_complete_html' );
if ( $html_check->invoke( null, '<html><body>Complete</body></html>' ) !== true
	|| $html_check->invoke( null, '<html><body>Truncated' ) !== false ) {
	throw new RuntimeException( 'Incomplete HTML must never be cached.' );
}

$cache_dir = WP_CONTENT_DIR . '/cache/wutm-page-cache';
if ( ! mkdir( $cache_dir, 0700, true ) ) {
	throw new RuntimeException( 'Failed to create isolated cache fixture.' );
}
$cache_file = $cache_dir . '/fixture.html.gz';
$property = new ReflectionProperty( 'WUTM_Page_Cache', 'cache_file' );
$property->setValue( null, $cache_file );
$writer = new ReflectionMethod( 'WUTM_Page_Cache', 'write_cache_atomically' );
$writer->invoke( null, gzencode( '<html>first</html>' ), '{"version":1}' );
$writer->invoke( null, gzencode( '<html>second</html>' ), '{"version":2}' );
if ( gzdecode( file_get_contents( $cache_file ) ) !== '<html>second</html>'
	|| file_get_contents( $cache_file . '.json' ) !== '{"version":2}'
	|| count( glob( $cache_dir . '/.wutm-*' ) ) !== 0 ) {
	throw new RuntimeException( 'Atomic cache replacement left incomplete files or mismatched metadata.' );
}
unlink( $cache_file );
unlink( $cache_file . '.json' );
rmdir( $cache_dir );
rmdir( dirname( $cache_dir ) );
rmdir( WP_CONTENT_DIR );

echo "TranslatePress exclusions and page-cache integrity passed.\n";
