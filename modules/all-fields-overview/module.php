<?php
/** 顯示網站所有欄位。 */
defined('ABSPATH') || exit;

final class WUTM_All_Fields_Overview {
    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu'], 20);
    }

    public function register_menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '顯示網站所有欄位',
            '顯示網站所有欄位',
            'manage_options',
            'wu-all-fields-overview',
            [$this, 'render_page']
        );
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) wp_die(esc_html__('您沒有管理此工具的權限。', 'wu-toolbox-modular'));
        global $wpdb;

        echo '<div class="wrap wutm-fields-overview"><h1>顯示網站所有欄位</h1>';
        echo '<p>集中檢視目前已註冊或曾儲存的 ACF、文章、使用者、分類及留言欄位。此工具只讀取資料，不會修改欄位。</p>';
        echo '<p><input type="search" id="wutm-field-search" class="regular-text" placeholder="搜尋欄位標籤、名稱、類型或 Key"></p>';
        echo '<style>.wutm-fields-overview h2{margin-top:28px;border-left:4px solid #2271b1;padding-left:10px}.wutm-fields-overview table{margin:10px 0 18px}.wutm-fields-overview code{font-size:12px}.wutm-field-type{display:inline-block;padding:2px 7px;border-radius:4px;background:#e8f3fc;color:#135e96}.wutm-field-count{font-size:13px;color:#646970;font-weight:400}</style>';

        echo '<section class="wutm-field-section"><h2>ACF 欄位群組</h2>';
        if (function_exists('acf_get_field_groups')) {
            $groups = (array) acf_get_field_groups();
            if (!$groups) echo '<p>目前沒有 ACF 欄位群組。</p>';
            foreach ($groups as $group) {
                $fields = (array) acf_get_fields($group['key']);
                echo '<h3>' . esc_html($group['title']) . ' <span class="wutm-field-count">(' . esc_html((string) $this->count_acf($fields)) . ' 個欄位)</span></h3>';
                $this->render_acf_table($fields);
            }
        } else {
            echo '<p>ACF 未安裝或未啟用。</p>';
        }
        echo '</section>';

        $sources = [
            '文章 Meta' => $wpdb->postmeta,
            '使用者 Meta' => $wpdb->usermeta,
            '分類 Meta' => $wpdb->termmeta,
            '留言 Meta' => $wpdb->commentmeta,
        ];
        foreach ($sources as $label => $table) {
            $keys = $wpdb->get_col("SELECT DISTINCT meta_key FROM {$table} ORDER BY meta_key ASC"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            echo '<section class="wutm-field-section"><h2>' . esc_html($label) . ' <span class="wutm-field-count">(' . esc_html((string) count($keys)) . ' 筆)</span></h2>';
            $this->render_meta_table($keys);
            echo '</section>';
        }

        echo '<script>document.getElementById("wutm-field-search").addEventListener("input",function(){var q=this.value.toLowerCase();document.querySelectorAll(".wutm-fields-overview tbody tr").forEach(function(row){row.style.display=!q||row.textContent.toLowerCase().indexOf(q)!==-1?"":"none";});});</script></div>';
    }

    private function render_acf_table(array $fields, int $depth = 0): void {
        if (!$fields) { echo '<p>此群組沒有欄位。</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>欄位標籤</th><th>欄位名稱</th><th>類型</th><th>欄位 Key</th></tr></thead><tbody>';
        foreach ($fields as $field) {
            echo '<tr><td>' . esc_html(str_repeat('— ', $depth) . ($field['label'] ?? '')) . '</td><td><code>' . esc_html($field['name'] ?? '') . '</code></td><td><span class="wutm-field-type">' . esc_html($field['type'] ?? '') . '</span></td><td><code>' . esc_html($field['key'] ?? '') . '</code></td></tr>';
            if (!empty($field['sub_fields'])) $this->render_acf_rows((array) $field['sub_fields'], $depth + 1);
            foreach ((array) ($field['layouts'] ?? []) as $layout) {
                if (!empty($layout['sub_fields'])) $this->render_acf_rows((array) $layout['sub_fields'], $depth + 1, (string) ($layout['label'] ?? ''));
            }
        }
        echo '</tbody></table>';
    }

    private function render_acf_rows(array $fields, int $depth, string $layout = ''): void {
        foreach ($fields as $field) {
            $label = ($layout !== '' ? $layout . ' / ' : '') . ($field['label'] ?? '');
            echo '<tr><td>' . esc_html(str_repeat('— ', $depth) . $label) . '</td><td><code>' . esc_html($field['name'] ?? '') . '</code></td><td><span class="wutm-field-type">' . esc_html($field['type'] ?? '') . '</span></td><td><code>' . esc_html($field['key'] ?? '') . '</code></td></tr>';
            if (!empty($field['sub_fields'])) $this->render_acf_rows((array) $field['sub_fields'], $depth + 1);
            foreach ((array) ($field['layouts'] ?? []) as $child_layout) {
                if (!empty($child_layout['sub_fields'])) $this->render_acf_rows((array) $child_layout['sub_fields'], $depth + 1, (string) ($child_layout['label'] ?? ''));
            }
        }
    }

    private function render_meta_table(array $keys): void {
        if (!$keys) { echo '<p>沒有找到欄位。</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th style="width:60px">#</th><th>meta_key（欄位名稱）</th></tr></thead><tbody>';
        foreach ($keys as $index => $key) echo '<tr><td>' . esc_html((string) ($index + 1)) . '</td><td><code>' . esc_html((string) $key) . '</code></td></tr>';
        echo '</tbody></table>';
    }

    private function count_acf(array $fields): int {
        $count = 0;
        foreach ($fields as $field) {
            $count++;
            if (!empty($field['sub_fields'])) $count += $this->count_acf((array) $field['sub_fields']);
            foreach ((array) ($field['layouts'] ?? []) as $layout) {
                if (!empty($layout['sub_fields'])) $count += $this->count_acf((array) $layout['sub_fields']);
            }
        }
        return $count;
    }
}

new WUTM_All_Fields_Overview();
