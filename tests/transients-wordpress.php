<?php
if (!defined('WP_CLI') || getenv('WUTM_MEDIA_INTEGRATION') !== '1' || DB_NAME !== 'wci_fixture') {
    throw new RuntimeException('Disposable WordPress fixture only.');
}
require_once dirname(__DIR__) . '/modules/transients-manager/module.php';
function wutm_verify_transient($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$manager = new WU_Transients_Manager();
$stats = new ReflectionMethod($manager, 'get_transients_stats');
$clear = new ReflectionMethod($manager, 'clear_expired_transients');
$expired = 'wutm_fixture_expired';
$permanent = 'wutm_fixture_permanent';
$future = 'wutm_fixture_future';
$protected = 'wc_session_wutm_fixture';
set_transient($expired, 'old', 3600);
set_transient($permanent, 'keep');
set_transient($future, 'keep', 3600);
set_transient($protected, 'keep', 3600);
update_option('_transient_timeout_' . $expired, time() - 5);
update_option('_transient_timeout_' . $protected, time() - 5);
set_site_transient($expired, 'old', 3600);
update_site_option('_site_transient_timeout_' . $expired, time() - 5);
update_option('Xtransient_wutm_fixture_unrelated', 'keep', false);
try {
    $before = $stats->invoke($manager);
    wutm_verify_transient($before['expired'] >= 3, 'Includes expired local and site transients.');
    $result = $clear->invoke($manager);
    wutm_verify_transient($result['success'] && $result['cleared'] >= 2, 'Expired entries deleted.');
    wutm_verify_transient(get_option('_transient_' . $expired) === false && get_site_option('_site_transient_' . $expired) === false, 'Both stores cleaned.');
    wutm_verify_transient(get_transient($permanent) === 'keep' && get_transient($future) === 'keep', 'No-expiry and future values preserved.');
    wutm_verify_transient(get_option('_transient_' . $protected) === 'keep', 'Protected prefix preserved.');
    wutm_verify_transient(get_option('Xtransient_wutm_fixture_unrelated') === 'keep', 'LIKE wildcards do not match unrelated options.');
    wp_using_ext_object_cache(true);
    try {
        wutm_verify_transient(!$clear->invoke($manager)['success'] && $stats->invoke($manager)['using_object_cache'], 'External object cache disables database actions.');
    } finally {
        wp_using_ext_object_cache(false);
    }
} finally {
    foreach ([$expired,$permanent,$future,$protected] as $name) delete_transient($name);
    delete_site_transient($expired);
    delete_option('Xtransient_wutm_fixture_unrelated');
}
echo "Native transient checks passed.\n";
