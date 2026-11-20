<?php
if (!defined('WP_CLI') || getenv('WUTM_MEDIA_INTEGRATION') !== '1' || DB_NAME !== 'wci_fixture') {
    throw new RuntimeException('Disposable WordPress fixture only.');
}

require_once dirname(__DIR__) . '/modules/knowledge-base-ai/quota.php';
require_once dirname(__DIR__) . '/modules/data-import-export/module.php';
require_once dirname(__DIR__) . '/modules/dashboard-status/module.php';

function wutm_security_check($result, string $message): void {
    if (!$result) throw new RuntimeException($message);
}

$webhook_option = get_option('wutm_dashboard_discord_webhook');
$admin_id = get_current_user_id();
$subscriber = 0;
try {
    $fake_webhook = 'https://discord.com/api/webhooks/' . str_repeat('1', 18) . '/' . str_repeat('a', 60);
    wutm_security_check(wutm_dashboard_webhook_url($fake_webhook) === $fake_webhook, 'Expected HTTPS webhook accepted.');
    wutm_security_check(wutm_dashboard_webhook_url(str_replace('discord.com', 'example.test', $fake_webhook)) === '', 'Non-Discord URL rejected.');
    wutm_security_check(wutm_dashboard_webhook_url($fake_webhook . '?extra=1') === '', 'Webhook URL suffix rejected.');
    update_option('wutm_dashboard_discord_webhook', $fake_webhook, false);
    defined('DOING_AJAX') || define('DOING_AJAX', true);
    add_filter('wp_die_ajax_handler', static function () {
        return static function () { throw new LogicException('ajax_return'); };
    });
    $notifications = 0;
    add_filter('pre_http_request', static function ($response, $args, $url) use ($fake_webhook, &$notifications) {
        if ($url !== $fake_webhook) return $response;
        ++$notifications;
        wutm_security_check($args['redirection'] === 0 && $args['reject_unsafe_urls'], 'Webhook transport guarded.');
        return ['response'=>['code'=>204],'body'=>''];
    }, 10, 3);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $request = static function ($nonce) {
        $_REQUEST['nonce'] = $nonce;
        ob_start();
        try {
            wu_handle_advanced_plan_request();
        } catch (LogicException $error) {
            if ($error->getMessage() !== 'ajax_return') throw $error;
        } finally {
            $json = ob_get_clean();
        }
        return json_decode($json, true);
    };
    wutm_security_check(!$request('')['success'], 'Admin requires a nonce.');
    $subscriber = wp_insert_user(['user_login'=>'security416-fixture','user_pass'=>wp_generate_password(),'role'=>'subscriber']);
    wutm_security_check(is_int($subscriber), 'Created unprivileged fixture.');
    wp_set_current_user($subscriber);
    wutm_security_check(!$request(wp_create_nonce('wu_request_advanced_plan'))['success'], 'A valid lower-role nonce grants no access.');
    wp_set_current_user($admin_id);
    $nonce = wp_create_nonce('wu_request_advanced_plan');
    wutm_security_check($request($nonce)['success'] && !$request($nonce)['success'] && $notifications === 1, 'Only one valid notification per window.');
    $config = ['session_limit'=>3, 'ip_daily_limit'=>3, 'site_daily_limit'=>4, 'cooldown'=>0];
    $leases = [];
    for ($n = 0; $n < 3; $n++) {
        $rate = WUTM_KB_AI_Quota::reserve('session' . $n, 'fixture-ip', $config);
        wutm_security_check(!is_wp_error($rate) && $rate['site_n'] === $n + 1, 'Reservation increments before provider work.');
        $leases[] = $rate['lease'];
    }
    wutm_security_check(WUTM_KB_AI_Quota::reserve('another', 'fixture-ip-2', $config)->get_error_code() === 'busy', 'Fourth active request rejected.');
    WUTM_KB_AI_Quota::release($leases[0]);
    wutm_security_check(WUTM_KB_AI_Quota::reserve('another', 'fixture-ip', $config)->get_error_code() === 'ip_limit', 'Releasing the lease retains charged IP quota.');
    $fourth = WUTM_KB_AI_Quota::reserve('another', 'fixture-ip-2', $config);
    wutm_security_check(!is_wp_error($fourth), 'Slot frees after release.');
    WUTM_KB_AI_Quota::release($leases[1]);
    wutm_security_check(WUTM_KB_AI_Quota::reserve('different', 'fixture-ip-3', $config)->get_error_code() === 'site_limit', 'Charged requests retain the site cap.');

    $class = new ReflectionClass(WUTM_Data_Import_Export::class);
    $export = $class->getMethod('export_csv_row');
    $export->setAccessible(true);
    $instance = $class->newInstanceWithoutConstructor();
    $csv = static function ($format) use ($export, $instance) {
        $_REQUEST['csv_format'] = $format;
        $stream = fopen('php://temp', 'w+');
        $export->invoke($instance,$stream,['=fixture','＠fixture','normal, text']);
        rewind($stream);
        $data = fgetcsv($stream, 0, ',', '"', $format === 'raw' ? '\\' : '');
        fclose($stream);
        return $data;
    };
    wutm_security_check($csv('spreadsheet') === ["\t=fixture","\t＠fixture",'normal, text'], 'Spreadsheet fields neutralized.');
    wutm_security_check($csv('raw') === ['=fixture','＠fixture','normal, text'], 'Machine import values preserved.');
    echo "Security v4.1.6 native checks passed.\n";
} finally {
    wp_set_current_user($admin_id);
    if ($subscriber) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($subscriber);
    }
    if ($webhook_option === false) delete_option('wutm_dashboard_discord_webhook');
    else update_option('wutm_dashboard_discord_webhook', $webhook_option, false);
    delete_option('wutm_dashboard_window_plan');
    delete_option(WUTM_KB_AI_Quota::OPTION);
}
