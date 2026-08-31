<?php
/**
 * Module: content-ordering
 * Drag-and-drop ordering for posts and taxonomy terms.
 */
defined('ABSPATH') || exit;

class WUTM_Content_Ordering {
    private const SLUG = 'wu-content-ordering';
    private const OPTION = 'wutm_content_ordering_settings';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu'], 15);
        add_action('admin_post_wutm_content_ordering_save', [$this, 'save_settings']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('wp_ajax_wutm_order_posts', [$this, 'save_posts']);
        add_action('wp_ajax_wutm_order_terms', [$this, 'save_terms']);
        add_action('pre_get_posts', [$this, 'apply_post_order']);
        add_action('pre_get_terms', [$this, 'apply_term_order']);
        add_filter('terms_clauses', [$this, 'apply_term_order_clauses'], 10, 3);
    }

    private function settings(): array {
        return wp_parse_args((array)get_option(self::OPTION, []), ['auto_posts'=>false,'auto_terms'=>false]);
    }

    public function menu(): void {
        add_submenu_page('wu-toolbox-modular', '文章及分類排序', '文章及分類排序', 'manage_options', self::SLUG, [$this, 'page']);
    }

    private function post_types(): array {
        $types = get_post_types(['show_ui' => true], 'objects');
        // WooCommerce can register products with different UI flags by version.
        // Always expose them when WooCommerce itself has registered the type.
        if (post_type_exists('product')) {
            $types['product'] = get_post_type_object('product');
        }
        return array_filter($types, static fn($type) => $type && !in_array($type->name, ['attachment','revision','nav_menu_item'], true));
    }

    private function taxonomies(): array {
        $taxonomies = get_taxonomies(['show_ui' => true], 'objects');
        // Keep product categories available even if a WooCommerce extension changes its UI flag.
        if (taxonomy_exists('product_cat')) {
            $taxonomies['product_cat'] = get_taxonomy('product_cat');
        }
        return array_filter($taxonomies, static fn($tax) => $tax && !in_array($tax->name, ['post_format','nav_menu'], true));
    }

