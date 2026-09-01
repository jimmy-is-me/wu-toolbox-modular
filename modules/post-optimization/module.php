<?php
/**
 * Module: post-optimization
 * Loaded only when enabled in WU Toolbox Modular.
 */

if (!defined('ABSPATH')) exit;

/*
 * WumetaxToolkit - Post Optimization
 * - 文章瀏覽量統計（真實計數 + 管理員調整 + Cookie 防重複）
 * - 自動文章目錄（TOC）
 * - 平滑捲動與偏移量設定
 */

// ========== 常數快取（避免重複 get_option）==========

// ✅ 修正：加入 $reset 參數，避免同一 request 快取舊值
function wu_post_opts( bool $reset = false ): array {
    static $opts = null;
    if ( $reset || $opts === null ) {
        $opts = [
            'auto_display'  => (int) get_option('wu_views_auto_display', 0),
            'admin_display' => (int) get_option('wu_views_admin_display', 0),
            'display_mode'  => get_option('wu_views_display_mode', 'total'),
            'toc_enable'    => (int) get_option('wu_toc_enable', 1),
            'toc_min'       => (int) get_option('wu_toc_min_headings', 2),
            'toc_offset'    => (int) get_option('wu_toc_scroll_offset', 100),
        ];
    }
    return $opts;
}

// ========== 瀏覽量功能 ==========

function wu_get_post_views(int $post_id = 0, string $mode = 'total'): int {
    $post_id = $post_id ?: (int) get_the_ID();
    if (!$post_id) return 0;
    $real   = (int) get_post_meta($post_id, '_wu_views_real',   true);
    $offset = (int) get_post_meta($post_id, '_wu_views_offset', true);
    return $mode === 'real' ? $real : max(0, $real + $offset);
}

// ✅ 修正：改用 SQL 原子累加，避免高流量競態條件（Race Condition）
function wu_increment_post_views(): void {
    if (!is_singular('post')) return;
    if (is_user_logged_in() && current_user_can('manage_options')) return;

    $post_id = (int) get_queried_object_id();
    if (!$post_id) return;

    $cookie_key = 'wu_viewed_' . $post_id;
    if (!empty($_COOKIE[$cookie_key])) return;

    global $wpdb;
    $existing = get_post_meta($post_id, '_wu_views_real', true);

    if ($existing === '') {
        add_post_meta($post_id, '_wu_views_real', 1, true);
    } else {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta}
             SET meta_value = meta_value + 1
             WHERE post_id = %d AND meta_key = '_wu_views_real'",
            $post_id
        ));
    }

    if (!headers_sent()) {
        setcookie($cookie_key, '1', time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    }
}
add_action('template_redirect', 'wu_increment_post_views');

// 短代碼 [wu_views id="" show="real|total"]
add_shortcode('wu_views', function(array $atts): string {
    $atts = shortcode_atts(['id' => 0, 'show' => 'total'], $atts, 'wu_views');
    $id   = (int) $atts['id'] ?: (int) get_the_ID();
    $show = $atts['show'] === 'real' ? 'real' : 'total';
    return (string) wu_get_post_views($id, $show);
});

// 前台自動顯示瀏覽量
add_filter('the_content', function(string $content): string {
    if (!is_singular('post')) return $content;
    $opts = wu_post_opts();
    if (!$opts['auto_display']) return $content;
    $mode  = $opts['display_mode'] === 'real' ? 'real' : 'total';
    $views = wu_get_post_views((int) get_the_ID(), $mode);
    return $content . '<div class="wu-views" style="opacity:.8;font-size:.9em;margin-top:1em;">👁 瀏覽量：' . $views . '</div>';
});

// 後台文章列表欄位
add_filter('manage_post_posts_columns', function(array $cols): array {
    if (wu_post_opts()['admin_display']) {
        $cols['wu_views'] = '瀏覽量';
    }
    return $cols;
});

