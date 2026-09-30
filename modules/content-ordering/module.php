<?php
/**
 * Module: content-ordering
 *
 * Dedicated drag-and-drop ordering screen for posts and taxonomy terms.
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


    public static function assets(string $hook): void {
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== self::SLUG) return;
        wp_enqueue_script('jquery-ui-sortable');
        wp_add_inline_script('jquery-ui-sortable', 'window.WUTMNativeOrdering=' . wp_json_encode([
            'nonce' => wp_create_nonce('wutm_content_ordering'),
            'error' => '排序未能儲存，請重新整理頁面後再試。',
        ]) . ';', 'before');
        wp_add_inline_script('jquery-ui-sortable', 'jQuery(function($) {
            var config = window.WUTMNativeOrdering;
            if (!config) return;
            var list = $("#wutm-co-list"), status = $("#wutm-co-status");
            if (!list.length) return;
            var kind = list.data("kind"), object = list.data("object"), parent = list.data("parent");
            list.sortable({
                items: "> li[data-id]",
                handle: ".wutm-co-handle",
                cancel: "input,textarea,select,option,a",
                axis: "y",
                tolerance: "pointer",
                placeholder: "wutm-co-placeholder",
                update: function() {
                    var ids = list.children("li[data-id]").map(function(){ return $(this).data("id"); }).get();
                    if (!ids.length) return;
                    var data = { action: kind === "terms" ? "wutm_content_ordering_terms" : "wutm_content_ordering_posts", nonce: config.nonce, ids: ids };
                    if (kind === "terms") { data.taxonomy = object; data.parent = parent; } else data.post_type = object;
                    list.addClass("wutm-co-saving"); status.text("儲存中…");
                    $.post(ajaxurl, data).done(function(response) {
                        if (!response || !response.success) { status.text(config.error); window.alert(config.error); }
                        else status.text("順序已儲存");
                    }).fail(function() { status.text(config.error); window.alert(config.error); }).always(function() {
                        list.removeClass("wutm-co-saving");
                    });
                }
            });
        });');
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) wp_die('權限不足');
        $settings = self::settings();
        $kind = sanitize_key(wp_unslash($_GET['kind'] ?? 'posts'));
        $object = sanitize_key(wp_unslash($_GET['object'] ?? 'post'));
        $parent = absint($_GET['parent'] ?? 0);
        $types = self::post_types();
        $taxonomies = self::taxonomies();
        if ($kind === 'terms') {
            if (!isset($taxonomies[$object])) $object = isset($taxonomies['product_cat']) ? 'product_cat' : (string) array_key_first($taxonomies);
            $parents = get_terms(['taxonomy' => $object, 'hide_empty' => false, 'orderby' => 'name', 'wutm_content_ordering_ignore' => true]);
            if (is_wp_error($parents)) $parents = [];
            if ($parent && !in_array($parent, array_map(static fn($term) => (int) $term->term_id, $parents), true)) $parent = 0;
            $items = get_terms(['taxonomy' => $object, 'parent' => $parent, 'hide_empty' => false, 'orderby' => 'name', 'wutm_content_ordering_ignore' => true]);
            if (is_wp_error($items)) $items = [];
            usort($items, static function ($a, $b): int {
                $a_order = get_term_meta($a->term_id, self::TERM_META, true);
                $b_order = get_term_meta($b->term_id, self::TERM_META, true);
                $a_order = $a_order === '' ? PHP_INT_MAX : (int) $a_order;
                $b_order = $b_order === '' ? PHP_INT_MAX : (int) $b_order;
                if ($a_order !== $b_order) return $a_order <=> $b_order;
                $a_wc = get_term_meta($a->term_id, 'order', true);
                $b_wc = get_term_meta($b->term_id, 'order', true);
                $a_wc = $a_wc === '' ? PHP_INT_MAX : (int) $a_wc;
                $b_wc = $b_wc === '' ? PHP_INT_MAX : (int) $b_wc;
                return ($a_wc <=> $b_wc) ?: strnatcasecmp($a->name, $b->name);
            });
        } else {
            $kind = 'posts';
            if (!isset($types[$object])) $object = 'post';
            $items = get_posts(['post_type' => $object, 'post_status' => ['publish', 'future', 'draft', 'pending', 'private'], 'numberposts' => -1, 'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'], 'suppress_filters' => true]);
        }
        ?>
        <div class="wrap wutm-module-wrap wutm-content-ordering">
            <h1>文章及分類排序</h1>
            <p class="wutm-module-subtitle">所有排序都在此頁操作；選擇內容類型或分類法後，拖曳項目即可儲存。</p>
            <style>.wutm-co-panel{max-width:960px;margin:18px 0;padding:22px;background:#fff;border:1px solid #dcdcde;border-radius:8px}.wutm-co-panel h2{margin:0 0 15px}.wutm-co-picker{display:flex;gap:12px;flex-wrap:wrap;align-items:end}.wutm-co-picker label{display:grid;gap:5px;font-weight:600}.wutm-co-picker select{min-width:210px}.wutm-co-list{max-width:960px;margin:0;padding:0;list-style:none}.wutm-co-list li{display:flex;align-items:center;gap:12px;min-height:49px;margin:0 0 6px;padding:5px 13px;background:#fff;border:1px solid #dcdcde;border-radius:7px}.wutm-co-handle{display:flex;align-items:center;justify-content:center;width:36px;height:36px;border:1px solid #c3c4c7;border-radius:5px;background:#f6f7f7;cursor:grab}.wutm-co-placeholder{height:49px;border:2px dashed #2271b1!important;background:#f0f6fc!important}.wutm-co-saving{opacity:.65}.wutm-co-list small{margin-left:auto;color:#646970}.wutm-co-status{min-height:24px;color:#2271b1}</style>
            <section class="wutm-co-panel"><h2>選擇排序清單</h2><form method="get" class="wutm-co-picker"><input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>"><label>項目種類<select name="kind" onchange="this.form.submit()"><option value="posts" <?php selected($kind, 'posts'); ?>>文章、頁面與商品</option><option value="terms" <?php selected($kind, 'terms'); ?>>分類</option></select></label><label>選擇項目<select name="object" onchange="this.form.submit()"><option value="">請選擇</option><?php foreach (($kind === 'terms' ? $taxonomies : $types) as $key => $type): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($object, $key); ?>><?php echo esc_html($type->labels->name ?? $key); ?></option><?php endforeach; ?></select></label><?php if ($kind === 'terms'): ?><label>上層分類<select name="parent" onchange="this.form.submit()"><option value="0" <?php selected($parent, 0); ?>>頂層分類</option><?php foreach ($parents as $term): ?><option value="<?php echo (int) $term->term_id; ?>" <?php selected($parent, (int) $term->term_id); ?>><?php echo esc_html($term->name); ?></option><?php endforeach; ?></select></label><?php endif; ?><button class="button" type="submit">顯示</button></form><p class="description">分類只在同一個上層分類下排序；如需排序子分類，請先選擇其上層分類。</p></section>
            <section class="wutm-co-panel"><h2>拖曳排序</h2><p id="wutm-co-status" class="wutm-co-status" role="status"></p><?php if ($items): ?><ul id="wutm-co-list" class="wutm-co-list" data-kind="<?php echo esc_attr($kind); ?>" data-object="<?php echo esc_attr($object); ?>" data-parent="<?php echo (int) $parent; ?>"><?php foreach ($items as $item): ?><li data-id="<?php echo (int) ($kind === 'terms' ? $item->term_id : $item->ID); ?>"><button class="wutm-co-handle" type="button" aria-label="拖曳排序 <?php echo esc_attr($kind === 'terms' ? $item->name : $item->post_title); ?>"><span class="dashicons dashicons-menu"></span></button><span><?php echo esc_html($kind === 'terms' ? $item->name : ($item->post_title ?: '(無標題)')); ?></span><?php if ($kind === 'terms' && !empty($item->count)): ?><small><?php echo (int) $item->count; ?> 件商品／內容</small><?php endif; ?></li><?php endforeach; ?></ul><?php else: ?><p>此清單目前沒有項目。</p><?php endif; ?></section>

            <section class="wutm-co-panel">
                <h2>排序套用設定</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wutm_content_ordering_save">
                    <?php wp_nonce_field('wutm_content_ordering_save'); ?>
                    <label class="wutm-inline-choice"><input type="checkbox" name="auto_posts" value="1" <?php checked(!empty($settings['auto_posts'])); ?>> <strong>自動套用內容排序至前台</strong>：未指定排序的標準內容查詢會依拖曳順序顯示。</label>
                    <label class="wutm-inline-choice"><input type="checkbox" name="auto_terms" value="1" <?php checked(!empty($settings['auto_terms'])); ?>> <strong>自動套用分類排序至前台</strong>：使用標準分類查詢的選單與分類清單會依拖曳順序顯示。</label>
                    <p class="description">前台自動排序只影響未指定排序的標準查詢；主題或外掛已明確指定排序時不會被覆蓋。</p>
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
        $ids = array_values(array_unique($ids));
        if (!$ids) wp_send_json_error(['message' => '沒有排序項目'], 400);
        foreach ($ids as $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_type !== $type) wp_send_json_error(['message' => '內容類型不符'], 400);
        }
        foreach ($ids as $position => $post_id) {
            $post = get_post($post_id);
            if ((int) $post->menu_order !== $position) wp_update_post(['ID' => $post_id, 'menu_order' => $position]);
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
        $parent = absint($_POST['parent'] ?? 0);
        $ids = array_values(array_filter(array_map('absint', (array) ($_POST['ids'] ?? []))));
        $ids = array_values(array_unique($ids));
        if (!$ids) wp_send_json_error(['message' => '沒有排序項目'], 400);
        foreach ($ids as $position => $term_id) {
            $term = get_term($term_id, $taxonomy);
            if (!$term || is_wp_error($term) || (int) $term->parent !== $parent) wp_send_json_error(['message' => '分類層級不符'], 400);
        }
        foreach ($ids as $position => $term_id) {
            if (get_term_meta($term_id, self::TERM_META, true) !== (string) $position) update_term_meta($term_id, self::TERM_META, $position);
        }
        wp_send_json_success();
    }

    public static function apply_post_order(WP_Query $query): void {
        $post_type = $query->get('post_type') ?: 'post';
        if (is_admin()) return;

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

        if (is_admin()) return;

        $taxonomies = (array) ($query->query_vars['taxonomy'] ?? []);
        if (!$taxonomies) return;
        foreach ($taxonomies as $taxonomy) {
            if (!isset(self::taxonomies()[$taxonomy])) return;
        }

        $settings = self::settings();
        if (empty($settings['auto_terms'])) return;

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
