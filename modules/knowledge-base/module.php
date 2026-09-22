<?php
/**
 * Module: knowledge-base
 * Simple Knowledge Base Pro, loaded only when enabled.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/quick-support.php';

// ==========================================
// 1. 註冊自訂文章類型與分類
// ==========================================
function wutm_kb_register_post_type() {
    register_post_type( 'skb_doc', array(
        'labels'              => array(
            'name'               => '知識庫文件',
            'singular_name'      => '文件',
            'menu_name'          => '知識庫',
            'add_new'            => '新增文件',
            'add_new_item'       => '新增知識庫文件',
        ),
        'public'              => true,
        'has_archive'         => 'docs',
        'rewrite'             => array( 'slug' => 'doc' ),
        'supports'            => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt' ),
        'menu_icon'           => 'dashicons-book',
        'show_in_rest'        => true,
    ));
}
add_action( 'init', 'wutm_kb_register_post_type' );

function wutm_kb_register_taxonomy() {
    register_taxonomy( 'skb_category', array( 'skb_doc' ), array(
        'hierarchical'      => true,
        'labels'            => array(
            'name'          => '文件分類',
            'menu_name'     => '文件分類',
        ),
        'show_ui'           => true,
        'show_admin_column' => true,
        'rewrite'           => array( 'slug' => 'doc-category' ),
        'show_in_rest'      => true,
    ));
}
add_action( 'init', 'wutm_kb_register_taxonomy' );

// ==========================================
// 2. 建立後台「設定」頁面
// ==========================================
function wutm_kb_add_settings_menu() {
    add_submenu_page(
        'edit.php?post_type=skb_doc',
        '知識庫設定',
        '設定',
        'manage_options',
        'skb-settings',
        'wutm_kb_render_settings_page'
    );
}
add_action( 'admin_menu', 'wutm_kb_add_settings_menu' );

function wutm_kb_register_settings() {
    register_setting( 'skb_settings_group', 'skb_home_url', array( 'sanitize_callback' => 'esc_url_raw' ) );
    register_setting( 'skb_settings_group', 'skb_primary_color', array( 'sanitize_callback' => 'sanitize_hex_color' ) );
    register_setting( 'skb_settings_group', 'skb_recent_count', array( 'sanitize_callback' => 'absint' ) );
    register_setting( 'skb_settings_group', 'skb_show_sidebar_list', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'skb_settings_group', 'skb_show_breadcrumb', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'skb_settings_group', 'skb_footer_text', array( 'sanitize_callback' => 'wp_kses_post' ) );
}
add_action( 'admin_init', 'wutm_kb_register_settings' );

// 取得設定值的統一函式（含預設值）
function wutm_kb_get_settings() {
    return array(
        'home_url'          => get_option( 'skb_home_url', home_url( '/docs-center' ) ),
        'primary_color'     => get_option( 'skb_primary_color', '#3182ce' ),
        'recent_count'      => get_option( 'skb_recent_count', 5 ) ?: 5,
        'show_sidebar_list' => get_option( 'skb_show_sidebar_list', 'yes' ),
        'show_breadcrumb'   => get_option( 'skb_show_breadcrumb', 'yes' ),
        'footer_text'       => get_option( 'skb_footer_text', '' ),
    );
}

// 2.3 設定頁面 HTML 輸出
function wutm_kb_render_settings_page() {
    $s = wutm_kb_get_settings();
    ?>
    <div class="wrap">
        <h1>知識庫設定</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'skb_settings_group' );
            do_settings_sections( 'skb_settings_group' );
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">知識庫大廳 (首頁) 網址</th>
                    <td>
                        <input type="text" name="skb_home_url" value="<?php echo esc_attr( $s['home_url'] ); ?>" class="regular-text" style="width: 400px;" />
                        <p class="description">請輸入您貼上 <code>[knowledge_base]</code> 短代碼的那個頁面的<strong>完整網址</strong>。<br>例如：<code><?php echo esc_html( home_url( '/help-center' ) ); ?></code><br>這將會作為前台左側目錄「回知識庫首頁」的連結目標。</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">主題色</th>
                    <td>
                        <input type="text" name="skb_primary_color" value="<?php echo esc_attr( $s['primary_color'] ); ?>" class="skb-color-field" data-default-color="#3182ce" />
                        <p class="description">此顏色會套用到搜尋按鈕、連結 hover、分類卡片裝飾等元件，統一整體配色。</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">「最新文件」顯示筆數</th>
                    <td>
                        <input type="number" name="skb_recent_count" value="<?php echo esc_attr( $s['recent_count'] ); ?>" class="small-text" min="1" max="20" />
                        <p class="description">首頁側邊欄顯示的最新文件數量，預設 5 筆。</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">單一文件頁－麵包屑</th>
                    <td>
                        <label>
                            <input type="checkbox" name="skb_show_breadcrumb" value="yes" <?php checked( $s['show_breadcrumb'], 'yes' ); ?> />
                            顯示「知識庫首頁 / 分類 / 目前文件」導覽列
                        </label>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">首頁側邊欄</th>
                    <td>
                        <label>
                            <input type="checkbox" name="skb_show_sidebar_list" value="yes" <?php checked( $s['show_sidebar_list'], 'yes' ); ?> />
                            顯示「最新文件」清單（關閉後首頁只顯示分類卡片）
                        </label>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">頁尾提示文字</th>
                    <td>
                        <textarea name="skb_footer_text" rows="2" class="large-text"><?php echo esc_textarea( $s['footer_text'] ); ?></textarea>
                        <p class="description">顯示在單一文件頁面最下方，可留空。例如：「找不到答案？請聯絡客服。」</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function wutm_kb_admin_enqueue_scripts( $hook ) {
    if ( strpos( $hook, 'skb-settings' ) === false ) return;
    wp_enqueue_style( 'wp-color-picker' );
    wp_enqueue_script( 'wp-color-picker' );
    wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){ $(".skb-color-field").wpColorPicker(); });' );
}
add_action( 'admin_enqueue_scripts', 'wutm_kb_admin_enqueue_scripts' );

// ==========================================
// 3. 前台腳本：AJAX 搜尋
// ==========================================
function wutm_kb_enqueue_frontend_assets() {
    wp_register_script( 'skb-frontend', false, array( 'jquery' ), WUTM_VERSION, true );
    wp_enqueue_script( 'skb-frontend' );
    wp_localize_script( 'skb-frontend', 'skbAjax', array(
        'url'   => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'skb_search_nonce' ),
    ));
}
add_action( 'wp_enqueue_scripts', 'wutm_kb_enqueue_frontend_assets' );

// AJAX 端點：站內搜尋（已登入 / 未登入都可用）
function wutm_kb_ajax_search_handler() {
    check_ajax_referer( 'skb_search_nonce', 'nonce' );

    $keyword  = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
    $category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';

    $args = array(
        'post_type'      => 'skb_doc',
        'posts_per_page' => 20,
        's'              => $keyword,
    );
    if ( ! empty( $category ) ) {
        $args['tax_query'] = array( array(
            'taxonomy' => 'skb_category',
            'field'    => 'slug',
            'terms'    => $category,
        ));
    }

    $q = new WP_Query( $args );
    $results = array();
    if ( $q->have_posts() ) {
        while ( $q->have_posts() ) {
            $q->the_post();
            $result_categories = wp_get_post_terms( get_the_ID(), 'skb_category', array( 'fields' => 'names' ) );
            $results[] = array(
                'title'   => get_the_title(),
                'link'    => get_permalink(),
                'excerpt' => wp_strip_all_tags( get_the_excerpt() ),
                'image'   => get_the_post_thumbnail_url( get_the_ID(), 'medium' ) ?: '',
                'date'    => get_the_modified_date( 'Y-m-d' ),
                'category'=> is_wp_error( $result_categories ) ? array() : $result_categories,
            );
        }
        wp_reset_postdata();
    }

    wp_send_json_success( array(
        'keyword' => $keyword,
        'count'   => count( $results ),
        'results' => $results,
    ));
}
add_action( 'wp_ajax_skb_search', 'wutm_kb_ajax_search_handler' );
add_action( 'wp_ajax_nopriv_skb_search', 'wutm_kb_ajax_search_handler' );

// ==========================================
// 4. 首頁短代碼：搜尋 + 分類卡片 + 最新文件
// ==========================================
function wutm_kb_display_knowledge_base() {
    $s = wutm_kb_get_settings();
    ob_start();
    $terms = get_terms( array( 'taxonomy' => 'skb_category', 'hide_empty' => false ) );
    ?>
    <style>
        .skb-wrap { --skb-primary: <?php echo esc_html( $s['primary_color'] ); ?>; }
        .skb-wrap * { box-sizing: border-box !important; }
        .skb-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 1180px; margin: 0 auto; color: #1d2a23; }

        .skb-search-box {
            display: flex; align-items: center; background: #fff; padding: 10px;
            border-radius: 18px; border: 1px solid #dce8e0; box-shadow: 0 14px 38px rgba(26,62,39,.07);
            margin-bottom: 24px; gap: 10px; flex-wrap: wrap;
        }
        .skb-search-box input[type="text"] {
            flex: 1; min-width: 200px; border: none !important; padding: 12px 15px !important;
            font-size: 16px !important; outline: none !important; box-shadow: none !important; background: transparent !important;
        }
        .skb-search-box select {
            width: 180px; border: 1px solid #e2e8f0 !important; padding: 12px !important;
            border-radius: 6px; background: #f8f9fa !important; color:#333; height: auto !important;
        }
        .skb-search-box button {
            background: var(--skb-primary) !important; color: #fff !important; border: none !important;
            padding: 12px 24px !important; border-radius: 6px !important; cursor: pointer; font-weight: 600;
            transition: opacity 0.2s;
        }
        .skb-search-box button:hover { opacity: 0.85; }

        /* 搜尋結果區：與側邊欄最新文件共用同一套卡片樣式 */
        .skb-search-results { margin-bottom: 40px; display: none; }
        .skb-search-results.is-active { display: block; }
        .skb-search-results-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; border-bottom: 2px solid #edf2f7; padding-bottom: 10px; }
        .skb-search-results-head h3 { margin: 0 !important; font-size: 18px !important; color: #2d3748 !important; }
        .skb-search-clear { background: none !important; border: none !important; color: #a0aec0 !important; cursor: pointer; font-size: 13px; text-decoration: underline; padding: 0 !important; }
        .skb-result-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 12px; }
        .skb-result-item { display:grid;grid-template-columns:130px minmax(0,1fr);gap:22px;align-items:center;padding:18px 20px; border: 1px solid #e2e8f0; border-radius: 14px; transition: 0.2s; }
        .skb-result-image,.skb-doc-thumb { width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:11px;background:#f1f5f2; }
        .skb-result-meta,.skb-doc-meta { display:flex;gap:10px;flex-wrap:wrap;margin-bottom:7px;color:#829087;font-size:12px;font-weight:600; }
        .skb-result-item:hover { border-color: var(--skb-primary); box-shadow: 0 6px 14px rgba(0,0,0,0.04); transform: translateX(4px); }
        .skb-result-item a { text-decoration: none !important; font-size: 17px; color: #2d3748; font-weight: bold; box-shadow: none !important; display: block; }
        .skb-result-item p { margin: 8px 0 0 0; color: #718096; font-size: 14px; }
        .skb-search-loading, .skb-search-empty { text-align: center; padding: 30px 20px; color: #a0aec0; background: #f8fafc; border-radius: 10px; }

        .skb-layout { display: grid; grid-template-columns:minmax(0,1fr) 250px; gap: 34px; }
        .skb-layout.is-hidden { display: none; }
        @media (max-width: 768px) { .skb-layout { grid-template-columns: 1fr; } }

        .skb-categories { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px; }
        .skb-card {
            position:relative;min-height:174px;overflow:hidden;background:linear-gradient(145deg,#fff,#f4faf6);border:1px solid #e1ece5;border-radius:18px;padding:25px 24px;
            display:flex;flex-direction:column;align-items:flex-start;justify-content:space-between;text-decoration:none!important;color:#333;
            transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease;box-shadow:0 8px 24px rgba(23,54,35,.045);
        }
        .skb-card::after{content:"";position:absolute;right:-42px;bottom:-58px;width:145px;height:145px;border-radius:50%;background:color-mix(in srgb,var(--skb-primary) 10%,transparent);transition:transform .25s ease;}
        .skb-card:hover { border-color:var(--skb-primary);box-shadow:0 15px 34px rgba(23,54,35,.12);transform:translateY(-4px); }
        .skb-card:hover::after{transform:scale(1.15)}
        .skb-card-top{position:relative;z-index:1;display:flex;width:100%;align-items:center;justify-content:space-between;gap:12px}.skb-card-index{display:grid;place-items:center;width:42px;height:42px;border-radius:13px;background:#edf7f0;color:var(--skb-primary);font-size:12px;font-weight:800;letter-spacing:.06em}.skb-card-count{color:#809087;font-size:12px;font-weight:650}
        .skb-card-info{position:relative;z-index:1}.skb-card-info h3 { margin:0 0 8px!important;font-size:22px!important;color:#17251d!important;font-weight:750;line-height:1.3; }
        .skb-card-info p { margin:0!important;font-size:13px!important;color:#68786f!important;line-height:1.65; }.skb-card-arrow{position:relative;z-index:1;align-self:flex-end;color:var(--skb-primary);font-size:20px;font-weight:700}

        .skb-sidebar-list h3 { font-size: 18px !important; margin: 0 0 15px 0 !important; color: #2d3748 !important; border-bottom: 2px solid #edf2f7; padding-bottom: 10px; }
        .skb-sidebar-list ul { list-style: none !important; padding: 0 !important; margin: 0 !important; }
        .skb-sidebar-list li { margin-bottom: 15px; }
        .skb-sidebar-list a { text-decoration: none !important; color: #4a5568 !important; font-size: 14px; box-shadow: none !important; transition: color 0.2s; }
        .skb-sidebar-list a:hover { color: var(--skb-primary) !important; }
        .skb-recent-item { display:grid;grid-template-columns:72px minmax(0,1fr);gap:11px;align-items:center;margin-bottom:14px; }
        .skb-recent-item img { width:72px;height:54px;object-fit:cover;border-radius:8px;background:#f1f5f2; }

        .skb-empty { text-align: center; padding: 40px 20px; background: #f8fafc; border-radius: 10px; color: #a0aec0; }
        @media(max-width:760px){.skb-categories{grid-template-columns:1fr}}@media(max-width:600px){.skb-result-item{grid-template-columns:1fr}.skb-result-image{max-height:190px}.skb-search-box select,.skb-search-box button{width:100%}}
    </style>

    <div class="skb-wrap">
        <form id="skb-search-form" class="skb-search-box">
            <input type="text" id="skb-search-input" name="s" placeholder="搜尋文件..." autocomplete="off" />
            <select id="skb-search-category" name="skb_category">
                <option value="">所有分類</option>
                <?php
                if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                    foreach ( $terms as $term ) echo '<option value="' . esc_attr( $term->slug ) . '">' . esc_html( $term->name ) . '</option>';
                }
                ?>
            </select>
            <button type="submit">搜 尋</button>
        </form>

        <div id="skb-search-results" class="skb-search-results">
            <div class="skb-search-results-head">
                <h3 id="skb-search-results-title">搜尋結果</h3>
                <button type="button" class="skb-search-clear" id="skb-search-clear">清除搜尋，返回分類列表</button>
            </div>
            <div id="skb-search-results-body"></div>
        </div>

        <div id="skb-default-layout" class="skb-layout">
            <div class="skb-categories">
                <?php
                if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                    foreach ( $terms as $index => $term ) {
                        $link = get_term_link( $term );
                        echo '<a href="'. esc_url($link) .'" class="skb-card">';
                        echo '<div class="skb-card-top"><span class="skb-card-index">'. esc_html( str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) ) .'</span><span class="skb-card-count">'. esc_html($term->count) .' 篇文件</span></div>';
                        echo '<div class="skb-card-info"><h3>'. esc_html($term->name) .'</h3><p>查看此分類的知識庫文件與操作說明。</p></div><span class="skb-card-arrow" aria-hidden="true">→</span>';
                        echo '</a>';
                    }
                } else {
                    echo '<div class="skb-empty">請先至後台「知識庫 &gt; 文件分類」新增分類。</div>';
                }
                ?>
            </div>
            <?php if ( $s['show_sidebar_list'] === 'yes' ) : ?>
            <div class="skb-sidebar-list">
                <h3>最新文件</h3>
                <ul>
                    <?php
                    $q = new WP_Query( array( 'post_type' => 'skb_doc', 'posts_per_page' => (int) $s['recent_count'] ) );
                    if ( $q->have_posts() ) {
                        while ( $q->have_posts() ) { $q->the_post();
                            $thumb = get_the_post_thumbnail_url( get_the_ID(), 'thumbnail' );
                            echo '<li class="skb-recent-item">';
                            echo $thumb ? '<img src="'. esc_url( $thumb ) .'" alt="" loading="lazy">' : '<span class="skb-doc-thumb" aria-hidden="true"></span>';
                            echo '<a href="'. esc_url( get_permalink() ) .'">'. esc_html( get_the_title() ) .'</a></li>';
                        }
                        wp_reset_postdata();
                    } else { echo '<li>無文件</li>'; }
                    ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    (function($){
        $(function(){
            var $form      = $('#skb-search-form');
            var $input     = $('#skb-search-input');
            var $category  = $('#skb-search-category');
            var $results   = $('#skb-search-results');
            var $resultsBody = $('#skb-search-results-body');
            var $resultsTitle = $('#skb-search-results-title');
            var $defaultLayout = $('#skb-default-layout');
            var $clearBtn  = $('#skb-search-clear');

            function escapeHtml(str){
                return $('<div>').text(str).html();
            }

            var searchTimer = null;
            var searchRequest = null;
            function runSearch(){
                var keyword = $.trim($input.val());
                var category = $category.val();

                if ( keyword === '' && category === '' ) {
                    $results.removeClass('is-active');
                    $defaultLayout.removeClass('is-hidden');
                    return;
                }

                $results.addClass('is-active');
                $defaultLayout.addClass('is-hidden');
                $resultsTitle.text('搜尋中...');
                $resultsBody.html('<div class="skb-search-loading">搜尋中，請稍候...</div>');

                if ( searchRequest ) searchRequest.abort();
                searchRequest = $.post(skbAjax.url, {
                    action: 'skb_search',
                    nonce: skbAjax.nonce,
                    keyword: keyword,
                    category: category
                }).done(function(res){
                    if ( ! res.success ) {
                        $resultsBody.html('<div class="skb-search-empty">搜尋發生錯誤，請稍後再試。</div>');
                        return;
                    }
                    var data = res.data;
                    $resultsTitle.text((data.keyword ? '「' + data.keyword + '」' : '所選分類') + '的搜尋結果（' + data.count + '）');

                    if ( data.count === 0 ) {
                        $resultsBody.html('<div class="skb-search-empty">找不到符合的文件，換個關鍵字試試。</div>');
                        return;
                    }

                    var html = '<ul class="skb-result-list">';
                    data.results.forEach(function(item){
                        html += '<li class="skb-result-item">';
                        if(item.image){html += '<img class="skb-result-image" src="'+escapeHtml(item.image)+'" alt="" loading="lazy">';}
                        html += '<div class="skb-result-copy"><div class="skb-result-meta">';
                        if(item.category&&item.category.length){html += '<span>'+escapeHtml(item.category.join('、'))+'</span>';}
                        if(item.date){html += '<span>最後更新 '+escapeHtml(item.date)+'</span>';}
                        html += '</div><a href="' + escapeHtml(item.link) + '">' + escapeHtml(item.title) + '</a>';
                        if ( item.excerpt ) {
                            html += '<p>' + escapeHtml(item.excerpt) + '</p>';
                        }
                        html += '</div></li>';
                    });
                    html += '</ul>';
                    $resultsBody.html(html);
                }).fail(function(xhr,status){
                    if(status==='abort') return;
                    $resultsBody.html('<div class="skb-search-empty">搜尋發生錯誤，請稍後再試。</div>');
                });
            }

            $form.on('submit', function(e){
                e.preventDefault();
                runSearch();
            });

            $input.on('input', function(){
                clearTimeout(searchTimer);
                searchTimer = setTimeout(runSearch, 250);
            });

            $category.on('change', runSearch);

            $clearBtn.on('click', function(){
                $input.val('');
                $category.val('');
                $results.removeClass('is-active');
                $defaultLayout.removeClass('is-hidden');
            });
        });
    })(jQuery);
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode( 'knowledge_base', 'wutm_kb_display_knowledge_base' );

// ==========================================
// 5. 攔截並優化「單一文件」與「分類頁面」
// ==========================================
add_filter( 'template_include', 'wutm_kb_custom_page_templates', 99 );
function wutm_kb_custom_page_templates( $template ) {
    if ( is_singular( 'skb_doc' ) || is_tax( 'skb_category' ) ) {
        wutm_kb_render_custom_layout();
        exit;
    }
    return $template;
}

function wutm_kb_render_custom_layout() {
    get_header();
    $s = wutm_kb_get_settings();
    $terms = get_terms( array( 'taxonomy' => 'skb_category', 'hide_empty' => false ) );
    $skb_home_url = $s['home_url'];
    ?>
    <style>
        .skb-page { --skb-primary: <?php echo esc_html( $s['primary_color'] ); ?>; max-width: 1320px; margin: 48px auto; display: flex; gap: 48px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 0 28px; color:#1d2a23; }

        .skb-left-menu { width: 280px; flex-shrink: 0; background: #fff; border-right: 1px solid #edf2f7; padding-right: 20px; }
        .skb-left-menu h4 { margin-top: 0; color: #a0aec0; font-size: 13px; text-transform: uppercase; letter-spacing: 1px; }
        .skb-left-menu a {
            display: block; padding: 10px 15px; text-decoration: none !important; color: #4a5568;
            border-radius: 6px; margin-bottom: 5px; transition: 0.2s; font-size: 15px; box-shadow: none !important;
            border-left: 3px solid transparent;
        }
        .skb-left-menu a:hover { background: #f7fafc; color: var(--skb-primary); }
        .skb-left-menu a.active { background: #f7fafc; color: var(--skb-primary); font-weight: bold; border-left-color: var(--skb-primary); }
        .skb-toc { position:sticky;top:32px;margin-top:24px;padding:20px;border:1px solid #e2e8e4;border-radius:16px;background:#f8faf9; }
        .skb-toc h4 { margin:0 0 13px;color:#1d2a23;font-size:14px;letter-spacing:0;text-transform:none; }
        .skb-toc-list { list-style:none!important;margin:0!important;padding:0!important; }
        .skb-toc-list li { margin:0!important; }
        .skb-toc-list a { padding:7px 5px;margin:0;border:0;font-size:13px;line-height:1.45; }
        .skb-toc-list .level-3 a { padding-left:17px; }.skb-toc-list .level-4 a { padding-left:29px; }

        .skb-main { flex: 1; min-width: 0; }
        .skb-breadcrumb { font-size: 14px; color: #718096; margin-bottom: 30px; background: #f7fafc; padding: 10px 15px; border-radius: 6px; display: inline-block; }
        .skb-breadcrumb a { color: var(--skb-primary); text-decoration: none !important; box-shadow: none !important; }

        .skb-article h1 { font-size: 32px; color: #2d3748; margin-bottom: 10px; font-weight: bold; line-height: 1.3; }
        .skb-article-meta { font-size: 13px; color: #a0aec0; margin-bottom: 30px; border-bottom: 1px solid #edf2f7; padding-bottom: 20px; }
        .skb-article-content { line-height: 1.8; color: #2d3748; font-size: 16px; }
        .skb-article-content img { max-width: 100%; height: auto; border-radius: 8px; margin: 20px 0; }
        .skb-article-hero { width:100%;max-height:560px;object-fit:cover;border-radius:18px;margin:0 0 34px;display:block; }
        .skb-article-content h2,.skb-article-content h3,.skb-article-content h4 { scroll-margin-top:32px; }
        .skb-article-content blockquote { border-left: 4px solid var(--skb-primary); background: #f7fafc; margin: 20px 0; padding: 12px 20px; color: #4a5568; }
        .skb-article-content code { background: #f1f5f9; color: #d53f8c; padding: 2px 6px; border-radius: 4px; font-size: 14px; }
        .skb-article-content pre { background: #2d3748; color: #e2e8f0; padding: 16px; border-radius: 8px; overflow-x: auto; }
        .skb-article-content pre code { background: transparent; color: inherit; padding: 0; }
        .skb-article-content table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .skb-article-content table th, .skb-article-content table td { border: 1px solid #e2e8f0; padding: 10px 14px; text-align: left; }
        .skb-article-content table th { background: #f7fafc; }
        .skb-article-footer-note { margin-top: 40px; padding: 16px 20px; background: #f7fafc; border-radius: 8px; color: #718096; font-size: 14px; }

        .skb-page--category{display:block;max-width:1180px}.skb-page--category .skb-main{width:100%}.skb-category-nav{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:0 0 30px}.skb-category-link{display:flex;align-items:center;justify-content:space-between;gap:10px;min-height:64px;padding:14px 16px;border:1px solid #e2ece5;border-radius:13px;background:#fff;color:#314138!important;text-decoration:none!important;box-shadow:none!important;transition:.18s ease}.skb-category-link:hover{border-color:var(--skb-primary);color:var(--skb-primary)!important;box-shadow:0 8px 18px rgba(28,67,42,.08);transform:translateY(-2px)}.skb-category-link.active{border-color:var(--skb-primary);background:color-mix(in srgb,var(--skb-primary) 8%,#fff);color:var(--skb-primary)!important}.skb-category-link small{display:block;margin-top:3px;color:#8a988f;font-size:11px}.skb-category-link .skb-category-arrow{font-size:18px;font-weight:700}.skb-category-heading{display:flex;align-items:baseline;justify-content:space-between;gap:16px;margin:0 0 18px;padding-bottom:14px;border-bottom:1px solid #e7eeea}.skb-category-heading h1{margin:0;color:#1d2a23;font-size:30px;line-height:1.3}.skb-category-heading p{margin:0;color:#819087;font-size:13px}
        .skb-doc-list { list-style: none; padding: 0; }
        .skb-doc-list li { display:grid;grid-template-columns:180px minmax(0,1fr);gap:24px;align-items:center;padding: 20px; border: 1px solid #e2e8f0; border-radius: 14px; margin-bottom: 15px; transition: 0.2s; }
        .skb-doc-list img,.skb-doc-placeholder { width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:11px;background:#f1f5f2;display:block; }
        .skb-doc-list li:hover { border-color: var(--skb-primary); box-shadow: 0 6px 14px rgba(0,0,0,0.04); transform: translateX(5px); }
        .skb-doc-list a { text-decoration: none !important; font-size: 18px; color: #2d3748; font-weight: bold; box-shadow: none !important; display: block; }
        .skb-doc-list p { margin: 10px 0 0 0; color: #718096; font-size: 14px; }

        .skb-empty { text-align: center; padding: 40px 20px; background: #f8fafc; border-radius: 10px; color: #a0aec0; }

        @media (max-width: 768px) { .skb-page { flex-direction: column;padding:0 18px;margin:28px auto; } .skb-left-menu { width: 100%; border-right: none; border-bottom: 1px solid #edf2f7; padding-bottom: 20px; } .skb-toc{position:relative;top:auto}.skb-doc-list li{grid-template-columns:1fr}.skb-doc-list img{max-height:220px} }
    </style>

    <div class="skb-page<?php echo is_tax( 'skb_category' ) ? ' skb-page--category' : ''; ?>">
        <?php if ( is_singular( 'skb_doc' ) ) : ?>
        <div class="skb-left-menu">
            <h4>目錄導覽</h4>
            <a href="<?php echo esc_url( $skb_home_url ); ?>">回知識庫首頁</a>
            <div style="margin-top:20px;"></div>
            <?php
            $current_term_id = is_tax('skb_category') ? get_queried_object_id() : 0;
            if ( !empty($terms) ) {
                foreach ( $terms as $t ) {
                    $active = ($t->term_id == $current_term_id) ? 'active' : '';
                    echo '<a href="'. esc_url(get_term_link($t)) .'" class="'. esc_attr( $active ) .'">'. esc_html($t->name) .'</a>';
                }
            }
            ?>
            <?php if ( is_singular( 'skb_doc' ) ) : ?>
                <nav class="skb-toc" id="skb-toc" aria-label="本頁目錄" hidden>
                    <h4>本頁目錄</h4>
                    <ol class="skb-toc-list" id="skb-toc-list"></ol>
                </nav>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="skb-main">
            <?php if ( is_tax('skb_category') ) : ?>
                <?php $term = get_queried_object(); ?>
                <?php if ( $s['show_breadcrumb'] === 'yes' ) : ?>
                <div class="skb-breadcrumb">
                    <a href="<?php echo esc_url($skb_home_url); ?>">知識庫首頁</a> / <?php echo esc_html($term->name); ?>
                </div>
                <?php endif; ?>
                <nav class="skb-category-nav" aria-label="文件分類">
                    <?php foreach ( $terms as $category_term ) : $category_link = get_term_link( $category_term ); if ( is_wp_error( $category_link ) ) continue; ?>
                    <a class="skb-category-link<?php echo (int) $category_term->term_id === (int) $term->term_id ? ' active' : ''; ?>" href="<?php echo esc_url( $category_link ); ?>"><span><strong><?php echo esc_html( $category_term->name ); ?></strong><small><?php echo esc_html( $category_term->count ); ?> 篇文件</small></span><span class="skb-category-arrow" aria-hidden="true">→</span></a>
                    <?php endforeach; ?>
                </nav>
                <div class="skb-category-heading"><h1><?php echo esc_html($term->name); ?></h1><p><?php echo esc_html($term->count); ?> 篇文件</p></div>
                <?php if ( have_posts() ) : ?>
                <ul class="skb-doc-list">
                    <?php while ( have_posts() ) : the_post(); ?>
                        <li>
                            <?php if ( has_post_thumbnail() ) : the_post_thumbnail( 'medium_large', array( 'loading' => 'lazy' ) ); else : ?><span class="skb-doc-placeholder" aria-hidden="true"></span><?php endif; ?>
                            <div><div class="skb-doc-meta"><span><?php echo esc_html( $term->name ); ?></span><span>最後更新 <?php echo esc_html( get_the_modified_date( 'Y-m-d' ) ); ?></span></div>
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                            <?php if(has_excerpt()) { echo '<p>'. esc_html( get_the_excerpt() ) .'</p>'; } ?></div>
                        </li>
                    <?php endwhile; ?>
                </ul>
                <?php else: ?>
                    <div class="skb-empty">此分類尚無文件。</div>
                <?php endif; ?>

            <?php elseif ( is_singular('skb_doc') ) : ?>
                <?php
                while ( have_posts() ) : the_post();
                $post_terms = wp_get_post_terms( get_the_ID(), 'skb_category' );
                $term_name = !empty($post_terms) ? $post_terms[0]->name : '未分類';
                $term_link = !empty($post_terms) ? get_term_link($post_terms[0]) : '#';
                ?>
                <?php if ( $s['show_breadcrumb'] === 'yes' ) : ?>
                <div class="skb-breadcrumb">
                    <a href="<?php echo esc_url($skb_home_url); ?>">知識庫首頁</a> /
                    <a href="<?php echo esc_url($term_link); ?>"><?php echo esc_html($term_name); ?></a> /
                    目前文件
                </div>
                <?php endif; ?>
                <article class="skb-article">
                    <h1><?php the_title(); ?></h1>
                    <div class="skb-article-meta">
                        最後更新：<?php the_modified_date('Y-m-d'); ?>
                    </div>
                    <?php if ( has_post_thumbnail() ) : the_post_thumbnail( 'full', array( 'class' => 'skb-article-hero', 'loading' => 'eager' ) ); endif; ?>
                    <div class="skb-article-content">
                        <?php the_content(); ?>
                    </div>
                    <?php if ( ! empty( $s['footer_text'] ) ) : ?>
                        <div class="skb-article-footer-note"><?php echo wp_kses_post( $s['footer_text'] ); ?></div>
                    <?php endif; ?>
                </article>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php if ( is_singular( 'skb_doc' ) ) : ?>
    <script>
    document.addEventListener('DOMContentLoaded',function(){
        const content=document.querySelector('.skb-article-content'),toc=document.getElementById('skb-toc'),list=document.getElementById('skb-toc-list');
        if(!content||!toc||!list)return;
        const headings=[...content.querySelectorAll('h2,h3,h4')];
        if(!headings.length)return;
        const used=new Set();
        headings.forEach((heading,index)=>{
            let id=heading.id||heading.textContent.trim().toLowerCase().replace(/[^\p{L}\p{N}]+/gu,'-').replace(/^-|-$/g,'')||'section-'+(index+1);
            if(!heading.id){let unique=id,suffix=2;while(used.has(unique)||document.getElementById(unique)){unique=id+'-'+suffix++;}heading.id=unique;}
            used.add(heading.id);
            const li=document.createElement('li');li.className='level-'+heading.tagName.slice(1);const a=document.createElement('a');a.href='#'+heading.id;a.textContent=heading.textContent.trim();li.appendChild(a);list.appendChild(li);
        });
        toc.hidden=false;
    });
    </script>
    <?php endif; ?>
    <?php
    get_footer();
}

// Flush the knowledge-base rewrites once after the module is first enabled.
add_action( 'init', function () {
    if ( get_option( 'wutm_kb_rewrite_flushed_255', false ) ) return;
    flush_rewrite_rules( false );
    update_option( 'wutm_kb_rewrite_flushed_255', 1, false );
}, 99 );
