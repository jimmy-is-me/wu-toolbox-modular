<?php
/**
 * Module: global-text-replace
 * Safely search and replace text across selected WordPress database content.
 */
defined('ABSPATH') || exit;

class WUTM_Global_Text_Replace {
    private const SLUG = 'wu-global-text-replace';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu'], 15);
    }

    public function menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '全站文字搜尋與取代',
            '全站文字取代',
            'manage_options',
            self::SLUG,
            [$this, 'page']
        );
    }

    private function targets(): array {
        global $wpdb;
        return [
            'posts' => ['label' => '文章、頁面、商品與內容', 'table' => $wpdb->posts, 'pk' => 'ID', 'fields' => ['post_title', 'post_content', 'post_excerpt']],
            'postmeta' => ['label' => '文章／商品 Meta 與 Elementor 資料', 'table' => $wpdb->postmeta, 'pk' => 'meta_id', 'fields' => ['meta_value']],
            'terms' => ['label' => '分類與標籤名稱', 'table' => $wpdb->terms, 'pk' => 'term_id', 'fields' => ['name']],
            'term_taxonomy' => ['label' => '分類與標籤描述', 'table' => $wpdb->term_taxonomy, 'pk' => 'term_taxonomy_id', 'fields' => ['description']],
            'termmeta' => ['label' => '分類 Meta', 'table' => $wpdb->termmeta, 'pk' => 'meta_id', 'fields' => ['meta_value']],
            'options' => ['label' => '網站與外掛設定', 'table' => $wpdb->options, 'pk' => 'option_id', 'fields' => ['option_value']],
            'comments' => ['label' => '留言內容', 'table' => $wpdb->comments, 'pk' => 'comment_ID', 'fields' => ['comment_content']],
            'commentmeta' => ['label' => '留言 Meta', 'table' => $wpdb->commentmeta, 'pk' => 'meta_id', 'fields' => ['meta_value']],
            'usermeta' => ['label' => '使用者 Meta', 'table' => $wpdb->usermeta, 'pk' => 'umeta_id', 'fields' => ['meta_value']],
        ];
    }

    private function default_targets(): array {
        return ['posts', 'postmeta', 'terms', 'term_taxonomy', 'termmeta', 'options'];
    }

    private function recursive_replace($value, string $search, string $replace) {
        if (is_string($value)) return str_replace($search, $replace, $value);
        if (!is_array($value)) return $value;
        foreach ($value as $key => $item) $value[$key] = $this->recursive_replace($item, $search, $replace);
        return $value;
    }

    /**
     * Returns the changed value. Serialized objects and unreadable structures are skipped.
     */
    private function safe_replace(string $value, string $search, string $replace, bool &$skipped): string {
        $skipped = false;
        if (is_serialized($value)) {
            $data = @unserialize(trim($value), ['allowed_classes' => false]);
            if ($data === false && trim($value) !== 'b:0;') {
                $skipped = true;
                return $value;
            }
            if (is_object($data)) {
                $skipped = true;
                return $value;
            }
            return serialize($this->recursive_replace($data, $search, $replace));
        }

        $trimmed = ltrim($value);
        if (($trimmed !== '') && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $json = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                return wp_json_encode($this->recursive_replace($json, $search, $replace), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return str_replace($search, $replace, $value);
    }

    private function process(string $search, string $replace, array $selected, bool $execute): array {
        global $wpdb;
        $result = ['rows' => 0, 'occurrences' => 0, 'updated' => 0, 'skipped' => 0, 'details' => []];
        $targets = $this->targets();
        $like = '%' . $wpdb->esc_like($search) . '%';

        foreach ($selected as $key) {
            if (!isset($targets[$key])) continue;
            $config = $targets[$key];
            $counts = ['rows' => 0, 'occurrences' => 0, 'updated' => 0, 'skipped' => 0];

            foreach ($config['fields'] as $field) {
                $query = $wpdb->prepare("SELECT {$config['pk']}, {$field} FROM {$config['table']} WHERE {$field} LIKE %s", $like);
                $rows = $wpdb->get_results($query);
                foreach ((array) $rows as $row) {
                    $old = $row->{$field};
                    if (!is_string($old)) continue;
                    $occurrences = substr_count($old, $search);
                    if ($occurrences < 1) continue;
                    $counts['rows']++;
                    $counts['occurrences'] += $occurrences;
                    if (!$execute) continue;

                    $skipped = false;
                    $new = $this->safe_replace($old, $search, $replace, $skipped);
                    if ($skipped) {
                        $counts['skipped']++;
                        continue;
                    }
                    if ($new === $old) continue;
                    $updated = $wpdb->update($config['table'], [$field => $new], [$config['pk'] => $row->{$config['pk']}], ['%s']);
                    if ($updated !== false) $counts['updated']++;
                }
            }

            foreach ($counts as $name => $count) $result[$name] += $count;
            $result['details'][] = ['label' => $config['label']] + $counts;
        }
        return $result;
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('您沒有權限操作此功能。');

        $search = '';
        $replace = '';
        $selected = $this->default_targets();
        $result = null;
        $mode = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wutm_global_replace_action'])) {
            check_admin_referer('wutm_global_text_replace');
            $search = sanitize_text_field(wp_unslash($_POST['search_text'] ?? ''));
            $replace = sanitize_text_field(wp_unslash($_POST['replace_text'] ?? ''));
            $selected = array_values(array_intersect(array_map('sanitize_key', (array) ($_POST['targets'] ?? [])), array_keys($this->targets())));
            $mode = sanitize_key(wp_unslash($_POST['wutm_global_replace_action']));

            if ($search === '') {
                echo '<div class="notice notice-error"><p>搜尋文字不能是空白。</p></div>';
            } elseif (!$selected) {
                echo '<div class="notice notice-error"><p>請至少選擇一個搜尋範圍。</p></div>';
            } elseif ($mode === 'replace' && empty($_POST['confirm_replace'])) {
                echo '<div class="notice notice-error"><p>請確認您已完成資料庫備份，才能執行正式取代。</p></div>';
            } else {
                $result = $this->process($search, $replace, $selected, $mode === 'replace');
                if ($mode === 'replace') {
                    wp_cache_flush();
                    if (function_exists('wc_delete_product_transients')) wc_delete_product_transients();
                }
            }
        }
        ?>
        <div class="wrap wutm-module-wrap wutm-text-replace">
            <h1>全站文字搜尋與取代</h1>
            <p class="wutm-module-subtitle">先使用「模擬搜尋」確認命中範圍，再執行正式取代。此工具只修改您勾選的資料範圍。</p>
            <div class="notice notice-warning inline"><p><strong>重要：</strong>正式取代會直接修改資料庫，無法自動復原。請先完成完整資料庫備份，並先使用模擬搜尋確認結果。</p></div>

            <section class="wutm-ordering-panel">
                <h2>搜尋與取代設定</h2>
                <form method="post">
                    <?php wp_nonce_field('wutm_global_text_replace'); ?>
                    <table class="form-table" role="presentation">
                        <tr><th scope="row"><label for="wutm-search-text">搜尋文字</label></th><td><input id="wutm-search-text" name="search_text" type="text" class="regular-text" value="<?php echo esc_attr($search); ?>" required><p class="description">輸入要尋找的完整文字，例如舊公司名稱、網址或產品名稱。</p></td></tr>
                        <tr><th scope="row"><label for="wutm-replace-text">取代成</label></th><td><input id="wutm-replace-text" name="replace_text" type="text" class="regular-text" value="<?php echo esc_attr($replace); ?>"><p class="description">可留白以刪除搜尋文字。JSON、Elementor 與序列化陣列資料會以安全方式重新建立。</p></td></tr>
                        <tr><th scope="row">搜尋範圍</th><td><p class="description">只勾選需要的範圍可縮短執行時間，並降低誤改設定的風險。</p><div class="wutm-target-list"><?php foreach ($this->targets() as $key => $target): ?><label class="wutm-inline-choice"><input type="checkbox" name="targets[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $selected, true)); ?>> <?php echo esc_html($target['label']); ?></label><?php endforeach; ?></div></td></tr>
                    </table>
                    <p class="wutm-action-row"><button type="submit" name="wutm_global_replace_action" value="scan" class="button button-secondary">模擬搜尋</button> <label class="wutm-confirm-replace"><input type="checkbox" name="confirm_replace" value="1"> 我已完成資料庫備份，了解正式取代會直接修改資料。</label> <button type="submit" name="wutm_global_replace_action" value="replace" class="button button-primary">正式取代</button></p>
                </form>
            </section>

            <?php if ($result !== null): ?>
            <section class="wutm-ordering-panel wutm-replace-results">
                <h2><?php echo $mode === 'replace' ? '取代結果' : '模擬搜尋結果'; ?></h2>
                <p><?php echo $mode === 'replace' ? '正式取代已完成，網站與 WooCommerce 快取已清除。' : '以下僅顯示可能命中的資料，尚未修改任何資料。'; ?></p>
                <table class="widefat striped"><thead><tr><th>範圍</th><th>命中資料</th><th>文字出現次數</th><th>已修改</th><th>安全略過</th></tr></thead><tbody><?php foreach ($result['details'] as $detail): ?><tr><td><?php echo esc_html($detail['label']); ?></td><td><?php echo number_format_i18n($detail['rows']); ?></td><td><?php echo number_format_i18n($detail['occurrences']); ?></td><td><?php echo number_format_i18n($detail['updated']); ?></td><td><?php echo number_format_i18n($detail['skipped']); ?></td></tr><?php endforeach; ?></tbody></table>
                <p><strong>總計：</strong>命中 <?php echo number_format_i18n($result['rows']); ?> 筆資料，出現 <?php echo number_format_i18n($result['occurrences']); ?> 次，修改 <?php echo number_format_i18n($result['updated']); ?> 筆，略過 <?php echo number_format_i18n($result['skipped']); ?> 筆。</p>
            </section>
            <?php endif; ?>
        </div>
        <?php
    }
}
new WUTM_Global_Text_Replace();
