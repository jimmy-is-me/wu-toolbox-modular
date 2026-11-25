<?php
if (!defined('WP_CLI') || getenv('WUTM_MEDIA_INTEGRATION') !== '1' || DB_NAME !== 'wci_fixture') {
    throw new RuntimeException('Disposable WordPress fixture only.');
}

require_once ABSPATH . 'wp-admin/includes/admin.php';
defined('WP_ADMIN') || define('WP_ADMIN', true);
require_once dirname(__DIR__) . '/modules/advanced-tracking-manager/module.php';
foreach (['class-event-queue','class-observability','class-event-admin','class-consent-admin'] as $admin_class) {
    require_once WUTM_ATM_DIR . 'includes/' . $admin_class . '.php';
}

$saved = get_option('atm_last_diagnostic');
$gtm_options = ['wutm_atm_privacy'=>['enabled'=>true,'gtm'=>'analytics'],
    'atm_gtm_enabled'=>1, 'atm_gtm_id'=>'GTM-FIXTURE'];
$previous = [];
foreach ($gtm_options as $name=>$value) {
    $previous[$name] = get_option($name);
    update_option($name, $value);
}
$frontend = static function ($response, $args, $url) {
    if ($url !== home_url('/')) return $response;
    return ['response'=>['code'=>200], 'body'=>'<html><body>Fixture</body></html>', 'headers'=>[]];
};
update_option('atm_last_diagnostic', ['items'=>[
    ['status'=>'success', 'title'=>'已儲存的診斷', 'message'=>'原有紀錄'],
]]);

try {
    $tab_ids = ['overview','tracking','events','privacy','history','diagnostic','guide'];
    $render = static function () {
        ob_start();
        require WUTM_ATM_DIR . 'templates/admin-page.php';
        return ob_get_clean();
    };
    foreach (array_merge($tab_ids, ['invalid']) as $requested) {
        $_GET['atm_tab'] = $requested;
        $current = $requested === 'invalid' ? 'overview' : $requested;
        $html = $render();
        if (substr_count($html, 'aria-current="page"') !== 1 ||
            strpos($html, 'id="atm-tab-' . $current . '"') === false ||
            substr_count($html, 'class="atm-tab-content"') !== 1) {
            throw new RuntimeException('Incorrect active panel for ' . $requested);
        }
        foreach ($tab_ids as $tab) {
            if (strpos($html, 'atm_tab=' . $tab . '"') === false) {
                throw new RuntimeException('Missing native navigation link for ' . $tab);
            }
        }
        if (preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{23F3}\x{25B6}]/u', $html)) {
            throw new RuntimeException('Unexpected emoji in ' . $requested);
        }
        if ($current === 'overview' && (strpos($html, '測試工具') === false ||
            strpos($html, '處理到期事件') === false || strpos($html, '驗收清單') !== false || strpos($html, '驗收報告') !== false)) {
            throw new RuntimeException('Overview must keep testing tools without the checklist or report.');
        }
        if ($current === 'tracking' && (strpos($html, '#wutm-atm-gtm-consent') === false ||
            strpos($html, '分析（限純分析容器）') === false)) {
            throw new RuntimeException('Tracking settings must show the configured GTM consent category.');
        }
        if ($current === 'history' && strpos($html, 'wutm_atm_export_event_csv') === false) {
            throw new RuntimeException('Event CSV must be available from history.');
        }
        if ($current === 'diagnostic' && strpos($html, '通過 · 已儲存的診斷') === false) {
            throw new RuntimeException('Saved diagnostics failed to render.');
        }
    }
    add_filter('pre_http_request', $frontend, 10, 3);
    $diagnose = new ReflectionMethod(WUTM_Advanced_Tracking_Manager::class, 'run_diagnostics');
    $diagnose->setAccessible(true);
    $diagnostic = $diagnose->invoke(WUTM_Advanced_Tracking_Manager::get_instance());
    $items = array_column($diagnostic['items'], 'message', 'title');
    if (($items['GTM 容器載入條件'] ?? '') !== 'GTM-FIXTURE · 分析（限純分析容器）' ||
        isset($items['找不到 GTM Container ID'])) {
        throw new RuntimeException('A static fetch cannot establish that consent-managed GTM is missing.');
    }
    update_option('atm_gtm_id', 'G-FIXTURE');
    $diagnostic = $diagnose->invoke(WUTM_Advanced_Tracking_Manager::get_instance());
    if (!in_array('GTM 已啟用但容器 ID 無效', array_column($diagnostic['items'], 'title'), true)) {
        throw new RuntimeException('Diagnostics must flag an invalid GTM container ID.');
    }
    echo "Native tracking admin tabs and GTM diagnostics passed.\n";
} finally {
    remove_filter('pre_http_request', $frontend, 10);
    foreach ($previous as $name=>$value) {
        if ($value === false) delete_option($name);
        else update_option($name, $value);
    }
    if ($saved === false) delete_option('atm_last_diagnostic');
    else update_option('atm_last_diagnostic', $saved);
}
