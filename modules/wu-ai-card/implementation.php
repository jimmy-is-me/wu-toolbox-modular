<?php
/**
 * Plugin Name: WU AI Card
 * Description: 自訂 AI 名片頁面：基本資料、9 種內容區塊、8 組配色主題、全站浮動按鈕、傳送給 AI 文字、QR Code 分享。
 * Version: 1.2.0
 * Author: WU
 * Plugin URI: https://wumetax.com/
 * Text Domain: wu-ai-card
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

defined( 'WU_AIC_VERSION' ) || define( 'WU_AIC_VERSION', '1.2.0' );
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
        'theme'           => 'blue',
        'dark_mode'       => '0',
        'enable_floating' => '1',
        'enable_send_ai'  => '1',
        'enable_qrcode'   => '1',
        'blocks'          => [
            [
                'type'  => 'cta',
                'label' => '立即諮詢',
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

/* 內容區塊 9 種類型 */
function wu_aic_block_types() {
    return [
        'cta'         => 'CTA 按鈕',
        'link'        => '一般連結按鈕',
        'image_link'  => '圖文連結',
        'post'        => '精選文章',
        'product'     => '精選商品',
        'latest_post' => '最新文章',
        'heading'     => '標題文字',
        'html'        => '自訂 HTML',
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
<?php wp_print_scripts( [ 'wu-aic-script', 'wu-aic-qrcode' ] ); ?>
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

function wu_aic_sanitize( $input ) {
    $input = is_array( $input ) ? $input : [];
    $out   = wu_aic_get_option();

    $out['enabled']         = isset( $input['enabled'] ) ? '1' : '0';
    $out['slug']            = ! empty( $input['slug'] ) ? sanitize_title( $input['slug'] ) : 'card';
    $out['name']            = isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : $out['name'];
    $out['title']           = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : $out['title'];
    $out['avatar']          = isset( $input['avatar'] ) ? esc_url_raw( $input['avatar'] ) : '';
    $out['bio']             = isset( $input['bio'] ) ? sanitize_textarea_field( $input['bio'] ) : $out['bio'];
    $out['theme']           = isset( $input['theme'] ) && array_key_exists( $input['theme'], wu_aic_color_themes() ) ? sanitize_key( $input['theme'] ) : 'blue';
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
            ];
        }
    }

    $out['blocks'] = ! empty( $blocks ) ? $blocks : wu_aic_get_defaults()['blocks'];

    return $out;
}

