<?php
/** Request-local presentation recovery. Never changes roles/options or licenses. */
defined('ABSPATH') || exit;
function wutm_dashboard_problems(): array { return $GLOBALS['wutm_dashboard_problems'] ?? []; }
function wutm_dashboard_record(string $stage, Throwable $error): void {
    if (isset($GLOBALS['wutm_dashboard_problems'][$stage])) return;
    $id = substr(hash('sha256', uniqid('wutm', true)), 0, 12);
    $GLOBALS['wutm_dashboard_problems'][$stage] = $id;
    // Do not log exception messages/arguments: plugins may include keys or PII.
    if (function_exists('error_log')) error_log('[WU Toolbox dashboard] ' . json_encode(['id'=>$id,'stage'=>$stage,'version'=>defined('WUTM_VERSION')?WUTM_VERSION:'unknown','type'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()]));
}
function wutm_dashboard_optional(string $stage, callable $callback, $fallback) {
    try { return $callback(); } catch (Throwable $error) { wutm_dashboard_record($stage, $error); return $fallback; }
}
function wutm_dashboard_fragment(string $stage, callable $callback): string {
    $level = ob_get_level(); ob_start();
    try { $callback(); return (string) ob_get_clean(); }
    catch (Throwable $error) {
        while (ob_get_level() > $level) ob_end_clean();
        wutm_dashboard_record($stage, $error);
        return '<section id="wutm-license" class="wutm-ui-panel"><h2>授權與更新</h2><p>此區塊暫時無法顯示，既有模組設定未被更動。請將下方檢查編號提供給維護人員。</p></section>';
    }
}
function wutm_dashboard_problem_notice(): void {
    $problems = wutm_dashboard_problems(); if (!$problems) return;
    echo '<div class="notice notice-warning inline wutm-dashboard-health" role="status"><p>部分面板資訊暫時無法讀取，請勿重設設定或重新啟用模組。檢查編號：<code>' . esc_html(implode('、', $problems)) . '</code>。請將編號及 PHP 錯誤紀錄提供給維護人員；記錄不包含授權碼或密碼。</p></div>';
}
