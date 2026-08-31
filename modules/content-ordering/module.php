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
    }

    private function settings(): array {
        return wp_parse_args((array)get_option(self::OPTION, []), ['auto_posts'=>false,'auto_terms'=>false]);
    }

    public function menu(): void {
        add_submenu_page('wu-toolbox-modular', '文章及分類排序', '文章及分類排序', 'manage_options', self::SLUG, [$this, 'page']);
    }

    private function post_types(): array {
        return array_filter(get_post_types(['show_ui'=>true], 'objects'), static fn($type) => !in_array($type->name, ['attachment','revision','nav_menu_item'], true));
    }

    private function taxonomies(): array {
        return array_filter(get_taxonomies(['show_ui'=>true], 'objects'), static fn($tax) => !in_array($tax->name, ['post_format','nav_menu'], true));
    }

    public function assets(string $hook): void {
        if ($hook !== 'wu-toolbox_page_' . self::SLUG) return;
        wp_enqueue_script('jquery-ui-sortable');
        wp_add_inline_script('jquery-ui-sortable', 'jQuery(function($){
            function save(list, action, extra){
                var ids=list.sortable("toArray",{attribute:"data-id"});
                list.addClass("is-saving");
                $.post(ajaxurl,Object.assign({action:action,nonce:WUTMOrdering.nonce,ids:ids},extra)).always(function(){list.removeClass("is-saving");});
            }
            $(".wutm-order-list").sortable({handle:".wutm-order-handle",placeholder:"wutm-order-placeholder",update:function(){var l=$(this);save(l,l.data("kind")==="term"?"wutm_order_terms":"wutm_order_posts",l.data("kind")==="term"?{taxonomy:l.data("taxonomy")}:{post_type:l.data("post-type")});}});
        });');
        wp_add_inline_script('jquery-ui-sortable', 'window.WUTMOrdering=' . wp_json_encode(['nonce'=>wp_create_nonce('wutm_content_ordering')]) . ';', 'before');
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
            <p class="wutm-module-subtitle">拖曳後會立即儲存。每個排序頁最多載入 300 筆，避免大型網站的後台逾時。</p>
            <div class="notice notice-info inline"><p>文章排序使用 WordPress 原生欄位；分類排序僅儲存自訂排序值。勾選「自動套用」後，前台的標準查詢才會依此順序顯示。</p></div>

            <section class="wutm-ordering-panel">
                <h2>排序套用設定</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wutm_content_ordering_save'); ?><input type="hidden" name="action" value="wutm_content_ordering_save">
                    <label class="wutm-inline-choice"><input type="checkbox" name="auto_posts" value="1" <?php checked($s['auto_posts']); ?>> 自動將文章／自訂文章類型的標準前台查詢依排序顯示</label>
                    <label class="wutm-inline-choice"><input type="checkbox" name="auto_terms" value="1" <?php checked($s['auto_terms']); ?>> 自動將分類法項目依排序顯示</label>
                    <?php submit_button('儲存設定', 'secondary', 'submit', false); ?>
                </form>
            </section>

            <div class="wutm-ordering-grid">
                <section class="wutm-ordering-panel">
                    <h2>文章排序</h2>
                    <form method="get" class="wutm-order-select"><input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>"><label>內容類型 <select name="post_type" onchange="this.form.submit()"><?php foreach($types as $key=>$obj): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($type,$key); ?>><?php echo esc_html($obj->labels->name); ?></option><?php endforeach; ?></select></label></form>
                    <?php if(empty($posts)): ?><p>此類型沒有可排序內容。</p><?php else: ?><ol class="wutm-order-list" data-kind="post" data-post-type="<?php echo esc_attr($type); ?>"><?php foreach($posts as $post): ?><li data-id="<?php echo (int)$post->ID; ?>"><span class="wutm-order-handle" aria-label="拖曳排序">☰</span><span><?php echo esc_html(get_the_title($post) ?: '（無標題）'); ?></span><small><?php echo esc_html($post->post_status); ?></small><a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>">編輯</a></li><?php endforeach; ?></ol><?php endif; ?>
                </section>
                <section class="wutm-ordering-panel">
                    <h2>分類排序</h2>
                    <form method="get" class="wutm-order-select"><input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>"><input type="hidden" name="post_type" value="<?php echo esc_attr($type); ?>"><label>分類法 <select name="taxonomy" onchange="this.form.submit()"><?php foreach($taxes as $key=>$obj): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($tax,$key); ?>><?php echo esc_html($obj->labels->name); ?></option><?php endforeach; ?></select></label></form>
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
        $query->query_vars['meta_key']='wutm_ordering_position';$query->query_vars['orderby']='meta_value_num';$query->query_vars['order']='ASC';
    }
}
new WUTM_Content_Ordering();