function wu_aic_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    wp_enqueue_media();

    $opt      = wu_aic_get_option();
    $themes   = wu_aic_color_themes();
    $types    = wu_aic_block_types();
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
            </table>

            <h2 class="title">外觀主題</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">配色主題</th>
                    <td>
                        <?php foreach ( $themes as $key => $colors ) : ?>
                            <label style="display:inline-block;margin:4px 14px 4px 0;">
                                <input type="radio" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[theme]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $opt['theme'], $key ); ?>>
                                <span style="display:inline-block;width:16px;height:16px;border-radius:50%;background:linear-gradient(135deg, <?php echo esc_attr( $colors[0] ); ?>, <?php echo esc_attr( $colors[1] ); ?>);vertical-align:middle;"></span>
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
                    <td><label><input type="checkbox" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[enable_send_ai]" value="1" <?php checked( $opt['enable_send_ai'], '1' ); ?>> 顯示一鍵複製結構化品牌資訊</label></td>
                </tr>
                <tr>
                    <th scope="row">QR Code 分享</th>
                    <td><label><input type="checkbox" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[enable_qrcode]" value="1" <?php checked( $opt['enable_qrcode'], '1' ); ?>> 顯示 QR Code 按鈕</label></td>
                </tr>
            </table>

            <h2 class="title">內容區塊</h2>
            <p class="description">最多 20 個，可拖曳排序。圖文連結現在有獨立圖片欄位。</p>

            <div style="overflow-x:auto;">
                <table class="widefat striped" id="wu-aic-blocks-table" style="min-width:1100px;">
                    <thead>
                        <tr>
                            <th style="width:56px;">排序</th>
                            <th style="width:150px;">類型</th>
                            <th>標籤 / 標題</th>
                            <th>連結網址</th>
                            <th>圖片網址</th>
                            <th>自訂 HTML</th>
                            <th style="width:70px;">刪除</th>
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
            ],
            $types
        );
        ?>
    </template>

    <style>
        #wu-aic-blocks-body tr.wu-aic-dragging { opacity: .45; }
        #wu-aic-blocks-body .wu-aic-drag { cursor: grab; font-size: 18px; text-align: center; user-select: none; }
        #wu-aic-blocks-body .wu-aic-drag:active { cursor: grabbing; }
        .wu-aic-admin-wrap .wu-aic-image-wrap { display:flex; gap:6px; align-items:center; }
    </style>

    <script>
    (function () {
        var body = document.getElementById('wu-aic-blocks-body');
        var addBtn = document.getElementById('wu-aic-add-block');
        var template = document.getElementById('wu-aic-block-template');

        if (!body || !addBtn || !template) return;

        function bindRow(row) {
            row.setAttribute('draggable', 'true');
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
                var input = mediaBtn.closest('.wu-aic-image-wrap').querySelector('input');
                var frame = wp.media({
                    title: '選擇圖片',
                    button: { text: '使用這張圖片' },
                    multiple: false
                });
                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    input.value = attachment.url || '';
                });
                frame.open();
            }
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
        ]
    );
    ?>
    <tr class="wu-aic-block-row">
        <td class="wu-aic-drag" title="拖曳排序">☰</td>
        <td>
            <select name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[block_type][]">
                <?php foreach ( $types as $tkey => $tlabel ) : ?>
                    <option value="<?php echo esc_attr( $tkey ); ?>" <?php selected( $block['type'], $tkey ); ?>><?php echo esc_html( $tlabel ); ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input type="text" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[block_label][]" value="<?php echo esc_attr( $block['label'] ); ?>" class="regular-text"></td>
        <td><input type="url" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[block_url][]" value="<?php echo esc_attr( $block['url'] ); ?>" class="regular-text" placeholder="https://..."></td>
        <td>
            <div class="wu-aic-image-wrap">
                <input type="url" name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[block_image][]" value="<?php echo esc_attr( $block['image'] ); ?>" class="regular-text" placeholder="https://...">
                <button type="button" class="button wu-aic-row-media-pick">選圖</button>
            </div>
        </td>
        <td><textarea name="<?php echo esc_attr( WU_AIC_OPTION ); ?>[block_html][]" rows="2" class="large-text code"><?php echo esc_textarea( $block['html'] ); ?></textarea></td>
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
            border-radius:18px;
            box-shadow:0 14px 40px rgba(0,0,0,.14);
            overflow:hidden;
            font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Noto Sans TC',Arial,sans-serif;
            margin:20px auto;
            border:1px solid {$border};
        }
        .wu-aic-header{
            background:linear-gradient(135deg,{$c1} 0%,{$c2} 100%);
            height:92px;
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
            margin:-45px auto 12px;
            box-shadow:0 4px 14px rgba(0,0,0,.16);
            display:flex;
            align-items:center;
            justify-content:center;
        }
        .wu-aic-name{font-size:1.35rem;font-weight:700;margin:0 0 4px;color:{$text};}
        .wu-aic-title{font-size:.9rem;color:{$subtext};margin:0 0 16px;font-weight:500;}
        .wu-aic-bio{
            font-size:.92rem;color:{$text};line-height:1.7;background:{$bio_bg};
            padding:14px;border-radius:10px;text-align:left;margin-bottom:18px;
        }
        .wu-aic-blocks{display:flex;flex-direction:column;gap:10px;text-align:left;}
        .wu-aic-block-cta,.wu-aic-block-link{
            display:block;text-decoration:none;padding:12px 16px;border-radius:10px;
            font-weight:600;text-align:center;transition:transform .2s ease,box-shadow .2s ease;
        }
        .wu-aic-block-cta{background:linear-gradient(135deg,{$c1},{$c2});color:#fff!important;}
        .wu-aic-block-link{background:{$bio_bg};color:{$text}!important;border:1px solid {$border};}
        .wu-aic-block-cta:hover,.wu-aic-block-link:hover{transform:translateY(-2px);box-shadow:0 5px 14px rgba(0,0,0,.08);}
        .wu-aic-block-image_link{
            display:flex;align-items:center;gap:12px;text-decoration:none;color:{$text}!important;
            background:{$bio_bg};border:1px solid {$border};border-radius:10px;padding:9px;
        }
        .wu-aic-block-image_link img{width:52px;height:52px;border-radius:8px;object-fit:cover;flex:0 0 52px;}
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

        .wu-aic-floating-btn{
            position:fixed!important;bottom:24px!important;left:24px!important;right:auto!important;
            width:60px!important;height:60px!important;
            background:linear-gradient(135deg,{$c1} 0%,{$c2} 100%)!important;
            color:#fff!important;border:0!important;border-radius:50%!important;
            display:flex!important;align-items:center!important;justify-content:center!important;
            box-shadow:0 8px 24px rgba(0,0,0,.25)!important;cursor:pointer!important;
            z-index:2147483001!important;font-size:25px!important;line-height:1!important;
            transition:transform .2s ease!important;
        }
        .wu-aic-floating-btn:hover{transform:scale(1.06)!important;}
        .wu-aic-floating-popup{
            position:fixed!important;bottom:96px!important;left:24px!important;right:auto!important;
            z-index:2147483000!important;display:none!important;opacity:0!important;
            transform:translateY(12px)!important;transition:opacity .22s ease,transform .22s ease!important;
            width:min(380px,calc(100vw - 32px))!important;max-height:calc(100vh - 130px)!important;
            overflow:auto!important;overscroll-behavior:contain!important;
        }
        .wu-aic-floating-popup.wu-active{display:block!important;opacity:1!important;transform:translateY(0)!important;}
        .wu-aic-floating-popup .wu-aic-container{margin:0!important;max-width:none!important;}

        @media (max-width:480px){
            .wu-aic-page-body{padding:22px 12px;}
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

    wp_register_script( 'wu-aic-script', false, [], WU_AIC_VERSION, true );
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
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    btn.textContent = open ? '✕' : '💬';
                    return;
                }

                if(action === 'copy-ai'){
                    var payload = btn.getAttribute('data-copy') || '';
                    try { payload = JSON.parse(payload); } catch(err) {}
                    copyText(payload).then(function(){
                        var old = btn.textContent;
                        btn.textContent = '✓ 已複製';
                        setTimeout(function(){ btn.textContent = old; }, 1400);
                    }).catch(function(){
                        alert('瀏覽器無法自動複製，請手動複製名片資訊。');
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

                        if(window.QRCode && canvas){
                            QRCode.toCanvas(canvas, url, {width:160, margin:1}, function(error){
                                if(!error) box.dataset.rendered = '1';
                            });
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
                    var id = item.id;
                    var controller = document.querySelector('.wu-aic-floating-btn[aria-controls=\"' + id + '\"]');
                    if(controller){
                        controller.setAttribute('aria-expanded','false');
                        controller.textContent = '💬';
                    }
                });
            });
        });
    })();
    ";
    wp_add_inline_script( 'wu-aic-script', $js );

    if ( $opt['enable_qrcode'] === '1' ) {
        wp_enqueue_script(
            'wu-aic-qrcode',
            'https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js',
            [],
            '1.5.3',
            true
        );
    }
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
        ]
    );

    $type  = sanitize_key( $block['type'] );
    $label = esc_html( $block['label'] );
    $url   = esc_url( $block['url'] );
    $image = esc_url( $block['image'] );

    switch ( $type ) {
        case 'cta':
            return '<a href="' . ( $url ?: '#' ) . '" class="wu-aic-block-cta" target="_blank" rel="noopener noreferrer">' . $label . '</a>';

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
            if ( $url ) {
                return '<a href="' . $url . '" class="wu-aic-block-link" target="_blank" rel="noopener noreferrer">' . ( $label ?: '查看文章' ) . '</a>';
            }
            return wu_aic_render_latest_item( 'post', $label );

        case 'latest_post':
            return wu_aic_render_latest_item( 'post', $label );

        case 'product':
            if ( $url ) {
                return '<a href="' . $url . '" class="wu-aic-block-link" target="_blank" rel="noopener noreferrer">' . ( $label ?: '查看商品' ) . '</a>';
            }
            return wu_aic_render_latest_item( 'product', $label );

        case 'social':
            return '<div class="wu-aic-block-social"><a href="' . ( $url ?: '#' ) . '" target="_blank" rel="noopener noreferrer">' . ( $label ?: '社群連結' ) . '</a></div>';
    }

    return '';
}

