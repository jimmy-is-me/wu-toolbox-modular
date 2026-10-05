<?php
/**
 * ============================================================
 * WUMETAX Map Manager v2.2
 * ============================================================
 *
 * 功能：
 * - 後台建立多筆 Google Maps 地點
 * - Shortcode
 * - Gutenberg 原生 WUMETAX 地圖區塊
 * - Elementor / Greenshift 可直接放 Shortcode
 * - 不需 Google Maps API Key
 * - 不需 Google Cloud Billing
 * - 寬度 / 最大寬度
 * - 左 / 中 / 右對齊
 * - 可選真正 Full Width
 * - 桌機 / 手機高度
 * - Google Maps iframe 自動填滿
 * - 深色 / 黑白 / 低彩度等效果
 * - 圓角 / 邊框 / 陰影
 * - 資訊卡
 * - Google Maps 導航
 * - Lazy Load
 * - 前台無自製 JavaScript
 *
 * Shortcode：
 *
 * [wumetax_map id="123"]
 *
 * 可覆寫：
 *
 * [wumetax_map id="123" height="600"]
 *
 * [wumetax_map id="123" max_width="1000px"]
 *
 * [wumetax_map id="123" align="left"]
 *
 * [wumetax_map id="123" full_width="1"]
 *
 * [wumetax_map id="123" map_style="dark"]
 *
 * ============================================================
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ============================================================
 * 工具函式
 * ============================================================
 */

function wutm_google_map_clamp($value, $min, $max) {

    $value = intval($value);

    return max(
        intval($min),
        min(intval($max), $value)
    );
}

/**
 * CSS 尺寸
 *
 * 支援：
 * 100%
 * 1290px
 * 80vw
 * 60rem
 * 50em
 */
function wutm_google_map_sanitize_size(
    $value,
    $default = '100%',
    $allow_none = false
) {

    $value = trim(
        sanitize_text_field($value)
    );

    if (
        $allow_none &&
        strtolower($value) === 'none'
    ) {
        return 'none';
    }

    if (
        preg_match(
            '/^\d+(?:\.\d+)?(?:px|%|rem|em|vw|vh)$/i',
            $value
        )
    ) {
        return $value;
    }

    return $default;
}

/**
 * CSS Filter 安全處理
 */
function wutm_google_map_sanitize_filter($value) {

    $value = sanitize_text_field($value);

    $value = str_replace(
        array(
            ';',
            '{',
            '}',
            '<',
            '>',
            '"',
            "'"
        ),
        '',
        $value
    );

    return trim($value);
}

/**
 * Embed URL
 *
 * 可貼：
 * - URL
 * - 完整 iframe
 */
function wutm_google_map_parse_embed_url($value) {

    $value = trim(
        wp_unslash($value)
    );

    if (!$value) {
        return '';
    }

    if (
        stripos(
            $value,
            '<iframe'
        ) !== false
    ) {

        if (
            preg_match(
                '/src=["\']([^"\']+)["\']/i',
                $value,
                $matches
            )
        ) {

            return esc_url_raw(
                html_entity_decode(
                    $matches[1],
                    ENT_QUOTES,
                    'UTF-8'
                )
            );
        }

        return '';
    }

    return esc_url_raw(
        html_entity_decode(
            $value,
            ENT_QUOTES,
            'UTF-8'
        )
    );
}

/**
 * ============================================================
 * 1. CPT
 * ============================================================
 */

add_action(
    'init',
    'wutm_google_map_register_post_type'
);

function wutm_google_map_register_post_type() {

    $labels = array(

        'name'               => '地圖管理',
        'singular_name'      => '地圖',
        'menu_name'          => '地圖管理',
        'name_admin_bar'     => '新增地圖',
        'add_new'            => '新增地圖',
        'add_new_item'       => '新增地圖',
        'edit_item'          => '編輯地圖',
        'new_item'           => '新地圖',
        'view_item'          => '查看地圖',
        'all_items'          => '所有地圖',
        'search_items'       => '搜尋地圖',
        'not_found'          => '尚未建立地圖',
        'not_found_in_trash' => '回收桶內沒有地圖',

    );

    register_post_type(
        'wumetax_map',
        array(

            'labels' => $labels,

            'public' => false,

            'publicly_queryable' => false,

            'show_ui' => true,

            'show_in_menu' => 'wu-toolbox-modular',

            'show_in_rest' => false,

            'menu_icon' =>
                'dashicons-location-alt',

            'menu_position' => 58,

            'supports' =>
                array(
                    'title'
                ),

            'capability_type' =>
                'post',

            'map_meta_cap' =>
                true,

            'rewrite' =>
                false,

            'query_var' =>
                false,

        )
    );
}

/**
 * ============================================================
 * 2. Meta Box
 * ============================================================
 */

add_action(
    'add_meta_boxes',
    'wutm_google_map_add_meta_boxes'
);

function wutm_google_map_add_meta_boxes() {

    add_meta_box(
        'wumetax_map_settings',
        '地圖設定',
        'wutm_google_map_settings_callback',
        'wumetax_map',
        'normal',
        'high'
    );

    add_meta_box(
        'wutm_google_map_shortcode',
        '使用方式',
        'wutm_google_map_shortcode_box',
        'wumetax_map',
        'side',
        'high'
    );
}

/**
 * ============================================================
 * 3. 後台設定
 * ============================================================
 */

