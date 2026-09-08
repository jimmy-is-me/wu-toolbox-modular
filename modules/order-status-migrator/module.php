<?php
/** WooCommerce 訂單狀態安全批次遷移工具。 */
defined('ABSPATH') || exit;

final class WUTM_Order_Status_Migrator {
    private const PAGE = 'wu-order-status-migrator';
    private const LOG_OPTION = 'wutm_order_status_migration_log';

    public function __construct() {
        if (!class_exists('WooCommerce')) return;
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_init', array($this, 'handle'));
    }

    public function menu(): void {
        add_submenu_page('wu-toolbox-modular', '訂單狀態遷移工具', '訂單狀態遷移', 'manage_woocommerce', self::PAGE, array($this, 'page'));
    }

    public function handle(): void {
        if (($_GET['page'] ?? '') !== self::PAGE || empty($_POST['wutm_migration_action'])) return;
        if (!current_user_can('manage_woocommerce')) wp_die('您沒有遷移訂單狀態的權限。');
        check_admin_referer('wutm_order_status_migration');
        $action = sanitize_key(wp_unslash($_POST['wutm_migration_action']));
        $base = admin_url('admin.php?page=' . self::PAGE);

        if ($action === 'clear_log') {
            delete_option(self::LOG_OPTION);
            wp_safe_redirect(add_query_arg('migration_result', 'log_cleared', $base));
            exit;
        }

        $from = $this->normalize_status($_POST['wutm_from_status'] ?? '');
        $to = $this->normalize_status($_POST['wutm_to_status'] ?? '');
        $batch = max(1, min(500, absint($_POST['wutm_batch_size'] ?? 100)));
        if (!$this->valid_status($from) || !$this->valid_status($to) || $from === $to) {
            wp_safe_redirect(add_query_arg('migration_result', 'invalid', $base));
            exit;
        }
        $available = function_exists('wutm_all_order_statuses') ? wutm_all_order_statuses() : wc_get_order_statuses();
        if (!isset($available[$to]) || (function_exists('wutm_disabled_order_statuses') && in_array($to, wutm_disabled_order_statuses(), true))) {
            wp_safe_redirect(add_query_arg('migration_result', 'target_unavailable', $base));
            exit;
        }

        $before = $this->count($from);
        $result = array('action' => $action, 'from' => $from, 'to' => $to, 'found' => $before, 'migrated' => 0, 'failed' => 0, 'remaining' => $before, 'time' => current_time('mysql'), 'storage' => $this->uses_hpos() ? 'HPOS' : '傳統文章資料表');
        if ($action === 'run') {
            foreach ($this->ids($from, $batch) as $order_id) {
                try {
                    $order = wc_get_order($order_id);
                    if (!($order instanceof WC_Order)) { $result['failed']++; continue; }
                    $order->update_status(substr($to, 3), '【訂單狀態遷移工具】由 ' . $from . ' 遷移至 ' . $to . '。', false);
                    $result['migrated']++;
                } catch (Throwable $error) {
                    $result['failed']++;
                }
            }
            $result['remaining'] = $this->count($from);
            $logs = get_option(self::LOG_OPTION, array());
            if (!is_array($logs)) $logs = array();
            array_unshift($logs, $result);
            update_option(self::LOG_OPTION, array_slice($logs, 0, 50), false);
        }
        set_transient('wutm_order_status_migration_' . get_current_user_id(), $result, 120);
        wp_safe_redirect(add_query_arg('migration_result', $action, $base));
        exit;
    }

