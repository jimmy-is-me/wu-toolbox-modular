<?php
define( 'ABSPATH', __DIR__ );
$filters = $options = [];
function add_action( ...$args ) {}
function add_filter( $name, $callback, ...$args ) { $GLOBALS['filters'][$name] = $callback; }
function get_option( $name, $fallback = false ) { return $GLOBALS['options'][$name] ?? $fallback; }
class WP_Block_Type_Registry {
    public static function get_instance() { return new self(); }
    public function get_all_registered() { return array_fill_keys( ['core/image','core/paragraph','wu-toolbox/advanced-gallery'], true ); }
}
require dirname( __DIR__ ) . '/modules/gutenberg-manager/module.php';
function expect_blocks( $actual, $expected ) {
    if ( $actual !== $expected ) throw new RuntimeException( var_export( $actual, true ) );
}
$allow = $filters['allowed_block_types_all'];
expect_blocks( $allow( true ), ['core/image','core/paragraph'] );
expect_blocks( $allow( false ), false );
$options['wutm_gutenberg_hidden'] = ['core/image'];
$options['wutm_gutenberg_gallery'] = true;
expect_blocks( $allow( ['core/image','plugin/example'] ), ['plugin/example'] );
expect_blocks( $allow( true ), ['core/paragraph','wu-toolbox/advanced-gallery'] );
$counts = [];
wutm_gutenberg_count_blocks( [
    ['blockName'=>'core/group','innerBlocks'=>[['blockName'=>'core/image'],['blockName'=>'core/image']]],
    ['blockName'=>null],
], $counts );
expect_blocks( $counts, ['core/group'=>1,'core/image'=>2] );
echo "Gutenberg usage and insertion rules passed.\n";
function current_user_can( $cap ) { return true; }
function wutm_admin_page_open( ...$args ) {}
function wutm_admin_page_close() {}
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_attr( $value ) { return esc_html( $value ); }
function wp_date( ...$args ) { return 'scan timestamp'; }
function wp_create_nonce( $action ) { return 'fixture'; }
function wp_nonce_field( $action ) {}
function wp_json_encode( $value ) { return json_encode( $value ); }
function disabled( ...$args ) { return ''; }
function checked( ...$args ) { return ''; }
$options['wutm_gutenberg_usage'] = [
    'time' => 1,
    'counts' => [ 'core/paragraph' => 7, 'core/image' => 7, 'plugin/unknown' => 20 ],
];
ob_start();
wutm_gutenberg_page();
$html = ob_get_clean();
preg_match_all( '~<code>([^<]+)</code>~', $html, $matches );
expect_blocks( $matches[1], ['plugin/unknown','core/image','core/paragraph','wu-toolbox/advanced-gallery'] );
expect_blocks( substr_count( $html, 'name="wutm_save_blocks"' ), 1 );
expect_blocks( strpos( $html, 'form="wutm-block-settings"' ) < strpos( $html, '<table' ), true );
echo "Gutenberg scan results and top save placement passed.\n";
