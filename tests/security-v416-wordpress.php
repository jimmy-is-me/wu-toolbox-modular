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

try {
    $fake_webhook = 'https://discord.com/api/webhooks/' . str_repeat('1', 18) . '/' . str_repeat('a', 60);
    wutm_security_check(wutm_dashboard_webhook_url($fake_webhook) === $fake_webhook, 'Expected HTTPS webhook accepted.');
    wutm_security_check(wutm_dashboard_webhook_url(str_replace('discord.com', 'example.test', $fake_webhook)) === '', 'Non-Discord URL rejected.');
    wutm_security_check(wutm_dashboard_webhook_url($fake_webhook . '?extra=1') === '', 'Webhook URL suffix rejected.');
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
    delete_option(WUTM_KB_AI_Quota::OPTION);
}
