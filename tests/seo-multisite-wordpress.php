<?php
if ( ! defined( 'WP_CLI' ) || ! is_multisite() || DB_NAME !== 'wci_fixture' || $GLOBALS['wpdb']->base_prefix !== 'wms_wp_' ) { throw new RuntimeException( 'Disposable multisite fixture only' ); }
define( 'WUTM_VERSION', 'fixture' ); define( 'WUTM_URL', 'https://network.example.test/' );
require dirname(__DIR__) . '/modules/seo-core/module.php';
function seo_ms_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function seo_ms_settings() { return ( new ReflectionMethod( Wumetax_SEO_Core_v120::class, 'settings' ) )->invoke( null ); }
function seo_ms_indexnow( $method, ...$args ) { return ( new ReflectionMethod( Wumetax_SEO_Core_v120::class, $method ) )->invoke( null, ...$args ); }
$first = get_current_blog_id();
$first_indexnow = get_option( 'wu_seo_indexnow' );
$second = wpmu_create_blog( 'network.example.test', '/second/', 'Second site', get_current_user_id() );
if ( is_wp_error( $second ) ) { throw new RuntimeException( $second->get_error_message() ); }
try {
    update_option( Wumetax_SEO_Core_v120::OPTION_KEY, [ 'organization_name' => 'First site', 'target_locale' => 'en_US' ] );
    set_transient( 'wu_seo_ms_fixture', 'first', 300 );
    $a = seo_ms_settings(); $a_url = Wumetax_SEO_Core_v120::sitemap_url();
    switch_to_blog( $second );
    update_option( Wumetax_SEO_Core_v120::OPTION_KEY, [ 'organization_name' => 'Second site', 'target_locale' => 'de_DE' ] );
    seo_ms_assert( seo_ms_settings()['organization_name'] === 'Second site', 'Settings switch with current blog, not static first site' );
    seo_ms_assert( false === get_transient( 'wu_seo_ms_fixture' ), 'Diagnostic caches are per site' );
    set_transient( 'wu_seo_ms_fixture', 'second', 300 );
    $b_url = Wumetax_SEO_Core_v120::sitemap_url(); seo_ms_assert( $a_url !== $b_url && strpos( $b_url, '/second/' ) !== false, 'Native sitemap URL scoped to subsite' );
    $method = new ReflectionMethod( Wumetax_SEO_Core_v120::class, 'google_secret' ); $cipher = $method->invoke( null, 'second-secret' );
    $indexnow = ['enabled'=>true,'key'=>'second-426','location'=>'','types'=>['page']];
    update_option( 'wu_seo_indexnow', $indexnow );
    $key_url = seo_ms_indexnow( 'indexnow_location', $indexnow );
    seo_ms_assert( str_ends_with( $key_url, '/second/second-426.txt' ) &&
        seo_ms_indexnow( 'indexnow_in_scope', 'https://network.example.test/second/page/', $indexnow ) &&
        ! seo_ms_indexnow( 'indexnow_in_scope', 'https://network.example.test/page/', $indexnow ), 'Subsite key is scoped to its directory.' );
    seo_ms_assert( seo_ms_indexnow( 'indexnow_install' ) && seo_ms_indexnow( 'indexnow_enqueue', 'https://network.example.test/second/page/', 123, 'page', 'published' ), 'Subsite queue initializes.' );
    $indexnow_table = seo_ms_indexnow( 'indexnow_table' );
    restore_current_blog();
    seo_ms_assert( get_option( 'wu_seo_indexnow' ) === $first_indexnow &&
        seo_ms_indexnow( 'indexnow_table' ) !== $indexnow_table, 'IndexNow settings and queue tables are per-site.' );
    seo_ms_assert( seo_ms_settings()['organization_name'] === 'First site' && get_transient( 'wu_seo_ms_fixture' ) === 'first', 'Restore site restores own options and transient' );
    seo_ms_assert( $method->invoke( null, $cipher, true ) === '', 'OAuth ciphertext cannot be used by another subsite' );
    echo "SEO multisite checks passed: separate settings, native sitemap URLs, cache and OAuth credentials across switch_to_blog.\n";
} finally {
    while ( get_current_blog_id() !== $first ) { restore_current_blog(); }
    if ( isset( $indexnow_table ) ) $GLOBALS['wpdb']->query( "DROP TABLE IF EXISTS $indexnow_table" );
    require_once ABSPATH . 'wp-admin/includes/ms.php'; wpmu_delete_blog( $second, true );
}
