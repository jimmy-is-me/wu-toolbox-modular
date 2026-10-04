<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', __DIR__ );

class TRP_Translate_Press {}

$trp_settings = array(
	'default-language' => 'zh_TW',
	'publish-languages' => array( 'zh_TW', 'en_US' ),
	'url-slugs' => array( 'zh_TW' => 'zh', 'en_US' => 'en' ),
);
$site_url = 'https://example.test/';

function get_option( $name, $default = false ) {
	global $trp_settings;
	if ( 'wutm_page_cache_device_variants_264' === $name ) return 1;
	if ( 'trp_settings' === $name ) return $trp_settings;
	return $default;
}
function add_action() {}
function home_url( $path = '' ) {
	global $site_url;
	return rtrim( $site_url, '/' ) . '/' . ltrim( $path, '/' );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
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

echo "TranslatePress page-cache exclusions passed.\n";
