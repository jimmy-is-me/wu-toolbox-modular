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
