<?php
/**
 * Plugin Name: WU AI Card
 * Description: 自訂 AI 名片頁面：基本資料、內容區塊、配色主題、全站浮動按鈕、傳送給 AI 文字、QR Code 分享。
 * Version: 1.2.0
 * Author: WU
 * Plugin URI: https://wumetax.com/
 * Text Domain: wu-ai-card
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

defined( 'WU_AIC_VERSION' ) || define( 'WU_AIC_VERSION', '1.2.1' );
defined( 'WU_AIC_OPTION' ) || define( 'WU_AIC_OPTION', 'wu_aic_data' );
defined( 'WU_AIC_SLUG' ) || define( 'WU_AIC_SLUG', 'wu-ai-card' );

/* ======================================================
 * 0. 預設值 / 啟用停用 / Rewrite
 * ====================================================== */

function wu_aic_get_defaults() {
    return [
        'enabled'         => '1',
        'slug'            => 'card',
        'name'            => 'AI 小助手',
        'title'           => '智慧客服代表',
        'avatar'          => '',
        'bio'             => '您好！我是您的專屬 AI 助理，隨時為您提供最專業的協助。',
        'support_url'     => '',
        'theme'           => 'green',
        'dark_mode'       => '0',
        'enable_floating' => '1',
        'enable_send_ai'  => '1',
        'enable_qrcode'   => '1',
        'blocks'          => [
            [
                'type'  => 'cta',
                'label' => '立刻詢問',
                'url'   => '#',
                'image' => '',
                'html'  => '',
            ],
            [
                'type'  => 'link',
                'label' => '官方網站',
                'url'   => home_url( '/' ),
                'image' => '',
                'html'  => '',
            ],
        ],
    ];
}

function wu_aic_get_option() {
    $data = get_option( WU_AIC_OPTION, [] );
    $data = is_array( $data ) ? $data : [];
    $defaults = wu_aic_get_defaults();
    $merged = wp_parse_args( $data, $defaults );

    // Older/imported option values can contain arrays where front-end output
    // expects strings. Do not let one malformed value fatal on the card URL.
    foreach ( $defaults as $key => $default ) {
        if ( $key === 'blocks' ) {
            continue;
        }
        if ( ! is_scalar( $merged[ $key ] ) ) {
            $merged[ $key ] = $default;
        }
    }

    if ( empty( $merged['blocks'] ) || ! is_array( $merged['blocks'] ) ) {
        $merged['blocks'] = $defaults['blocks'];
    }

    // Update the former built-in CTA copy without overriding other custom labels.
    foreach ( $merged['blocks'] as &$block ) {
        if ( is_array( $block ) && ( $block['type'] ?? '' ) === 'cta' && ( $block['label'] ?? '' ) === '立即諮詢' ) {
            $block['label'] = '立刻詢問';
        }
    }
    unset( $block );

    return $merged;
}

function wu_aic_add_rewrite_rule() {
    $opt  = wu_aic_get_option();
    $slug = ! empty( $opt['slug'] ) ? sanitize_title( $opt['slug'] ) : 'card';

    add_rewrite_tag( '%wu_aic_page%', '1' );
    add_rewrite_rule(
        '^' . preg_quote( $slug, '/' ) . '/?$',
        'index.php?wu_aic_page=1',
        'top'
    );
}
add_action( 'init', 'wu_aic_add_rewrite_rule', 5 );

/**
 * 設定真的寫入資料庫後再刷新 rewrite。
 * 舊版在 sanitize 階段就 flush，當下資料庫仍是舊 slug，會造成新網址 404。
 */
function wu_aic_after_option_update( $old_value, $value, $option ) {
    $old_slug = is_array( $old_value ) && ! empty( $old_value['slug'] ) ? sanitize_title( $old_value['slug'] ) : 'card';
    $new_slug = is_array( $value ) && ! empty( $value['slug'] ) ? sanitize_title( $value['slug'] ) : 'card';

    if ( $old_slug !== $new_slug ) {
        wu_aic_add_rewrite_rule();
        flush_rewrite_rules( false );
    }
}
add_action( 'update_option_' . WU_AIC_OPTION, 'wu_aic_after_option_update', 10, 3 );

/* 8 組配色主題 */
function wu_aic_color_themes() {
    return [
        'blue'   => [ '#4A90E2', '#50E3C2' ],
        'purple' => [ '#7B61FF', '#B98BFF' ],
        'green'  => [ '#1FAA59', '#7CE495' ],
        'orange' => [ '#FF7A45', '#FFC069' ],
        'pink'   => [ '#FF5C8D', '#FFA1C1' ],
        'red'    => [ '#E23744', '#FF8A80' ],
        'gold'   => [ '#C9A227', '#F1D97A' ],
        'gray'   => [ '#4B5563', '#9CA3AF' ],
    ];
}

/* Keep the HTML type only for backward compatibility with existing saved cards. */
function wu_aic_block_types() {
    return [
        'cta'         => 'CTA 按鈕',
        'link'        => '一般連結按鈕',
        'image_link'  => '圖文連結',
        'post'        => '精選文章',
        'page'        => '精選頁面',
        'product'     => '精選商品',
        'latest_post' => '最新文章',
        'heading'     => '標題文字',
        'html'        => '既有自訂內容（保留）',
        'social'      => '社群連結',
    ];
}

/* ======================================================
 * 1. 獨立網址頁面
 * ====================================================== */

function wu_aic_is_card_page() {
    return (string) get_query_var( 'wu_aic_page' ) === '1';
}

function wu_aic_maybe_render_page() {
    if ( ! wu_aic_is_card_page() ) {
        return;
    }

    $opt = wu_aic_get_option();

    if ( empty( $opt['enabled'] ) ) {
        status_header( 404 );
        nocache_headers();
        wp_die( esc_html__( 'AI 名片尚未啟用。', 'wu-ai-card' ), '', [ 'response' => 404 ] );
    }

    status_header( 200 );
    nocache_headers();
    wu_aic_render_full_page();
    exit;
}
add_action( 'template_redirect', 'wu_aic_maybe_render_page' );

function wu_aic_render_full_page() {
    $opt = wu_aic_get_option();
    // This is a virtual route, not a normal post/theme template. Printing the
    // site's full head/body/footer hooks here makes unrelated callbacks run
    // without a queried post and can turn this standalone page into a 500.
    // Print only the card's own registered assets on this route.
    wu_aic_enqueue_assets();
    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_print_styles( [ 'wu-aic-style' ] ); ?>
<title><?php echo esc_html( $opt['name'] ); ?> - AI 名片</title>
</head>
<body <?php body_class( 'wu-aic-page-body' ); ?>>
<?php echo do_shortcode( '[wu_ai_card mode="full"]' ); ?>
<?php wp_print_scripts( [ 'wu-aic-qrcode', 'wu-aic-script' ] ); ?>
</body>
</html>
    <?php
}

/* ======================================================
 * 2. 後台設定
 * ====================================================== */

function wu_aic_menu() {
    add_submenu_page(
        'wu-toolbox-modular',
        'WU AI 名片設定',
        'AI 名片設定',
        'manage_options',
        WU_AIC_SLUG,
        'wu_aic_settings_page'
    );
}
add_action( 'admin_menu', 'wu_aic_menu' );

function wu_aic_register_settings() {
    register_setting( 'wu_aic_options', WU_AIC_OPTION, 'wu_aic_sanitize' );
}
add_action( 'admin_init', 'wu_aic_register_settings' );

