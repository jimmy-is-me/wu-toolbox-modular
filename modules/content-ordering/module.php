<?php
/**
 * Module: content-ordering
 *
 * Central drag-and-drop ordering for posts and taxonomy terms.
 */
defined('ABSPATH') || exit;

final class WUTM_Content_Ordering {
    private const SLUG = 'wu-content-ordering';
    private const OPTION = 'wutm_content_ordering_settings';
    private const TERM_META = 'wutm_content_order_position';
    private const LIMIT = 300;

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 15);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_post_wutm_content_ordering_save', [__CLASS__, 'save_settings']);
        add_action('wp_ajax_wutm_content_ordering_posts', [__CLASS__, 'save_posts']);
        add_action('wp_ajax_wutm_content_ordering_terms', [__CLASS__, 'save_terms']);
        add_action('pre_get_posts', [__CLASS__, 'apply_post_order']);
        add_action('pre_get_terms', [__CLASS__, 'apply_term_order']);
        add_filter('get_terms_orderby', [__CLASS__, 'term_orderby'], 20, 3);
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

    private static function default_key(array $items): string {
        $keys = array_keys($items);
        return isset($keys[0]) ? (string) $keys[0] : '';
    }

    public static function menu(): void {
        add_submenu_page('wu-toolbox-modular', '文章及分類排序', '文章及分類排序', 'manage_options', self::SLUG, [__CLASS__, 'page']);
    }

    public static function assets(string $hook): void {
        if ($hook !== 'wu-toolbox_page_' . self::SLUG) return;
        wp_enqueue_script('jquery-ui-sortable');
        wp_add_inline_script('jquery-ui-sortable', 'window.WUTMContentOrdering=' . wp_json_encode([
            'nonce' => wp_create_nonce('wutm_content_ordering'),
            'error' => '排序未能儲存，請重新整理頁面後再試。',
        ]) . ';', 'before');
        wp_add_inline_script('jquery-ui-sortable', 'jQuery(function($) {
            function save(list) {
                var terms = list.data("kind") === "terms";
                var data = { action: terms ? "wutm_content_ordering_terms" : "wutm_content_ordering_posts", nonce: WUTMContentOrdering.nonce, ids: list.sortable("toArray", {attribute:"data-id"}) };
                if (terms) data.taxonomy = list.data("taxonomy"); else data.post_type = list.data("post-type");
                list.addClass("is-saving");
                $.post(ajaxurl, data).done(function(response) {
                    if (!response || !response.success) window.alert(WUTMContentOrdering.error);
                }).fail(function() { window.alert(WUTMContentOrdering.error); }).always(function() { list.removeClass("is-saving"); });
            }
            $(".wutm-co-list").sortable({ handle: ".wutm-co-handle", placeholder: "wutm-co-placeholder", update: function(){ save($(this)); } });
        });');
    }

    private static function posts(string $post_type, ?string &$error): array {
        $error = null;
        if (!$post_type || !post_type_exists($post_type)) return [];
        try {
            $query = new WP_Query([
                'post_type' => $post_type,
                'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
                'posts_per_page' => self::LIMIT,
                'orderby' => 'menu_order',
                'order' => 'ASC',
                'ignore_sticky_posts' => true,
                'suppress_filters' => true,
                'no_found_rows' => true,
                'ignore_wutm_content_order' => true,
            ]);
            return is_array($query->posts) ? $query->posts : [];
        } catch (Throwable $exception) {
            error_log('WU Toolbox content ordering posts: ' . $exception->getMessage());
            $error = '讀取此內容類型時發生問題，請確認相關外掛是否正常啟用。';
            return [];
        }
    }

    private static function terms(string $taxonomy, ?string &$error): array {
        $error = null;
        if (!$taxonomy || !taxonomy_exists($taxonomy)) return [];
        try {
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'number' => self::LIMIT,
                'orderby' => 'name',
                'order' => 'ASC',
                'wutm_content_ordering_ignore' => true,
            ]);
            if (is_wp_error($terms)) {
                $error = $terms->get_error_message();
                return [];
            }
            if (!is_array($terms)) return [];
            usort($terms, static function ($left, $right): int {
                $left_position = get_term_meta($left->term_id, self::TERM_META, true);
                $right_position = get_term_meta($right->term_id, self::TERM_META, true);
                $left_position = $left_position === '' ? PHP_INT_MAX : (int) $left_position;
                $right_position = $right_position === '' ? PHP_INT_MAX : (int) $right_position;
                return $left_position === $right_position ? strcasecmp($left->name, $right->name) : ($left_position <=> $right_position);
            });
            return $terms;
        } catch (Throwable $exception) {
            error_log('WU Toolbox content ordering terms: ' . $exception->getMessage());
            $error = '讀取此分類法時發生問題，請確認相關外掛是否正常啟用。';
            return [];
        }
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) wp_die('權限不足');
        try {
            self::render_page();
        } catch (Throwable $exception) {
            error_log('WU Toolbox content ordering page: ' . $exception->getMessage());
            echo '<div class="wrap wutm-module-wrap"><h1>文章及分類排序</h1><div class="notice notice-error"><p>排序頁面暫時無法讀取部分資料；其他網站功能不會受影響。</p></div></div>';
        }
    }

    private static function render_page(): void {
        $post_types = self::post_types();
        $taxonomies = self::taxonomies();
        $settings = self::settings();
        $post_type = sanitize_key(wp_unslash($_GET['post_type'] ?? self::default_key($post_types)));
        $taxonomy = sanitize_key(wp_unslash($_GET['taxonomy'] ?? self::default_key($taxonomies)));
        if (!isset($post_types[$post_type])) $post_type = self::default_key($post_types);
        if (!isset($taxonomies[$taxonomy])) $taxonomy = self::default_key($taxonomies);
        $post_error = null;
        $term_error = null;
        $posts = self::posts($post_type, $post_error);
        $terms = self::terms($taxonomy, $term_error);
        ?>
        <div class="wrap wutm-module-wrap wutm-content-ordering">
            <h1>文章及分類排序</h1>
            <p class="wutm-module-subtitle">集中拖曳排序文章、頁面、WooCommerce 商品及自訂內容類型；也支援商品分類與其他自訂分類法。</p>
            <div class="notice notice-info inline"><p><strong>使用方式：</strong>先儲存是否自動套用至前台，再從下方選取類型並拖曳每筆資料左側的排序把手。每次拖曳都會立即儲存。</p></div>

            <section class="wutm-co-panel">
                <h2>排序套用設定</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wutm_content_ordering_save">
                    <?php wp_nonce_field('wutm_content_ordering_save'); ?>
                    <label class="wutm-inline-choice"><input type="checkbox" name="auto_posts" value="1" <?php checked(!empty($settings['auto_posts'])); ?>> <strong>自動套用內容排序至前台</strong>：未指定排序的標準文章、頁面、商品與自訂內容查詢會依拖曳順序顯示。</label>
                    <label class="wutm-inline-choice"><input type="checkbox" name="auto_terms" value="1" <?php checked(!empty($settings['auto_terms'])); ?>> <strong>自動套用分類排序至前台</strong>：使用 WordPress 標準分類查詢的選單與分類清單會依拖曳順序顯示。</label>
                    <p class="description">主題或外掛已明確指定排序、搜尋結果、單篇內容及日期／作者彙整頁不會被覆蓋。自訂查詢可加入 <code>ignore_custom_sort</code> 停用自動排序。</p>
                    <?php submit_button('儲存設定', 'secondary', 'submit', false); ?>
                </form>
            </section>

            <div class="wutm-co-grid">
                <section class="wutm-co-panel">
                    <h2>內容排序</h2>
                    <p class="description">選擇文章、頁面、商品或自訂內容類型後，按住左側 ☰ 拖曳。為維持後台效能，每次最多載入 300 筆。</p>
                    <form method="get" class="wutm-co-select">
                        <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                        <input type="hidden" name="taxonomy" value="<?php echo esc_attr($taxonomy); ?>">
                        <label>內容類型 <select name="post_type" onchange="this.form.submit()"><?php foreach ($post_types as $key => $object): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($post_type, $key); ?>><?php echo esc_html($object->labels->name); ?></option><?php endforeach; ?></select></label>
                    </form>
                    <?php if ($post_error): ?><div class="notice notice-error inline"><p><?php echo esc_html($post_error); ?></p></div><?php endif; ?>
                    <?php if (!$posts): ?><p class="description">此內容類型目前沒有可排序資料。</p><?php else: ?>
                    <ol class="wutm-co-list" data-kind="posts" data-post-type="<?php echo esc_attr($post_type); ?>"><?php foreach ($posts as $post): ?>
                        <li data-id="<?php echo (int) $post->ID; ?>"><span class="wutm-co-handle" aria-label="拖曳排序">☰</span><span class="wutm-co-name"><?php echo esc_html(get_the_title($post) ?: '（無標題）'); ?></span><small><?php echo esc_html($post->post_status); ?></small><a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>">編輯</a></li>
                    <?php endforeach; ?></ol><?php endif; ?>
                </section>

                <section class="wutm-co-panel">
                    <h2>分類排序</h2>
                    <p class="description">選擇文章分類、商品分類或自訂分類法後拖曳排序。右側「項」表示目前使用該分類的內容數。</p>
                    <form method="get" class="wutm-co-select">
                        <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                        <input type="hidden" name="post_type" value="<?php echo esc_attr($post_type); ?>">
                        <label>分類法 <select name="taxonomy" onchange="this.form.submit()"><?php foreach ($taxonomies as $key => $object): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($taxonomy, $key); ?>><?php echo esc_html($object->labels->name); ?></option><?php endforeach; ?></select></label>
                    </form>
                    <?php if ($term_error): ?><div class="notice notice-error inline"><p><?php echo esc_html($term_error); ?></p></div><?php endif; ?>
                    <?php if (!$terms): ?><p class="description">此分類法目前沒有可排序項目。</p><?php else: ?>
                    <ol class="wutm-co-list" data-kind="terms" data-taxonomy="<?php echo esc_attr($taxonomy); ?>"><?php foreach ($terms as $term): ?>
                        <li data-id="<?php echo (int) $term->term_id; ?>"><span class="wutm-co-handle" aria-label="拖曳排序">☰</span><span class="wutm-co-name"><?php echo esc_html($term->name); ?></span><small><?php echo number_format_i18n($term->count); ?> 項</small><a href="<?php echo esc_url(get_edit_term_link($term->term_id, $taxonomy)); ?>">編輯</a></li>
                    <?php endforeach; ?></ol><?php endif; ?>
                </section>
            </div>
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
        $settings = self::settings();
        if (empty($settings['auto_posts']) || is_admin() || !$query->is_main_query() || $query->get('ignore_wutm_content_order') || $query->get('ignore_custom_sort')) return;
        if ($query->is_singular() || $query->is_search() || $query->is_date() || $query->is_author() || $query->get('orderby')) return;
        $post_type = $query->get('post_type');
        if (is_array($post_type)) {
            foreach ($post_type as $type) if (!isset(self::post_types()[$type])) return;
        } elseif ($post_type && !isset(self::post_types()[$post_type])) {
            return;
        }
        $query->set('orderby', 'menu_order');
        $query->set('order', 'ASC');
    }

    public static function apply_term_order(WP_Term_Query $query): void {
        $settings = self::settings();
        if (empty($settings['auto_terms']) || is_admin() || !empty($query->query_vars['wutm_content_ordering_ignore'])) return;
        $taxonomies = (array) ($query->query_vars['taxonomy'] ?? []);
        if (!$taxonomies) return;
        foreach ($taxonomies as $taxonomy) if (!isset(self::taxonomies()[$taxonomy])) return;
        $orderby = $query->query_vars['orderby'] ?? '';
        if ($orderby && !in_array($orderby, ['name', 'none'], true)) return;
        $query->query_vars['wutm_content_ordering'] = true;
    }

    public static function term_orderby(string $orderby, array $args, array $taxonomies): string {
        if (empty($args['wutm_content_ordering'])) return $orderby;
        global $wpdb;
        $meta_key = esc_sql(self::TERM_META);
        return "COALESCE((SELECT CAST(wutm_order_meta.meta_value AS UNSIGNED) FROM {$wpdb->termmeta} AS wutm_order_meta WHERE wutm_order_meta.term_id = t.term_id AND wutm_order_meta.meta_key = '{$meta_key}' LIMIT 1), 2147483647), t.name";
    }
}
WUTM_Content_Ordering::boot();