add_action('manage_post_posts_custom_column', function(string $col, int $post_id): void {
    if ($col !== 'wu_views' || !wu_post_opts()['admin_display']) return;
    $real   = (int) get_post_meta($post_id, '_wu_views_real',   true);
    $offset = (int) get_post_meta($post_id, '_wu_views_offset', true);
    echo '<span title="真實：' . $real . ' + 調整：' . $offset . '">' . ($real + $offset) . '</span>';
}, 10, 2);

// 可排序欄位
add_filter('manage_edit-post_sortable_columns', function(array $columns): array {
    if (wu_post_opts()['admin_display']) {
        $columns['wu_views'] = 'wu_views';
    }
    return $columns;
});

add_action('pre_get_posts', function(WP_Query $query): void {
    if (!is_admin() || !$query->is_main_query()) return;
    if ($query->get('orderby') !== 'wu_views') return;
    // total = real + offset，WordPress 無法直接加總，以 real 排序作為近似
    $query->set('meta_key', '_wu_views_real');
    $query->set('orderby',  'meta_value_num');
});

// 快速編輯欄位
add_action('quick_edit_custom_box', function(string $column_name, string $post_type): void {
    if ($post_type !== 'post' || $column_name !== 'wu_views') return;
    if (!wu_post_opts()['admin_display']) return;
    wp_nonce_field('wu_views_quick_edit_nonce', 'wu_views_quick_edit_nonce_field');
    echo '<fieldset class="inline-edit-col-left"><div class="inline-edit-col">';
    echo '<label class="inline-edit-group"><span class="title">瀏覽量調整</span>';
    echo '<input type="number" name="wu_views_offset" value="0" class="ptitle" style="width:100px;">';
    echo '</label></div></fieldset>';
}, 10, 2);

// save_post 雙路徑 nonce 驗證
add_action('save_post_post', function(int $post_id): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // Meta box 儲存
    if (isset($_POST['wu_views_meta_box_nonce'])
        && wp_verify_nonce($_POST['wu_views_meta_box_nonce'], 'wu_views_meta_box')) {
        update_post_meta($post_id, '_wu_views_offset', (int) ($_POST['wu_views_offset'] ?? 0));
        wu_post_opts(true);
        return;
    }

    // Quick Edit 儲存
    if (isset($_POST['wu_views_quick_edit_nonce_field'])
        && wp_verify_nonce($_POST['wu_views_quick_edit_nonce_field'], 'wu_views_quick_edit_nonce')
        && isset($_POST['wu_views_offset'])) {
        update_post_meta($post_id, '_wu_views_offset', (int) $_POST['wu_views_offset']);
        wu_post_opts(true);
    }
});

// Meta Box（文章編輯頁）
add_action('add_meta_boxes', function(): void {
    if (!wu_post_opts()['admin_display']) return;
    add_meta_box('wu_views_box', '瀏覽量', function(WP_Post $post): void {
        $real   = (int) get_post_meta($post->ID, '_wu_views_real',   true);
        $offset = (int) get_post_meta($post->ID, '_wu_views_offset', true);
        ?>
        <p>真實瀏覽量：<strong><?php echo $real; ?></strong></p>
        <p>總瀏覽量：<strong><?php echo $real + $offset; ?></strong></p>
        <p>
            <label>
                瀏覽量調整（顯示加總）<br>
                <input type="number" name="wu_views_offset" value="<?php echo esc_attr($offset); ?>" style="width:120px;">
            </label>
        </p>
        <p class="description">調整值可為正數或負數，不影響真實計數。</p>
        <?php
        wp_nonce_field('wu_views_meta_box', 'wu_views_meta_box_nonce');
    }, 'post', 'side', 'default');
});

// ========== 文章目錄（TOC）==========

