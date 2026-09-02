<?php
/**
 * Module: content-ordering
 *
 * Native WordPress list-table drag-and-drop ordering for posts and taxonomy terms.
 */
defined('ABSPATH') || exit;

final class WUTM_Content_Ordering {
    private const SLUG = 'wu-content-ordering';
    private const OPTION = 'wutm_content_ordering_settings';
    private const TERM_META = 'wutm_content_order_position';

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 15);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_post_wutm_content_ordering_save', [__CLASS__, 'save_settings']);
        add_action('wp_ajax_wutm_content_ordering_posts', [__CLASS__, 'save_posts']);
        add_action('wp_ajax_wutm_content_ordering_terms', [__CLASS__, 'save_terms']);

        /*
         * WordPress creates terms through admin-ajax.php and then renders the
         * new list-table row in the same request. Do not register any ordering
         * query or custom-column callbacks there; WooCommerce and other
         * taxonomy plugins may register their own dynamic columns.
         */
        if (!self::is_ajax_request()) {
            add_action('pre_get_posts', [__CLASS__, 'apply_post_order']);
            add_action('pre_get_terms', [__CLASS__, 'apply_term_order']);
            add_filter('get_terms_orderby', [__CLASS__, 'term_orderby'], 20, 3);
            add_action('init', [__CLASS__, 'register_native_columns'], 99);
        }
    }

    public static function register_native_columns(): void {
        if (!is_admin() || self::is_ajax_request()) return;

        foreach (self::post_types() as $post_type => $object) {
            add_filter("manage_{$post_type}_posts_columns", [__CLASS__, 'add_order_column']);
            add_action("manage_{$post_type}_posts_custom_column", [__CLASS__, 'render_post_column'], 10, 2);
        }
        add_filter('manage_pages_columns', [__CLASS__, 'add_order_column']);
        add_action('manage_pages_custom_column', [__CLASS__, 'render_post_column'], 10, 2);

        foreach (self::taxonomies() as $taxonomy => $object) {
            add_filter("manage_edit-{$taxonomy}_columns", [__CLASS__, 'add_order_column']);
            add_filter("manage_{$taxonomy}_custom_column", [__CLASS__, 'render_term_column'], 10, 3);
        }
    }

    private static function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), [
            'auto_posts' => false,
            'auto_terms' => false,
        ]);
    }

    private static function post_types(): array {
        $types = get_post_types(['show_ui' => true], 'objects');
        foreach ($types as $key => $type) {
            if (!$type || in_array($key, ['attachment', 'revision', 'nav_menu_item'], true)) unset($types[$key]);
        }
        return $types;
    }

    private static function taxonomies(): array {
        $taxonomies = get_taxonomies(['show_ui' => true], 'objects');
        foreach ($taxonomies as $key => $taxonomy) {
            if (!$taxonomy || in_array($key, ['nav_menu', 'post_format'], true)) unset($taxonomies[$key]);
        }
        return $taxonomies;
    }

    public static function menu(): void {
        add_submenu_page('wu-toolbox-modular', '文章及分類排序', '文章及分類排序', 'manage_options', self::SLUG, [__CLASS__, 'page']);
    }

    public static function add_order_column(array $columns): array {
        if (isset($columns['wutm_content_order'])) return $columns;
        $result = [];
        foreach ($columns as $key => $label) {
            $result[$key] = $label;
            if ($key === 'cb') $result['wutm_content_order'] = '<span class="screen-reader-text">拖曳排序</span>';
        }
        if (!isset($result['wutm_content_order'])) $result = ['wutm_content_order' => '<span class="screen-reader-text">拖曳排序</span>'] + $result;
        return $result;
    }

    public static function render_post_column(string $column, int $post_id): void {
        if ($column !== 'wutm_content_order') return;
        echo '<button type="button" class="wutm-native-order-handle" aria-label="拖曳排序"><span class="dashicons dashicons-menu"></span></button>';
    }

    public static function render_term_column($output, $column, $term_id): string {
        $output = is_string($output) ? $output : '';
        if ($column !== 'wutm_content_order') return $output;
        return '<button type="button" class="wutm-native-order-handle" aria-label="拖曳排序"><span class="dashicons dashicons-menu"></span></button>';
    }

    public static function assets(string $hook): void {
        $screen = get_current_screen();
        if (!$screen || !empty($_GET['orderby']) || !empty($_GET['s'])) return;

        $kind = '';
        $object = '';
        if ($screen->base === 'edit' && !empty($screen->post_type) && isset(self::post_types()[$screen->post_type])) {
            $kind = 'posts';
            $object = $screen->post_type;
        } elseif ($screen->base === 'edit-tags' && !empty($screen->taxonomy) && isset(self::taxonomies()[$screen->taxonomy])) {
            $kind = 'terms';
            $object = $screen->taxonomy;
        }
        if (!$kind || !$object) return;

        wp_enqueue_style('wutm-content-ordering', WUTM_URL . 'assets/css/content-ordering.css', [], WUTM_VERSION);
        wp_enqueue_script('jquery-ui-sortable');
        wp_add_inline_script('jquery-ui-sortable', 'window.WUTMNativeOrdering=' . wp_json_encode([
            'nonce' => wp_create_nonce('wutm_content_ordering'),
            'kind' => $kind,
            'object' => $object,
            'error' => '排序未能儲存，請重新整理頁面後再試。',
        ]) . ';', 'before');
        wp_add_inline_script('jquery-ui-sortable', 'jQuery(function($) {
            var config = window.WUTMNativeOrdering;
            if (!config) return;
            var list = $("#the-list");
            if (!list.length || !list.find(".wutm-native-order-handle").length) return;
            function itemId(row) {
                var match = String(row.id || "").match(/(?:post|tag)-(\\d+)/);
                return match ? match[1] : "";
            }
            list.sortable({
                items: "> tr[id]",
                handle: ".wutm-native-order-handle",
                cancel: "input,textarea,select,option,a",
                axis: "y",
                tolerance: "pointer",
                helper: function(event, row) {
                    var helper = row.clone();
                    helper.children().each(function(index) { $(this).width(row.children().eq(index).outerWidth()); });
                    return helper;
                },
                placeholder: "wutm-native-order-placeholder",
                update: function() {
                    var ids = list.children("tr[id]").map(function(){ return itemId(this); }).get().filter(Boolean);
                    if (!ids.length) return;
                    var data = { action: config.kind === "terms" ? "wutm_content_ordering_terms" : "wutm_content_ordering_posts", nonce: config.nonce, ids: ids };
                    if (config.kind === "terms") data.taxonomy = config.object; else data.post_type = config.object;
                    list.addClass("wutm-native-order-saving");
                    $.post(ajaxurl, data).done(function(response) {
                        if (!response || !response.success) window.alert(config.error);
                    }).fail(function() { window.alert(config.error); }).always(function() {
                        list.removeClass("wutm-native-order-saving");
                    });
                }
            });
        });');
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) wp_die('權限不足');
        $settings = self::settings();
        ?>
        <div class="wrap wutm-module-wrap wutm-content-ordering">
            <h1>文章及分類排序</h1>
            <p class="wutm-module-subtitle">在 WordPress 原生文章、頁面、商品、分類與自訂內容類型清單直接拖曳排序。</p>
            <div class="notice notice-info inline"><p><strong>使用方式：</strong>啟用本模組後，前往「文章」、「頁面」、「商品」或各分類法的原生清單。每筆資料左側勾選框後方都會出現 ☰ 排序把手；拖曳後立即儲存。</p></div>

            <section class="wutm-co-panel">
                <h2>排序套用設定</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wutm_content_ordering_save">
                    <?php wp_nonce_field('wutm_content_ordering_save'); ?>
                    <label class="wutm-inline-choice"><input type="checkbox" name="auto_posts" value="1" <?php checked(!empty($settings['auto_posts'])); ?>> <strong>自動套用內容排序至前台</strong>：未指定排序的標準內容查詢會依拖曳順序顯示。</label>
                    <label class="wutm-inline-choice"><input type="checkbox" name="auto_terms" value="1" <?php checked(!empty($settings['auto_terms'])); ?>> <strong>自動套用分類排序至前台</strong>：使用標準分類查詢的選單與分類清單會依拖曳順序顯示。</label>
                    <p class="description">搜尋、原生欄位排序畫面不提供拖曳，避免把暫時的篩選結果寫成正式順序。主題或外掛已明確指定排序時不會被覆蓋；自訂查詢可加入 <code>ignore_custom_sort</code> 排除自動排序。</p>
                    <?php submit_button('儲存設定', 'secondary', 'submit', false); ?>
                </form>
            </section>
        </div>
        <?php
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_options')) wp_die('權限不足', 403);
        check_admin_referer('wutm_content_ordering_save');
        update_option(self::OPTION, [
            'auto_posts' => !empty($_POST['auto_posts']),
            'auto_terms' => !empty($_POST['auto_terms']),
        ], false);
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
        exit;
    }

    private static function ajax_guard(): void {
        check_ajax_referer('wutm_content_ordering', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => '權限不足'], 403);
    }

    public static function save_posts(): void {
        self::ajax_guard();
        $type = sanitize_key(wp_unslash($_POST['post_type'] ?? ''));
        if (!isset(self::post_types()[$type])) wp_send_json_error(['message' => '無效內容類型'], 400);
        $ids = array_values(array_filter(array_map('absint', (array) ($_POST['ids'] ?? []))));
        foreach ($ids as $position => $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_type !== $type) continue;
            wp_update_post(['ID' => $post_id, 'menu_order' => $position]);
            clean_post_cache($post_id);
        }
        wp_send_json_success();
    }

    public static function save_terms(): void {
        self::ajax_guard();
        $taxonomy = sanitize_key(wp_unslash($_POST['taxonomy'] ?? ''));
        $taxonomies = self::taxonomies();
        if (!isset($taxonomies[$taxonomy])) wp_send_json_error(['message' => '無效分類法'], 400);
        $capability = $taxonomies[$taxonomy]->cap->manage_terms ?? 'manage_categories';
        if (!current_user_can($capability)) wp_send_json_error(['message' => '權限不足'], 403);
        $ids = array_values(array_filter(array_map('absint', (array) ($_POST['ids'] ?? []))));
        foreach ($ids as $position => $term_id) {
            $term = get_term($term_id, $taxonomy);
            if (!$term || is_wp_error($term)) continue;
            update_term_meta($term_id, self::TERM_META, $position);
            clean_term_cache($term_id, $taxonomy);
        }
        wp_send_json_success();
    }

    public static function apply_post_order(WP_Query $query): void {
        $post_type = $query->get('post_type') ?: 'post';
        if (is_admin()) {
            global $pagenow;
            if ($pagenow !== 'edit.php' || !$query->is_main_query() || is_array($post_type) || !isset(self::post_types()[$post_type])) return;
            if (!empty($_GET['orderby']) || !empty($_GET['s'])) return;
            $query->set('orderby', 'menu_order');
            $query->set('order', 'ASC');
            return;
        }

        $settings = self::settings();
        if (empty($settings['auto_posts']) || !$query->is_main_query() || $query->get('ignore_wutm_content_order') || $query->get('ignore_custom_sort')) return;
        if ($query->is_singular() || $query->is_search() || $query->is_date() || $query->is_author() || $query->get('orderby')) return;
        if (is_array($post_type)) {
            foreach ($post_type as $type) if (!isset(self::post_types()[$type])) return;
        } elseif (!isset(self::post_types()[$post_type])) {
            return;
        }
        $query->set('orderby', 'menu_order');
        $query->set('order', 'ASC');
    }

    public static function apply_term_order(WP_Term_Query $query): void {
        if (!empty($query->query_vars['wutm_content_ordering_ignore'])) return;

        /*
         * Never alter term lookups used while WordPress creates or edits a term.
         * The add-category screen submits to admin-ajax.php; adding our custom
         * ORDER BY to that internal existence check can turn a valid insert into
         * a 500 response.
         */
        if ((function_exists('wp_doing_ajax') && wp_doing_ajax())
            || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }

        $is_admin = is_admin();
        $is_native_list = $is_admin && ($GLOBALS['pagenow'] ?? '') === 'edit-tags.php';
        if ($is_admin) {
            $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
            if (!$is_native_list || $method !== 'GET') return;
        }

        $taxonomies = (array) ($query->query_vars['taxonomy'] ?? []);
        if (!$taxonomies) return;
        foreach ($taxonomies as $taxonomy) {
            if (!isset(self::taxonomies()[$taxonomy])) return;
        }

        $settings = self::settings();
        if (!$is_native_list && empty($settings['auto_terms'])) return;

        $orderby = $query->query_vars['orderby'] ?? '';
        if ($orderby && !in_array($orderby, ['name', 'none'], true)) return;
        $query->query_vars['wutm_content_ordering'] = true;
    }

    public static function term_orderby($orderby, $args, $taxonomies): string {
        $orderby = is_string($orderby) ? $orderby : '';
        $args = is_array($args) ? $args : [];
        if (empty($args['wutm_content_ordering'])) return $orderby;
        global $wpdb;
        $meta_key = esc_sql(self::TERM_META);
        return "COALESCE((SELECT CAST(wutm_order_meta.meta_value AS UNSIGNED) FROM {$wpdb->termmeta} AS wutm_order_meta WHERE wutm_order_meta.term_id = t.term_id AND wutm_order_meta.meta_key = '{$meta_key}' LIMIT 1), 2147483647), t.name";
    }

    private static function is_ajax_request(): bool {
        return (function_exists('wp_doing_ajax') && wp_doing_ajax())
            || (defined('DOING_AJAX') && DOING_AJAX);
    }
}
WUTM_Content_Ordering::boot();