/** Search published content for the block editor without embedding a huge catalog in the page. */
function wu_aic_ajax_search_content() {
    check_ajax_referer( 'wu_aic_search_content', 'nonce' );

    $post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
    $search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
    $allowed   = [ 'post', 'page' ];

    if ( post_type_exists( 'product' ) && current_user_can( 'edit_products' ) ) {
        $allowed[] = 'product';
    }

    $capability = $post_type === 'page' ? 'edit_pages' : ( $post_type === 'product' ? 'edit_products' : 'edit_posts' );
    if ( ! in_array( $post_type, $allowed, true ) || ! current_user_can( $capability ) ) {
        wp_send_json_error( [ 'message' => '無權搜尋此內容。' ], 403 );
    }

    $items = get_posts(
        [
            'post_type'              => $post_type,
            'post_status'            => 'publish',
            'posts_per_page'         => 51,
            'orderby'                => 'modified',
            'order'                  => 'DESC',
            's'                      => $search,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]
    );

    $results = [];
    foreach ( $items as $item ) {
        $results[] = [
            'id'    => (int) $item->ID,
            'title' => wp_strip_all_tags( get_the_title( $item ) ),
        ];
    }

    wp_send_json_success( $results );
}
add_action( 'wp_ajax_wu_aic_search_content', 'wu_aic_ajax_search_content' );

function wu_aic_sanitize( $input ) {
    $input = is_array( $input ) ? $input : [];
    $out   = wu_aic_get_option();

    $out['enabled']         = isset( $input['enabled'] ) ? '1' : '0';
    $out['slug']            = ! empty( $input['slug'] ) ? sanitize_title( $input['slug'] ) : 'card';
    $out['name']            = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : $out['name'];
    $out['title']           = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : $out['title'];
    $out['avatar']          = isset( $input['avatar'] ) ? esc_url_raw( $input['avatar'] ) : '';
    $out['bio']             = isset( $input['bio'] ) ? sanitize_textarea_field( $input['bio'] ) : $out['bio'];
    $out['support_url']     = isset( $input['support_url'] ) ? esc_url_raw( $input['support_url'] ) : '';
    $out['theme']           = isset( $input['theme'] ) && array_key_exists( $input['theme'], wu_aic_color_themes() ) ? sanitize_key( $input['theme'] ) : 'green';
    $out['dark_mode']       = isset( $input['dark_mode'] ) ? '1' : '0';
    $out['enable_floating'] = isset( $input['enable_floating'] ) ? '1' : '0';
    $out['enable_send_ai']  = isset( $input['enable_send_ai'] ) ? '1' : '0';
    $out['enable_qrcode']   = isset( $input['enable_qrcode'] ) ? '1' : '0';

    $blocks = [];
    $types  = wu_aic_block_types();

    if ( ! empty( $input['block_type'] ) && is_array( $input['block_type'] ) ) {
        foreach ( array_slice( $input['block_type'], 0, 20 ) as $i => $type ) {
            $type = sanitize_key( $type );

            if ( ! array_key_exists( $type, $types ) ) {
                continue;
            }

            $blocks[] = [
                'type'  => $type,
                'label' => isset( $input['block_label'][ $i ] ) ? sanitize_text_field( $input['block_label'][ $i ] ) : '',
                'url'   => isset( $input['block_url'][ $i ] ) ? esc_url_raw( $input['block_url'][ $i ] ) : '',
                'image' => isset( $input['block_image'][ $i ] ) ? esc_url_raw( $input['block_image'][ $i ] ) : '',
                'html'  => isset( $input['block_html'][ $i ] ) ? wp_kses_post( $input['block_html'][ $i ] ) : '',
                'content_id' => isset( $input['block_content_id'][ $i ] ) ? wu_aic_validate_content_id( absint( $input['block_content_id'][ $i ] ), $type ) : 0,
            ];
            if ( $type === 'cta' && $blocks[ count( $blocks ) - 1 ]['label'] === '立即諮詢' ) {
                $blocks[ count( $blocks ) - 1 ]['label'] = '立刻詢問';
            }
        }
    }

    $out['blocks'] = ! empty( $blocks ) ? $blocks : wu_aic_get_defaults()['blocks'];

    return $out;
}

function wu_aic_validate_content_id( $content_id, $type ) {
    $expected_type = in_array( $type, [ 'post', 'page', 'product' ], true ) ? $type : '';
    $post          = $content_id ? get_post( $content_id ) : null;

    if ( ! $expected_type || ! $post || $post->post_type !== $expected_type || $post->post_status !== 'publish' ) {
        return 0;
    }

    return (int) $post->ID;
}

/**
 * Return a small initial list for the selector so the field is usable before
 * any search request. Results are cached for this request and AJAX remains
 * available for larger sites.
 */