function wu_generate_toc(string $content): string {
    if (!is_singular('post')) return $content;
    $opts = wu_post_opts();
    if (!$opts['toc_enable']) return $content;

    $min_headings = $opts['toc_min'];

    preg_match_all(
        '/<h([2-6])(\s[^>]*)?>(\S[\s\S]*?)<\/h\1>/i',
        $content, $matches, PREG_SET_ORDER
    );

    if (count($matches) < $min_headings) return $content;

    $toc_items     = [];
    $heading_count = 0;
    $counters      = array_fill(2, 5, 0); // level 2~6

    foreach ($matches as $heading) {
        $level = (int) $heading[1];
        $attrs = $heading[2] ?? '';
        $inner = $heading[3];
        $text  = wp_strip_all_tags(html_entity_decode($inner, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text  = trim($text);
        $heading_count++;

        // 若已有 id 屬性則沿用，否則自動產生並注入
        if (preg_match('/\bid=["\']([^"\']+)["\']/', $attrs, $id_match)) {
            $id = $id_match[1];
        } else {
            $id      = 'wu-heading-' . $heading_count;
            $new_tag = '<h' . $level . $attrs . ' id="' . esc_attr($id) . '">' . $inner . '</h' . $level . '>';
            $content = preg_replace('/' . preg_quote($heading[0], '/') . '/', $new_tag, $content, 1);
        }

        $counters[$level]++;
        for ($i = $level + 1; $i <= 6; $i++) $counters[$i] = 0;

        $num_parts = [];
        for ($i = 2; $i <= $level; $i++) {
            if ($counters[$i] > 0) $num_parts[] = $counters[$i];
        }

        $toc_items[] = [
            'level'  => $level,
            'text'   => $text,
            'id'     => $id,
            'number' => implode('.', $num_parts),
        ];
    }

    if (empty($toc_items)) return $content;

    $toc_html  = '<div class="wu-toc-container">';
    $toc_html .= '<div class="wu-toc-title">';
    $toc_html .= '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'
               . '<line x1="8" y1="6"  x2="21" y2="6" />'
               . '<line x1="8" y1="12" x2="21" y2="12"/>'
               . '<line x1="8" y1="18" x2="21" y2="18"/>'
               . '<circle cx="3" cy="6"  r="0.5" fill="currentColor"/>'
               . '<circle cx="3" cy="12" r="0.5" fill="currentColor"/>'
               . '<circle cx="3" cy="18" r="0.5" fill="currentColor"/>'
               . '</svg> 目錄';
    $toc_html .= '</div><nav class="wu-toc-nav" aria-label="文章目錄">';

    foreach ($toc_items as $item) {
        $depth     = max(0, (int) $item['level'] - 2);
        $toc_html .= '<div class="wu-toc-item" style="--wu-toc-depth:' . $depth . ';">';
        $toc_html .= '<a href="#' . esc_attr($item['id']) . '" class="wu-toc-link">';
        $toc_html .= '<span class="wu-toc-num">' . esc_html($item['number']) . '</span>';
        $toc_html .= '<span class="wu-toc-text">' . esc_html($item['text']) . '</span>';
        $toc_html .= '</a></div>';
    }

    $toc_html .= '</nav></div>';

    preg_match('/<h[2-6][\s>]/i', $content, $first_match, PREG_OFFSET_CAPTURE);
    if (!empty($first_match)) {
        $content = substr_replace($content, $toc_html, $first_match[0][1], 0);
    } else {
        $content = $toc_html . $content;
    }

    return $content;
}
add_filter('the_content', 'wu_generate_toc', 5);

// TOC 前端腳本與樣式
add_action('wp_footer', function(): void {
    if (!is_singular('post')) return;
    $opts = wu_post_opts();
    if (!$opts['toc_enable']) return;
    $offset = $opts['toc_offset'];
    ?>
    <style>
    .wu-toc-container {
        width: 100%; margin: 2em 0; padding: 1.2em 1.8em;
        background: #f8f9fa; border: 1px solid #ddd;
        border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,.06);
        box-sizing: border-box;
    }
    .wu-toc-title {
        font-size: 1.1em; font-weight: 700; margin-bottom: .9em;
        color: #1d2327; display: flex; align-items: center; gap: .5em;
    }
    .wu-toc-nav { line-height: 1.9; }
    .wu-toc-item { margin-bottom: .3em; padding-left: calc(var(--wu-toc-depth, 0) * 1.25em); }
    .wu-toc-link {
        color: #0073aa; text-decoration: none;
        display: inline-flex; align-items: baseline; gap: .5em;
        transition: color .15s ease, transform .15s ease;
    }
    .wu-toc-link:hover { color: #005177; transform: translateX(3px); }
    .wu-toc-num {
        color: #aaa; font-size: .85em; font-weight: 600;
        min-width: 2em; flex-shrink: 0;
    }
    .wu-toc-link:hover .wu-toc-num { color: #0073aa; }
    .wu-views { opacity: .8; font-size: .9em; margin-top: 1em; }
    @media (max-width: 600px) {
        .wu-toc-container {
            width: 100%; margin: 1.25em 0; padding: 1em .9em;
            border-radius: 7px;
        }
        .wu-toc-title {
            margin-bottom: .65em; font-size: clamp(18px, 5vw, 20px);
            gap: .4em;
        }
        .wu-toc-nav {
            font-size: clamp(14px, 3.8vw, 16px);
            line-height: 1.55;
        }
        .wu-toc-item {
            margin-bottom: .2em;
            padding-left: calc(var(--wu-toc-depth, 0) * .55em);
        }
        .wu-toc-link {
            display: grid; grid-template-columns: 2.2em minmax(0, 1fr);
            gap: .35em; width: 100%; align-items: start;
        }
        .wu-toc-num { min-width: 0; text-align: right; line-height: 1.55; }
        .wu-toc-text { min-width: 0; overflow-wrap: anywhere; line-height: 1.55; }
    }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var offset = <?php echo (int) $offset; ?>;
        document.querySelectorAll('.wu-toc-link').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var id  = this.getAttribute('href').slice(1);
                var el  = document.getElementById(id);
                if (!el) return;
                var top = el.getBoundingClientRect().top + window.pageYOffset - offset;
                window.scrollTo({ top: top, behavior: 'smooth' });
                history.pushState(null, '', '#' + id);
            });
        });
    });
    </script>
    <?php
});