function wutm_google_map_settings_callback($post) {

    wp_nonce_field(
        'wutm_google_map_save',
        'wumetax_map_nonce'
    );

    $address =
        get_post_meta(
            $post->ID,
            '_wm_address',
            true
        );

    $embed_url =
        get_post_meta(
            $post->ID,
            '_wm_embed_url',
            true
        );

    $zoom =
        get_post_meta(
            $post->ID,
            '_wm_zoom',
            true
        );

    $width =
        get_post_meta(
            $post->ID,
            '_wm_width',
            true
        );

    $max_width =
        get_post_meta(
            $post->ID,
            '_wm_max_width',
            true
        );

    $alignment =
        get_post_meta(
            $post->ID,
            '_wm_alignment',
            true
        );

    $full_width =
        get_post_meta(
            $post->ID,
            '_wm_full_width',
            true
        );

    $height =
        get_post_meta(
            $post->ID,
            '_wm_height',
            true
        );

    $mobile_height =
        get_post_meta(
            $post->ID,
            '_wm_mobile_height',
            true
        );

    $map_style =
        get_post_meta(
            $post->ID,
            '_wm_style',
            true
        );

    $custom_filter =
        get_post_meta(
            $post->ID,
            '_wm_custom_filter',
            true
        );

    $radius =
        get_post_meta(
            $post->ID,
            '_wm_radius',
            true
        );

    $border_width =
        get_post_meta(
            $post->ID,
            '_wm_border_width',
            true
        );

    $border_color =
        get_post_meta(
            $post->ID,
            '_wm_border_color',
            true
        );

    $shadow =
        get_post_meta(
            $post->ID,
            '_wm_shadow',
            true
        );

    $show_info =
        get_post_meta(
            $post->ID,
            '_wm_show_info',
            true
        );

    $info_label =
        get_post_meta(
            $post->ID,
            '_wm_info_label',
            true
        );

    $info_title =
        get_post_meta(
            $post->ID,
            '_wm_info_title',
            true
        );

    $info_address =
        get_post_meta(
            $post->ID,
            '_wm_info_address',
            true
        );

    $button_text =
        get_post_meta(
            $post->ID,
            '_wm_button_text',
            true
        );

    $allow_fullscreen =
        get_post_meta(
            $post->ID,
            '_wm_allow_fullscreen',
            true
        );

    $loading =
        get_post_meta(
            $post->ID,
            '_wm_loading',
            true
        );

    /*
     * Defaults
     */

    if ($zoom === '') {
        $zoom = 15;
    }

    if (!$width) {
        $width = '100%';
    }

    /*
     * 這版最重要：
     * 預設不再跟整個 Full Width 頁面跑。
     */
    if (!$max_width) {
        $max_width = '1290px';
    }

    if (!$alignment) {
        $alignment = 'center';
    }

    if ($full_width === '') {
        $full_width = '0';
    }

    if (!$height) {
        $height = 500;
    }

    if (!$mobile_height) {
        $mobile_height = 380;
    }

    if (!$map_style) {
        $map_style = 'normal';
    }

    if ($radius === '') {
        $radius = 0;
    }

    if ($border_width === '') {
        $border_width = 0;
    }

    if (!$border_color) {
        $border_color = '#dddddd';
    }

    if (!$shadow) {
        $shadow = 'none';
    }

    if ($show_info === '') {
        $show_info = '0';
    }

    if (!$info_label) {
        $info_label = 'LOCATION';
    }

    if (!$button_text) {
        $button_text = 'Google Maps 導航';
    }

    if ($allow_fullscreen === '') {
        $allow_fullscreen = '1';
    }

    if (!$loading) {
        $loading = 'lazy';
    }

    ?>

<style>

.wumetax-map-admin,
.wumetax-map-admin *,
.wumetax-map-admin *::before,
.wumetax-map-admin *::after {
    box-sizing: border-box;
}

.wumetax-map-admin {
    max-width: 1050px;
}

.wumetax-map-admin-section {

    margin-bottom: 20px;

    padding: 22px;

    background: #fff;

    border:
        1px solid #dcdcde;

    border-radius: 7px;
}

.wumetax-map-admin-section h3 {

    margin:
        0 0 22px;

    padding-bottom: 12px;

    border-bottom:
        1px solid #eee;

    font-size: 16px;
}

.wumetax-map-row {

    display: grid;

    grid-template-columns:
        210px minmax(0,1fr);

    gap: 20px;

    margin-bottom: 20px;

    align-items: start;
}

.wumetax-map-row:last-child {
    margin-bottom: 0;
}

.wumetax-map-row >
label:first-child {

    padding-top: 6px;

    font-weight: 600;
}

.wumetax-map-row input[type="text"],
.wumetax-map-row input[type="number"],
.wumetax-map-row textarea,
.wumetax-map-row select {

    width: 100%;

    max-width: 650px;
}

.wumetax-map-help {

    display: block;

    max-width: 650px;

    margin-top: 7px;

    color: #646970;

    font-size: 12px;

    line-height: 1.65;
}

.wumetax-map-note {

    margin-bottom: 20px;

    padding: 15px 17px;

    background: #f0f6fc;

    border-left:
        4px solid #2271b1;

    line-height: 1.7;
}

.wumetax-map-inline {

    display: flex;

    align-items: center;

    gap: 8px;

    flex-wrap: wrap;
}

@media(max-width:782px) {

    .wumetax-map-row {

        grid-template-columns:
            1fr;

        gap: 6px;
    }

}

</style>

<div class="wumetax-map-admin">

    <div class="wumetax-map-note">

        <strong>預設顯示方式：</strong>

        寬度 100% ＋ 最大寬度 1290px ＋ 置中。

        <br>

        即使 Elementor /
        Blocksy 頁面本身為 Full Width，
        地圖也不會自動變成整個螢幕寬。

        <br>

        如果真的需要滿版，
        再開啟下方的
        「強制滿版顯示」。

    </div>

    <!-- =================================
         地點
    ================================== -->

    <div class="wumetax-map-admin-section">

        <h3>地點設定</h3>

        <div class="wumetax-map-row">

            <label>
                地址 / 地點名稱
            </label>

            <div>

                <input

                    type="text"

                    name="wm_address"

                    value="<?php
                    echo esc_attr($address);
                    ?>"

                    placeholder="例如：高雄市楠梓區大學二十一路32號"
                >

                <span class="wumetax-map-help">

                    一般只需要輸入完整地址。

                    此地址也會用於
                    Google Maps 導航。

                </span>

            </div>

        </div>

        <div class="wumetax-map-row">

            <label>
                Google Maps Embed
            </label>

            <div>

                <textarea

                    name="wm_embed_url"

                    rows="4"

                    placeholder="選填，可貼完整 iframe 或 iframe src 網址"

                ><?php
                echo esc_textarea(
                    $embed_url
                );
                ?></textarea>

                <span class="wumetax-map-help">

                    一般可留空。

                    如果需要精準指定
                    Google 商家地標，

                    可至 Google Maps：

                    分享 →
                    嵌入地圖 →
                    複製 HTML，

                    再整段貼入。

                </span>

            </div>

        </div>

        <div class="wumetax-map-row">

            <label>
                Zoom
            </label>

            <div>

                <input

                    type="number"

                    name="wm_zoom"

                    value="<?php
                    echo esc_attr($zoom);
                    ?>"

                    min="1"

                    max="20"
                >

                <span class="wumetax-map-help">
                    建議一般公司地點使用 14～17。
                </span>

            </div>

        </div>

    </div>

    <!-- =================================
         尺寸
    ================================== -->

    <div class="wumetax-map-admin-section">

        <h3>尺寸與對齊</h3>

        <div class="wumetax-map-row">

            <label>
                寬度
            </label>

            <div>

                <input

                    type="text"

                    name="wm_width"

                    value="<?php
                    echo esc_attr($width);
                    ?>"

                    placeholder="100%"
                >

                <span class="wumetax-map-help">

                    預設 100%。

                    這表示使用目前可用寬度，
                    但仍會受到下面
                    「最大寬度」限制。

                </span>

            </div>

        </div>

        <div class="wumetax-map-row">

            <label>
                最大寬度
            </label>

            <div>

                <input

                    type="text"

                    name="wm_max_width"

                    value="<?php
                    echo esc_attr(
                        $max_width
                    );
                    ?>"

                    placeholder="1290px"
                >

                <span class="wumetax-map-help">

                    預設 1290px。

                    例如：

                    1290px、
                    1200px、
                    900px、
                    90%。

                    <br>

                    如果要完全不限制，
                    可輸入 none，
                    或直接開啟
                    「強制滿版」。

                </span>

            </div>

        </div>

        <div class="wumetax-map-row">

            <label>
                對齊方式
            </label>

            <div>

                <select
                    name="wm_alignment"
                >

                    <option
                        value="left"
                        <?php
                        selected(
                            $alignment,
                            'left'
                        );
                        ?>
                    >
                        靠左
                    </option>

                    <option
                        value="center"
                        <?php
                        selected(
                            $alignment,
                            'center'
                        );
                        ?>
                    >
                        置中
                    </option>

                    <option
                        value="right"
                        <?php
                        selected(
                            $alignment,
                            'right'
                        );
                        ?>
                    >
                        靠右
                    </option>

                </select>

            </div>

        </div>

        <div class="wumetax-map-row">

            <label>
                強制滿版
            </label>

            <div>

                <label>

                    <input

                        type="checkbox"

                        name="wm_full_width"

                        value="1"

                        <?php
                        checked(
                            $full_width,
                            '1'
                        );
                        ?>
                    >

                    忽略最大寬度，
                    地圖使用父層可用寬度 100%

                </label>

                <span class="wumetax-map-help">

                    一般網站聯絡頁建議不要勾。

                    只有需要整片滿版地圖時才使用。

                </span>

            </div>

        </div>

        <div class="wumetax-map-row">

            <label>
                桌機高度
            </label>

            <div class="wumetax-map-inline">

                <input

                    type="number"

                    name="wm_height"

                    value="<?php
                    echo esc_attr($height);
                    ?>"

                    min="150"

                    max="2000"

                    style="width:120px;"
                >

                px

            </div>

        </div>

        <div class="wumetax-map-row">

            <label>
                手機高度
            </label>

            <div class="wumetax-map-inline">

                <input

                    type="number"

                    name="wm_mobile_height"

                    value="<?php
                    echo esc_attr(
                        $mobile_height
                    );
                    ?>"

                    min="150"

                    max="1500"

                    style="width:120px;"
                >

                px

            </div>

        </div>

    </div>

    <!-- =================================
         外觀
    ================================== -->

    <div class="wumetax-map-admin-section">

        <h3>地圖外觀</h3>

        <div class="wumetax-map-row">

            <label>
                配色
            </label>

            <div>

                <select name="wm_style">

                    <option
                        value="normal"
                        <?php
                        selected(
                            $map_style,
                            'normal'
                        );
                        ?>
                    >
                        一般 Google Maps
                    </option>

                    <option
                        value="dark"
                        <?php
                        selected(
                            $map_style,
                            'dark'
                        );
                        ?>
                    >
                        深色
                    </option>

                    <option
                        value="dark_soft"
                        <?php
                        selected(
                            $map_style,
                            'dark_soft'
                        );
                        ?>
                    >
                        柔和深色
                    </option>

                    <option
                        value="grayscale"
                        <?php
                        selected(
                            $map_style,
                            'grayscale'
                        );
                        ?>
                    >
                        黑白
                    </option>

                    <option
                        value="muted"
                        <?php
                        selected(
                            $map_style,
                            'muted'
                        );
                        ?>
                    >
                        低彩度
                    </option>

                    <option
                        value="warm"
                        <?php
                        selected(
                            $map_style,
                            'warm'
                        );
                        ?>
                    >
                        暖色調
                    </option>

                    <option
                        value="custom"
                        <?php
                        selected(
                            $map_style,
                            'custom'
                        );
                        ?>
                    >
                        自訂 CSS Filter
                    </option>

                </select>

            </div>

        </div>

        <div class="wumetax-map-row">

            <label>
                自訂 Filter
            </label>

            <div>

                <input

                    type="text"

                    name="wm_custom_filter"

                    value="<?php
                    echo esc_attr(
                        $custom_filter
                    );
                    ?>"

                    placeholder="grayscale(1) brightness(.8) contrast(1.1)"
                >

                <span class="wumetax-map-help">

                    只有選擇
                    「自訂 CSS Filter」
                    時使用。

                </span>

            </div>

        </div>

        <div class="wumetax-map-row">

            <label>
                圓角
            </label>

            <div class="wumetax-map-inline">

                <input

                    type="number"

                    name="wm_radius"

                    value="<?php
                    echo esc_attr($radius);
                    ?>"

                    min="0"

                    max="100"

                    style="width:120px;"
                >

                px

            </div>

        </div>

        <div class="wumetax-map-row">

            <label>
                邊框
            </label>

            <div class="wumetax-map-inline">

                <input

                    type="number"

                    name="wm_border_width"

                    value="<?php
                    echo esc_attr(
                        $border_width
                    );
                    ?>"

                    min="0"

                    max="20"

                    style="width:90px;"
                >

                px

                <input

                    type="color"

                    name="wm_border_color"

                    value="<?php
                    echo esc_attr(
                        $border_color
                    );
                    ?>"
                >

            </div>

        </div>

        <div class="wumetax-map-row">

            <label>
                陰影
            </label>

            <div>

                <select
                    name="wm_shadow"
                >

                    <option
                        value="none"
                        <?php
                        selected(
                            $shadow,
                            'none'
                        );
                        ?>
                    >
                        無
                    </option>

                    <option
                        value="small"
                        <?php
                        selected(
                            $shadow,
                            'small'
                        );
                        ?>
                    >
                        輕微
                    </option>

                    <option
                        value="medium"
                        <?php
                        selected(
                            $shadow,
                            'medium'
                        );
                        ?>
                    >
                        中等
                    </option>

                    <option
                        value="large"
                        <?php
                        selected(
                            $shadow,
                            'large'
                        );
                        ?>
                    >
                        明顯
                    </option>

                </select>

            </div>

        </div>

    </div>

    <!-- =================================
         資訊卡
    ================================== -->

    <div class="wumetax-map-admin-section">

        <h3>地圖資訊卡</h3>

        <div class="wumetax-map-row">

            <label>
                顯示資訊卡
            </label>

            <div>

                <label>

                    <input

                        type="checkbox"

                        name="wm_show_info"

                        value="1"

                        <?php
                        checked(
                            $show_info,
                            '1'
                        );
                        ?>
                    >

                    顯示地點資訊卡

                </label>

            </div>

        </div>

        <div class="wumetax-map-row">

            <label>
                小標
            </label>

            <div>

                <input

                    type="text"

                    name="wm_info_label"

                    value="<?php
                    echo esc_attr(
                        $info_label
                    );
                    ?>"

                    placeholder="LOCATION"
                >

            </div>

        </div>

        <div class="wumetax-map-row">

            <label>
                標題
            </label>

            <div>

                <input

                    type="text"

                    name="wm_info_title"

                    value="<?php
                    echo esc_attr(
                        $info_title
                    );
                    ?>"

                    placeholder="WUMETAX"
                >

            </div>

        </div>

        <div class="wumetax-map-row">

            <label>
                地址 / 說明
            </label>

            <div>

                <textarea

                    name="wm_info_address"

                    rows="3"

                ><?php
                echo esc_textarea(
                    $info_address
                );
                ?></textarea>

            </div>

        </div>

        <div class="wumetax-map-row">

            <label>
                導航按鈕
            </label>

            <div>

                <input

                    type="text"

                    name="wm_button_text"

                    value="<?php
                    echo esc_attr(
                        $button_text
                    );
                    ?>"
                >

            </div>

        </div>

    </div>

    <!-- =================================
         效能
    ================================== -->

    <div class="wumetax-map-admin-section">

        <h3>載入與效能</h3>

        <div class="wumetax-map-row">

            <label>
                載入方式
            </label>

            <div>

                <select
                    name="wm_loading"
                >

                    <option
                        value="lazy"
                        <?php
                        selected(
                            $loading,
                            'lazy'
                        );
                        ?>
                    >
                        延遲載入 Lazy
                    </option>

                    <option
                        value="eager"
                        <?php
                        selected(
                            $loading,
                            'eager'
                        );
                        ?>
                    >
                        立即載入
                    </option>

                </select>

                <span class="wumetax-map-help">

                    建議維持 Lazy。

                    地圖接近使用者畫面時
                    才會載入。

                </span>

            </div>

        </div>

        <div class="wumetax-map-row">

            <label>
                全螢幕
            </label>

            <div>

                <label>

                    <input

                        type="checkbox"

                        name="wm_allow_fullscreen"

                        value="1"

                        <?php
                        checked(
                            $allow_fullscreen,
                            '1'
                        );
                        ?>
                    >

                    允許 Google Maps
                    全螢幕操作

                </label>

            </div>

        </div>

    </div>

</div>

<?php
}