    public function page(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('您沒有遷移訂單狀態的權限。');
        $result = get_transient('wutm_order_status_migration_' . get_current_user_id());
        if ($result) delete_transient('wutm_order_status_migration_' . get_current_user_id());
        $notice = sanitize_key(wp_unslash($_GET['migration_result'] ?? ''));
        $statuses = function_exists('wutm_all_order_statuses') ? wutm_all_order_statuses() : wc_get_order_statuses();
        $logs = get_option(self::LOG_OPTION, array());
        ?>
        <div class="wrap"><h1>🔄 訂單狀態遷移工具</h1>
            <p>透過 WooCommerce 訂單介面分批更新狀態，自動使用目前啟用的 <?php echo esc_html($this->uses_hpos() ? 'HPOS' : '傳統'); ?> 儲存模式，並讓相容性同步機制正常運作。</p>
            <?php if ($notice === 'invalid') : ?><div class="notice notice-error"><p>來源與目標狀態必須有效，而且不能相同。</p></div><?php endif; ?>
            <?php if ($notice === 'target_unavailable') : ?><div class="notice notice-error"><p>目標狀態不存在或目前已停用，請先到「訂單狀態管理」建立或啟用。</p></div><?php endif; ?>
            <?php if ($notice === 'log_cleared') : ?><div class="notice notice-success"><p>遷移紀錄已清除。</p></div><?php endif; ?>
            <?php if (is_array($result)) : ?><div class="notice notice-<?php echo $result['action'] === 'run' ? 'success' : 'info'; ?>"><p><strong><?php echo $result['action'] === 'run' ? '本批次完成' : '預覽完成（尚未修改）'; ?></strong>：<?php echo esc_html($result['from']); ?> → <?php echo esc_html($result['to']); ?>；找到 <?php echo absint($result['found']); ?> 筆，已遷移 <?php echo absint($result['migrated']); ?> 筆，失敗 <?php echo absint($result['failed']); ?> 筆，剩餘 <?php echo absint($result['remaining']); ?> 筆。</p></div><?php endif; ?>
            <div style="max-width:760px;padding:20px 24px;background:#fff;border:1px solid #c3c4c7;border-radius:6px">
                <form method="post"><table class="form-table"><tr><th><label for="wutm_from_status">來源狀態</label></th><td><input id="wutm_from_status" name="wutm_from_status" class="regular-text" list="wutm-status-list" required placeholder="wc-old-status"><p class="description">可輸入已不存在於選單中的舊狀態代碼。</p></td></tr><tr><th><label for="wutm_to_status">目標狀態</label></th><td><select id="wutm_to_status" name="wutm_to_status" required><option value="">請選擇</option><?php foreach ($statuses as $status => $label) : if (function_exists('wutm_disabled_order_statuses') && in_array($status, wutm_disabled_order_statuses(), true)) continue; ?><option value="<?php echo esc_attr($status); ?>"><?php echo esc_html($label . ' (' . $status . ')'); ?></option><?php endforeach; ?></select></td></tr><tr><th><label for="wutm_batch_size">每批處理筆數</label></th><td><input id="wutm_batch_size" type="number" name="wutm_batch_size" value="100" min="1" max="500"><p class="description">大量訂單請分批執行，降低逾時風險。</p></td></tr></table>
                    <datalist id="wutm-status-list"><?php foreach ($statuses as $status => $label) : ?><option value="<?php echo esc_attr($status); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></datalist>
                    <?php wp_nonce_field('wutm_order_status_migration'); ?>
                    <p><button class="button" name="wutm_migration_action" value="preview">預覽筆數</button> <button class="button button-primary" name="wutm_migration_action" value="run" onclick="return confirm('確定執行這一批訂單狀態遷移？請先備份資料庫。')">執行一批遷移</button></p>
                </form>
            </div>
            <?php if (is_array($logs) && $logs) : ?><h2>最近 50 次執行紀錄</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>時間</th><th>來源</th><th>目標</th><th>儲存模式</th><th>成功</th><th>失敗</th><th>剩餘</th></tr></thead><tbody><?php foreach ($logs as $log) : ?><tr><td><?php echo esc_html($log['time'] ?? ''); ?></td><td><code><?php echo esc_html($log['from'] ?? ''); ?></code></td><td><code><?php echo esc_html($log['to'] ?? ''); ?></code></td><td><?php echo esc_html($log['storage'] ?? ''); ?></td><td><?php echo absint($log['migrated'] ?? 0); ?></td><td><?php echo absint($log['failed'] ?? 0); ?></td><td><?php echo absint($log['remaining'] ?? 0); ?></td></tr><?php endforeach; ?></tbody></table><form method="post" style="margin-top:10px"><?php wp_nonce_field('wutm_order_status_migration'); ?><button class="button button-link-delete" name="wutm_migration_action" value="clear_log" onclick="return confirm('確定清除遷移紀錄？')">清除紀錄</button></form><?php endif; ?>
        </div>
        <?php
    }

    private function normalize_status($status): string {
        $status = sanitize_key(wp_unslash((string) $status));
        return strpos($status, 'wc-') === 0 ? $status : 'wc-' . $status;
    }

    private function valid_status(string $status): bool {
        return (bool) preg_match('/^wc-[a-z0-9_-]+$/', $status) && strlen($status) <= 20;
    }

    private function uses_hpos(): bool {
        return class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    private function count(string $status): int {
        global $wpdb;
        if ($this->uses_hpos()) return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND status = %s", $status));
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status = %s", $status));
    }

    private function ids(string $status, int $limit): array {
        global $wpdb;
        if ($this->uses_hpos()) return array_map('absint', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND status = %s ORDER BY id ASC LIMIT %d", $status, $limit)));
        return array_map('absint', (array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status = %s ORDER BY ID ASC LIMIT %d", $status, $limit)));
    }
}

new WUTM_Order_Status_Migrator();
