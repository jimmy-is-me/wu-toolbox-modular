<?php
/**
 * 多語言管理模組
 *
 * 提供語言數量解鎖，以及 TranslatePress 翻譯資料的 JSON 匯出／匯入。
 * 所有功能只在模組啟用且相依外掛存在時載入。
 */
defined('ABSPATH') || exit;

if (!class_exists('TRP_Translate_Press')) {
    return;
}

final class WUTM_TranslatePress_Addons {
    private const MAX_LANGUAGES = 1000;

    public static function boot(): void {
        add_filter('trp_secondary_languages', [__CLASS__, 'unlock_languages'], 5);
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 30);
        add_action('admin_post_wutm_tp_export', [__CLASS__, 'export_translations']);
        add_action('admin_post_wutm_tp_import', [__CLASS__, 'import_translations']);
    }

    public static function unlock_languages($limit): int {
        return max((int) $limit, self::MAX_LANGUAGES);
    }

    public static function admin_menu(): void {
        add_submenu_page('wu-toolbox-modular', '多語言管理', '多語言管理', 'manage_options', 'wu-translatepress-addons', [__CLASS__, 'render_page']);
    }

    private static function languages(): array {
        $trp = get_option('trp_settings', []);
        if (!is_array($trp)) return [];
        $languages = [];
        $default = $trp['default-language'] ?? '';
        $additional = $trp['translation-languages'] ?? [];
        if (is_string($default) && $default !== '') $languages[] = $default;
        if (is_array($additional)) foreach ($additional as $language) if (is_string($language) && $language !== '') $languages[] = $language;
        return array_values(array_unique($languages));
    }

    private static function table_names(): array {
        global $wpdb;
        $prefix = $wpdb->prefix . 'trp_';
        $like = $wpdb->esc_like($prefix) . '%';
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        return array_values(array_filter((array) $tables, static function ($table) use ($prefix) {
            return is_string($table) && strpos($table, $prefix) === 0 && preg_match('/^[A-Za-z0-9_]+$/', $table);
        }));
    }

    private static function export_rows(string $table): array {
        global $wpdb;
        $columns = $wpdb->get_col('DESCRIBE ' . $table, 0);
        $wanted = array_values(array_intersect(['original', 'translated', 'status', 'domain'], (array) $columns));
        if (!in_array('original', $wanted, true) || !in_array('translated', $wanted, true)) return [];
        $select = implode(', ', $wanted);
        $rows = $wpdb->get_results('SELECT ' . $select . ' FROM ' . $table . ' WHERE original <> \'\'', ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public static function export_translations(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('wutm_tp_export')) wp_die('安全驗證失敗', 403);
        $payload = [
            'format' => 'wutm-translatepress-json',
            'version' => '1.0',
            'site' => home_url('/'),
            'exported_at' => gmdate('c'),
            'languages' => self::languages(),
            'tables' => [],
        ];
        foreach (self::table_names() as $table) {
            $rows = self::export_rows($table);
            if ($rows) $payload['tables'][$table] = $rows;
        }
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="wutm-translations-' . gmdate('Ymd-His') . '.json"');
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public static function import_translations(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('wutm_tp_import')) wp_die('安全驗證失敗', 403);
        $redirect = admin_url('admin.php?page=wu-translatepress-addons');
        if (empty($_FILES['translation_file']['tmp_name']) || !is_uploaded_file($_FILES['translation_file']['tmp_name'])) {
            wp_safe_redirect(add_query_arg('wutm_import', 'missing', $redirect));
            exit;
        }
        $raw = file_get_contents($_FILES['translation_file']['tmp_name']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $data = json_decode((string) $raw, true);
        $updated = 0;
        if (is_array($data) && !empty($data['tables']) && is_array($data['tables'])) {
            global $wpdb;
            $allowed = array_flip(self::table_names());
            foreach ($data['tables'] as $table => $rows) {
                if (!isset($allowed[$table]) || !is_array($rows) || !preg_match('/^[A-Za-z0-9_]+$/', (string) $table)) continue;
                foreach ($rows as $row) {
                    if (empty($row['original']) || !isset($row['translated'])) continue;
                    $values = ['translated' => (string) $row['translated']];
                    $formats = ['%s'];
                    if (isset($row['status'])) { $values['status'] = (int) $row['status']; $formats[] = '%d'; }
                    $result = $wpdb->update($table, $values, ['original' => (string) $row['original']], $formats, ['%s']);
                    if ($result !== false && $result > 0) $updated++;
                }
            }
        }
        wp_safe_redirect(add_query_arg('wutm_import', $updated, $redirect));
        exit;
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;
        $languages = self::languages();
        $count = count($languages);
        $running = class_exists('TRP_Translate_Press');
        $notice = isset($_GET['wutm_import']) ? sanitize_text_field(wp_unslash($_GET['wutm_import'])) : '';
        ?>
        <div class="wrap wutm-module-wrap">
            <header class="wutm-header"><div><h1>🌐 多語言管理</h1><p>語言數量解鎖與翻譯資料匯出／匯入。</p></div><span><?php echo $running ? '運作中' : '待設定'; ?></span></header>
            <?php if ($notice !== ''): ?><div class="notice notice-success"><p><?php echo $notice === 'missing' ? '未選擇匯入檔案。' : '翻譯匯入完成，更新 ' . esc_html($notice) . ' 筆資料。'; ?></p></div><?php endif; ?>
            <div class="wutm-grid" style="margin-top:20px">
                <section class="wu-stat-box"><h2>已設定語言數量</h2><p style="font-size:42px;line-height:1;margin:8px 0;color:var(--wutm-wp-blue)"><?php echo esc_html((string) $count); ?></p><p class="description">已移除次要語言限制，最多可設定 <?php echo esc_html((string) self::MAX_LANGUAGES); ?> 種語言。</p></section>
                <section class="wu-stat-box"><h2>模組狀態</h2><p><strong><?php echo $running ? '運作中' : '尚未設定'; ?></strong></p><p class="description"><?php echo $running ? '語言解鎖與翻譯資料工具已載入。' : '請先啟用 TranslatePress。'; ?></p></section>
            </div>
            <section class="wu-info-panel" style="margin-top:20px"><h2>翻譯匯出／匯入</h2><p>可將全站翻譯資料匯出成 JSON，外部編修後再匯入。匯入前建議先保留一份備份。</p><p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wutm_tp_export'), 'wutm_tp_export')); ?>">匯出全部翻譯</a></p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data"><input type="hidden" name="action" value="wutm_tp_import"><?php wp_nonce_field('wutm_tp_import'); ?><input type="file" name="translation_file" accept="application/json,.json" required><?php submit_button('匯入翻譯', 'secondary', 'submit', false); ?></form></section>
            <section class="wu-info-panel" style="margin-top:20px"><h2>已設定語言</h2><?php if ($languages): ?><ul><?php foreach ($languages as $language): ?><li><?php echo esc_html($language); ?></li><?php endforeach; ?></ul><?php else: ?><p>目前尚未讀取到語言設定，請先在 TranslatePress 設定語言。</p><?php endif; ?></section>
        </div>
        <?php
    }
}
WUTM_TranslatePress_Addons::boot();