/**
 * ============================================================
 * 4. Shortcode Box
 * ============================================================
 */

function wutm_google_map_shortcode_box($post) {

    $shortcode =
        '[wumetax_map id="' .
        absint($post->ID) .
        '"]';

    ?>

<p>
    Elementor /
    Greenshift /
    Shortcode：
</p>

<input

    type="text"

    readonly

    value="<?php
    echo esc_attr(
        $shortcode
    );
    ?>"

    onclick="this.select();"

    style="
        width:100%;
        font-family:monospace;
    "
>

<p
    style="
        margin-top:18px;
        line-height:1.6;
        color:#646970;
    "
>
    Gutenberg 可搜尋：

    <br>

    <strong>
        WUMETAX 地圖
    </strong>
</p>

<?php
}

/**
 * ============================================================
 * 5. 儲存設定
 * ============================================================
 */

add_action(
    'save_post_wumetax_map',
    'wutm_google_map_save'
);

function wutm_google_map_save($post_id) {
    // Reject malformed field arrays without overwriting existing map data.
    foreach ($_POST as $field => $value) {
        if (strpos((string) $field, 'wm_') === 0 && !is_scalar($value)) return;
    }

    if (
        !isset(
            $_POST[
                'wumetax_map_nonce'
            ]
        )
        || !is_string($_POST['wumetax_map_nonce'])
        ||
        !wp_verify_nonce(
            sanitize_text_field(
                wp_unslash(
                    $_POST[
                        'wumetax_map_nonce'
                    ]
                )
            ),
            'wutm_google_map_save'
        )
    ) {
        return;
    }

    if (
        defined('DOING_AUTOSAVE')
        &&
        DOING_AUTOSAVE
    ) {
        return;
    }

    if (
        !current_user_can(
            'edit_post',
            $post_id
        )
    ) {
        return;
    }

    /*
     * Address
     */

    if (
        isset(
            $_POST['wm_address']
        )
    ) {

        update_post_meta(
            $post_id,
            '_wm_address',
            sanitize_text_field(
                wp_unslash(
                    $_POST[
                        'wm_address'
                    ]
                )
            )
        );
    }

    /*
     * Embed
     */

    if (
        isset(
            $_POST[
                'wm_embed_url'
            ]
        )
    ) {

        update_post_meta(
            $post_id,
            '_wm_embed_url',
            wutm_google_map_parse_embed_url(
                $_POST[
                    'wm_embed_url'
                ]
            )
        );
    }

    /*
     * Zoom
     */

    update_post_meta(
        $post_id,
        '_wm_zoom',
        isset($_POST['wm_zoom'])
        ?
        wutm_google_map_clamp(
            $_POST['wm_zoom'],
            1,
            20
        )
        :
        15
    );

    /*
     * Width
     */

    update_post_meta(
        $post_id,
        '_wm_width',
        isset($_POST['wm_width'])
        ?
        wutm_google_map_sanitize_size(
            wp_unslash(
                $_POST['wm_width']
            ),
            '100%'
        )
        :
        '100%'
    );

    /*
     * Max Width
     */

    update_post_meta(
        $post_id,
        '_wm_max_width',
        isset(
            $_POST[
                'wm_max_width'
            ]
        )
        ?
        wutm_google_map_sanitize_size(
            wp_unslash(
                $_POST[
                    'wm_max_width'
                ]
            ),
            '1290px',
            true
        )
        :
        '1290px'
    );

    /*
     * Alignment
     */

    $alignment =
        isset(
            $_POST[
                'wm_alignment'
            ]
        )
        ?
        sanitize_key(
            wp_unslash(
                $_POST[
                    'wm_alignment'
                ]
            )
        )
        :
        'center';

    if (
        !in_array(
            $alignment,
            array(
                'left',
                'center',
                'right'
            ),
            true
        )
    ) {
        $alignment = 'center';
    }

    update_post_meta(
        $post_id,
        '_wm_alignment',
        $alignment
    );

    /*
     * Full Width
     */

    update_post_meta(
        $post_id,
        '_wm_full_width',
        isset(
            $_POST[
                'wm_full_width'
            ]
        )
        ?
        '1'
        :
        '0'
    );

    /*
     * Heights
     */

    update_post_meta(
        $post_id,
        '_wm_height',
        isset($_POST['wm_height'])
        ?
        wutm_google_map_clamp(
            $_POST['wm_height'],
            150,
            2000
        )
        :
        500
    );

    update_post_meta(
        $post_id,
        '_wm_mobile_height',
        isset(
            $_POST[
                'wm_mobile_height'
            ]
        )
        ?
        wutm_google_map_clamp(
            $_POST[
                'wm_mobile_height'
            ],
            150,
            1500
        )
        :
        380
    );

    /*
     * Style
     */

    $allowed_styles =
        array(
            'normal',
            'dark',
            'dark_soft',
            'grayscale',
            'muted',
            'warm',
            'custom'
        );

    $map_style =
        isset($_POST['wm_style'])
        ?
        sanitize_key(
            wp_unslash(
                $_POST[
                    'wm_style'
                ]
            )
        )
        :
        'normal';

    if (
        !in_array(
            $map_style,
            $allowed_styles,
            true
        )
    ) {
        $map_style = 'normal';
    }

    update_post_meta(
        $post_id,
        '_wm_style',
        $map_style
    );

    /*
     * Custom Filter
     */

    update_post_meta(
        $post_id,
        '_wm_custom_filter',
        isset(
            $_POST[
                'wm_custom_filter'
            ]
        )
        ?
        wutm_google_map_sanitize_filter(
            wp_unslash(
                $_POST[
                    'wm_custom_filter'
                ]
            )
        )
        :
        ''
    );

    /*
     * Radius
     */

    update_post_meta(
        $post_id,
        '_wm_radius',
        isset($_POST['wm_radius'])
        ?
        wutm_google_map_clamp(
            $_POST['wm_radius'],
            0,
            100
        )
        :
        0
    );

    /*
     * Border Width
     */

    update_post_meta(
        $post_id,
        '_wm_border_width',
        isset(
            $_POST[
                'wm_border_width'
            ]
        )
        ?
        wutm_google_map_clamp(
            $_POST[
                'wm_border_width'
            ],
            0,
            20
        )
        :
        0
    );

    /*
     * Border Color
     */

    $border_color =
        isset(
            $_POST[
                'wm_border_color'
            ]
        )
        ?
        sanitize_hex_color(
            wp_unslash(
                $_POST[
                    'wm_border_color'
                ]
            )
        )
        :
        '#dddddd';

    if (!$border_color) {
        $border_color = '#dddddd';
    }

    update_post_meta(
        $post_id,
        '_wm_border_color',
        $border_color
    );

    /*
     * Shadow
     */

    $shadow =
        isset(
            $_POST[
                'wm_shadow'
            ]
        )
        ?
        sanitize_key(
            wp_unslash(
                $_POST[
                    'wm_shadow'
                ]
            )
        )
        :
        'none';

    if (
        !in_array(
            $shadow,
            array(
                'none',
                'small',
                'medium',
                'large'
            ),
            true
        )
    ) {
        $shadow = 'none';
    }

    update_post_meta(
        $post_id,
        '_wm_shadow',
        $shadow
    );

    /*
     * Info
     */

    update_post_meta(
        $post_id,
        '_wm_show_info',
        isset(
            $_POST[
                'wm_show_info'
            ]
        )
        ?
        '1'
        :
        '0'
    );

    $text_fields =
        array(

            'wm_info_label'
            =>
            '_wm_info_label',

            'wm_info_title'
            =>
            '_wm_info_title',

            'wm_button_text'
            =>
            '_wm_button_text',

        );

    foreach (
        $text_fields
        as
        $field => $meta_key
    ) {

        if (
            isset(
                $_POST[$field]
            )
        ) {

            update_post_meta(
                $post_id,
                $meta_key,
                sanitize_text_field(
                    wp_unslash(
                        $_POST[$field]
                    )
                )
            );
        }
    }

    if (
        isset(
            $_POST[
                'wm_info_address'
            ]
        )
    ) {

        update_post_meta(
            $post_id,
            '_wm_info_address',
            sanitize_textarea_field(
                wp_unslash(
                    $_POST[
                        'wm_info_address'
                    ]
                )
            )
        );
    }

    /*
     * Fullscreen
     */

    update_post_meta(
        $post_id,
        '_wm_allow_fullscreen',
        isset(
            $_POST[
                'wm_allow_fullscreen'
            ]
        )
        ?
        '1'
        :
        '0'
    );

    /*
     * Loading
     */

    $loading =
        isset(
            $_POST[
                'wm_loading'
            ]
        )
        ?
        sanitize_key(
            wp_unslash(
                $_POST[
                    'wm_loading'
                ]
            )
        )
        :
        'lazy';

    if (
        !in_array(
            $loading,
            array(
                'lazy',
                'eager'
            ),
            true
        )
    ) {
        $loading = 'lazy';
    }

    update_post_meta(
        $post_id,
        '_wm_loading',
        $loading
    );
}

