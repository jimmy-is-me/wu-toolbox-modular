<?php
/** Real discovery integration with isolated WordPress storage / HTTP fixtures. */
define( 'DAY_IN_SECONDS', 86400 );
function get_current_user_id() { return $GLOBALS['scan_owner'] ?? 1; }
function wp_generate_uuid4() { return 'scan-' . ++$GLOBALS['scan_serial']; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) {
    if ( array_key_exists( $key, $GLOBALS['options'] ) ) return false;
    $GLOBALS['options'][$key] = $value; return true;
}
function delete_option( $key ) { unset( $GLOBALS['options'][$key] ); }
function wp_schedule_single_event( ...$args ) { $GLOBALS['scan_events'][] = $args; }
function wp_clear_scheduled_hook( ...$args ) {}
$GLOBALS['scan_serial'] = 0;
require __DIR__ . '/ai-translate-translatepress.php';

function scan_request( $operation, $job = null ) {
    // A new AJAX request has a fresh per-process frontend/dictionary cache.
    foreach ( array( 'frontend_html_cache', 'dictionary_originals_cache' ) as $name ) {
        $property = new ReflectionProperty( WU_AIT_TranslatePress_Bridge::class, $name );
        $property->setValue( null, array() );
    }
    return WU_AIT_Batched_Scan::request( $operation, $job['token'] ?? '', $job['revision'] ?? -1 );
}
function expect_scan_error( $callback, $message ) {
    try { $callback(); } catch ( RuntimeException $error ) { check( true, $message ); return; }
    check( false, $message );
}
$old = array( 'old' => array( 'original' => '既有有效文字', 'post_id' => 99, 'first_seen' => 'old', 'last_seen' => 'old' ) );
$GLOBALS['options'][WU_AIT_Discovery_Service::OPTION_KEY] = $old;
$GLOBALS['wpdb']->rows = array();
$GLOBALS['remote_requests'] = 0;
$job = scan_request( 'start' );
check( $GLOBALS['remote_requests'] === 0, 'Starting a job does not crawl all pages' );
check( scan_request( 'start' )['token'] === $job['token'], 'Refresh resumes the existing job' );
$GLOBALS['scan_owner'] = 2;
expect_scan_error( fn() => scan_request( 'start' ), 'Other administrator cannot replace a job' );
expect_scan_error( fn() => scan_request( 'step', $job ), 'Other administrator cannot advance a job' );
$GLOBALS['scan_owner'] = 1;
expect_scan_error( fn() => scan_request( 'step', array( 'token' => 'wrong', 'revision' => 0 ) ), 'Token validation' );
$GLOBALS['options'][WU_AIT_Batched_Scan::LOCK] = time();
expect_scan_error( fn() => scan_request( 'step', $job ), 'Concurrent request is rejected' );
delete_option( WU_AIT_Batched_Scan::LOCK );
$prior = $job;
$job = scan_request( 'step', $job );
check( $GLOBALS['remote_requests'] === 1, 'One render request reads only one page' );
check( scan_request( 'step', $prior )['revision'] === $job['revision'], 'Duplicate requests do not advance' );
check( get_option( WU_AIT_Discovery_Service::OPTION_KEY ) === $old, 'Old list survives render' );
$GLOBALS['fail_frontend'] = true;
expect_scan_error( fn() => scan_request( 'step', $job ), 'Failed page leaves job resumable' );
check( get_option( WU_AIT_Discovery_Service::OPTION_KEY ) === $old, 'Failed HTTP read preserves old list' );
check( scan_request( 'status', $job )['revision'] === $job['revision'], 'Failure does not advance checkpoint' );
$GLOBALS['fail_frontend'] = false;
$job = scan_request( 'step', $job );
check( get_option( WU_AIT_Discovery_Service::OPTION_KEY ) === $old, 'Old list survives render completion' );
$requests = $GLOBALS['remote_requests'];
$job = scan_request( 'step', $job );
check( get_option( WU_AIT_Discovery_Service::OPTION_KEY ) === $old, 'Old list survives partial dictionary matching' );
$GLOBALS['fail_option'] = WU_AIT_Discovery_Service::OPTION_KEY;
expect_scan_error( fn() => scan_request( 'step', $job ), 'Failed final write must not publish' );
check( get_option( WU_AIT_Discovery_Service::OPTION_KEY ) === $old, 'Failed publication keeps old list' );
$GLOBALS['fail_option'] = '';
$job = scan_request( 'step', $job );
check( $job['done'] && $job['processed'] === $job['total'], 'Final progress reaches completion' );
check( $GLOBALS['remote_requests'] === $requests, 'Dictionary pass uses saved HTML, not a second HTTP crawl' );
$published = get_option( WU_AIT_Discovery_Service::OPTION_KEY );
check( ! isset( $published['old'] ), 'Only completed job replaces the old list' );
check( in_array( '<span>第一頁動態中文</span>', array_column( $published, 'original' ), true ), 'Late dictionary registration still matches earlier pages' );
check( scan_request( 'step', $job )['done'], 'Lost completion response is safe to replay' );
check( ! array_filter( array_keys( $GLOBALS['options'] ), fn($key) => strpos( $key, 'wu_ait_scan_page_' ) === 0 ), 'Completed temporary HTML is removed' );
$job = scan_request( 'start' );
scan_request( 'step', $job );
scan_request( 'cancel', $job );
check( get_option( WU_AIT_Discovery_Service::OPTION_KEY ) === $published, 'Cancel preserves published list' );
$GLOBALS['scan_ids'] = array();
$job = scan_request( 'start' );
$job = scan_request( 'step', $job );
$job = scan_request( 'step', $job );
check( $job['done'] && $job['count'] === 0, 'Empty site completes safely' );
$job = scan_request( 'start' );
$state = get_option( WU_AIT_Batched_Scan::JOB );
$state['expires'] = time() - 1;
update_option( WU_AIT_Batched_Scan::JOB, $state );
expect_scan_error( fn() => scan_request( 'step', $job ), 'Expired jobs cannot publish' );
check( get_option( WU_AIT_Batched_Scan::JOB, null ) === null, 'Expired temporary state is removed' );
echo "Batched scan preservation, resume, lock, expiry and dictionary tests passed.\n";
