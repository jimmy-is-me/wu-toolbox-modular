<?php
if (!defined('WP_CLI') || getenv('WUTM_MEDIA_INTEGRATION') !== '1' || DB_NAME !== 'wci_fixture') {
    throw new RuntimeException('Disposable WordPress fixture only.');
}

require_once ABSPATH . 'wp-admin/includes/admin.php';
defined('WP_ADMIN') || define('WP_ADMIN', true);
require_once dirname(__DIR__) . '/modules/advanced-tracking-manager/module.php';

$saved = get_option('atm_last_diagnostic');
update_option('atm_last_diagnostic', ['items'=>[
    ['status'=>'success', 'title'=>'已儲存的診斷', 'message'=>'原有紀錄'],
]]);

try {
    $tab_ids = ['tracking','events','privacy','history','diagnostic','guide'];
    $render = static function () {
        ob_start();
        require WUTM_ATM_DIR . 'templates/admin-page.php';
        return ob_get_clean();
    };
    foreach (array_merge($tab_ids, ['invalid']) as $requested) {
        $_GET['atm_tab'] = $requested;
        $current = $requested === 'invalid' ? 'tracking' : $requested;
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
        if ($current === 'diagnostic' && strpos($html, '通過 · 已儲存的診斷') === false) {
            throw new RuntimeException('Saved diagnostics failed to render.');
        }
    }
    echo "Native tracking admin tab checks passed.\n";
} finally {
    if ($saved === false) delete_option('atm_last_diagnostic');
    else update_option('atm_last_diagnostic', $saved);
}