/**
 * ============================================================
 * 6. 後台列表 Shortcode
 * ============================================================
 */

add_filter(
    'manage_wumetax_map_posts_columns',
    'wutm_google_map_admin_columns'
);

function wutm_google_map_admin_columns(
    $columns
) {

    $result = array();

    foreach (
        $columns
        as
        $key => $label
    ) {

        $result[$key] =
            $label;

        if (
            $key === 'title'
        ) {

            $result[
                'wm_shortcode'
            ] =
                'Shortcode';
        }
    }

    return $result;
}

add_action(
    'manage_wumetax_map_posts_custom_column',
    'wutm_google_map_admin_column_content',
    10,
    2
);

function wutm_google_map_admin_column_content(
    $column,
    $post_id
) {

    if (
        $column !==
        'wm_shortcode'
    ) {
        return;
    }

    ?>

<code
    style="
        user-select:all;
        cursor:text;
    "
>[wumetax_map id="<?php
echo absint($post_id);
?>"]</code>

<?php
}

/**
 * ============================================================
 * 7. Filter
 * ============================================================
 */

function wutm_google_map_filter_value(
    $style,
    $custom = ''
) {

    switch ($style) {

        case 'dark':

            return
                'grayscale(1) ' .
                'invert(.92) ' .
                'contrast(.90) ' .
                'brightness(.72)';

        case 'dark_soft':

            return
                'grayscale(.75) ' .
                'invert(.88) ' .
                'contrast(.84) ' .
                'brightness(.80)';

        case 'grayscale':

            return
                'grayscale(1)';

        case 'muted':

            return
                'saturate(.35) ' .
                'contrast(.95) ' .
                'brightness(.98)';

        case 'warm':

            return
                'sepia(.22) ' .
                'saturate(.75) ' .
                'brightness(.98)';

        case 'custom':

            return
                wutm_google_map_sanitize_filter(
                    $custom
                );

        default:

            return 'none';
    }
}

/**
 * ============================================================
 * 8. Shadow
 * ============================================================
 */

function wutm_google_map_shadow_value(
    $shadow
) {

    switch ($shadow) {

        case 'small':

            return
                '0 5px 20px rgba(0,0,0,.08)';

        case 'medium':

            return
                '0 12px 36px rgba(0,0,0,.14)';

        case 'large':

            return
                '0 20px 60px rgba(0,0,0,.20)';

        default:

            return 'none';
    }
}

/**
 * ============================================================
 * 9. Front CSS
 * ============================================================
 */

function wutm_google_map_front_css() {

    static $printed = false;

    if ($printed) {
        return '';
    }

    $printed = true;

    return <<<'CSS'

<style id="wumetax-map-style">

.wumetax-map,
.wumetax-map *,
.wumetax-map *::before,
.wumetax-map *::after {
    box-sizing: border-box;
}

/*
 * ==========================================
 * Map Container
 * ==========================================
 */

.wumetax-map {

    --wm-width: 100%;

    --wm-max-width: 1290px;

    --wm-height: 500px;

    --wm-mobile-height: 380px;

    --wm-radius: 0px;

    --wm-border-width: 0px;

    --wm-border-color: #ddd;

    --wm-shadow: none;

    --wm-filter: none;

    position: relative;

    /*
     * 100% 是可用寬度
     * 但會受到 max-width 限制
     */
    width:
        var(--wm-width);

    max-width:
        var(--wm-max-width);

    height:
        var(--wm-height);

    padding:
        0;

    overflow:
        hidden;

    border:
        var(--wm-border-width)
        solid
        var(--wm-border-color);

    border-radius:
        var(--wm-radius);

    box-shadow:
        var(--wm-shadow);

    background:
        #eee;

    isolation:
        isolate;
}

/*
 * 對齊
 */

.wumetax-map--left {

    margin-left: 0;

    margin-right: auto;
}

.wumetax-map--center {

    margin-left: auto;

    margin-right: auto;
}

.wumetax-map--right {

    margin-left: auto;

    margin-right: 0;
}

/*
 * 真正滿版
 *
 * 注意：
 * 仍然只會填滿父層，
 * 不使用 100vw，
 * 避免產生水平捲軸。
 */
.wumetax-map--full {

    width:
        100% !important;

    max-width:
        none !important;

    margin-left:
        0 !important;

    margin-right:
        0 !important;
}

/*
 * ==========================================
 * Map Viewport
 * ==========================================
 */

.wumetax-map__viewport {

    position:
        absolute;

    inset:
        0;

    width:
        100%;

    height:
        100%;

    overflow:
        hidden;
}

/*
 * ==========================================
 * Google iframe
 *
 * 強制覆寫：
 * Elementor
 * Blocksy
 * Greenshift
 * Theme iframe CSS
 * ==========================================
 */

.wumetax-map
iframe.wumetax-map__iframe {

    position:
        absolute !important;

    inset:
        0 !important;

    display:
        block !important;

    width:
        100% !important;

    min-width:
        100% !important;

    max-width:
        none !important;

    height:
        100% !important;

    min-height:
        100% !important;

    max-height:
        none !important;

    margin:
        0 !important;

    padding:
        0 !important;

    border:
        0 !important;

    outline:
        0 !important;

    border-radius:
        0 !important;

    aspect-ratio:
        auto !important;

    object-fit:
        fill !important;

    transform:
        none !important;

    filter:
        var(--wm-filter);
}

/*
 * ==========================================
 * Info Card
 * ==========================================
 */

.wumetax-map__info {

    position:
        absolute;

    z-index:
        10;

    left:
        28px;

    bottom:
        28px;

    width:
        320px;

    max-width:
        calc(100% - 56px);

    padding:
        24px 26px;

    background:
        rgba(
            28,
            29,
            34,
            .94
        );

    color:
        #fff;

    box-shadow:
        0 12px 40px
        rgba(
            0,
            0,
            0,
            .18
        );

    backdrop-filter:
        blur(14px);

    -webkit-backdrop-filter:
        blur(14px);
}

.wumetax-map__label {

    margin:
        0 0 9px;

    color:
        rgba(
            255,
            255,
            255,
            .58
        );

    font-size:
        12px;

    line-height:
        1.3;

    letter-spacing:
        .18em;
}

.wumetax-map__title {

    margin:
        0 0 10px !important;

    padding:
        0 !important;

    color:
        #fff !important;

    font-size:
        21px !important;

    line-height:
        1.4 !important;

    font-weight:
        700 !important;
}

.wumetax-map__address {

    margin:
        0 !important;

    padding:
        0 !important;

    color:
        rgba(
            255,
            255,
            255,
            .70
        );

    font-size:
        14px;

    line-height:
        1.75;

    white-space:
        pre-line;
}

.wumetax-map__button {

    display:
        inline-flex;

    align-items:
        center;

    gap:
        6px;

    margin-top:
        15px;

    padding-bottom:
        3px;

    color:
        #fff !important;

    border-bottom:
        1px solid
        rgba(
            255,
            255,
            255,
            .45
        );

    text-decoration:
        none !important;

    font-size:
        13px;

    line-height:
        1.4;

    transition:
        opacity .2s ease;
}

.wumetax-map__button:hover {

    color:
        #fff !important;

    opacity:
        .65;
}

/*
 * ==========================================
 * Mobile
 * ==========================================
 */

@media(max-width:767px) {

    .wumetax-map {

        /*
         * 手機仍不超過父層
         */
        width:
            100%;

        max-width:
            100%;

        height:
            var(
                --wm-mobile-height
            );
    }

    .wumetax-map__info {

        left:
            14px;

        bottom:
            14px;

        width:
            calc(100% - 28px);

        max-width:
            none;

        padding:
            18px 20px;
    }

    .wumetax-map__title {

        font-size:
            18px !important;
    }

}

</style>

CSS;
}

/**
 * ============================================================
 * 10. Render
 * ============================================================
 */

function wutm_google_map_render(
    $map_id,
    $overrides = array()
) {

    $map_id =
        absint($map_id);

    if (!$map_id) {
        return '';
    }

    if (
        get_post_type(
            $map_id
        )
        !==
        'wumetax_map'
    ) {
        return '';
    }

    // Draft/private maps remain available to their editors, not anonymous visitors.
    if (get_post_status($map_id) !== 'publish' && !current_user_can('edit_post', $map_id)) return '';

    /*
     * 一次讀 Meta
     */

    $meta =
        get_post_meta(
            $map_id
        );

    $get =
        function(
            $key,
            $default = ''
        )
        use ($meta)
        {

            return
                isset(
                    $meta[
                        $key
                    ][0]
                )
                ?
                $meta[
                    $key
                ][0]
                :
                $default;
        };

    /*
     * Data
     */

    $address =
        $get(
            '_wm_address',
            ''
        );

    $embed_url =
        $get(
            '_wm_embed_url',
            ''
        );

    $zoom =
        wutm_google_map_clamp(
            $get(
                '_wm_zoom',
                15
            ),
            1,
            20
        );

    $width =
        wutm_google_map_sanitize_size(
            $get(
                '_wm_width',
                '100%'
            ),
            '100%'
        );

    $max_width =
        wutm_google_map_sanitize_size(
            $get(
                '_wm_max_width',
                '1290px'
            ),
            '1290px',
            true
        );

    $alignment =
        sanitize_key(
            $get(
                '_wm_alignment',
                'center'
            )
        );

    if (
        !in_array(
            $alignment,
            array(
                'left',
                'center',
                'right'
            ),
            true
        )
    ) {
        $alignment = 'center';
    }

    $full_width =
        $get(
            '_wm_full_width',
            '0'
        );

    $height =
        wutm_google_map_clamp(
            $get(
                '_wm_height',
                500
            ),
            150,
            2000
        );

    $mobile_height =
        wutm_google_map_clamp(
            $get(
                '_wm_mobile_height',
                380
            ),
            150,
            1500
        );

    $style =
        sanitize_key(
            $get(
                '_wm_style',
                'normal'
            )
        );

    $custom_filter =
        $get(
            '_wm_custom_filter',
            ''
        );

    $radius =
        wutm_google_map_clamp(
            $get(
                '_wm_radius',
                0
            ),
            0,
            100
        );

    $border_width =
        wutm_google_map_clamp(
            $get(
                '_wm_border_width',
                0
            ),
            0,
            20
        );

    $border_color =
        sanitize_hex_color(
            $get(
                '_wm_border_color',
                '#dddddd'
            )
        );

    if (!$border_color) {
        $border_color =
            '#dddddd';
    }

    $shadow =
        sanitize_key(
            $get(
                '_wm_shadow',
                'none'
            )
        );

    $show_info =
        $get(
            '_wm_show_info',
            '0'
        );

    $info_label =
        $get(
            '_wm_info_label',
            'LOCATION'
        );

    $info_title =
        $get(
            '_wm_info_title',
            ''
        );

    $info_address =
        $get(
            '_wm_info_address',
            ''
        );

    $button_text =
        $get(
            '_wm_button_text',
            'Google Maps 導航'
        );

    $allow_fullscreen =
        $get(
            '_wm_allow_fullscreen',
            '1'
        );

    $loading =
        $get(
            '_wm_loading',
            'lazy'
        );

    /*
     * ========================================================
     * Shortcode Overrides
     * ========================================================
     */

    if (
        !empty(
            $overrides['width']
        )
    ) {

        $width =
            wutm_google_map_sanitize_size(
                $overrides['width'],
                $width
            );
    }

    if (
        !empty(
            $overrides[
                'max_width'
            ]
        )
    ) {

        $max_width =
            wutm_google_map_sanitize_size(
                $overrides[
                    'max_width'
                ],
                $max_width,
                true
            );
    }

    if (
        !empty(
            $overrides[
                'align'
            ]
        )
    ) {

        $override_align =
            sanitize_key(
                $overrides[
                    'align'
                ]
            );

        if (
            in_array(
                $override_align,
                array(
                    'left',
                    'center',
                    'right'
                ),
                true
            )
        ) {

            $alignment =
                $override_align;
        }
    }

    if (
        isset(
            $overrides[
                'full_width'
            ]
        )
        &&
        $overrides[
            'full_width'
        ] !== ''
    ) {

        $full_width_value =
            strtolower(
                trim(
                    strval(
                        $overrides[
                            'full_width'
                        ]
                    )
                )
            );

        $full_width =
            in_array(
                $full_width_value,
                array(
                    '1',
                    'true',
                    'yes',
                    'on'
                ),
                true
            )
            ?
            '1'
            :
            '0';
    }

    if (
        !empty(
            $overrides[
                'height'
            ]
        )
    ) {

        $height =
            wutm_google_map_clamp(
                $overrides[
                    'height'
                ],
                150,
                2000
            );
    }

    if (
        !empty(
            $overrides[
                'mobile_height'
            ]
        )
    ) {

        $mobile_height =
            wutm_google_map_clamp(
                $overrides[
                    'mobile_height'
                ],
                150,
                1500
            );
    }

    if (
        !empty(
            $overrides[
                'map_style'
            ]
        )
    ) {

        $override_style =
            sanitize_key(
                $overrides[
                    'map_style'
                ]
            );

        if (
            in_array(
                $override_style,
                array(
                    'normal',
                    'dark',
                    'dark_soft',
                    'grayscale',
                    'muted',
                    'warm'
                ),
                true
            )
        ) {

            $style =
                $override_style;
        }
    }

    if (
        isset(
            $overrides['info']
        )
        &&
        $overrides['info'] !== ''
    ) {

        $info_value =
            strtolower(
                trim(
                    strval(
                        $overrides[
                            'info'
                        ]
                    )
                )
            );

        $show_info =
            in_array(
                $info_value,
                array(
                    '1',
                    'true',
                    'yes',
                    'on'
                ),
                true
            )
            ?
            '1'
            :
            '0';
    }

    /*
     * ========================================================
     * Map URL
     * ========================================================
     */

    if ($embed_url) {

        $map_url =
            $embed_url;

    } else {

        if (!$address) {
            return '';
        }

        $map_url =
            'https://www.google.com/maps' .
            '?hl=zh-TW' .
            '&q=' .
            rawurlencode($address) .
            '&z=' .
            absint($zoom) .
            '&output=embed';
    }

    /*
     * Navigation
     */

    $navigation_url =
        'https://www.google.com/maps/search/' .
        '?api=1' .
        '&query=' .
        rawurlencode($address);

    /*
     * Filter
     */

    $filter =
        wutm_google_map_filter_value(
            $style,
            $custom_filter
        );

    /*
     * Shadow
     */

    $shadow_css =
        wutm_google_map_shadow_value(
            $shadow
        );

    /*
     * CSS Variables
     */

    $style_vars =
        '--wm-width:' .
        $width .
        ';' .

        '--wm-max-width:' .
        $max_width .
        ';' .

        '--wm-height:' .
        $height .
        'px;' .

        '--wm-mobile-height:' .
        $mobile_height .
        'px;' .

        '--wm-radius:' .
        $radius .
        'px;' .

        '--wm-border-width:' .
        $border_width .
        'px;' .

        '--wm-border-color:' .
        $border_color .
        ';' .

        '--wm-shadow:' .
        $shadow_css .
        ';' .

        '--wm-filter:' .
        $filter .
        ';';

    /*
     * Classes
     */

    $classes =
        array(
            'wumetax-map',
            'wumetax-map--' .
            $alignment
        );

    if (
        $full_width === '1'
    ) {

        $classes[] =
            'wumetax-map--full';
    }

    /*
     * Loading
     */

    if (
        !in_array(
            $loading,
            array(
                'lazy',
                'eager'
            ),
            true
        )
    ) {
        $loading = 'lazy';
    }

    ob_start();

    echo
        wutm_google_map_front_css();

    ?>

<div

    class="<?php
    echo esc_attr(
        implode(
            ' ',
            $classes
        )
    );
    ?>"

    style="<?php
    echo esc_attr(
        $style_vars
    );
    ?>"

>

    <div
        class="
            wumetax-map__viewport
        "
    >

        <iframe

            class="
                wumetax-map__iframe
            "

            src="<?php
            echo esc_url(
                $map_url
            );
            ?>"

            width="100%"

            height="100%"

            loading="<?php
            echo esc_attr(
                $loading
            );
            ?>"

            referrerpolicy="
                no-referrer-when-downgrade
            "

            title="<?php
            echo esc_attr(
                get_the_title(
                    $map_id
                )
            );
            ?>"

            <?php
            if (
                $allow_fullscreen === '1'
            ) :
            ?>

                allowfullscreen

            <?php endif; ?>

        ></iframe>

    </div>

    <?php
    if (
        $show_info === '1'
    ) :
    ?>

        <div
            class="
                wumetax-map__info
            "
        >

            <?php
            if ($info_label) :
            ?>

                <div
                    class="
                        wumetax-map__label
                    "
                >
                    <?php
                    echo esc_html(
                        $info_label
                    );
                    ?>
                </div>

            <?php endif; ?>

            <?php
            if ($info_title) :
            ?>

                <h3
                    class="
                        wumetax-map__title
                    "
                >
                    <?php
                    echo esc_html(
                        $info_title
                    );
                    ?>
                </h3>

            <?php endif; ?>

            <?php
            if ($info_address) :
            ?>

                <p
                    class="
                        wumetax-map__address
                    "
                ><?php
                echo esc_html(
                    $info_address
                );
                ?></p>

            <?php endif; ?>

            <?php
            if (
                $button_text
                &&
                $address
            ) :
            ?>

                <a

                    class="
                        wumetax-map__button
                    "

                    href="<?php
                    echo esc_url(
                        $navigation_url
                    );
                    ?>"

                    target="_blank"

                    rel="
                        noopener noreferrer
                    "
                >

                    <?php
                    echo esc_html(
                        $button_text
                    );
                    ?>

                    <span
                        aria-hidden="true"
                    >
                        ↗
                    </span>

                </a>

            <?php endif; ?>

        </div>

    <?php endif; ?>

</div>

    <?php

    return
        ob_get_clean();
}

/**
 * ============================================================
 * 11. Shortcode
 * ============================================================
 */

add_shortcode(
    'wumetax_map',
    'wutm_google_map_shortcode'
);

function wutm_google_map_shortcode($atts) {

    $atts =
        shortcode_atts(
            array(

                'id' =>
                    0,

                'width' =>
                    '',

                'max_width' =>
                    '',

                'align' =>
                    '',

                'full_width' =>
                    '',

                'height' =>
                    '',

                'mobile_height' =>
                    '',

                'map_style' =>
                    '',

                'info' =>
                    '',

            ),
            $atts,
            'wumetax_map'
        );

    return
        wutm_google_map_render(
            absint(
                $atts['id']
            ),
            array(

                'width' =>
                    $atts['width'],

                'max_width' =>
                    $atts[
                        'max_width'
                    ],

                'align' =>
                    $atts['align'],

                'full_width' =>
                    $atts[
                        'full_width'
                    ],

                'height' =>
                    $atts['height'],

                'mobile_height' =>
                    $atts[
                        'mobile_height'
                    ],

                'map_style' =>
                    $atts[
                        'map_style'
                    ],

                'info' =>
                    $atts['info'],

            )
        );
}

/**
 * ============================================================
 * 12. Gutenberg Dynamic Block
 * ============================================================
 */

add_action(
    'init',
    'wutm_google_map_register_block'
);

function wutm_google_map_register_block() {

    if (
        !function_exists(
            'register_block_type'
        )
    ) {
        return;
    }

    register_block_type(
        'wumetax/map',
        array(

            'api_version' =>
                2,

            'attributes' =>
                array(

                    'mapId' =>
                        array(

                            'type' =>
                                'integer',

                            'default' =>
                                0,

                        ),

                ),

            'render_callback' =>
                'wutm_google_map_block_render',

        )
    );
}

function wutm_google_map_block_render(
    $attributes
) {

    $map_id =
        isset(
            $attributes['mapId']
        )
        ?
        absint(
            $attributes['mapId']
        )
        :
        0;

    if (!$map_id) {
        return '';
    }

    return
        wutm_google_map_render(
            $map_id
        );
}

/**
 * ============================================================
 * 13. Gutenberg Editor
 *
 * 只有後台編輯器才載入。
 * 前台完全不載此 JS。
 * ============================================================
 */

add_action(
    'enqueue_block_editor_assets',
    'wutm_google_map_block_editor_assets'
);

function wutm_google_map_block_editor_assets() {

    $maps =
        get_posts(
            array(

                'post_type' =>
                    'wumetax_map',

                'post_status' =>
                    'publish',

                'posts_per_page' =>
                    -1,

                'orderby' =>
                    'title',

                'order' =>
                    'ASC',

                'no_found_rows' =>
                    true,

                'update_post_meta_cache'
                    =>
                    false,

                'update_post_term_cache'
                    =>
                    false,

            )
        );

    $options =
        array();

    foreach (
        $maps
        as
        $map
    ) {

        $options[] =
            array(

                'label' =>
                    $map->post_title
                    ?
                    $map->post_title
                    :
                    (
                        '地圖 #' .
                        $map->ID
                    ),

                'value' =>
                    intval(
                        $map->ID
                    ),

            );
    }

    wp_register_script(
        'wumetax-map-block-editor',
        false,
        array(
            'wp-blocks',
            'wp-element',
            'wp-components',
            'wp-block-editor',
            'wp-i18n',
        ),
        '2.2.0',
        true
    );

    wp_enqueue_script(
        'wumetax-map-block-editor'
    );

    wp_add_inline_script(
        'wumetax-map-block-editor',
        'window.WUMETAX_MAP_OPTIONS = ' .
        wp_json_encode(
            $options,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) .
        ';',
        'before'
    );

    $script = <<<'JS'

(function () {

    if (
        !window.wp ||
        !wp.blocks ||
        !wp.element ||
        !wp.components
    ) {
        return;
    }

    var el =
        wp.element.createElement;

    var registerBlockType =
        wp.blocks.registerBlockType;

    var SelectControl =
        wp.components.SelectControl;

    var Placeholder =
        wp.components.Placeholder;

    var Notice =
        wp.components.Notice;

    var options = [

        {
            label:
                '請選擇地圖',

            value:
                0
        }

    ];

    if (
        Array.isArray(
            window
            .WUMETAX_MAP_OPTIONS
        )
    ) {

        window
        .WUMETAX_MAP_OPTIONS
        .forEach(
            function (item) {

                options.push({

                    label:
                        item.label,

                    value:
                        parseInt(
                            item.value,
                            10
                        )

                });

            }
        );
    }

    registerBlockType(
        'wumetax/map',
        {

            apiVersion:
                2,

            title:
                'WUMETAX 地圖',

            description:
                '顯示「地圖管理」中建立的 Google Maps。',

            icon:
                'location-alt',

            category:
                'widgets',

            keywords: [

                'map',

                'google',

                '地圖',

                'wumetax'

            ],

            attributes: {

                mapId: {

                    type:
                        'integer',

                    default:
                        0
                }

            },

            edit:
                function (props) {

                    var mapId =
                        parseInt(
                            props
                            .attributes
                            .mapId
                            ||
                            0,
                            10
                        );

                    var selected =
                        options.find(
                            function (
                                item
                            ) {

                                return (
                                    parseInt(
                                        item.value,
                                        10
                                    )
                                    ===
                                    mapId
                                );

                            }
                        );

                    var children = [];

                    children.push(

                        el(
                            SelectControl,
                            {

                                label:
                                    '選擇地點',

                                value:
                                    mapId,

                                options:
                                    options,

                                onChange:
                                    function (
                                        value
                                    ) {

                                        props
                                        .setAttributes(
                                            {

                                                mapId:
                                                    parseInt(
                                                        value,
                                                        10
                                                    )
                                                    ||
                                                    0

                                            }
                                        );

                                    }

                            }
                        )

                    );

                    if (mapId) {

                        children.push(

                            el(
                                'div',
                                {

                                    style: {

                                        width:
                                            '100%',

                                        marginTop:
                                            '12px',

                                        padding:
                                            '18px',

                                        border:
                                            '1px solid #dcdcde',

                                        background:
                                            '#f6f7f7',

                                        borderRadius:
                                            '4px'

                                    }

                                },

                                el(
                                    'strong',
                                    null,

                                    selected
                                    ?
                                    selected.label
                                    :
                                    (
                                        '地圖 #' +
                                        mapId
                                    )
                                ),

                                el(
                                    'p',
                                    {

                                        style: {

                                            margin:
                                                '8px 0 0',

                                            color:
                                                '#646970',

                                            lineHeight:
                                                '1.6'

                                        }

                                    },

                                    '為避免拖慢區塊編輯器，後台不載入 Google Maps iframe；前台會依地圖管理設定正常顯示。'
                                )

                            )

                        );

                    } else {

                        children.push(

                            el(
                                Notice,
                                {

                                    status:
                                        'info',

                                    isDismissible:
                                        false

                                },

                                '請先選擇地圖。'
                            )

                        );

                    }

                    return el(
                        Placeholder,
                        {

                            icon:
                                'location-alt',

                            label:
                                'WUMETAX 地圖',

                            instructions:
                                '選擇「地圖管理」中建立的地點。'

                        },

                        children
                    );

                },

            save:
                function () {

                    return null;

                }

        }
    );

})();

JS;

    wp_add_inline_script(
        'wumetax-map-block-editor',
        $script,
        'after'
    );
}