// ========== 設定頁面 ==========

add_action('admin_menu', function(): void {
    add_submenu_page(
        'wu-toolbox-modular',
        '文章目錄設定',
        '文章目錄設定',
        'manage_options',
        'wu-post-optimization',
        'wu_post_optimization_settings_page'
    );
});

function wu_post_optimization_settings_page(): void {
    if (!current_user_can('manage_options')) wp_die('權限不足');

    if (isset($_POST['submit'])) {
        check_admin_referer('wu_post_optimization_settings');

        update_option('wu_views_auto_display',  isset($_POST['wu_views_auto_display'])  ? 1 : 0);
        update_option('wu_views_admin_display', isset($_POST['wu_views_admin_display']) ? 1 : 0);
        update_option('wu_views_display_mode',  ($_POST['wu_views_display_mode'] ?? '') === 'real' ? 'real' : 'total');
        update_option('wu_toc_enable',          isset($_POST['wu_toc_enable']) ? 1 : 0);
        update_option('wu_toc_min_headings',    max(1, (int) ($_POST['wu_toc_min_headings'] ?? 2)));
        update_option('wu_toc_scroll_offset',   max(0, (int) ($_POST['wu_toc_scroll_offset'] ?? 100)));

        // ✅ 儲存後清除快取，確保頁面即時反映新設定
        wu_post_opts(true);

        echo '<div class="notice notice-success is-dismissible"><p>✅ 設定已儲存。</p></div>';
    }

    $v_auto   = (int) get_option('wu_views_auto_display', 0);
    $v_admin  = (int) get_option('wu_views_admin_display', 0);
    $v_mode   = get_option('wu_views_display_mode', 'total');
    $t_enable = (int) get_option('wu_toc_enable', 1);
    $t_min    = (int) get_option('wu_toc_min_headings', 2);
    $t_offset = (int) get_option('wu_toc_scroll_offset', 100);
    ?>
    <div class="wrap">
        <h1>文章目錄設定</h1>
        <form method="post">
            <?php wp_nonce_field('wu_post_optimization_settings'); ?>
            <table class="form-table" role="presentation">

                <tr><th colspan="2"><h2 style="margin:0;">📊 瀏覽量設定</h2></th></tr>
                <tr>
                    <th scope="row">前台自動顯示</th>
                    <td><label>
                        <input type="checkbox" name="wu_views_auto_display" value="1" <?php checked(1, $v_auto); ?>>
                        在文章內容後自動顯示瀏覽量
                    </label></td>
                </tr>
                <tr>
                    <th scope="row">後台顯示設定</th>
                    <td><label>
                        <input type="checkbox" name="wu_views_admin_display" value="1" <?php checked(1, $v_admin); ?>>
                        在文章列表和編輯頁面顯示瀏覽量欄位
                    </label></td>
                </tr>
                <tr>
                    <th scope="row">顯示模式</th>
                    <td>
                        <label><input type="radio" name="wu_views_display_mode" value="total" <?php checked('total', $v_mode); ?>> 真實 + 管理員調整</label><br>
                        <label><input type="radio" name="wu_views_display_mode" value="real"  <?php checked('real',  $v_mode); ?>> 只顯示真實瀏覽量</label>
                    </td>
                </tr>

                <tr><th colspan="2"><h2 style="margin:2em 0 0;">📋 文章目錄設定</h2></th></tr>
                <tr>
                    <th scope="row">啟用目錄功能</th>
                    <td>
                        <label>
                            <input type="checkbox" name="wu_toc_enable" value="1" <?php checked(1, $t_enable); ?>>
                            自動產生文章目錄
                        </label>
                        <p class="description">目錄插入第一個標題前，支援 H2~H6 階層編號</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">最少標題數量</th>
                    <td>
                        <input type="number" name="wu_toc_min_headings" value="<?php echo esc_attr($t_min); ?>" min="1" max="10" style="width:80px;"> 個
                        <p class="description">文章至少需要幾個標題才顯示目錄</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">捲動偏移量</th>
                    <td>
                        <input type="number" name="wu_toc_scroll_offset" value="<?php echo esc_attr($t_offset); ?>" min="0" max="500" style="width:80px;"> px
                        <p class="description">點擊目錄時，標題與畫面頂端的距離（防止被固定選單遮住）</p>
                    </td>
                </tr>

            </table>
            <?php submit_button('儲存設定'); ?>
        </form>

        <hr>
        <h2>使用說明</h2>
        <h3>瀏覽量功能</h3>
        <ul>
            <li><strong>短代碼：</strong><code>[wu_views id="" show="real|total"]</code></li>
            <li><strong>累計計算：</strong>所有瀏覽量為累計數值，不會每日重置</li>
            <li><strong>防重複：</strong>同一訪客 24 小時內重複瀏覽不計入（Cookie 判斷）</li>
            <li><strong>管理員免計：</strong>管理員身份瀏覽不計入統計</li>
            <li><strong>調整功能：</strong>可在文章編輯頁面或快速編輯中調整顯示數字</li>
        </ul>
        <h3>目錄功能</h3>
        <ul>
            <li>自動偵測文章中的 H2~H6 標題，支援含 class/style 等屬性的標題</li>
            <li>達到最少標題數量才會顯示目錄</li>
            <li>自動產生階層編號（H2=1, H3=1.1, H4=1.1.1）</li>
            <li>點擊目錄項目會平滑捲動並更新網址 hash，方便直接分享</li>
            <li>自動插入第一個標題前方，全寬顯示</li>
        </ul>
    </div>
    <?php
}