function wu_aic_get_initial_content_choices( $type, $selected_id = 0 ) {
    static $choices = [];
    $type = in_array( $type, [ 'post', 'page', 'product' ], true ) ? $type : 'post';
    if ( $type === 'product' && ! post_type_exists( 'product' ) ) {
        return [ 'items' => [], 'has_more' => false ];
    }
    if ( ! isset( $choices[ $type ] ) ) {
        $items = get_posts( [
            'post_type'              => $type,
            'post_status'            => 'publish',
            'posts_per_page'         => 51,
            'orderby'                => 'modified',
            'order'                  => 'DESC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ] );
        $choices[ $type ] = [ 'items' => [], 'has_more' => count( $items ) > 50 ];
        foreach ( array_slice( $items, 0, 50 ) as $item ) {
            $choices[ $type ]['items'][ (int) $item->ID ] = wp_strip_all_tags( get_the_title( $item ) );
        }
    }
    if ( $selected_id && ! isset( $choices[ $type ]['items'][ (int) $selected_id ] ) ) {
        $selected = get_post( (int) $selected_id );
        if ( $selected && $selected->post_type === $type && $selected->post_status === 'publish' ) {
            $choices[ $type ]['items'][ (int) $selected->ID ] = wp_strip_all_tags( get_the_title( $selected ) );
        }
    }
    return $choices[ $type ];
}

function wu_aic_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    wp_enqueue_media();

    $opt      = wu_aic_get_option();
    $themes   = wu_aic_color_themes();
    $types    = array_diff_key( wu_aic_block_types(), [ 'html' => true ] );
    $card_url = home_url( '/' . ( $opt['slug'] ?: 'card' ) . '/' );
    ?>
    <div class="wrap wu-aic-admin-wrap">
        <h1>WU AI 名片設定</h1>

        <div class="notice notice-info inline">
            <p>
                <strong>前台網址：</strong>
                <a href="<?php echo esc_url( $card_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $card_url ); ?></a>
                ｜ <strong>短代碼：</strong><code>[wu_ai_card]</code>
                ｜ <strong>版本：</strong><?php echo esc_html( WU_AIC_VERSION ); ?>
            </p>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields( 'wu_aic_options' ); ?>

            <h2 class="title">基本設定</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">啟用 AI 名片</th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[enabled]" value="1" <?php checked( $opt['enabled'], '1' ); ?>> 啟用名片頁面與前台功能</label></td>
                </tr>
                <tr>
                    <th scope="row">自訂網址 Slug</th>
                    <td>
                        <code><?php echo esc_html( home_url( '/' ) ); ?></code>
                        <input type="text" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[slug]" value="<?php echo esc_attr( $opt['slug'] ); ?>" class="regular-text" />
                        <p class="description">例如輸入 <code>card</code>，前台網址就是 <code><?php echo esc_html( home_url( '/card/' ) ); ?></code>。本版儲存後會自動更新固定網址規則。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">AI 助理 / 品牌名稱</th>
                    <td><input type="text" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[name]" value="<?php echo esc_attr( $opt['name'] ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">職稱 / 角色</th>
                    <td><input type="text" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[title]" value="<?php echo esc_attr( $opt['title'] ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row">頭像圖片</th>
                    <td>
                        <input type="url" id="wu-aic-avatar" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[avatar]" value="<?php echo esc_attr( $opt['avatar'] ); ?>" class="regular-text" placeholder="https://..." />
                        <button type="button" class="button wu-aic-media-pick" data-target="#wu-aic-avatar">選擇圖片</button>
                    </td>
                </tr>
                <tr>
                    <th scope="row">介紹與歡迎詞</th>
                    <td><textarea name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[bio]" rows="4" class="large-text"><?php echo esc_textarea( $opt['bio'] ); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row">聯絡客服連結</th>
                    <td>
                        <input type="url" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[support_url]" value="<?php echo esc_attr( $opt['support_url'] ); ?>" class="regular-text" placeholder="https://line.me/R/ti/p/..." />
                        <p class="description">可填客服頁面、LINE 官方帳號或其他 HTTPS 連結；留空就不顯示「聯絡客服」，原有名片內容不受影響。</p>
                    </td>
                </tr>
            </table>

            <h2 class="title">外觀主題</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">配色主題</th>
                    <td>
                        <?php foreach ( $themes as $key => $colors ) : ?>
                            <label style="display:inline-block;margin:4px 14px 4px 0;">
                                <input type="radio" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[theme]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $opt['theme'], $key ); ?>>
                                <span style="display:inline-block;width:16px;height:16px;border-radius:50%;background:<?php echo esc_attr( $colors[0] ); ?>;vertical-align:middle;"></span>
                                <?php echo esc_html( $key ); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">深色模式</th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[dark_mode]" value="1" <?php checked( $opt['dark_mode'], '1' ); ?>> 名片使用深色背景</label></td>
                </tr>
            </table>

            <h2 class="title">浮動按鈕與工具</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">全站浮動按鈕</th>
                    <td>
                        <label><input type="checkbox" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[enable_floating]" value="1" <?php checked( $opt['enable_floating'], '1' ); ?>> 在網站左下角顯示 AI 名片入口</label>
                        <p class="description">本版同時支援 <code>wp_body_open</code> 與 <code>wp_footer</code>，並有防重複輸出。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">「傳給 AI」按鈕</th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[enable_send_ai]" value="1" <?php checked( $opt['enable_send_ai'], '1' ); ?>> 顯示「複製 AI 指令」按鈕</label><p class="description">不會直接呼叫 AI 服務；訪客可複製整理好的品牌資訊與回答規則，再貼到 ChatGPT 等 AI 工具。</p></td>
                </tr>
                <tr>
                    <th scope="row">QR Code 分享</th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[enable_qrcode]" value="1" <?php checked( $opt['enable_qrcode'], '1' ); ?>> 顯示 QR Code 按鈕</label></td>
                </tr>
            </table>

            <h2 class="title">內容區塊</h2>
            <p class="description">最多 20 個，可拖曳排序。文章、頁面及商品可直接從下拉選單選取；項目超過清單時，再於欄位中輸入關鍵字搜尋。選擇「自動顯示最新內容」時會依區塊類型顯示最新項目。</p>

            <div class="wu-aic-table-scroll">
                <table class="widefat striped" id="wu-aic-blocks-table">
                    <thead>
                        <tr>
                            <th class="wu-aic-col-sort">排序</th>
                            <th class="wu-aic-col-type">類型</th>
                            <th class="wu-aic-col-label">標籤 / 標題</th>
                            <th class="wu-aic-col-content">指定文章 / 頁面 / 商品</th>
                            <th class="wu-aic-col-url">備用連結網址</th>
                            <th class="wu-aic-col-image">圖片</th>
                            <th class="wu-aic-col-delete">刪除</th>
                        </tr>
                    </thead>
                    <tbody id="wu-aic-blocks-body">
                        <?php foreach ( $opt['blocks'] as $block ) : ?>
                            <?php wu_aic_admin_block_row( $block, $types ); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p><button type="button" class="button" id="wu-aic-add-block">＋ 新增區塊</button></p>

            <?php submit_button( '儲存設定' ); ?>
        </form>

        <hr>
        <h2>使用方式</h2>
        <p>1. 獨立頁面：直接開啟 <a href="<?php echo esc_url( $card_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $card_url ); ?></a></p>
        <p>2. 短代碼：任何文章、頁面或小工具放入 <code>[wu_ai_card]</code></p>
        <p>3. 全站浮動：勾選「全站浮動按鈕」後，前台左下角會顯示入口。</p>
    </div>

    <template id="wu-aic-block-template">
        <?php
        wu_aic_admin_block_row(
            [
                'type'  => 'link',
                'label' => '',
                'url'   => '',
                'image' => '',
                'html'  => '',
                'content_id' => 0,
            ],
			$types
        );
        ?>
    </template>

    <style>
        #wu-aic-blocks-table { table-layout:fixed; min-width:1420px; }
        #wu-aic-blocks-table th, #wu-aic-blocks-table td { box-sizing:border-box; vertical-align:top; }
        #wu-aic-blocks-table .wu-aic-col-sort { width:54px; }
        #wu-aic-blocks-table .wu-aic-col-type { width:175px; }
        #wu-aic-blocks-table .wu-aic-col-label { width:240px; }
        #wu-aic-blocks-table .wu-aic-col-content { width:320px; }
        #wu-aic-blocks-table .wu-aic-col-url { width:260px; }
        #wu-aic-blocks-table .wu-aic-col-image { width:270px; }
        #wu-aic-blocks-table .wu-aic-col-delete { width:90px; }
        .wu-aic-table-scroll { max-width:100%; overflow-x:auto; }
        #wu-aic-blocks-table input[type="text"], #wu-aic-blocks-table input[type="url"], #wu-aic-blocks-table select { max-width:100%; width:100%; }
        #wu-aic-blocks-body tr.wu-aic-dragging { opacity: .45; }
        #wu-aic-blocks-body .wu-aic-drag { cursor: grab; font-size: 18px; text-align: center; user-select: none; }
        #wu-aic-blocks-body .wu-aic-drag:active { cursor: grabbing; }
        .wu-aic-admin-wrap .wu-aic-image-wrap { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
        .wu-aic-admin-wrap .wu-aic-image-preview { display:block; width:72px; height:56px; object-fit:cover; border:1px solid #c3c4c7; border-radius:3px; background:#f6f7f7; }
        .wu-aic-admin-wrap .wu-aic-image-preview[hidden] { display:none!important; }
        .wu-aic-admin-wrap .wu-aic-content-picker { min-width:0; }
        .wu-aic-admin-wrap .wu-aic-content-picker[hidden] { display:none!important; }
        .wu-aic-admin-wrap .wu-aic-content-picker input { width:100%; margin:5px 0; }
        .wu-aic-admin-wrap .wu-aic-content-select { display:block; width:100%; }
        @media screen and (max-width:782px) {
            #wu-aic-blocks-table { min-width:0; table-layout:auto; }
            #wu-aic-blocks-table thead { display:none; }
            #wu-aic-blocks-table, #wu-aic-blocks-table tbody, #wu-aic-blocks-table tr, #wu-aic-blocks-table td { display:block; width:100%; }
            #wu-aic-blocks-table tr { padding:12px; margin:0 0 14px; border:1px solid #c3c4c7; border-radius:6px; background:#fff; }
            #wu-aic-blocks-table td { padding:8px 0; border:0; }
            #wu-aic-blocks-table td:not(.wu-aic-drag)::before { display:block; margin-bottom:5px; color:#50575e; font-weight:600; }
            #wu-aic-blocks-table td:nth-child(2)::before { content:'類型'; }
            #wu-aic-blocks-table td:nth-child(3)::before { content:'標籤 / 標題'; }
            #wu-aic-blocks-table td:nth-child(4)::before { content:'指定文章 / 頁面 / 商品'; }
            #wu-aic-blocks-table td:nth-child(5)::before { content:'備用連結網址'; }
            #wu-aic-blocks-table td:nth-child(6)::before { content:'圖片'; }
            #wu-aic-blocks-table td:last-child { text-align:right; }
            #wu-aic-blocks-table .wu-aic-col-sort { width:auto; }
            .wu-aic-admin-wrap .wu-aic-content-select { min-height:44px; font-size:16px; }
            .wu-aic-admin-wrap .wu-aic-content-picker input { min-height:44px; font-size:16px; }
        }
    </style>

    <script>
    (function () {
        var body = document.getElementById('wu-aic-blocks-body');
        var addBtn = document.getElementById('wu-aic-add-block');
        var template = document.getElementById('wu-aic-block-template');

        if (!body || !addBtn || !template) return;

        function bindRow(row) {
            row.setAttribute('draggable', 'true');
            updateContentPicker(row);
        }

        function fillContentChoices(row, type, searchValue) {
            var picker = row.querySelector('.wu-aic-content-picker');
            var search = picker.querySelector('.wu-aic-content-search');
            var select = picker.querySelector('.wu-aic-content-select');
            var status = picker.querySelector('.wu-aic-content-status');
            var requestId = (searchRequests.get(search) || 0) + 1;
            searchRequests.set(search, requestId);
            var data = new FormData();
            data.append('action', 'wu_aic_search_content');
            data.append('nonce', search.dataset.nonce);
            data.append('post_type', type);
            data.append('search', searchValue || '');
            status.textContent = '載入本站內容中…';
            fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
                .then(function (response) { return response.json(); })
                .then(function (response) {
                    if (searchRequests.get(search) !== requestId || row.querySelector('.wu-aic-block-type').value !== type || search.value !== (searchValue || '')) return;
                    if (!response.success || !Array.isArray(response.data)) {
                        status.textContent = (response.data && response.data.message) || '無法載入內容，請重新整理後再試。';
                        return;
                    }
                    var previous = select.value;
                    var previousOption = select.options[select.selectedIndex];
                    select.replaceChildren(new Option('自動顯示最新內容', '0'));
                    response.data.slice(0, 50).forEach(function (item) { select.add(new Option(item.title, String(item.id))); });
                    if (!searchValue) search.hidden = response.data.length <= 50;
                    var hasPrevious = Array.from(select.options).some(function (option) { return option.value === previous; });
                    if (!hasPrevious && previous !== '0' && previousOption) {
                        select.add(new Option(previousOption.text, previous));
                        hasPrevious = true;
                    }
                    select.value = hasPrevious ? previous : '0';
                    status.textContent = response.data.length ? '' : '目前沒有符合的已發布內容。';
                })
                .catch(function () {
                    if (searchRequests.get(search) === requestId) status.textContent = '載入失敗，請檢查網路後重新搜尋。';
                });
        }

        function updateContentPicker(row) {
            var type = row.querySelector('.wu-aic-block-type');
            var picker = row.querySelector('.wu-aic-content-picker');
            if (!type || !picker) return;
            picker.hidden = !['post', 'page', 'product'].includes(type.value);
            if (picker.dataset.contentType !== type.value) {
                picker.dataset.contentType = type.value;
                var select = picker.querySelector('.wu-aic-content-select');
                var search = picker.querySelector('.wu-aic-content-search');
                select.replaceChildren(new Option('自動顯示最新內容', '0'));
                search.value = '';
                search.placeholder = '搜尋本站已發布' + (type.value === 'product' ? '商品' : (type.value === 'page' ? '頁面' : '文章'));
                search.hidden = true;
                if (picker.hidden === false) fillContentChoices(row, type.value, '');
            }
        }

        body.querySelectorAll('tr').forEach(bindRow);

        addBtn.addEventListener('click', function () {
            if (body.querySelectorAll('tr').length >= 20) {
                alert('內容區塊最多 20 個。');
                return;
            }

            var fragment = template.content.cloneNode(true);
            var row = fragment.querySelector('tr');
            bindRow(row);
            body.appendChild(fragment);
        });

        body.addEventListener('click', function (e) {
            var remove = e.target.closest('.wu-aic-remove-block');
            if (remove) {
                var rows = body.querySelectorAll('tr');
                if (rows.length <= 1) {
                    alert('至少保留 1 個內容區塊。');
                    return;
                }
                remove.closest('tr').remove();
                return;
            }

            var mediaBtn = e.target.closest('.wu-aic-row-media-pick');
            if (mediaBtn && window.wp && wp.media) {
                var wrap = mediaBtn.closest('.wu-aic-image-wrap');
                var input = wrap.querySelector('input[type="hidden"]');
                var preview = wrap.querySelector('.wu-aic-image-preview');
                var frame = wp.media({
                    title: '選擇圖片',
                    button: { text: '使用這張圖片' },
                    multiple: false
                });
                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    input.value = attachment.url || '';
                    preview.src = attachment.url || '';
                    preview.hidden = !attachment.url;
                    wrap.querySelector('.wu-aic-row-image-clear').hidden = !attachment.url;
                });
                frame.open();
                return;
            }
            var clearImage = e.target.closest('.wu-aic-row-image-clear');
            if (clearImage) {
                var imageWrap = clearImage.closest('.wu-aic-image-wrap');
                imageWrap.querySelector('input[type="hidden"]').value = '';
                imageWrap.querySelector('.wu-aic-image-preview').removeAttribute('src');
                imageWrap.querySelector('.wu-aic-image-preview').hidden = true;
                clearImage.hidden = true;
            }
        });

        body.addEventListener('change', function (e) {
            if (e.target.matches('.wu-aic-block-type')) updateContentPicker(e.target.closest('tr'));
        });

        var searchTimers = new WeakMap();
        var searchRequests = new WeakMap();
        body.addEventListener('input', function (e) {
            var search = e.target.closest('.wu-aic-content-search');
            if (!search) return;
            var row = search.closest('tr');
            var type = row.querySelector('.wu-aic-block-type').value;
            if (!['post', 'page', 'product'].includes(type)) return;
            clearTimeout(searchTimers.get(search));
            var searchValue = search.value;
            searchTimers.set(search, setTimeout(function () {
                fillContentChoices(row, type, searchValue);
            }, 250));
        });

        document.addEventListener('click', function (e) {
            var mediaBtn = e.target.closest('.wu-aic-media-pick');
            if (!mediaBtn || !(window.wp && wp.media)) return;

            var target = document.querySelector(mediaBtn.getAttribute('data-target'));
            if (!target) return;

            var frame = wp.media({
                title: '選擇圖片',
                button: { text: '使用這張圖片' },
                multiple: false
            });
            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                target.value = attachment.url || '';
            });
            frame.open();
        });

        var dragging = null;

        body.addEventListener('dragstart', function (e) {
            var row = e.target.closest('tr');
            if (!row) return;
            dragging = row;
            row.classList.add('wu-aic-dragging');
            e.dataTransfer.effectAllowed = 'move';
        });

        body.addEventListener('dragend', function () {
            if (dragging) dragging.classList.remove('wu-aic-dragging');
            dragging = null;
        });

        body.addEventListener('dragover', function (e) {
            if (!dragging) return;
            e.preventDefault();

            var after = getDragAfterElement(body, e.clientY);
            if (!after) {
                body.appendChild(dragging);
            } else if (after !== dragging) {
                body.insertBefore(dragging, after);
            }
        });

        function getDragAfterElement(container, y) {
            var rows = Array.prototype.slice.call(
                container.querySelectorAll('tr:not(.wu-aic-dragging)')
            );

            return rows.reduce(function (closest, child) {
                var box = child.getBoundingClientRect();
                var offset = y - box.top - box.height / 2;

                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                }
                return closest;
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }
    })();
    </script>
    <?php
}

function wu_aic_admin_block_row( $block, $types ) {
    $block = wp_parse_args(
        is_array( $block ) ? $block : [],
        [
            'type'  => 'link',
            'label' => '',
            'url'   => '',
            'image' => '',
            'html'  => '',
            'content_id' => 0,
        ]
    );
    $has_content_type = in_array( $block['type'], [ 'post', 'page', 'product' ], true );
    $content_type = $has_content_type ? $block['type'] : 'none';
    if ( $block['type'] === 'html' ) {
        $types['html'] = '既有自訂內容（保留舊資料）';
    }
    $content_choices = $has_content_type ? wu_aic_get_initial_content_choices( $content_type, (int) $block['content_id'] ) : [ 'items' => [], 'has_more' => false ];
    ?>
    <tr class="wu-aic-block-row">
        <td class="wu-aic-drag" title="拖曳排序">☰
            <input type="hidden" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[block_html][]" value="<?php echo esc_attr( $block['html'] ); ?>">
        </td>
        <td>
            <select class="wu-aic-block-type" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[block_type][]">
                <?php foreach ( $types as $tkey => $tlabel ) : ?>
                    <option value="<?php echo esc_attr( $tkey ); ?>" <?php selected( $block['type'], $tkey ); ?>><?php echo esc_html( $tlabel ); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input type="text" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[block_label][]" value="<?php echo esc_attr( $block['label'] ); ?>" class="regular-text"></td>
        <td>
            <div class="wu-aic-content-picker" data-content-type="<?php echo esc_attr( $content_type ); ?>" <?php echo $has_content_type ? '' : 'hidden'; ?>>
                <input type="search" class="wu-aic-content-search" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wu_aic_search_content' ) ); ?>" placeholder="項目很多，可輸入關鍵字搜尋本站已發布<?php echo $content_type === 'product' ? '商品' : ( $content_type === 'page' ? '頁面' : '文章' ); ?>" <?php echo empty( $content_choices['has_more'] ) ? 'hidden' : ''; ?>>
                <select class="wu-aic-content-select" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[block_content_id][]">
                    <option value="0">自動顯示最新內容</option>
                    <?php foreach ( $content_choices['items'] as $choice_id => $choice_title ) : ?>
                        <option value="<?php echo esc_attr( $choice_id ); ?>" <?php selected( (int) $block['content_id'], (int) $choice_id ); ?>><?php echo esc_html( $choice_title ); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="wu-aic-content-status" aria-live="polite"></span>
            </div>
        </td>
        <td><input type="url" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[block_url][]" value="<?php echo esc_attr( $block['url'] ); ?>" class="regular-text" placeholder="https://..."></td>
        <td>
            <div class="wu-aic-image-wrap">
                <input type="hidden" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[block_image][]" value="<?php echo esc_attr( $block['image'] ); ?>">
                <img class="wu-aic-image-preview" src="<?php echo esc_url( $block['image'] ); ?>" alt="" <?php echo empty( $block['image'] ) ? 'hidden' : ''; ?>>
                <button type="button" class="button wu-aic-row-media-pick">選擇圖片</button>
                <button type="button" class="button-link wu-aic-row-image-clear" <?php echo empty( $block['image'] ) ? 'hidden' : ''; ?>>清除</button>
            </div>
        </td>
        <td><button type="button" class="button-link-delete wu-aic-remove-block">刪除</button></td>
    </tr>
    <?php
}

/* ======================================================
 * 3. 前端 CSS / JS
 * ====================================================== */

function wu_aic_enqueue_assets() {
    if ( is_admin() ) {
        return;
    }

    $opt = wu_aic_get_option();

    if ( empty( $opt['enabled'] ) ) {
        return;
    }

    $themes  = wu_aic_color_themes();
    $theme   = $themes[ $opt['theme'] ] ?? $themes['blue'];
    $c1      = $theme[0];
    $c2      = $theme[1];
    $is_dark = $opt['dark_mode'] === '1';

    $bg      = $is_dark ? '#1f2430' : '#ffffff';
    $text    = $is_dark ? '#f1f1f1' : '#2c3e50';
    $subtext = $is_dark ? '#b8bcc8' : '#7f8c8d';
    $bio_bg  = $is_dark ? '#2a3040' : '#f8f9fa';
    $border  = $is_dark ? 'rgba(255,255,255,.10)' : 'rgba(0,0,0,.08)';

    $css = "
        .wu-aic-page-body{
            margin:0;
            background:{$bio_bg};
            min-height:100vh;
            padding:40px 15px;
            box-sizing:border-box;
        }
        .wu-aic-page-body *{box-sizing:border-box;}
        .wu-aic-container{
            width:100%;
            max-width:380px;
            background:{$bg};
            color:{$text};
            border-radius:20px;
            box-shadow:0 18px 48px rgba(20,35,30,.13);
            overflow:hidden;
            font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Noto Sans TC',Arial,sans-serif;
            margin:20px auto;
            border:1px solid {$border};
        }
        .wu-aic-header{
            background:{$c1};
            height:104px;
        }
        .wu-aic-body{
            padding:0 22px 24px;
            text-align:center;
        }
        .wu-aic-avatar{
            width:90px;
            height:90px;
            border-radius:50%;
            border:4px solid {$bg};
            object-fit:cover;
            background:#e9ecef;
            margin:-45px auto 14px;
            box-shadow:0 6px 18px rgba(20,35,30,.14);
            display:flex;
            align-items:center;
            justify-content:center;
        }
        .wu-aic-name{font-size:1.45rem;font-weight:750;margin:0 0 4px;color:{$text};letter-spacing:.01em;}
        .wu-aic-title{font-size:.9rem;color:{$subtext};margin:0 0 16px;font-weight:500;}
        .wu-aic-bio{
            font-size:.92rem;color:{$text};line-height:1.7;background:{$bio_bg};
            padding:14px;border-radius:10px;text-align:left;margin-bottom:18px;
        }
        .wu-aic-search-wrap{margin:0 0 14px;text-align:left;}
        .wu-aic-search-input{width:100%;box-sizing:border-box;padding:12px 15px;border:1px solid {$border};border-radius:999px;background:{$bg};color:{$text};font:inherit;box-shadow:0 4px 14px rgba(0,0,0,.06);}
        .wu-aic-search-input:focus{outline:2px solid {$c1};outline-offset:1px;}
        .wu-aic-search-wrap small{display:block;margin:6px 10px;color:{$subtext};font-size:.78rem;}
        .wu-aic-search-empty{margin:8px 2px;color:{$subtext};font-size:.9rem;}
        .wu-aic-contact-link{display:block;margin-top:12px;padding:13px 16px;border-radius:12px;background:{$c1};color:#fff!important;text-align:center;text-decoration:none;font-weight:700;}
        .wu-aic-contact-link:hover{filter:brightness(.94);}
        .wu-aic-blocks{display:flex;flex-direction:column;gap:10px;text-align:left;}
        .wu-aic-blocks > [hidden],.wu-aic-search-empty[hidden]{display:none!important;}
        .wu-aic-block-cta,.wu-aic-block-link{
            display:block;text-decoration:none;padding:14px 16px;border-radius:12px;
            font-weight:650;text-align:center;transition:transform .18s ease,box-shadow .18s ease;
        }
        .wu-aic-block-cta{background:{$c1};color:#fff!important;}
        .wu-aic-block-link{background:{$bio_bg};color:{$text}!important;border:1px solid {$border};}
        .wu-aic-block-cta:hover,.wu-aic-block-link:hover{transform:translateY(-2px);box-shadow:0 5px 14px rgba(0,0,0,.08);}
        .wu-aic-block-image_link{
            display:flex;align-items:center;gap:12px;text-decoration:none;color:{$text}!important;
            background:{$bio_bg};border:1px solid {$border};border-radius:10px;padding:9px;
        }
        .wu-aic-block-image_link img{width:52px;height:52px;border-radius:8px;object-fit:cover;flex:0 0 52px;}
        .wu-aic-content-card{
            display:flex;align-items:center;gap:12px;min-height:82px;padding:10px;
            color:{$text}!important;text-decoration:none;background:{$bg};
            border:1px solid {$border};border-radius:12px;box-shadow:0 4px 14px rgba(0,0,0,.05);
            transition:transform .18s ease,box-shadow .18s ease;
        }
        .wu-aic-content-card:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.10);}
        .wu-aic-content-card img{width:68px;height:68px;flex:0 0 68px;object-fit:cover;border-radius:9px;background:{$bio_bg};}
        .wu-aic-content-card-copy{min-width:0;display:flex;flex-direction:column;gap:4px;}
        .wu-aic-content-card-title{font-weight:650;line-height:1.45;}
        .wu-aic-content-card-meta{font-size:.82rem;color:{$subtext};}
        .wu-aic-block-heading{font-weight:700;font-size:1rem;margin-top:8px;color:{$text};}
        .wu-aic-block-html{color:{$text};}
        .wu-aic-block-social{display:flex;gap:10px;justify-content:center;}
        .wu-aic-block-social a{
            text-decoration:none;padding:8px 12px;border-radius:8px;background:{$bio_bg};
            color:{$text}!important;border:1px solid {$border};
        }
        .wu-aic-tools{display:flex;gap:8px;margin-top:18px;justify-content:center;flex-wrap:wrap;}
        .wu-aic-tool-btn{
            appearance:none;border:1px solid {$border};background:{$bg};color:{$text};
            padding:8px 12px;border-radius:8px;font-size:.85rem;cursor:pointer;line-height:1.4;
        }
        .wu-aic-tool-btn:hover{background:{$bio_bg};}
        .wu-aic-qrcode-box{margin-top:14px;text-align:center;padding:12px;background:#fff;border-radius:10px;}
        .wu-aic-qrcode-box canvas{display:block;margin:0 auto;max-width:100%;height:auto!important;}
        .wu-aic-qr-message{color:#a33;font-size:.9rem;margin:8px 0 0;}

        .wu-aic-floating-btn{
            position:fixed!important;bottom:24px!important;left:24px!important;right:auto!important;
            width:58px!important;height:58px!important;box-sizing:border-box!important;
            background:linear-gradient(145deg,#59b971,#3d9858 58%,#347c4b)!important;
            color:#fff!important;border:4px solid rgba(255,255,255,.94)!important;border-radius:50%!important;
            display:flex!important;align-items:center!important;justify-content:center!important;
            box-shadow:0 12px 32px rgba(52,124,75,.28)!important;cursor:pointer!important;
            z-index:2147483001!important;font-size:25px!important;line-height:1!important;
            transition:transform .22s ease,box-shadow .22s ease,background .22s ease!important;
        }
        .wu-aic-floating-btn:hover{transform:translateY(-3px) scale(1.025)!important;box-shadow:0 14px 34px rgba(52,124,75,.34)!important;}
        .wu-aic-floating-btn::before{content:'';position:absolute;top:4px;right:4px;width:7px;height:7px;border:2px solid #fff;border-radius:50%;background:#8fe0a1;}
        .wu-aic-floating-btn[aria-expanded='true']{background:#171b19!important;}
        .wu-aic-floating-btn svg{width:24px;height:24px;display:block;}
        .wu-aic-floating-popup{
            position:fixed!important;bottom:96px!important;left:24px!important;right:auto!important;
            z-index:2147483000!important;display:none!important;opacity:0!important;
            transform:translateY(12px)!important;transition:opacity .22s ease,transform .22s ease!important;
            width:min(380px,calc(100vw - 32px))!important;max-height:calc(100vh - 130px)!important;
            overflow:auto!important;overscroll-behavior:contain!important;
        }
        .wu-aic-floating-popup.wu-active{display:block!important;opacity:1!important;transform:translateY(0)!important;}
        .wu-aic-floating-popup .wu-aic-container{margin:0!important;max-width:none!important;}
        .wu-aic-backdrop{position:fixed!important;inset:0!important;z-index:2147482999!important;display:none!important;background:rgba(17,22,19,.14)!important;backdrop-filter:blur(3px)!important;-webkit-backdrop-filter:blur(3px)!important;}
        .wu-aic-backdrop.wu-active{display:block!important;}

        @media (max-width:600px){
        .wu-aic-page-body{padding:16px 10px;}
        .wu-aic-container{max-width:100%;}
        .wu-aic-body{padding:0 16px 20px;}
            .wu-aic-floating-btn{left:16px!important;right:auto!important;bottom:16px!important;width:56px!important;height:56px!important;}
            .wu-aic-floating-popup{
                left:16px!important;right:auto!important;bottom:82px!important;width:calc(100vw - 32px)!important;
                max-height:calc(100vh - 105px)!important;
            }
            .wu-aic-container{border-radius:15px;}
        }
    ";

    wp_register_style( 'wu-aic-style', false, [], WU_AIC_VERSION );
    wp_enqueue_style( 'wu-aic-style' );
    wp_add_inline_style( 'wu-aic-style', $css );

    if ( $opt['enable_qrcode'] === '1' ) {
        // Bundle locally: qrcode@1.5.3's published package omitted the documented build bundle.
        wp_enqueue_script(
            'wu-aic-qrcode',
            plugins_url( 'assets/qrcode.min.js', __FILE__ ),
            [],
            '1.5.1',
            true
        );
    }

    $script_dependencies = $opt['enable_qrcode'] === '1' ? [ 'wu-aic-qrcode' ] : [];
    wp_register_script( 'wu-aic-script', false, $script_dependencies, WU_AIC_VERSION, true );
    wp_enqueue_script( 'wu-aic-script' );

    $js = "
    (function(){
        function ready(fn){
            if(document.readyState === 'loading'){
                document.addEventListener('DOMContentLoaded', fn);
            } else {
                fn();
            }
        }

        function floatingIcon(open){
            if(open){
                return '<svg viewBox=\"0 0 24 24\" aria-hidden=\"true\"><path d=\"M6 6l12 12M18 6L6 18\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2.4\" stroke-linecap=\"round\"/></svg>';
            }
            return '<svg viewBox=\"0 0 24 24\" aria-hidden=\"true\"><path d=\"M20.5 11.5a8 8 0 0 1-8 8 8.1 8.1 0 0 1-3.7-.9L4 20l1.4-4.4a8 8 0 1 1 15.1-4.1Z\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"/><path d=\"M9 11.5h.01M12 11.5h.01M15 11.5h.01\" stroke=\"currentColor\" stroke-width=\"2.2\" stroke-linecap=\"round\"/></svg>';
        }

        function copyText(text){
            if(navigator.clipboard && window.isSecureContext){
                return navigator.clipboard.writeText(text);
            }

            return new Promise(function(resolve, reject){
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();

                try {
                    document.execCommand('copy') ? resolve() : reject();
                } catch(err) {
                    reject(err);
                }

                document.body.removeChild(ta);
            });
        }

        ready(function(){
            document.addEventListener('click', function(e){
                var btn = e.target.closest('[data-wu-aic-action]');
                if(!btn) return;

                var action = btn.getAttribute('data-wu-aic-action');

                if(action === 'toggle-floating'){
                    var popupId = btn.getAttribute('aria-controls');
                    var popup = popupId ? document.getElementById(popupId) : null;
                    if(!popup) return;

                    var open = popup.classList.toggle('wu-active');
                    popup.style.display = open ? 'block' : 'none';
                    var backdrop = document.querySelector('.wu-aic-backdrop');
                    if(backdrop){
                        backdrop.classList.toggle('wu-active', open);
                        backdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
                    }
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    btn.innerHTML = floatingIcon(open);
                    btn.setAttribute('aria-label', open ? '關閉 AI 名片' : '開啟 AI 名片');
                    return;
                }

                if(action === 'copy-ai'){
                    var payload = btn.getAttribute('data-copy') || '';
                    try { payload = JSON.parse(payload); } catch(err) {}
                    copyText(payload).then(function(){
                        var old = btn.textContent;
                        btn.textContent = '✓ 已複製指令';
                        setTimeout(function(){ btn.textContent = old; }, 1400);
                    }).catch(function(){
                        alert('無法自動複製。請允許剪貼簿權限，或手動選取後貼到 ChatGPT 等 AI 工具。');
                    });
                    return;
                }

                if(action === 'toggle-qr'){
                    var boxId = btn.getAttribute('aria-controls');
                    var box = boxId ? document.getElementById(boxId) : null;
                    if(!box) return;

                    var willShow = box.hidden;
                    box.hidden = !willShow;
                    btn.setAttribute('aria-expanded', willShow ? 'true' : 'false');

                    if(willShow && !box.dataset.rendered){
                        var canvas = box.querySelector('canvas');
                        var url = btn.getAttribute('data-url') || window.location.href;

                        var message = box.querySelector('.wu-aic-qr-message');
                        if(window.QRCode && typeof window.QRCode.toCanvas === 'function' && canvas){
                            window.QRCode.toCanvas(canvas, url, {width:200, margin:2, errorCorrectionLevel:'M'}, function(error){
                                if(!error){
                                    box.dataset.rendered = '1';
                                    if(message) message.hidden = true;
                                } else if(message){
                                    message.textContent = 'QR Code 產生失敗，請重新整理後再試。';
                                    message.hidden = false;
                                }
                            });
                        } else if(message){
                            message.textContent = 'QR Code 元件載入失敗，請重新整理頁面後再試。';
                            message.hidden = false;
                        }
                    }
                }
            });

            document.addEventListener('click', function(e){
                var popup = e.target.closest('.wu-aic-floating-popup');
                var floatBtn = e.target.closest('.wu-aic-floating-btn');
                if(popup || floatBtn) return;

                document.querySelectorAll('.wu-aic-floating-popup.wu-active').forEach(function(item){
                    item.classList.remove('wu-active');
                    item.style.display = 'none';
                    var backdrop = document.querySelector('.wu-aic-backdrop');
                    if(backdrop){backdrop.classList.remove('wu-active');backdrop.setAttribute('aria-hidden','true');}
                    var id = item.id;
                    var controller = document.querySelector('.wu-aic-floating-btn[aria-controls=\"' + id + '\"]');
                    if(controller){
                        controller.setAttribute('aria-expanded','false');
                        controller.innerHTML = floatingIcon(false);
                        controller.setAttribute('aria-label','開啟 AI 名片');
                    }
                });
            });

            document.addEventListener('input', function(e){
                var input = e.target.closest('.wu-aic-search-input');
                if(!input) return;
                var container = input.closest('.wu-aic-container');
                var blocks = container ? container.querySelector('.wu-aic-blocks') : null;
                if(!blocks) return;
                var query = input.value.trim().toLocaleLowerCase();
                var visible = 0;
                Array.prototype.forEach.call(blocks.children, function(item){
                    var match = !query || item.textContent.toLocaleLowerCase().indexOf(query) !== -1;
                    item.hidden = !match;
                    if(match) visible++;
                });
                var empty = container.querySelector('.wu-aic-search-empty');
                if(empty) empty.hidden = !query || visible > 0;
            });

            document.addEventListener('keydown', function(e){
                if(e.key !== 'Escape') return;
                var open = document.querySelector('.wu-aic-floating-popup.wu-active');
                if(!open) return;
                var controller = Array.prototype.find.call(document.querySelectorAll('.wu-aic-floating-btn'), function(button){
                    return button.getAttribute('aria-controls') === open.id;
                });
                if(controller) controller.click();
            });
        });
    })();
    ";
    wp_add_inline_script( 'wu-aic-script', $js );

}
add_action( 'wp_enqueue_scripts', 'wu_aic_enqueue_assets', 20 );

/* ======================================================
 * 4. 區塊渲染
 * ====================================================== */

function wu_aic_render_block( $block ) {
    $block = wp_parse_args(
        is_array( $block ) ? $block : [],
        [
            'type'  => '',
            'label' => '',
            'url'   => '',
            'image' => '',
            'html'  => '',
            'content_id' => 0,
        ]
    );

    $type  = sanitize_key( $block['type'] );
    $label = esc_html( $block['label'] );
    $url   = esc_url( $block['url'] );
    $image = esc_url( $block['image'] );

    switch ( $type ) {
        case 'cta':
            return '<a href="' . ( $url ?: '#' ) . '" class="wu-aic-block-cta" target="_blank" rel="noopener noreferrer">' . ( $label ?: '立刻詢問' ) . '</a>';

        case 'link':
            return '<a href="' . ( $url ?: '#' ) . '" class="wu-aic-block-link" target="_blank" rel="noopener noreferrer">' . $label . '</a>';

        case 'image_link':
            $img_html = $image ? '<img src="' . $image . '" alt="' . esc_attr( wp_strip_all_tags( $block['label'] ) ) . '" loading="lazy">' : '';
            return '<a href="' . ( $url ?: '#' ) . '" class="wu-aic-block-image_link" target="_blank" rel="noopener noreferrer">' . $img_html . '<span>' . $label . '</span></a>';

        case 'heading':
            return '<div class="wu-aic-block-heading">' . $label . '</div>';

        case 'html':
            return '<div class="wu-aic-block-html">' . wp_kses_post( $block['html'] ) . '</div>';

        case 'post':
            if ( ! empty( $block['content_id'] ) ) {
                return wu_aic_render_content_card( absint( $block['content_id'] ), 'post', $block['label'], $block['image'] );
            }
            if ( $url ) {
                return '<a href="' . $url . '" class="wu-aic-block-link" target="_blank" rel="noopener noreferrer">' . ( $label ?: '查看文章' ) . '</a>';
            }
            return wu_aic_render_latest_item( 'post', $label );

        case 'latest_post':
            return wu_aic_render_latest_item( 'post', $label );

        case 'page':
            if ( ! empty( $block['content_id'] ) ) {
                return wu_aic_render_content_card( absint( $block['content_id'] ), 'page', $block['label'], $block['image'] );
            }
            if ( $url ) {
                return '<a href="' . $url . '" class="wu-aic-block-link" target="_blank" rel="noopener noreferrer">' . ( $label ?: '查看頁面' ) . '</a>';
            }
            return '';

        case 'product':
            if ( ! empty( $block['content_id'] ) ) {
                return wu_aic_render_content_card( absint( $block['content_id'] ), 'product', $block['label'], $block['image'] );
            }
            if ( $url ) {
                return '<a href="' . $url . '" class="wu-aic-block-link" target="_blank" rel="noopener noreferrer">' . ( $label ?: '查看商品' ) . '</a>';
            }
            return wu_aic_render_latest_item( 'product', $label );

        case 'social':
            return '<div class="wu-aic-block-social"><a href="' . ( $url ?: '#' ) . '" target="_blank" rel="noopener noreferrer">' . ( $label ?: '社群連結' ) . '</a></div>';
    }

    return '';
}

function wu_aic_render_content_card( $content_id, $post_type, $custom_label = '', $custom_image = '' ) {
    $item = get_post( $content_id );
    if ( ! $item || $item->post_type !== $post_type || $item->post_status !== 'publish' ) {
        return '';
    }

    $url = get_permalink( $item );
    if ( ! $url ) {
        return '';
    }

    $title = $custom_label ?: get_the_title( $item );
    $image = $custom_image ? esc_url_raw( $custom_image ) : get_the_post_thumbnail_url( $item, 'medium' );
    $price = '';
    if ( $post_type === 'product' && function_exists( 'wc_get_product' ) ) {
        $product = wc_get_product( $item->ID );
        if ( $product ) {
            $price = $product->get_price_html();
        }
    }

    $output = '<a class="wu-aic-content-card" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">';
    if ( $image ) {
        $output .= '<img src="' . esc_url( $image ) . '" alt="" loading="lazy">';
    }
    $output .= '<span class="wu-aic-content-card-copy"><span class="wu-aic-content-card-title">' . esc_html( $title ) . '</span>';
    if ( $price ) {
        $output .= '<span class="wu-aic-content-card-meta">' . wp_kses_post( $price ) . '</span>';
    } else {
        $output .= '<span class="wu-aic-content-card-meta">' . ( $post_type === 'page' ? '精選頁面' : '精選文章' ) . '</span>';
    }
    $output .= '</span></a>';

    return $output;
}

function wu_aic_render_latest_item( $post_type, $custom_label = '' ) {
    if ( $post_type === 'product' && ! post_type_exists( 'product' ) ) {
        return '';
    }

    $items = get_posts(
        [
            'post_type'           => $post_type,
            'post_status'         => 'publish',
            'posts_per_page'      => 1,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ]
    );

    if ( empty( $items ) ) {
        return '';
    }

    return wu_aic_render_content_card( $items[0]->ID, $post_type, $custom_label );
}

/* ======================================================
 * 5. Shortcode
 * ====================================================== */

function wu_aic_shortcode( $atts = [] ) {
    $atts = shortcode_atts( [ 'mode' => 'card' ], $atts, 'wu_ai_card' );
    $opt  = wu_aic_get_option();

    if ( empty( $opt['enabled'] ) ) {
        return current_user_can( 'manage_options' )
            ? '<div class="wu-aic-disabled-note">WU AI Card 目前已停用，請至後台「AI 名片設定」啟用。</div>'
            : '';
    }

    static $instance = 0;
    $instance++;

    $instance_id = 'wu-aic-' . $instance . '-' . wp_rand( 1000, 9999 );
    $name        = esc_html( $opt['name'] );
    $title       = esc_html( $opt['title'] );
    $avatar      = esc_url( $opt['avatar'] );
    $bio         = esc_html( $opt['bio'] );
    $card_url    = home_url( '/' . ( $opt['slug'] ?: 'card' ) . '/' );
    $qr_box_id   = $instance_id . '-qr';

    if ( $avatar ) {
        $avatar_html = '<img src="' . $avatar . '" class="wu-aic-avatar" alt="' . esc_attr( wp_strip_all_tags( $opt['name'] ) ) . '">';
    } else {
        $avatar_html = '<div class="wu-aic-avatar" aria-hidden="true" style="font-weight:700;font-size:22px;background:#fff;color:#333;">AI</div>';
    }

    $blocks_html = '';
    foreach ( $opt['blocks'] as $block ) {
        $blocks_html .= wu_aic_render_block( $block );
    }

    $copy_lines = [];
    foreach ( $opt['blocks'] as $block ) {
        if ( ! is_array( $block ) ) {
            continue;
        }
        $block_url = ! empty( $block['url'] ) ? esc_url_raw( $block['url'] ) : '';
        if ( ! empty( $block['content_id'] ) ) {
            $content_id = absint( $block['content_id'] );
            $block_url  = get_permalink( $content_id ) ?: $block_url;
            $block_label = ! empty( $block['label'] ) ? $block['label'] : get_the_title( $content_id );
        } else {
            $block_label = $block['label'] ?? '';
        }
        if ( $block_label ) {
            $copy_lines[] = '- ' . wp_strip_all_tags( $block_label ) . ( $block_url ? '：' . $block_url : '' );
        }
    }
    $copy_text  = "請扮演「" . wp_strip_all_tags( $opt['name'] ) . "」（" . wp_strip_all_tags( $opt['title'] ) . "），以繁體中文、親切清楚的方式協助訪客。\n";
    $copy_text .= "請只根據以下品牌資料與訪客提供的資訊回答；不要臆測價格、政策或服務內容。資料不足時，坦白說明並引導訪客聯絡客服。\n\n";
    $copy_text .= "品牌簡介：\n" . wp_strip_all_tags( $opt['bio'] ) . "\n\n";
    $copy_text .= "名片與參考連結：\n" . implode( "\n", $copy_lines ) . "\n- AI 名片：" . esc_url_raw( $card_url );
    if ( ! empty( $opt['support_url'] ) ) {
        $copy_text .= "\n- 聯絡客服：" . esc_url_raw( $opt['support_url'] );
    }

    ob_start();
    ?>
    <div class="wu-aic-container" id="<?php echo esc_attr( $instance_id ); ?>" data-wu-aic-mode="<?php echo esc_attr( $atts['mode'] ); ?>">
        <div class="wu-aic-header"></div>
        <div class="wu-aic-body">
            <?php echo $avatar_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <h3 class="wu-aic-name"><?php echo $name; ?></h3>
            <p class="wu-aic-title"><?php echo $title; ?></p>
            <div class="wu-aic-bio"><?php echo nl2br( $bio ); ?></div>

            <?php if ( $atts['mode'] === 'floating' ) : ?>
                <div class="wu-aic-search-wrap">
                    <input type="search" class="wu-aic-search-input" placeholder="搜尋文章、商品或服務…" aria-label="搜尋名片內容">
                    <small>搜尋此名片已設定的文章、商品與服務</small>
                    <p class="wu-aic-search-empty" role="status" hidden>沒有符合的內容，請試試其他關鍵字。</p>
                </div>
            <?php endif; ?>

            <?php if ( $blocks_html ) : ?>
                <div class="wu-aic-blocks"><?php echo $blocks_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
            <?php endif; ?>

            <?php if ( ! empty( $opt['support_url'] ) ) : ?>
                <a class="wu-aic-contact-link" href="<?php echo esc_url( $opt['support_url'] ); ?>" target="_blank" rel="noopener noreferrer">點此聯絡客服</a>
            <?php endif; ?>

            <?php if ( $opt['enable_send_ai'] === '1' || $opt['enable_qrcode'] === '1' ) : ?>
                <div class="wu-aic-tools">
                    <?php if ( $opt['enable_send_ai'] === '1' ) : ?>
                        <button
                            type="button"
                            class="wu-aic-tool-btn"
                            data-wu-aic-action="copy-ai"
                            data-copy="<?php echo esc_attr( wp_json_encode( $copy_text ) ); ?>"
                        >📋 複製 AI 指令</button>
                    <?php endif; ?>

                    <?php if ( $opt['enable_qrcode'] === '1' ) : ?>
                        <button
                            type="button"
                            class="wu-aic-tool-btn"
                            data-wu-aic-action="toggle-qr"
                            data-url="<?php echo esc_url( $card_url ); ?>"
                            aria-controls="<?php echo esc_attr( $qr_box_id ); ?>"
                            aria-expanded="false"
                        >▦ QR Code</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( $opt['enable_qrcode'] === '1' ) : ?>
                <div class="wu-aic-qrcode-box" id="<?php echo esc_attr( $qr_box_id ); ?>" hidden>
                    <canvas aria-label="AI 名片 QR Code"></canvas>
                    <p class="wu-aic-qr-message" role="status" hidden></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'wu_ai_card', 'wu_aic_shortcode' );

/* ======================================================
 * 6. 全站浮動按鈕
 * ====================================================== */

function wu_aic_should_show_floating() {
    if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() ) {
        return false;
    }

    $opt = wu_aic_get_option();

    if ( empty( $opt['enabled'] ) || empty( $opt['enable_floating'] ) ) {
        return false;
    }

    if ( wu_aic_is_card_page() ) {
        return false;
    }

    return true;
}

/**
 * 使用 wp_body_open + wp_footer 雙 hook。
 * static guard 確保正常佈景不會重複輸出；若佈景沒有 footer 仍有 body_open fallback。
 */
function wu_aic_render_floating() {
    static $rendered = false;

    if ( $rendered || ! wu_aic_should_show_floating() ) {
        return;
    }

    $rendered = true;

    $popup_id = 'wu-aic-floating-popup';
    ?>
    <div class="wu-aic-backdrop" aria-hidden="true"></div>
    <div class="wu-aic-floating-popup" id="<?php echo esc_attr( $popup_id ); ?>" role="dialog" aria-label="AI 名片與內容搜尋" aria-modal="true">
        <?php echo do_shortcode( '[wu_ai_card mode="floating"]' ); ?>
    </div>
    <button
        type="button"
        class="wu-aic-floating-btn"
        data-wu-aic-action="toggle-floating"
        aria-controls="<?php echo esc_attr( $popup_id ); ?>"
        aria-expanded="false"
        aria-label="開啟 AI 名片"
    ><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 11.5a8 8 0 0 1-8 8 8.1 8.1 0 0 1-3.7-.9L4 20l1.4-4.4a8 8 0 1 1 15.1-4.1Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 11.5h.01M12 11.5h.01M15 11.5h.01" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg></button>
    <?php
}
add_action( 'wp_body_open', 'wu_aic_render_floating', 99 );
add_action( 'wp_footer', 'wu_aic_render_floating', 5 );
