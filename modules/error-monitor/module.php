<?php
defined('ABSPATH') || exit;

/**
 * 錯誤監控模組：手動啟用後記錄 PHP 錯誤、例外與致命錯誤。
 * 使用獨立 wutm_ 前綴，避免與原外掛或其他模組衝突。
 */
defined('WUTM_EM_RECORDS') || define('WUTM_EM_RECORDS', 'wutm_error_monitor_records');
defined('WUTM_EM_ACTIVE') || define('WUTM_EM_ACTIVE', 'wutm_error_monitor_active');
defined('WUTM_EM_MAX') || define('WUTM_EM_MAX', 100);

if (get_option(WUTM_EM_ACTIVE, '0') === '1') {
    set_error_handler('wutm_em_error_handler');
    set_exception_handler('wutm_em_exception_handler');
    register_shutdown_function('wutm_em_shutdown_handler');
}
function wutm_em_save($type, $message, $file = '', $line = 0, $trace = []) {
    static $saving = false;
    if ($saving) return;
    $saving = true;
    $records = get_option(WUTM_EM_RECORDS, []);
    if (!is_array($records)) $records = [];
    $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
    $fingerprint = md5(wp_json_encode([$type, $message, $file, $line, $uri]));
    if ($records) {
        $last = reset($records);
        if (isset($last['fingerprint'], $last['timestamp']) && $last['fingerprint'] === $fingerprint && time() - (int) $last['timestamp'] < 5) {
            $saving = false;
            return;
        }
    }
    array_unshift($records, [
        'time' => current_time('mysql'), 'timestamp' => time(), 'type' => sanitize_text_field($type),
        'message' => wp_strip_all_tags((string) $message), 'file' => (string) $file, 'line' => (int) $line,
        'request_uri' => $uri, 'method' => $method, 'trace' => is_array($trace) ? $trace : [], 'fingerprint' => $fingerprint,
    ]);
    update_option(WUTM_EM_RECORDS, array_slice($records, 0, WUTM_EM_MAX), false);
    $saving = false;
}
function wutm_em_prepare_trace($raw) {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $item) $out[] = ['file' => wp_normalize_path($item['file'] ?? ''), 'line' => (int) ($item['line'] ?? 0), 'class' => $item['class'] ?? '', 'type' => $item['type'] ?? '', 'function' => $item['function'] ?? ''];
    return $out;
}
function wutm_em_error_handler($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    $types = [E_ERROR=>'Fatal Error',E_WARNING=>'Warning',E_PARSE=>'Parse Error',E_NOTICE=>'Notice',E_CORE_ERROR=>'Core Error',E_CORE_WARNING=>'Core Warning',E_COMPILE_ERROR=>'Compile Error',E_COMPILE_WARNING=>'Compile Warning',E_USER_ERROR=>'User Error',E_USER_WARNING=>'User Warning',E_USER_NOTICE=>'User Notice',E_STRICT=>'Strict',E_RECOVERABLE_ERROR=>'Recoverable Error',E_DEPRECATED=>'Deprecated',E_USER_DEPRECATED=>'User Deprecated'];
    wutm_em_save($types[$errno] ?? 'PHP Error', $errstr, $errfile, $errline, wutm_em_prepare_trace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15)));
    return false;
}
function wutm_em_exception_handler($e) {
    wutm_em_save(is_object($e) ? get_class($e) : 'Exception', is_object($e) ? $e->getMessage() : 'Unknown exception', is_object($e) ? $e->getFile() : '', is_object($e) ? $e->getLine() : 0, is_object($e) && method_exists($e, 'getTrace') ? wutm_em_prepare_trace($e->getTrace()) : []);
}
function wutm_em_shutdown_handler() {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR], true)) return;
    wutm_em_save('Fatal Error', $error['message'] ?? '', $error['file'] ?? '', $error['line'] ?? 0);
}
add_action('admin_menu', function () {
    add_submenu_page('wu-toolbox-modular', '錯誤監控', '錯誤監控', 'manage_options', 'wu-error-monitor', 'wutm_em_admin_page');
}, 30);
add_action('admin_post_wutm_em_toggle', function () {
    if (!current_user_can('manage_options')) wp_die('Permission denied.');
    check_admin_referer('wutm_em_toggle');
    update_option(WUTM_EM_ACTIVE, get_option(WUTM_EM_ACTIVE, '0') === '1' ? '0' : '1', false);
    wp_safe_redirect(admin_url('admin.php?page=wu-error-monitor')); exit;
});
add_action('admin_post_wutm_em_clear', function () {
    if (!current_user_can('manage_options')) wp_die('Permission denied.');
    check_admin_referer('wutm_em_clear');
    delete_option(WUTM_EM_RECORDS);
    wp_safe_redirect(admin_url('admin.php?page=wu-error-monitor&cleared=1')); exit;
});
function wutm_em_admin_page(): void {
    if (!current_user_can('manage_options')) return;
    $active = get_option(WUTM_EM_ACTIVE, '0') === '1';
    $records = get_option(WUTM_EM_RECORDS, []);
    if (!is_array($records)) $records = [];
    $toggle = wp_nonce_url(admin_url('admin-post.php?action=wutm_em_toggle'), 'wutm_em_toggle');
    $clear = wp_nonce_url(admin_url('admin-post.php?action=wutm_em_clear'), 'wutm_em_clear');
    ?>
    <div class="wrap wutm-wrap wutm-module-settings">
      <header class="wutm-header"><div><h1><?php echo esc_html__('錯誤監控', 'wu-toolbox-modular'); ?></h1><p><?php echo esc_html__('記錄 PHP 錯誤、例外與致命錯誤，協助快速診斷網站問題。', 'wu-toolbox-modular'); ?></p></div><span>v<?php echo esc_html(WUTM_VERSION); ?></span></header>
      <section class="wutm-panel"><h2><?php echo esc_html__('監控狀態', 'wu-toolbox-modular'); ?></h2><p><strong><?php echo $active ? esc_html__('監控中', 'wu-toolbox-modular') : esc_html__('已停止', 'wu-toolbox-modular'); ?></strong> · <?php echo esc_html(sprintf(__('%d / %d 筆記錄', 'wu-toolbox-modular'), count($records), WUTM_EM_MAX)); ?></p><p><a class="button button-primary" href="<?php echo esc_url($toggle); ?>"><?php echo $active ? esc_html__('停止偵測', 'wu-toolbox-modular') : esc_html__('開始偵測', 'wu-toolbox-modular'); ?></a> <?php if ($records): ?><a class="button" href="<?php echo esc_url($clear); ?>" onclick="return confirm('<?php echo esc_js(__('確定清除所有錯誤記錄？', 'wu-toolbox-modular')); ?>');"><?php echo esc_html__('清除記錄', 'wu-toolbox-modular'); ?></a><?php endif; ?></p><p class="description"><?php echo esc_html__('只有啟用偵測後才會掛載錯誤處理器；記錄最多保留 100 筆。', 'wu-toolbox-modular'); ?></p></section>
      <section class="wutm-panel"><h2><?php echo esc_html__('錯誤記錄', 'wu-toolbox-modular'); ?></h2>
      <?php if (!$records): ?><p><?php echo esc_html__('目前沒有錯誤記錄。', 'wu-toolbox-modular'); ?></p><?php else: ?><div class="wutm-table-wrap"><table class="widefat striped"><thead><tr><th><?php echo esc_html__('時間', 'wu-toolbox-modular'); ?></th><th><?php echo esc_html__('類型', 'wu-toolbox-modular'); ?></th><th><?php echo esc_html__('訊息', 'wu-toolbox-modular'); ?></th><th><?php echo esc_html__('檔案', 'wu-toolbox-modular'); ?></th><th><?php echo esc_html__('行號', 'wu-toolbox-modular'); ?></th><th><?php echo esc_html__('請求', 'wu-toolbox-modular'); ?></th></tr></thead><tbody><?php foreach ($records as $r): ?><tr><td><?php echo esc_html($r['time'] ?? ''); ?></td><td><strong><?php echo esc_html($r['type'] ?? ''); ?></strong></td><td><?php echo esc_html($r['message'] ?? ''); ?></td><td><code><?php echo esc_html($r['file'] ?? ''); ?></code></td><td><?php echo esc_html((string) ($r['line'] ?? 0)); ?></td><td><?php echo esc_html(($r['method'] ?? '') . ' ' . ($r['request_uri'] ?? '')); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    </div>
    <?php
}