function wu_aic_render_latest_item( $post_type, $custom_label = '' ) {
    if ( $post_type === 'product' && ! post_type_exists( 'product' ) ) {
        return '';
    }

    $q = new WP_Query(
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

    if ( ! $q->have_posts() ) {
        return '';
    }

    $q->the_post();

    $label = $custom_label ?: get_the_title();
    $icon  = $post_type === 'product' ? '🛒 ' : '📄 ';
    $out   = '<a href="' . esc_url( get_permalink() ) . '" class="wu-aic-block-link" target="_blank" rel="noopener noreferrer">' . $icon . esc_html( $label ) . '</a>';

    wp_reset_postdata();

    return $out;
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

    $copy_text = "品牌名稱：" . wp_strip_all_tags( $opt['name'] ) . "\n";
    $copy_text .= "角色：" . wp_strip_all_tags( $opt['title'] ) . "\n";
    $copy_text .= "簡介：" . wp_strip_all_tags( $opt['bio'] ) . "\n";
    $copy_text .= "名片網址：" . esc_url_raw( $card_url );

    ob_start();
    ?>
    <div class="wu-aic-container" id="<?php echo esc_attr( $instance_id ); ?>" data-wu-aic-mode="<?php echo esc_attr( $atts['mode'] ); ?>">
        <div class="wu-aic-header"></div>
        <div class="wu-aic-body">
            <?php echo $avatar_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <h3 class="wu-aic-name"><?php echo $name; ?></h3>
            <p class="wu-aic-title"><?php echo $title; ?></p>
            <div class="wu-aic-bio"><?php echo nl2br( $bio ); ?></div>

            <?php if ( $blocks_html ) : ?>
                <div class="wu-aic-blocks"><?php echo $blocks_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
            <?php endif; ?>

            <?php if ( $opt['enable_send_ai'] === '1' || $opt['enable_qrcode'] === '1' ) : ?>
                <div class="wu-aic-tools">
                    <?php if ( $opt['enable_send_ai'] === '1' ) : ?>
                        <button
                            type="button"
                            class="wu-aic-tool-btn"
                            data-wu-aic-action="copy-ai"
                            data-copy="<?php echo esc_attr( wp_json_encode( $copy_text ) ); ?>"
                        >📋 傳給 AI</button>
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
    <div class="wu-aic-floating-popup" id="<?php echo esc_attr( $popup_id ); ?>" aria-live="polite">
        <?php echo do_shortcode( '[wu_ai_card mode="floating"]' ); ?>
    </div>
    <button
        type="button"
        class="wu-aic-floating-btn"
        data-wu-aic-action="toggle-floating"
        aria-controls="<?php echo esc_attr( $popup_id ); ?>"
        aria-expanded="false"
        aria-label="開啟 AI 名片"
    >💬</button>
    <?php
}
add_action( 'wp_body_open', 'wu_aic_render_floating', 99 );
add_action( 'wp_footer', 'wu_aic_render_floating', 5 );
