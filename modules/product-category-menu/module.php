<?php
defined('ABSPATH') || exit;

function wutm_pcm_defaults(): array {
    return ['expand_mode' => 'current_only', 'show_count' => 0, 'hide_empty' => 0, 'font_size' => 15, 'sub_font_size' => 14, 'font_weight' => '600', 'text_color' => '#172033', 'active_color' => '#2271b1', 'row_bg' => '#f5f7fa', 'row_hover' => '#eaf3fb'];
}

function wutm_pcm_options(): array { return wp_parse_args((array) get_option('wutm_product_category_menu', []), wutm_pcm_defaults()); }

function wutm_pcm_sanitize($input): array {
    $input = is_array($input) ? $input : [];
    $defaults = wutm_pcm_defaults();
    $weights = ['300', '400', '500', '600', '700'];
    $modes = ['current_only', 'expand_all', 'collapse_all'];
    return [
        'expand_mode' => in_array($input['expand_mode'] ?? '', $modes, true) ? $input['expand_mode'] : $defaults['expand_mode'],
        'show_count' => empty($input['show_count']) ? 0 : 1,
        'hide_empty' => empty($input['hide_empty']) ? 0 : 1,
        'font_size' => max(10, min(30, absint($input['font_size'] ?? 15))),
        'sub_font_size' => max(10, min(30, absint($input['sub_font_size'] ?? 14))),
        'font_weight' => in_array((string) ($input['font_weight'] ?? ''), $weights, true) ? (string) $input['font_weight'] : '600',
        'text_color' => sanitize_hex_color($input['text_color'] ?? '') ?: $defaults['text_color'],
        'active_color' => sanitize_hex_color($input['active_color'] ?? '') ?: $defaults['active_color'],
        'row_bg' => sanitize_hex_color($input['row_bg'] ?? '') ?: $defaults['row_bg'],
        'row_hover' => sanitize_hex_color($input['row_hover'] ?? '') ?: $defaults['row_hover'],
    ];
}

add_action('admin_init', function (): void { register_setting('wutm_pcm_group', 'wutm_product_category_menu', ['type' => 'array', 'sanitize_callback' => 'wutm_pcm_sanitize', 'default' => wutm_pcm_defaults()]); });
add_action('admin_menu', function (): void { add_submenu_page('wu-toolbox-modular', '商品分類選單設定', '商品分類選單', 'manage_options', 'wu-product-category-menu', 'wutm_pcm_admin_page'); });
add_action('admin_enqueue_scripts', function (): void {
    if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== 'wu-product-category-menu') return;
    wp_enqueue_style('wp-color-picker'); wp_enqueue_script('wp-color-picker');
    wp_add_inline_script('wp-color-picker', 'jQuery(function($){$(".wutm-pcm-color").wpColorPicker();});');
});

