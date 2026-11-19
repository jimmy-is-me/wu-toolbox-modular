<?php
defined('ABSPATH') || exit;

function wutm_pcm_defaults(): array {
    return ['expand_mode'=>'current_only','show_count'=>0,'hide_empty'=>0,'show_search'=>0,'show_mobile'=>0,'width'=>'100%','font_size'=>15,'sub_font_size'=>14,'font_weight'=>'600','text_color'=>'#111111','active_color'=>'#111111','row_bg'=>'#f5f5f5','row_hover'=>'#e8e8e8'];
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
        'show_search' => empty($input['show_search']) ? 0 : 1,
        'show_mobile' => empty($input['show_mobile']) ? 0 : 1,
        'hide_empty' => empty($input['hide_empty']) ? 0 : 1,
        'width' => preg_match('/^\d+(?:\.\d+)?(?:px|%|vw|rem|em)$/', trim((string) ($input['width'] ?? ''))) ? trim((string) $input['width']) : '100%',
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
    <style>.wutm-pcm-admin .wutm-pcm-code{display:inline-block;margin-left:8px;padding:5px 10px;border-radius:6px}.wutm-pcm-admin .wutm-module-subtitle{margin:0 0 22px}.wutm-pcm-admin .wutm-pcm-panel{padding:20px 22px;border-color:#dcdcde;border-radius:8px;box-shadow:none}.wutm-pcm-admin .wutm-pcm-panel>h2{margin:0 0 18px;padding-bottom:10px;border-bottom:1px solid #e5e7eb;font-size:17px}.wutm-pcm-admin input[type=checkbox]{display:inline-block!important;margin:0 6px 0 0!important}.wutm-pcm-admin label:has(>input[type=checkbox]){display:flex;align-items:center}.wutm-pcm-color-field{min-width:0}.wutm-pcm-color-field>label{display:block!important;margin-bottom:8px}.wutm-pcm-color-field .iris-picker{max-width:100%}</style>
    <h1>商品分類選單設定</h1><p class="wutm-module-subtitle">建立支援多層分類與目前分類自動展開的手風琴選單。文字大小以 px 為單位；「目前分類與展開圖示顏色」同時控制目前所在分類及加減圖示；兩種背景色分別控制一般狀態與滑鼠移入／目前分類狀態。<button type="button" class="wutm-pcm-code" onclick="navigator.clipboard.writeText('[wutm_product_categories]');this.textContent='已複製：[wutm_product_categories]'">[wutm_product_categories]　點擊複製</button></p>
    <form method="post" action="options.php"><?php settings_fields('wutm_pcm_group'); ?><section class="wutm-pcm-panel"><h2>選單行為</h2><div class="wutm-pcm-grid"><label>預設展開狀態<select name="wutm_product_category_menu[expand_mode]"><option value="current_only" <?php selected($o['expand_mode'], 'current_only'); ?>>只展開目前分類</option><option value="expand_all" <?php selected($o['expand_mode'], 'expand_all'); ?>>全部展開</option><option value="collapse_all" <?php selected($o['expand_mode'], 'collapse_all'); ?>>全部收合</option></select></label><label><input type="checkbox" name="wutm_product_category_menu[show_count]" value="1" <?php checked($o['show_count']); ?>> 顯示商品數量</label><label><input type="checkbox" name="wutm_product_category_menu[hide_empty]" value="1" <?php checked($o['hide_empty']); ?>> 只隱藏自己及子分類皆無商品的分類</label><label><input type="checkbox" name="wutm_product_category_menu[show_search]" value="1" <?php checked($o['show_search']); ?>> 在選單頂部顯示分類搜尋</label><label><input type="checkbox" name="wutm_product_category_menu[show_mobile]" value="1" <?php checked($o['show_mobile']); ?>> 在手機顯示選單（預設收合）</label></div><p class="description">取消勾選即可顯示所有商品分類，包括沒有商品的分類。啟用手機顯示後，WooCommerce 商店與商品分類頁會在商品列表前顯示可收合選單。其他頁面依短碼放置位置顯示。分類順序可在「文章及分類排序」設定頁調整。</p></section>
    <section class="wutm-pcm-panel"><h2>外觀設定</h2><div class="wutm-pcm-grid"><label>選單區塊寬度<input name="wutm_product_category_menu[width]" value="<?php echo esc_attr($o['width']); ?>"><small>預設 100%，跟隨網站內容區。</small></label><label>主分類文字大小<input type="number" min="10" max="30" name="wutm_product_category_menu[font_size]" value="<?php echo (int) $o['font_size']; ?>"></label><label>子分類文字大小<input type="number" min="10" max="30" name="wutm_product_category_menu[sub_font_size]" value="<?php echo (int) $o['sub_font_size']; ?>"></label><label>分類文字粗細<select name="wutm_product_category_menu[font_weight]"><?php foreach (['300'=>'細體','400'=>'標準','500'=>'中等','600'=>'半粗','700'=>'粗體'] as $v=>$label): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($o['font_weight'], $v); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><div class="wutm-pcm-color-field"><label for="wutm-pcm-text_color">所有分類文字顏色</label><input id="wutm-pcm-text_color" class="wutm-pcm-color" name="wutm_product_category_menu[text_color]" value="<?php echo esc_attr($o['text_color']); ?>"></div><div class="wutm-pcm-color-field"><label for="wutm-pcm-active_color">目前分類與展開圖示顏色</label><input id="wutm-pcm-active_color" class="wutm-pcm-color" name="wutm_product_category_menu[active_color]" value="<?php echo esc_attr($o['active_color']); ?>"></div><div class="wutm-pcm-color-field"><label for="wutm-pcm-row_bg">主分類列背景顏色</label><input id="wutm-pcm-row_bg" class="wutm-pcm-color" name="wutm_product_category_menu[row_bg]" value="<?php echo esc_attr($o['row_bg']); ?>"></div><div class="wutm-pcm-color-field"><label for="wutm-pcm-row_hover">滑鼠移入與目前分類背景</label><input id="wutm-pcm-row_hover" class="wutm-pcm-color" name="wutm_product_category_menu[row_hover]" value="<?php echo esc_attr($o['row_hover']); ?>"></div></div></section><?php submit_button('儲存設定'); ?></form></div>
    <?php
}

function wutm_pcm_tree(array $terms, array $children_by_parent, int $current, array $ancestors, string $mode, bool $show_count): string {
    if (!$terms) return '';
    // Use neutral containers: many themes target menu ul/li:first-child and
    // hide the first nested item (or the only item) unintentionally.
    $html = '<div class="wutm-pcm-list" role="list">';
    foreach ($terms as $term) {
        $children = $children_by_parent[(int) $term->term_id] ?? [];
        $active = $current === (int) $term->term_id;
        $branch = $active || in_array((int) $term->term_id, $ancestors, true);
        $expanded = $mode === 'expand_all' || ($mode === 'current_only' && $branch);
        $link = get_term_link($term);
        $label = esc_html($term->name) . ($show_count ? ' <small>(' . absint($term->count) . ')</small>' : '');
        $html .= '<div class="wutm-pcm-item' . ($branch ? ' is-current' : '') . '" role="listitem"><div class="wutm-pcm-row">';
        if ( $children ) {
            $html .= '<button type="button" class="wutm-pcm-toggle" aria-expanded="' . ($expanded ? 'true' : 'false') . '"><span>' . $label . '</span><span class="wutm-pcm-icon" aria-hidden="true"></span></button>';
        } else {
            $html .= is_wp_error($link) ? '<span class="wutm-pcm-label">' . $label . '</span>' : '<a href="' . esc_url($link) . '"' . ($active ? ' aria-current="page"' : '') . '>' . $label . '</a>';
        }
        $html .= '</div>';
        if ($children) {
            $html .= '<div class="wutm-pcm-children"' . ($expanded ? '' : ' hidden') . '>';
            if (!is_wp_error($link)) $html .= '<a class="wutm-pcm-parent-link" href="' . esc_url($link) . '"' . ($active ? ' aria-current="page"' : '') . '>查看全部：' . esc_html($term->name) . '</a>';
            $html .= wutm_pcm_tree($children, $children_by_parent, $current, $ancestors, $mode, $show_count) . '</div>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}

/** Keep ancestors visible when a populated descendant has no direct products. */
function wutm_pcm_filter_empty(array $terms, array $children_by_parent, array &$visible_children): array {
    $visible = [];
    foreach ($terms as $term) {
        $children = wutm_pcm_filter_empty($children_by_parent[(int) $term->term_id] ?? [], $children_by_parent, $visible_children);
        if ((int) $term->count > 0 || $children) {
            $visible[] = $term;
            $visible_children[(int) $term->term_id] = $children;
        }
    }
    return $visible;
}

/** Reconcile WP's filtered term query with the canonical taxonomy rows. */
function wutm_pcm_all_product_categories(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $terms = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'hierarchical'=>false,'orderby'=>'name','order'=>'ASC','wutm_content_ordering_ignore'=>true]);
    if (is_wp_error($terms)) $terms = [];
    $by_id = [];
    foreach ($terms as $term) $by_id[(int) $term->term_id] = $term;

    global $wpdb;
    if (!isset($wpdb->term_taxonomy)) return $cached = array_values($by_id);
    $rows = $wpdb->get_results("SELECT tt.term_id, tt.parent, tt.count FROM {$wpdb->term_taxonomy} AS tt WHERE tt.taxonomy = 'product_cat'");
    if (!is_array($rows)) return $cached = array_values($by_id);
    foreach ($rows as $row) {
        $id = (int) $row->term_id;
        $term = $by_id[$id] ?? get_term($id, 'product_cat');
        if (!$term || is_wp_error($term)) continue;
        $term = clone $term;
        $term->parent = (int) $row->parent;
        $term->count = (int) $row->count;
        $by_id[$id] = $term;
    }
    return $cached = array_values($by_id);
}

function wutm_pcm_render($atts, bool $mobile_archive = false): string {
    $o = wutm_pcm_options();
    $atts = shortcode_atts(['hide_empty' => (string) $o['hide_empty'], 'show_count' => (string) $o['show_count'], 'expand_mode' => $o['expand_mode']], $atts, 'wutm_product_categories');
    $hide_empty = filter_var($atts['hide_empty'], FILTER_VALIDATE_BOOLEAN);
    $show_count = filter_var($atts['show_count'], FILTER_VALIDATE_BOOLEAN);
    $mode = in_array($atts['expand_mode'], ['current_only','expand_all','collapse_all'], true) ? $atts['expand_mode'] : $o['expand_mode'];
    // Reconcile with taxonomy rows because other term-query filters can omit
    // a category even when WooCommerce reports products in that category.
    $all_terms = wutm_pcm_all_product_categories();
    if (!$all_terms) return '';
    $children_by_parent = [];
    $known = [];
    foreach ($all_terms as $term) $known[(int) $term->term_id] = true;
    foreach ($all_terms as $term) {
        $parent = (int) $term->parent;
        // Legacy imports can leave an orphan; still show it at the root.
        $children_by_parent[isset($known[$parent]) ? $parent : 0][] = $term;
    }
    foreach ($children_by_parent as &$siblings) {
        $original = [];
        foreach ($siblings as $index => $term) $original[(int) $term->term_id] = $index;
        usort($siblings, static function ($a, $b) use ($original): int {
            $a_value = get_term_meta($a->term_id, 'wutm_content_order_position', true);
            $b_value = get_term_meta($b->term_id, 'wutm_content_order_position', true);
            $a_rank = $a_value === '' ? PHP_INT_MAX : (int) $a_value;
            $b_rank = $b_value === '' ? PHP_INT_MAX : (int) $b_value;
            if ($a_rank !== $b_rank) return $a_rank <=> $b_rank;
            $a_wc = get_term_meta($a->term_id, 'order', true);
            $b_wc = get_term_meta($b->term_id, 'order', true);
            $a_wc = $a_wc === '' ? PHP_INT_MAX : (int) $a_wc;
            $b_wc = $b_wc === '' ? PHP_INT_MAX : (int) $b_wc;
            return ($a_wc <=> $b_wc) ?: ($original[$a->term_id] <=> $original[$b->term_id]);
        });
    }
    unset($siblings);
    $terms = $children_by_parent[0] ?? [];
    if ($hide_empty) {
        $visible_children = [];
        $terms = wutm_pcm_filter_empty($terms, $children_by_parent, $visible_children);
        $children_by_parent = $visible_children;
    }
    if (is_wp_error($terms) || !$terms) return '';
    $current = is_tax('product_cat') ? (int) get_queried_object_id() : 0;
    $ancestors = $current ? array_map('intval', get_ancestors($current, 'product_cat')) : [];
    $uid = wp_unique_id('wutm-pcm-');
    $class = $mobile_archive ? 'wutm-pcm-archive-mobile' : 'wutm-pcm-inline';
    if (empty($o['show_mobile'])) $class .= ' wutm-pcm-desktop-only';
    $html = '<nav id="' . esc_attr($uid) . '" class="wutm-pcm ' . $class . '" aria-label="商品分類">';
    if (!empty($o['show_mobile'])) {
        $html .= '<button type="button" class="wutm-pcm-mobile-toggle" aria-expanded="false">商品分類 <span aria-hidden="true">＋</span></button>';
    }
    if (!empty($o['show_search'])) {
        $html .= '<label class="wutm-pcm-search">搜尋商品分類<input type="search" placeholder="輸入分類名稱"></label><p class="wutm-pcm-empty" role="status" hidden>找不到符合的分類。</p>';
    }
    $html .= wutm_pcm_tree($terms, $children_by_parent, $current, $ancestors, $mode, $show_count) . '</nav>';
    $html .= '<style>#' . esc_attr($uid) . '{max-width:440px;font-family:inherit}#' . esc_attr($uid) . ' ul{margin:0;padding:0;list-style:none}#' . esc_attr($uid) . ' .wutm-pcm-item{margin:0 0 7px}#' . esc_attr($uid) . ' .wutm-pcm-row{display:flex;align-items:center;min-height:46px;border-radius:9px;background:' . esc_attr($o['row_bg']) . ';overflow:hidden;transition:.2s}#' . esc_attr($uid) . ' .wutm-pcm-row:hover,#' . esc_attr($uid) . ' .is-current>.wutm-pcm-row{background:' . esc_attr($o['row_hover']) . '}#' . esc_attr($uid) . ' .wutm-pcm-row>a,#' . esc_attr($uid) . ' .wutm-pcm-toggle{flex:1;padding:11px 14px;color:' . esc_attr($o['text_color']) . ';font-size:' . (int) $o['font_size'] . 'px;font-weight:' . esc_attr($o['font_weight']) . ';text-decoration:none}#' . esc_attr($uid) . ' .is-current>.wutm-pcm-row>a,#' . esc_attr($uid) . ' .is-current>.wutm-pcm-row>.wutm-pcm-toggle{color:' . esc_attr($o['active_color']) . '}#' . esc_attr($uid) . ' small{opacity:.62}#' . esc_attr($uid) . ' .wutm-pcm-toggle{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;min-height:46px;text-align:start;border:0;border-radius:inherit;background:transparent;cursor:pointer}#' . esc_attr($uid) . ' .wutm-pcm-icon:before,#' . esc_attr($uid) . ' .wutm-pcm-icon:after{position:absolute;inset-inline-start:0;top:7px;width:12px;height:2px;background:' . esc_attr($o['active_color']) . ';content:""}#' . esc_attr($uid) . ' .wutm-pcm-icon:after{transform:rotate(90deg);transition:.2s}#' . esc_attr($uid) . ' .wutm-pcm-toggle[aria-expanded=true] .wutm-pcm-icon:after{transform:none}#' . esc_attr($uid) . ' .wutm-pcm-children{margin:4px 0 5px 14px;padding-left:12px;border-left:2px solid ' . esc_attr($o['row_bg']) . '}#' . esc_attr($uid) . ' .wutm-pcm-children .wutm-pcm-row{min-height:38px;background:transparent}#' . esc_attr($uid) . ' .wutm-pcm-children .wutm-pcm-row>a,#' . esc_attr($uid) . ' .wutm-pcm-children .wutm-pcm-toggle{padding:8px 10px;font-size:' . (int) $o['sub_font_size'] . 'px}</style>';
    // Elementor page CSS can hide the menu's first direct child with a more
    // specific ID + pseudo-class selector. Repeat the ID to ensure the first
    // category remains visible without affecting nested category lists.
    $html .= '<style>#' . esc_attr($uid) . ' .wutm-pcm-list,#' . esc_attr($uid) . ' .wutm-pcm-item{display:block!important;visibility:visible!important;opacity:1!important}#' . esc_attr($uid) . '#' . esc_attr($uid) . '>.wutm-pcm-list>.wutm-pcm-item:first-child{display:block!important;visibility:visible!important;opacity:1!important}#' . esc_attr($uid) . ' .wutm-pcm-children[hidden],#' . esc_attr($uid) . ' .wutm-pcm-item[hidden],#' . esc_attr($uid) . '#' . esc_attr($uid) . '>.wutm-pcm-list>.wutm-pcm-item[hidden]{display:none!important}#' . esc_attr($uid) . ' .wutm-pcm-row>.wutm-pcm-label{flex:1;padding:11px 14px;color:' . esc_attr($o['text_color']) . ';font-size:' . (int) $o['sub_font_size'] . 'px;font-weight:' . esc_attr($o['font_weight']) . '}</style>';
    static $assets_printed = false;
    if (!$assets_printed) {
        $assets_printed = true;
        $html .= '<style>' . file_get_contents(__DIR__ . '/menu.css') . '</style>';
        $html .= '<script defer src="' . esc_url(WUTM_URL . 'modules/product-category-menu/menu.js?ver=' . WUTM_VERSION) . '"></script>';
    }
    $html .= '<style>#' . esc_attr($uid) . '{width:100%;max-width:' . esc_attr($o['width']) . '}</style>';
    return $html;
}
function wutm_pcm_shortcode($atts): string {
    return wutm_pcm_render($atts);
}

add_action('woocommerce_before_main_content', static function (): void {
    if (!empty(wutm_pcm_options()['show_mobile']) && function_exists('is_shop') && (is_shop() || is_product_taxonomy())) {
        echo wutm_pcm_render([], true);
    }
}, 30);

add_shortcode('wutm_product_categories', 'wutm_pcm_shortcode');
add_shortcode('wc_category_accordion', 'wutm_pcm_shortcode');