    public function assets(string $hook): void {
        if ($hook !== 'wu-toolbox_page_' . self::SLUG) return;

        wp_enqueue_script('jquery-ui-sortable');
        $context = ['nonce' => wp_create_nonce('wutm_content_ordering')];
        wp_add_inline_script('jquery-ui-sortable', 'window.WUTMOrdering=' . wp_json_encode($context) . ';', 'before');
        wp_add_inline_script('jquery-ui-sortable', 'jQuery(function($){
            function save(list, action, extra){
                var ids=list.sortable("toArray",{attribute:"data-id"});
                list.addClass("is-saving");
                $.post(ajaxurl,Object.assign({action:action,nonce:WUTMOrdering.nonce,ids:ids},extra)).always(function(){list.removeClass("is-saving");});
            }
            $(".wutm-order-list").sortable({handle:".wutm-order-handle",placeholder:"wutm-order-placeholder",update:function(){
                var list=$(this), isTerm=list.data("kind")==="term";
                save(list,isTerm?"wutm_order_terms":"wutm_order_posts",isTerm?{taxonomy:list.data("taxonomy")}:{post_type:list.data("post-type")});
            }});
        });');
    }

    public function page(): void {
        if (!current_user_can('manage_options')) wp_die('權限不足');
        $types=$this->post_types(); $taxes=$this->taxonomies(); $s=$this->settings();
        $type=sanitize_key(wp_unslash($_GET['post_type']??array_key_first($types))); if(!isset($types[$type]))$type=array_key_first($types);
        $tax=sanitize_key(wp_unslash($_GET['taxonomy']??array_key_first($taxes))); if(!isset($taxes[$tax]))$tax=array_key_first($taxes);
        $posts=$type?get_posts(['post_type'=>$type,'post_status'=>['publish','draft','private','pending','future'],'posts_per_page'=>300,'orderby'=>'menu_order','order'=>'ASC','suppress_filters'=>true]):[];
        $terms=$tax?get_terms(['taxonomy'=>$tax,'hide_empty'=>false,'number'=>300,'orderby'=>'name','order'=>'ASC']):[]; if(is_array($terms)) usort($terms, static fn($a,$b) => ((int)get_term_meta($a->term_id,'wutm_ordering_position',true) ?: PHP_INT_MAX) <=> ((int)get_term_meta($b->term_id,'wutm_ordering_position',true) ?: PHP_INT_MAX));
        ?>
        <div class="wrap wutm-module-wrap">
            <h1>文章及分類排序</h1>
            <p class="wutm-module-subtitle">在此頁選擇內容類型或分類法後，直接拖曳 ☰ 圖示排序；排序會立即儲存。</p>
            <div class="notice notice-info inline"><p><strong>操作位置：</strong>本模組是唯一的拖曳排序位置，不會在 WordPress 原本的文章、商品、頁面或分類列表加入按鈕。每次最多載入 300 筆，避免大型網站後台逾時。</p></div>

            <section class="wutm-ordering-panel">
                <h2>排序套用設定</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wutm_content_ordering_save'); ?><input type="hidden" name="action" value="wutm_content_ordering_save">
                    <label class="wutm-inline-choice"><input type="checkbox" name="auto_posts" value="1" <?php checked($s['auto_posts']); ?>> <strong>將文章排序套用到前台</strong>：首頁與內容類型彙整頁等使用 WordPress 標準查詢的地方，會依您在下方設定的順序顯示。</label>
                    <label class="wutm-inline-choice"><input type="checkbox" name="auto_terms" value="1" <?php checked($s['auto_terms']); ?>> <strong>將分類排序套用到前台</strong>：選單、分類清單等使用 WordPress 標準查詢的地方，會依您在下方設定的順序顯示。</label>
                    <p class="description">不勾選時，只會儲存排序，不會改變訪客看到的順序。所有具有後台介面的自訂文章類型／分類法（包括商品與 ACF 建立的類型）都能在下方下拉選單選取。</p>
                    <?php submit_button('儲存設定', 'secondary', 'submit', false); ?>
                </form>
            </section>

            <div class="wutm-ordering-grid">
                <section class="wutm-ordering-panel">
                    <h2>文章排序</h2>
                    <p class="description">選擇文章、頁面、商品或自訂內容類型後，按住每筆資料左側的 ☰ 拖曳。狀態欄顯示該內容目前的發布狀態。</p>
                    <form method="get" class="wutm-order-select"><input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>"><label>內容類型 <select name="post_type" onchange="this.form.submit()"><?php foreach($types as $key=>$obj): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($type,$key); ?>><?php echo esc_html($key === 'product' ? '商品（WooCommerce）' : $obj->labels->name); ?></option><?php endforeach; ?></select></label></form>
                    <?php if(empty($posts)): ?><p>此類型沒有可排序內容。</p><?php else: ?><ol class="wutm-order-list" data-kind="post" data-post-type="<?php echo esc_attr($type); ?>"><?php foreach($posts as $post): ?><li data-id="<?php echo (int)$post->ID; ?>"><span class="wutm-order-handle" aria-label="拖曳排序">☰</span><span><?php echo esc_html(get_the_title($post) ?: '（無標題）'); ?></span><small><?php echo esc_html($post->post_status); ?></small><a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>">編輯</a></li><?php endforeach; ?></ol><?php endif; ?>
                </section>
                <section class="wutm-ordering-panel">
                    <h2>分類排序</h2>
                    <p class="description">選擇文章分類、商品分類或自訂分類法後，按住 ☰ 拖曳。右側「項」是目前使用此分類的內容數量。</p>
                    <form method="get" class="wutm-order-select"><input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>"><input type="hidden" name="post_type" value="<?php echo esc_attr($type); ?>"><label>分類法 <select name="taxonomy" onchange="this.form.submit()"><?php foreach($taxes as $key=>$obj): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($tax,$key); ?>><?php echo esc_html($key === 'product_cat' ? '商品分類（WooCommerce）' : $obj->labels->name); ?></option><?php endforeach; ?></select></label></form>
                    <?php if(is_wp_error($terms)||empty($terms)): ?><p>此分類法沒有可排序項目。</p><?php else: ?><ol class="wutm-order-list" data-kind="term" data-taxonomy="<?php echo esc_attr($tax); ?>"><?php foreach($terms as $term): ?><li data-id="<?php echo (int)$term->term_id; ?>"><span class="wutm-order-handle" aria-label="拖曳排序">☰</span><span><?php echo esc_html($term->name); ?></span><small><?php echo (int)$term->count; ?> 項</small><a href="<?php echo esc_url(get_edit_term_link($term)); ?>">編輯</a></li><?php endforeach; ?></ol><?php endif; ?>
                </section>
            </div>
        </div><?php
    }

    public function save_settings(): void {
        if(!current_user_can('manage_options'))wp_die('權限不足');check_admin_referer('wutm_content_ordering_save');
        update_option(self::OPTION,['auto_posts'=>!empty($_POST['auto_posts']),'auto_terms'=>!empty($_POST['auto_terms'])],false);
        wp_safe_redirect($this->page_url());exit;
    }

    private function page_url(): string { return admin_url('admin.php?page='.self::SLUG); }

    private function ajax_guard(): void { check_ajax_referer('wutm_content_ordering','nonce');if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'權限不足'],403); }

    public function save_posts(): void {
        $this->ajax_guard();$type=sanitize_key(wp_unslash($_POST['post_type']??''));if(!isset($this->post_types()[$type]))wp_send_json_error(['message'=>'無效內容類型'],400);
        $ids=array_values(array_filter(array_map('absint',(array)($_POST['ids']??[]))));foreach($ids as $position=>$id){$post=get_post($id);if(!$post||$post->post_type!==$type)continue;wp_update_post(['ID'=>$id,'menu_order'=>$position]);clean_post_cache($id);}wp_send_json_success();
    }

    public function save_terms(): void {
        $this->ajax_guard();$taxonomy=sanitize_key(wp_unslash($_POST['taxonomy']??''));$taxes=$this->taxonomies();if(!isset($taxes[$taxonomy]))wp_send_json_error(['message'=>'無效分類法'],400);
        $cap=$taxes[$taxonomy]->cap->manage_terms??'manage_categories';if(!current_user_can($cap))wp_send_json_error(['message'=>'權限不足'],403);
        foreach(array_values(array_filter(array_map('absint',(array)($_POST['ids']??[])))) as $position=>$id){$term=get_term($id,$taxonomy);if($term&&!is_wp_error($term))update_term_meta($id,'wutm_ordering_position',$position);}wp_send_json_success();
    }

    public function apply_post_order(WP_Query $query): void {
        $s=$this->settings();if(empty($s['auto_posts'])||is_admin()||!$query->is_main_query()||!$query->is_post_type_archive()&&!$query->is_home())return;
        if($query->get('orderby')||$query->get('ignore_wutm_order'))return;$query->set('orderby','menu_order');$query->set('order','ASC');
    }

    public function apply_term_order(WP_Term_Query $query): void {
        $s=$this->settings();if(empty($s['auto_terms'])||is_admin())return;$taxonomies=(array)($query->query_vars['taxonomy']??[]);if(!$taxonomies)return;
        foreach($taxonomies as $tax){if(!isset($this->taxonomies()[$tax]))return;}
        if(!empty($query->query_vars['orderby'])&&!in_array($query->query_vars['orderby'],['name','none'],true))return;
        $query->query_vars['wutm_content_order'] = true;
    }


    /**
     * Use a LEFT JOIN so new, unsorted terms still remain visible in the
     * module list until the administrator drags them into position.
     */
    public function apply_term_order_clauses(array $clauses, array $taxonomies, array $args): array {
        if (empty($args['wutm_content_order'])) return $clauses;
        global $wpdb;
        $alias = 'wutm_order_meta';
        if (strpos($clauses['join'], $alias) === false) {
            $clauses['join'] .= " LEFT JOIN {$wpdb->termmeta} AS {$alias} ON t.term_id = {$alias}.term_id AND {$alias}.meta_key = 'wutm_ordering_position' ";
        }
        // WP_Term_Query expects the ORDER BY keyword in this clause; leaving it out
        // appends the expression directly after WHERE and breaks MariaDB queries.
        $clauses['orderby'] = "ORDER BY COALESCE(CAST({$alias}.meta_value AS UNSIGNED), 2147483647) ASC, t.name ASC";
        $clauses['order'] = '';
        return $clauses;
    }
}
new WUTM_Content_Ordering();