function wutm_pcm_admin_page(): void {
    if (!current_user_can('manage_options')) return;
    $o = wutm_pcm_options(); ?>
    <div class="wrap wutm-pcm-admin"><style>.wutm-pcm-admin{max-width:980px}.wutm-pcm-hero{margin:18px 0;padding:25px 28px;border-radius:16px;background:linear-gradient(135deg,#102d4d,#2771ad);color:#fff}.wutm-pcm-hero h1{margin:0 0 8px;color:#fff}.wutm-pcm-code{padding:7px 11px;border:1px solid #acd0ea;border-radius:8px;background:#eff8ff;color:#155985;font:600 13px ui-monospace,monospace;cursor:pointer}.wutm-pcm-panel{margin:18px 0;padding:22px 26px;border:1px solid #dbe4ed;border-radius:14px;background:#fff;box-shadow:0 8px 24px rgba(20,45,75,.06)}.wutm-pcm-grid{display:grid;grid-template-columns:repeat(2,minmax(240px,1fr));gap:18px 28px}.wutm-pcm-grid label{font-weight:600}.wutm-pcm-grid label>*{display:block;margin-top:7px}.wutm-pcm-grid select,.wutm-pcm-grid input[type=number]{width:100%;min-height:40px}@media(max-width:700px){.wutm-pcm-grid{grid-template-columns:1fr}}</style>
    <style>.wutm-pcm-admin .wutm-pcm-code{display:inline-block;margin-left:8px;padding:5px 10px;border-radius:6px}.wutm-pcm-admin .wutm-module-subtitle{margin:0 0 22px}.wutm-pcm-admin .wutm-pcm-panel{padding:20px 22px;border-color:#dcdcde;border-radius:8px;box-shadow:none}.wutm-pcm-admin .wutm-pcm-panel>h2{margin:0 0 18px;padding-bottom:10px;border-bottom:1px solid #e5e7eb;font-size:17px}</style>
    <h1>商品分類選單設定</h1><p class="wutm-module-subtitle">建立支援多層分類與目前分類自動展開的手風琴選單。<button type="button" class="wutm-pcm-code" onclick="navigator.clipboard.writeText('[wutm_product_categories]');this.textContent='已複製：[wutm_product_categories]'">[wutm_product_categories]　點擊複製</button></p>
    <form method="post" action="options.php"><?php settings_fields('wutm_pcm_group'); ?><section class="wutm-pcm-panel"><h2>選單行為</h2><div class="wutm-pcm-grid"><label>預設展開狀態<select name="wutm_product_category_menu[expand_mode]"><option value="current_only" <?php selected($o['expand_mode'], 'current_only'); ?>>只展開目前分類</option><option value="expand_all" <?php selected($o['expand_mode'], 'expand_all'); ?>>全部展開</option><option value="collapse_all" <?php selected($o['expand_mode'], 'collapse_all'); ?>>全部收合</option></select></label><label><input type="checkbox" name="wutm_product_category_menu[show_count]" value="1" <?php checked($o['show_count']); ?>> 顯示商品數量</label><label><input type="checkbox" name="wutm_product_category_menu[hide_empty]" value="1" <?php checked($o['hide_empty']); ?>> 隱藏沒有商品的分類</label></div></section>
    <section class="wutm-pcm-panel"><h2>外觀設定</h2><div class="wutm-pcm-grid"><label>主分類字級<input type="number" min="10" max="30" name="wutm_product_category_menu[font_size]" value="<?php echo (int) $o['font_size']; ?>"></label><label>子分類字級<input type="number" min="10" max="30" name="wutm_product_category_menu[sub_font_size]" value="<?php echo (int) $o['sub_font_size']; ?>"></label><label>字體粗細<select name="wutm_product_category_menu[font_weight]"><?php foreach (['300'=>'細體','400'=>'標準','500'=>'中等','600'=>'半粗','700'=>'粗體'] as $v=>$label): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($o['font_weight'], $v); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><label>文字顏色<input class="wutm-pcm-color" name="wutm_product_category_menu[text_color]" value="<?php echo esc_attr($o['text_color']); ?>"></label><label>重點顏色<input class="wutm-pcm-color" name="wutm_product_category_menu[active_color]" value="<?php echo esc_attr($o['active_color']); ?>"></label><label>分類背景<input class="wutm-pcm-color" name="wutm_product_category_menu[row_bg]" value="<?php echo esc_attr($o['row_bg']); ?>"></label><label>懸停背景<input class="wutm-pcm-color" name="wutm_product_category_menu[row_hover]" value="<?php echo esc_attr($o['row_hover']); ?>"></label></div></section><?php submit_button('儲存設定'); ?></form></div>
    <?php
}

function wutm_pcm_tree(array $terms, array $children_by_parent, int $current, array $ancestors, string $mode, bool $show_count): string {
    if (!$terms) return '';
    $html = '<ul class="wutm-pcm-list">';
    foreach ($terms as $term) {
        $children = $children_by_parent[(int) $term->term_id] ?? [];
        $active = $current === (int) $term->term_id;
        $branch = $active || in_array((int) $term->term_id, $ancestors, true);
        $expanded = $mode === 'expand_all' || ($mode === 'current_only' && $branch);
        $link = get_term_link($term);
        if (is_wp_error($link)) continue;
        $html .= '<li class="wutm-pcm-item' . ($branch ? ' is-current' : '') . '"><div class="wutm-pcm-row"><a href="' . esc_url($link) . '">' . esc_html($term->name) . ($show_count ? ' <small>(' . absint($term->count) . ')</small>' : '') . '</a>';
        if ($children) $html .= '<button type="button" class="wutm-pcm-toggle" aria-expanded="' . ($expanded ? 'true' : 'false') . '" aria-label="' . esc_attr('切換 ' . $term->name . ' 子分類') . '"><span></span></button>';
        $html .= '</div>';
        if ($children) $html .= '<div class="wutm-pcm-children"' . ($expanded ? '' : ' hidden') . '>' . wutm_pcm_tree($children, $children_by_parent, $current, $ancestors, $mode, $show_count) . '</div>';
        $html .= '</li>';
    }
    return $html . '</ul>';
}

function wutm_pcm_shortcode($atts): string {
    $o = wutm_pcm_options();
    $atts = shortcode_atts(['hide_empty' => (string) $o['hide_empty'], 'show_count' => (string) $o['show_count'], 'expand_mode' => $o['expand_mode']], $atts, 'wutm_product_categories');
    $hide_empty = filter_var($atts['hide_empty'], FILTER_VALIDATE_BOOLEAN);
    $show_count = filter_var($atts['show_count'], FILTER_VALIDATE_BOOLEAN);
    $mode = in_array($atts['expand_mode'], ['current_only','expand_all','collapse_all'], true) ? $atts['expand_mode'] : $o['expand_mode'];
    $all_terms = get_terms(['taxonomy'=>'product_cat','hide_empty'=>$hide_empty,'orderby'=>'menu_order','order'=>'ASC']);
    if (is_wp_error($all_terms) || !$all_terms) return '';
    $children_by_parent = [];
    foreach ($all_terms as $term) $children_by_parent[(int) $term->parent][] = $term;
    $terms = $children_by_parent[0] ?? [];
    if (is_wp_error($terms) || !$terms) return '';
    $current = is_tax('product_cat') ? (int) get_queried_object_id() : 0;
    $ancestors = $current ? array_map('intval', get_ancestors($current, 'product_cat')) : [];
    $uid = wp_unique_id('wutm-pcm-');
    $html = '<nav id="' . esc_attr($uid) . '" class="wutm-pcm" aria-label="商品分類">' . wutm_pcm_tree($terms, $children_by_parent, $current, $ancestors, $mode, $show_count) . '</nav>';
    $html .= '<style>#' . esc_attr($uid) . '{max-width:440px;font-family:inherit}#' . esc_attr($uid) . ' ul{margin:0;padding:0;list-style:none}#' . esc_attr($uid) . ' .wutm-pcm-item{margin:0 0 7px}#' . esc_attr($uid) . ' .wutm-pcm-row{display:flex;align-items:center;min-height:46px;border-radius:9px;background:' . esc_attr($o['row_bg']) . ';overflow:hidden;transition:.2s}#' . esc_attr($uid) . ' .wutm-pcm-row:hover,#' . esc_attr($uid) . ' .is-current>.wutm-pcm-row{background:' . esc_attr($o['row_hover']) . '}#' . esc_attr($uid) . ' .wutm-pcm-row>a{flex:1;padding:11px 14px;color:' . esc_attr($o['text_color']) . ';font-size:' . (int) $o['font_size'] . 'px;font-weight:' . esc_attr($o['font_weight']) . ';text-decoration:none}#' . esc_attr($uid) . ' .is-current>.wutm-pcm-row>a{color:' . esc_attr($o['active_color']) . '}#' . esc_attr($uid) . ' small{opacity:.62}#' . esc_attr($uid) . ' .wutm-pcm-toggle{position:relative;width:42px;height:42px;border:0;background:transparent;cursor:pointer}#' . esc_attr($uid) . ' .wutm-pcm-toggle:before,#' . esc_attr($uid) . ' .wutm-pcm-toggle:after{position:absolute;left:15px;top:20px;width:12px;height:2px;background:' . esc_attr($o['active_color']) . ';content:""}#' . esc_attr($uid) . ' .wutm-pcm-toggle:after{transform:rotate(90deg);transition:.2s}#' . esc_attr($uid) . ' .wutm-pcm-toggle[aria-expanded=true]:after{transform:none}#' . esc_attr($uid) . ' .wutm-pcm-children{margin:4px 0 5px 14px;padding-left:12px;border-left:2px solid ' . esc_attr($o['row_bg']) . '}#' . esc_attr($uid) . ' .wutm-pcm-children .wutm-pcm-row{min-height:38px;background:transparent}#' . esc_attr($uid) . ' .wutm-pcm-children .wutm-pcm-row>a{padding:8px 10px;font-size:' . (int) $o['sub_font_size'] . 'px}</style>';
    $html .= '<script>(function(){const root=document.getElementById(' . wp_json_encode($uid) . ');if(!root||root.dataset.ready)return;root.dataset.ready="1";root.addEventListener("click",function(e){const b=e.target.closest(".wutm-pcm-toggle");if(!b||!root.contains(b))return;const c=b.closest(".wutm-pcm-item").querySelector(":scope > .wutm-pcm-children"),open=b.getAttribute("aria-expanded")==="true";b.setAttribute("aria-expanded",open?"false":"true");if(c)c.hidden=open;});})();</script>';
    return $html;
}
add_shortcode('wutm_product_categories', 'wutm_pcm_shortcode');
add_shortcode('wc_category_accordion', 'wutm_pcm_shortcode');
