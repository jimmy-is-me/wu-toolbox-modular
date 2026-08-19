<?php
/**
 * Module: content-duplicator
 * Loaded only when enabled in WU Toolbox Modular.
 */

/**
 * 內容重複模組
 * 一鍵複製頁面、貼文和自訂貼文的功能
 * 版本: 2.2
 */

if (!defined('ABSPATH')) exit;

class WU_Content_Duplicator {

    private $settings;
    private $option_name = 'wu_content_duplicator_settings';

    private $elementor_meta_keys = [
        '_elementor_data',
        '_elementor_edit_mode',
        '_elementor_template_type',
        '_elementor_version',
        '_elementor_pro_version',
        '_elementor_page_settings',
        '_elementor_controls_usage',
        '_elementor_css',
    ];

    private $woocommerce_excluded_meta = [
        '_sku',
        'total_sales',
        '_wc_average_rating',
        '_wc_rating_count',
        '_wc_review_count',
    ];

    private $seo_excluded_meta = [
        '_yoast_wpseo_linkdex',
        '_yoast_wpseo_content_score',
        'rank_math_internal_links_processed',
        'rank_math_analytic_object_id',
    ];

    public function __construct() {
        $this->settings = get_option($this->option_name, $this->get_default_settings());

        if (is_admin()) {
            add_action('admin_menu',            [$this, 'add_admin_menu'], 35);
            add_action('admin_init',            [$this, 'register_settings']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        }

        add_action('post_row_actions',          [$this, 'add_duplicate_link'], 10, 2);
        add_action('page_row_actions',          [$this, 'add_duplicate_link'], 10, 2);
        add_action('admin_action_wu_duplicate_post', [$this, 'duplicate_post']);
        add_action('wp_ajax_wu_duplicate_post', [$this, 'ajax_duplicate_post']);
        add_action('init',                      [$this, 'add_custom_post_type_support']);
    }

    private function get_default_settings() {
        return [
            'enabled'                  => false,
            'copy_metadata'            => true,
            'copy_taxonomies'          => true,
            'copy_featured_image'      => true,
            'excluded_post_types'      => ['attachment', 'elementor_library', 'wc_product_tab', 'shop_order', 'shop_coupon'],
            'excluded_meta_keys'       => ['_edit_lock', '_edit_last', '_wp_old_slug'],
            'title_prefix'             => '副本 - ',
            'slug_suffix'              => '-copy',
            'status_after_duplicate'   => 'draft',
            'regenerate_elementor_css' => true,
            'exclude_woocommerce_data' => true,
            'exclude_seo_stats'        => true,
        ];
    }

    public function register_settings() {
        register_setting('wu_content_duplicator_group', $this->option_name, [$this, 'sanitize_settings']);

        add_settings_section('wu_content_duplicator_section', '內容重複設定', [$this, 'section_callback'], 'wu-content-duplicator');

        $fields = [
            'enabled'              => ['啟用功能',       'enabled_field_callback'],
            'copy_options'         => ['複製選項',       'copy_options_field_callback'],
            'title_settings'       => ['標題與網址設定', 'title_settings_field_callback'],
            'status_after_duplicate' => ['複製後狀態',  'status_field_callback'],
            'advanced_options'     => ['進階選項',       'advanced_options_field_callback'],
            'excluded_post_types'  => ['排除的貼文類型', 'excluded_post_types_field_callback'],
            'excluded_meta_keys'   => ['排除的元數據鍵', 'excluded_meta_keys_field_callback'],
        ];

        foreach ($fields as $id => [$title, $callback]) {
            add_settings_field($id, $title, [$this, $callback], 'wu-content-duplicator', 'wu_content_duplicator_section');
        }
    }

    public function sanitize_settings($input) {
        $s = [];

        foreach (['enabled','copy_metadata','copy_taxonomies','copy_featured_image',
                  'regenerate_elementor_css','exclude_woocommerce_data','exclude_seo_stats'] as $bool) {
            $s[$bool] = !empty($input[$bool]);
        }

        $s['excluded_post_types'] = !empty($input['excluded_post_types'])
            ? array_map('sanitize_text_field', (array) $input['excluded_post_types'])
            : [];

        // ✅ 修正：用雙引號 "\n"
        $raw_keys = $input['excluded_meta_keys'] ?? '';
        $s['excluded_meta_keys'] = array_values(array_filter(array_map('trim', explode("\n", $raw_keys))));

        $s['title_prefix']           = sanitize_text_field($input['title_prefix'] ?? '');
        $s['slug_suffix']            = sanitize_title($input['slug_suffix'] ?? '');
        $s['status_after_duplicate'] = sanitize_text_field($input['status_after_duplicate'] ?? 'draft');

        return $s;
    }

    public function enqueue_admin_assets($hook) {
        // ✅ 修正：正確的子頁面 hook 名稱格式
        $allowed_hooks = ['edit.php', 'post.php', 'post-new.php', 'wumetax-toolkit_page_wu-content-duplicator'];

        if (!in_array($hook, $allowed_hooks, true)) return;

        // ✅ 修正：register 再 enqueue，src 傳 false
        wp_register_style('wu-content-duplicator', false);
        wp_enqueue_style('wu-content-duplicator');
        wp_add_inline_style('wu-content-duplicator', $this->get_admin_css());

        if ($hook === 'edit.php') {
            wp_register_script('wu-content-duplicator', false, ['jquery'], false, true);
            wp_enqueue_script('wu-content-duplicator');
            wp_add_inline_script('wu-content-duplicator', $this->get_admin_js());
        }
    }

    private function get_admin_css() {
        return '
        .wu-duplicator-stats { display:flex; gap:20px; margin:20px 0; flex-wrap:wrap; }
        .wu-stat-box { background:#f9f9f9; padding:15px; border-radius:5px; min-width:200px; border-left:4px solid #0073aa; }
        .wu-stat-box strong { display:block; margin-bottom:5px; color:#333; }
        .wu-stat-number { font-size:24px; font-weight:bold; color:#0073aa; }
        .wu-info-panel { background:#fff; padding:20px; border:1px solid #ddd; border-radius:5px; margin:20px 0; }
        .wu-info-panel h3 { margin-top:0; color:#0073aa; }
        .wu-two-column { display:flex; gap:30px; margin-top:15px; }
        .wu-two-column > div { flex:1; }
        .wu-two-column ul { margin:0; padding-left:20px; }
        .wu-two-column li { margin:5px 0; }
        .wu-status-badge { display:inline-block; padding:4px 12px; border-radius:4px; font-size:13px; font-weight:600; margin-left:10px; }
        .wu-status-on  { background:#00a32a; color:#fff; }
        .wu-status-off { background:#d63638; color:#fff; }
        ';
    }

    private function get_admin_js() {
        return '
        jQuery(document).ready(function($){
            $(document).on("click", ".row-actions .duplicate a", function(e){
                var $link = $(this);
                if ($link.data("duplicating")) { e.preventDefault(); return false; }
                $link.data("duplicating", true).css("opacity", "0.5");
                $link.after(\'<span class="spinner is-active" style="float:none;margin:0 0 0 5px;"></span>\');
            });
        });
        ';
    }

    public function add_admin_menu() {
        add_submenu_page(
            'wu-toolbox-modular',
            '內容重複設定',
            '內容重複設定',
            'manage_options',
            'wu-content-duplicator',
            [$this, 'admin_page']
        );
    }

    public function section_callback() {
        echo '<p>配置內容重複功能的相關設置。</p>';
    }

    public function enabled_field_callback() {
        $v = !empty($this->settings['enabled']);
        echo '<label><input type="checkbox" name="' . $this->option_name . '[enabled]" value="1" ' . checked(1, $v, false) . '> 啟用內容重複功能</label>';
        echo '<p class="description">啟用後，在文章列表中會顯示「複製」連結</p>';
    }

    public function copy_options_field_callback() {
        $fields = [
            'copy_metadata'       => ['複製所有元數據',   '包含自訂欄位、Elementor 設計資料等'],
            'copy_taxonomies'     => ['複製分類法',       '複製類別、標籤和自訂分類法'],
            'copy_featured_image' => ['複製特色圖片',     ''],
        ];
        foreach ($fields as $key => [$label, $desc]) {
            $checked = checked(1, !empty($this->settings[$key]), false);
            echo '<p><label><input type="checkbox" name="' . $this->option_name . '[' . $key . ']" value="1" ' . $checked . '> <strong>' . $label . '</strong></label>';
            if ($desc) echo '<br><span style="color:#666;font-size:13px;">' . $desc . '</span>';
            echo '</p>';
        }
    }

    public function title_settings_field_callback() {
        foreach ([
            ['title_prefix', '標題前綴', '複製後新文章標題的前綴', 'text'],
            ['slug_suffix',  '網址後綴', '複製後新文章網址的後綴（例如：-copy）', 'text'],
        ] as [$key, $label, $desc, $type]) {
            $val = esc_attr($this->settings[$key] ?? '');
            echo '<p><label>' . $label . '</label><br>
                  <input type="' . $type . '" name="' . $this->option_name . '[' . $key . ']" value="' . $val . '" class="regular-text">
                  <p class="description">' . $desc . '</p></p>';
        }
    }

    public function status_field_callback() {
        $cur = $this->settings['status_after_duplicate'] ?? 'draft';
        $options = ['draft' => '草稿', 'pending' => '待審核', 'private' => '私人', 'same' => '與原文相同'];
        echo '<select name="' . $this->option_name . '[status_after_duplicate]">';
        foreach ($options as $val => $label) {
            echo '<option value="' . $val . '" ' . selected($cur, $val, false) . '>' . $label . '</option>';
        }
        echo '</select><p class="description">複製後新文章的發佈狀態</p>';
    }

    public function advanced_options_field_callback() {
        $fields = [
            'regenerate_elementor_css' => ['重新產生 Elementor CSS',    '複製後自動清除 Elementor CSS 快取，確保前台樣式正常'],
            'exclude_woocommerce_data' => ['排除 WooCommerce 統計資料', '自動排除 SKU、銷售數量、評分等資料'],
            'exclude_seo_stats'        => ['排除 SEO 統計資料',         '排除 Yoast / Rank Math 的統計分數'],
        ];
        foreach ($fields as $key => [$label, $desc]) {
            $checked = checked(1, !empty($this->settings[$key]), false);
            echo '<p><label><input type="checkbox" name="' . $this->option_name . '[' . $key . ']" value="1" ' . $checked . '> <strong>' . $label . '</strong></label><br>
                  <span style="color:#666;font-size:13px;">' . $desc . '</span></p>';
        }
    }

    public function excluded_post_types_field_callback() {
        foreach ($this->get_supported_post_types() as $post_type => $label) {
            $checked = checked(in_array($post_type, $this->settings['excluded_post_types'] ?? [], true), true, false);
            echo '<label style="display:block;margin:5px 0;">
                  <input type="checkbox" name="' . $this->option_name . '[excluded_post_types][]" value="' . esc_attr($post_type) . '" ' . $checked . '>
                  ' . esc_html($label) . ' (' . esc_html($post_type) . ')</label>';
        }
        echo '<p class="description">選中的貼文類型將不會顯示複製功能</p>';
    }

    public function excluded_meta_keys_field_callback() {
        // ✅ 修正：用雙引號 "\n"
        $val = esc_textarea(implode("\n", $this->settings['excluded_meta_keys'] ?? []));
        echo '<textarea name="' . $this->option_name . '[excluded_meta_keys]" rows="5" cols="50" placeholder="_edit_lock">' . $val . '</textarea>';
        echo '<p class="description">這些元數據不會被複製（一行一個）</p>';
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('您沒有足夠的權限訪問此頁面。');
        }
        $this->settings = get_option($this->option_name, $this->get_default_settings());
        $status = !empty($this->settings['enabled']) ? 'on' : 'off';
        ?>
        <div class="wrap">
            <h1>內容重複設定
                <span class="wu-status-badge wu-status-<?php echo esc_attr($status); ?>">
                    <?php echo $status === 'on' ? '已啟用' : '已關閉'; ?>
                </span>
            </h1>

            <form method="post" action="options.php">
                <?php
                settings_fields('wu_content_duplicator_group');
                do_settings_sections('wu-content-duplicator');
                submit_button('儲存設定');
                ?>
            </form>

            <h2>統計資訊</h2>
            <div class="wu-duplicator-stats">
                <div class="wu-stat-box">
                    <strong>支援的貼文類型</strong>
                    <div class="wu-stat-number"><?php echo count($this->get_enabled_post_types()); ?></div>
                </div>
                <div class="wu-stat-box">
                    <strong>本月複製次數</strong>
                    <div class="wu-stat-number"><?php echo $this->get_duplicate_count('month'); ?></div>
                </div>
                <div class="wu-stat-box">
                    <strong>總複製次數</strong>
                    <div class="wu-stat-number"><?php echo $this->get_duplicate_count('total'); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    public function add_duplicate_link($actions, $post) {
        if (empty($this->settings['enabled'])) return $actions;
        if (in_array($post->post_type, $this->settings['excluded_post_types'] ?? [], true)) return $actions;
        if (!current_user_can('edit_posts')) return $actions;

        $url = wp_nonce_url(
            admin_url('admin.php?action=wu_duplicate_post&post=' . $post->ID),
            'wu_duplicate_post_' . $post->ID
        );
        $actions['duplicate'] = '<a href="' . esc_url($url) . '" title="複製這個項目">複製</a>';
        return $actions;
    }

    public function add_custom_post_type_support() {
        foreach (get_post_types(['public' => true], 'names') as $post_type) {
            if (!in_array($post_type, $this->settings['excluded_post_types'] ?? [], true)) {
                add_filter("{$post_type}_row_actions", [$this, 'add_duplicate_link'], 10, 2);
            }
        }
    }

    public function duplicate_post() {
        $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
        if (!$post_id || !isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wu_duplicate_post_' . $post_id)) {
            wp_die('安全驗證失敗');
        }
        if (!current_user_can('edit_posts')) wp_die('權限不足');

        $new_id = $this->create_duplicate($post_id);
        if ($new_id) {
            wp_redirect(admin_url('post.php?action=edit&post=' . $new_id));
            exit;
        }
        wp_die('複製失敗');
    }

    public function ajax_duplicate_post() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'wu_duplicate_post')) {
            wp_send_json_error('安全驗證失敗');
        }
        if (!current_user_can('edit_posts')) wp_send_json_error('權限不足');

        $post_id = intval($_POST['post_id'] ?? 0);
        $new_id  = $this->create_duplicate($post_id);

        if ($new_id) {
            wp_send_json_success([
                'new_post_id' => $new_id,
                'edit_url'    => admin_url('post.php?action=edit&post=' . $new_id),
                'message'     => '複製成功！',
            ]);
        }
        wp_send_json_error('複製失敗');
    }

    private function create_duplicate($post_id) {
        $post = get_post($post_id);
        if (!$post) return false;

        $new_slug = wp_unique_post_slug(
            $post->post_name . $this->settings['slug_suffix'],
            0, $post->post_status, $post->post_type, $post->post_parent
        );

        $new_id = wp_insert_post([
            'post_title'     => $this->settings['title_prefix'] . $post->post_title,
            'post_name'      => $new_slug,
            'post_content'   => $post->post_content,
            'post_excerpt'   => $post->post_excerpt,
            'post_type'      => $post->post_type,
            'post_status'    => $this->settings['status_after_duplicate'] === 'same' ? $post->post_status : $this->settings['status_after_duplicate'],
            'post_author'    => get_current_user_id(),
            'post_parent'    => $post->post_parent,
            'menu_order'     => $post->menu_order,
            'comment_status' => $post->comment_status,
            'ping_status'    => $post->ping_status,
            'post_password'  => $post->post_password,
        ]);

        if (is_wp_error($new_id)) return false;

        $fmt = get_post_format($post_id);
        if ($fmt) set_post_format($new_id, $fmt);

        if ($this->settings['copy_metadata'])       $this->copy_post_metadata($post_id, $new_id);
        if ($this->settings['copy_taxonomies'])      $this->copy_post_taxonomies($post_id, $new_id);
        if ($this->settings['copy_featured_image'])  $this->copy_featured_image($post_id, $new_id);
        if ($this->settings['regenerate_elementor_css']) $this->regenerate_elementor_css($new_id);

        $this->log_duplicate_action($post_id, $new_id);
        return $new_id;
    }

    private function copy_post_metadata($source_id, $target_id) {
        $post_type = get_post_type($source_id);
        $excluded  = array_merge(
            $this->settings['excluded_meta_keys'] ?? [],
            ['_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date']
        );

        if ($this->settings['exclude_woocommerce_data'] && $post_type === 'product') {
            $excluded = array_merge($excluded, $this->woocommerce_excluded_meta);
        }
        if ($this->settings['exclude_seo_stats']) {
            $excluded = array_merge($excluded, $this->seo_excluded_meta);
        }
        if (!$this->settings['copy_featured_image']) {
            $excluded[] = '_thumbnail_id';
        }

        foreach (get_post_meta($source_id) as $key => $values) {
            if (in_array($key, $excluded, true)) continue;

            foreach ($values as $value) {
                $v = maybe_unserialize($value);

                if (in_array($key, $this->elementor_meta_keys, true)) {
                    if ($key === '_elementor_data' && is_string($v)) {
                        $decoded = json_decode($v, true);
                        $v = (json_last_error() === JSON_ERROR_NONE)
                            ? wp_slash(wp_json_encode($decoded))
                            : wp_slash($v);
                    } else {
                        $v = wp_slash($v);
                    }
                    update_post_meta($target_id, $key, $v);
                } else {
                    add_post_meta($target_id, $key, $v);
                }
            }
        }
    }

    private function copy_post_taxonomies($source_id, $target_id) {
        foreach (get_object_taxonomies(get_post_type($source_id)) as $taxonomy) {
            $terms = wp_get_object_terms($source_id, $taxonomy, ['fields' => 'slugs']);
            if (!empty($terms) && !is_wp_error($terms)) {
                wp_set_object_terms($target_id, $terms, $taxonomy);
            }
        }
    }

    private function copy_featured_image($source_id, $target_id) {
        $thumb = get_post_thumbnail_id($source_id);
        if ($thumb) set_post_thumbnail($target_id, $thumb);
    }

    private function regenerate_elementor_css($post_id) {
        if (!class_exists('Elementor\\Plugin')) return;
        try {
            delete_post_meta($post_id, '_elementor_css');
            $doc = \Elementor\Plugin::$instance->documents->get($post_id);
            if ($doc) {
                $doc->save([]);
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            }
        } catch (\Exception $e) {
            error_log('Elementor CSS regeneration failed: ' . $e->getMessage());
        }
    }

    // ✅ 修正：月度統計改用獨立 option，不依賴 log array
    private function log_duplicate_action($source_id, $target_id) {
        update_option('wu_content_duplicator_total_count',
            (int) get_option('wu_content_duplicator_total_count', 0) + 1);

        $month_key = 'wu_dup_count_' . date('Y_m');
        update_option($month_key, (int) get_option($month_key, 0) + 1);

        if (class_exists('WU_Audit_Logger') && !empty($GLOBALS['wumetax_instances']['audit-logger'])) {
            $GLOBALS['wumetax_instances']['audit-logger']->log_action(
                'content_duplicate', 'success',
                sprintf('複製文章：%s (#%d) → (#%d)', get_the_title($source_id), $source_id, $target_id),
                ['source_id' => $source_id, 'target_id' => $target_id, 'post_type' => get_post_type($source_id)]
            );
        }
    }

    private function get_duplicate_count($period = 'total') {
        if ($period === 'total') return (int) get_option('wu_content_duplicator_total_count', 0);
        if ($period === 'month') return (int) get_option('wu_dup_count_' . date('Y_m'), 0);
        return 0;
    }

    private function get_supported_post_types() {
        $result = [];
        foreach (get_post_types(['public' => true], 'objects') as $pt => $obj) {
            $result[$pt] = $obj->labels->name;
        }
        return $result;
    }

    private function get_enabled_post_types() {
        $excluded = $this->settings['excluded_post_types'] ?? [];
        return array_filter($this->get_supported_post_types(),
            fn($pt) => !in_array($pt, $excluded, true), ARRAY_FILTER_USE_KEY);
    }
}

new WU_Content_Duplicator();
