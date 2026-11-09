<?php
defined('ABSPATH') || exit;

function wutm_website_optimization_settings(): array {
    return (array) get_option('wutm_website_optimization', []);
}

$wutm_optimization = wutm_website_optimization_settings();
if (!empty($wutm_optimization['disable_random'])) {
    add_filter('wp_enable_random_content_redirect', '__return_false');
}
if (!empty($wutm_optimization['slow_heartbeat'])) {
    add_filter('heartbeat_settings', static function (array $settings): array {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && !$screen->in_admin('network') &&
            ($screen->id === 'dashboard' || in_array($screen->id, ['edit-post', 'edit-page'], true))) {
            $settings['interval'] = max(60, (int) ($settings['interval'] ?? 0));
        }
        return $settings;
    });
}
if (!empty($wutm_optimization['skip_self_ping'])) {
    add_action('pre_ping', static function (array &$links): void {
        $home = wp_parse_url(home_url('/'));
        $base = rtrim($home['path'] ?? '', '/');
        foreach ($links as $index => $link) {
            $target = wp_parse_url($link);
            $path = $target['path'] ?? '/';
            if (!empty($home['host']) && strcasecmp($home['host'], $target['host'] ?? '') === 0
                && ($path === $base || strpos($path, $base . '/') === 0)) {
                unset($links[$index]);
            }
        }
    });
}
unset($wutm_optimization);

add_action('admin_menu', static function (): void {
    add_submenu_page('wu-toolbox-modular', '網站優化', '網站優化', 'manage_options', 'wu-website-optimization', 'wutm_render_website_optimization');
});

function wutm_render_website_optimization(): void {
    if (!current_user_can('manage_options')) return;

    $settings = wutm_website_optimization_settings();
    $supports_random = function_exists('wp_is_random_content_redirect_enabled');
    $diagnose = false;
    $saved = false;
    if (isset($_POST['wutm_optimization_action'])) {
        check_admin_referer('wutm_website_optimization');
        if ($_POST['wutm_optimization_action'] === 'save') {
            $next = [
                'disable_random' => $supports_random ? isset($_POST['disable_random']) : !empty($settings['disable_random']),
                'slow_heartbeat' => isset($_POST['slow_heartbeat']),
                'skip_self_ping' => isset($_POST['skip_self_ping']),
            ];
            if (update_option('wutm_website_optimization', $next, false) &&
                $next['disable_random'] !== !empty($settings['disable_random'])) {
                delete_option('rewrite_rules');
            }
            $settings = $next;
            $saved = true;
        } elseif ($_POST['wutm_optimization_action'] === 'diagnose') {
            $diagnose = true;
        }
    }

    wutm_admin_page_open('網站優化', '選擇本站需要的核心功能調整。各項預設關閉。');
    if ($saved) echo '<div class="notice notice-success inline"><p>已儲存網站優化設定。</p></div>';
    ?>
    <form method="post">
        <?php wp_nonce_field('wutm_website_optimization'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">隨機內容導向</th>
                <td>
                    <label><input type="checkbox" name="disable_random" value="1" <?php checked(!empty($settings['disable_random'])); disabled(!$supports_random); ?>> 關閉隨機內容導向</label>
                    <p class="description"><?php echo $supports_random ? '關閉 /random/ 及相關隨機導向網址。' : '目前的 WordPress 版本不支援此功能。'; ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">後台 Heartbeat</th>
                <td>
                    <label><input type="checkbox" name="slow_heartbeat" value="1" <?php checked(!empty($settings['slow_heartbeat'])); ?>> 降低後台 Heartbeat 頻率</label>
                    <p class="description">控制台、文章列表與頁面列表的輪詢間隔至少 60 秒。編輯頁與前台沿用原有設定。</p>
                </td>
            </tr>
            <tr>
                <th scope="row">站內 Pingback</th>
                <td>
                    <label><input type="checkbox" name="skip_self_ping" value="1" <?php checked(!empty($settings['skip_self_ping'])); ?>> 停止站內 Pingback</label>
                    <p class="description">發文連結到本站內容時，略過送給本站自己的通知。</p>
                </td>
            </tr>
        </table>
        <button class="button button-primary" name="wutm_optimization_action" value="save">儲存設定</button>
    </form>
    <h2>自動載入設定診斷</h2>
    <p>查看資料庫中的自動載入設定總大小、筆數與最大的 10 個項目。只讀取名稱與大小。</p>
    <form method="post">
        <?php wp_nonce_field('wutm_website_optimization'); ?>
        <button class="button" name="wutm_optimization_action" value="diagnose">檢查自動載入設定</button>
    </form>
    <?php
    if ($diagnose) wutm_render_autoload_diagnostics();
    wutm_admin_page_close();
}

function wutm_render_autoload_diagnostics(): void {
    global $wpdb;
    $autoload = function_exists('wp_autoload_values_to_autoload') ? wp_autoload_values_to_autoload() : ['yes'];
    if (!$autoload) {
        echo '<p>目前沒有符合的自動載入設定。</p>';
        return;
    }

    $placeholders = implode(', ', array_fill(0, count($autoload), '%s'));
    $totals = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS entries, SUM(OCTET_LENGTH(option_value)) AS bytes FROM {$wpdb->options} WHERE autoload IN ($placeholders)",
        $autoload
    ));
    $largest = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, OCTET_LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ($placeholders) ORDER BY bytes DESC, option_name LIMIT 10",
        $autoload
    ));
    if (!$totals || !is_array($largest)) {
        echo '<p>無法讀取自動載入設定，請稍後重試。</p>';
        return;
    }

    echo '<p role="status">共 ' . esc_html(number_format_i18n((int) $totals->entries)) . ' 筆，合計 ' . esc_html(size_format((int) $totals->bytes, 2)) . '。</p>';
    if (!$largest) return;
    echo '<table class="widefat striped"><thead><tr><th scope="col">設定名稱</th><th scope="col">大小</th></tr></thead><tbody>';
    foreach ($largest as $entry) {
        echo '<tr><th scope="row"><code>' . esc_html($entry->option_name) . '</code></th><td>' . esc_html(size_format((int) $entry->bytes, 2)) . '</td></tr>';
    }
    echo '</tbody></table>';
}
