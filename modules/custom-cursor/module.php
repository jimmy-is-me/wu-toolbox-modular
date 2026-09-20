<?php
/**
 * Module: custom-cursor
 * WUMETAX Minimal Cursor adapted for WU Toolbox Modular.
 */
defined('ABSPATH') || exit;

const WUTM_CUSTOM_CURSOR_OPTION = 'wutm_custom_cursor_options';

function wutm_custom_cursor_defaults(): array {
    return [
        'ring_color'  => '#78807c',
        'dot_color'   => '#4fa567',
        'hover_color' => '#3a7a4f',
    ];
}

function wutm_custom_cursor_options(): array {
    $saved = get_option(WUTM_CUSTOM_CURSOR_OPTION, []);
    return wp_parse_args(is_array($saved) ? $saved : [], wutm_custom_cursor_defaults());
}

function wutm_custom_cursor_sanitize($input): array {
    $input = is_array($input) ? $input : [];
    $defaults = wutm_custom_cursor_defaults();
    $clean = [];
    foreach (array_keys($defaults) as $key) {
        $clean[$key] = sanitize_hex_color($input[$key] ?? '') ?: $defaults[$key];
    }
    return $clean;
}

function wutm_custom_cursor_rgb(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) return '79,165,103';
    return hexdec(substr($hex, 0, 2)) . ',' . hexdec(substr($hex, 2, 2)) . ',' . hexdec(substr($hex, 4, 2));
}

add_action('admin_init', function (): void {
    register_setting('wutm_custom_cursor_group', WUTM_CUSTOM_CURSOR_OPTION, [
        'type' => 'array',
        'sanitize_callback' => 'wutm_custom_cursor_sanitize',
        'default' => wutm_custom_cursor_defaults(),
    ]);
});

add_action('admin_menu', function (): void {
    add_submenu_page(
        'wu-toolbox-modular',
        '自訂網站鼠標',
        '自訂網站鼠標',
        'manage_options',
        'wu-custom-cursor',
        'wutm_custom_cursor_settings_page'
    );
}, 999);

function wutm_custom_cursor_settings_page(): void {
    if (!current_user_can('manage_options')) return;
    $options = wutm_custom_cursor_options();
    ?>
    <div class="wrap wutm-module-wrap">
        <h1>自訂網站鼠標</h1>
        <p class="wutm-module-subtitle">在使用滑鼠的桌面裝置顯示極簡圓環鼠標；手機與觸控裝置會自動使用原生操作方式。</p>
        <form method="post" action="options.php">
            <?php settings_fields('wutm_custom_cursor_group'); ?>
            <div class="card" style="max-width:900px;padding:24px;">
                <h2 style="margin-top:0;">鼠標顏色</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wutm-cursor-ring">圓環顏色</label></th>
                        <td><input id="wutm-cursor-ring" type="color" name="<?php echo esc_attr(WUTM_CUSTOM_CURSOR_OPTION); ?>[ring_color]" value="<?php echo esc_attr($options['ring_color']); ?>"> <code><?php echo esc_html($options['ring_color']); ?></code><p class="description">一般狀態的外圈顏色。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wutm-cursor-dot">中心點顏色</label></th>
                        <td><input id="wutm-cursor-dot" type="color" name="<?php echo esc_attr(WUTM_CUSTOM_CURSOR_OPTION); ?>[dot_color]" value="<?php echo esc_attr($options['dot_color']); ?>"> <code><?php echo esc_html($options['dot_color']); ?></code><p class="description">一般狀態的中心圓點與柔光顏色。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wutm-cursor-hover">互動顏色</label></th>
                        <td><input id="wutm-cursor-hover" type="color" name="<?php echo esc_attr(WUTM_CUSTOM_CURSOR_OPTION); ?>[hover_color]" value="<?php echo esc_attr($options['hover_color']); ?>"> <code><?php echo esc_html($options['hover_color']); ?></code><p class="description">移到連結、按鈕與可點擊元件時使用。</p></td>
                    </tr>
                </table>
                <?php submit_button('儲存設定'); ?>
            </div>
        </form>
    </div>
    <?php
}

// Load in the document head so pointer tracking is ready before heavy page assets finish.
add_action('wp_head', function (): void {
    if (is_admin()) return;
    $options = wutm_custom_cursor_options();
    $ring = $options['ring_color'];
    $dot = $options['dot_color'];
    $hover = $options['hover_color'];
    $dot_rgb = wutm_custom_cursor_rgb($dot);
    $hover_rgb = wutm_custom_cursor_rgb($hover);
    ?>
    <style id="wutm-minimal-cursor-css">
    @media (hover:hover) and (pointer:fine){
        html.wutm-cursor-enabled,html.wutm-cursor-enabled body,html.wutm-cursor-enabled body *{cursor:none!important}
        html.wutm-cursor-enabled input[type="text"],html.wutm-cursor-enabled input[type="email"],html.wutm-cursor-enabled input[type="url"],html.wutm-cursor-enabled input[type="tel"],html.wutm-cursor-enabled input[type="number"],html.wutm-cursor-enabled input[type="password"],html.wutm-cursor-enabled input[type="search"],html.wutm-cursor-enabled textarea,html.wutm-cursor-enabled select,html.wutm-cursor-enabled [contenteditable="true"]{cursor:text!important}
        #wutm-minimal-cursor{position:fixed;z-index:2147483646;top:0;left:0;width:28px;height:28px;pointer-events:none;opacity:0;transform:translate3d(-100px,-100px,0) translate(-50%,-50%);will-change:transform;contain:layout style paint}
        html.wutm-cursor-visible #wutm-minimal-cursor{opacity:1}
        .wutm-cursor-ring{position:absolute;top:50%;left:50%;width:26px;height:26px;border:1px solid <?php echo esc_attr($ring); ?>;border-radius:50%;background:rgba(255,255,255,.04);transform:translate(-50%,-50%);box-shadow:0 1px 3px rgba(23,27,25,.05);transition:width .16s cubic-bezier(.22,1,.36,1),height .16s cubic-bezier(.22,1,.36,1),border-color .16s ease,background .16s ease,box-shadow .16s ease}
        .wutm-cursor-dot{position:absolute;top:50%;left:50%;width:5px;height:5px;border-radius:50%;background:<?php echo esc_attr($dot); ?>;transform:translate(-50%,-50%);box-shadow:0 0 0 4px rgba(<?php echo esc_attr($dot_rgb); ?>,.10);transition:width .14s cubic-bezier(.22,1,.36,1),height .14s cubic-bezier(.22,1,.36,1),background .14s ease,box-shadow .14s ease}
        html.wutm-cursor-hover .wutm-cursor-ring{width:44px;height:44px;border-color:<?php echo esc_attr($hover); ?>;background:rgba(<?php echo esc_attr($hover_rgb); ?>,.06);box-shadow:0 0 0 6px rgba(<?php echo esc_attr($hover_rgb); ?>,.06),0 2px 10px rgba(23,27,25,.06)}
        html.wutm-cursor-hover .wutm-cursor-dot{width:10px;height:10px;background:<?php echo esc_attr($hover); ?>;box-shadow:0 0 0 6px rgba(<?php echo esc_attr($hover_rgb); ?>,.12)}
        html.wutm-cursor-down .wutm-cursor-ring{transform:translate(-50%,-50%) scale(.85)}
        html.wutm-cursor-down .wutm-cursor-dot{transform:translate(-50%,-50%) scale(.8)}
        html.wutm-cursor-text #wutm-minimal-cursor{opacity:0}
    }
    @media (prefers-reduced-motion:reduce){.wutm-cursor-ring,.wutm-cursor-dot{transition:none!important}}
    </style>
    <script id="wutm-minimal-cursor-js">
    (function(){'use strict';
        if(!window.matchMedia('(hover:hover) and (pointer:fine)').matches)return;
        var root=document.documentElement,cursor=document.createElement('span');
        cursor.id='wutm-minimal-cursor';cursor.setAttribute('aria-hidden','true');cursor.innerHTML='<i class="wutm-cursor-ring"></i><i class="wutm-cursor-dot"></i>';root.appendChild(cursor);
        var clickable='a[href],button,[role="button"],summary,label[for],input[type="button"],input[type="submit"],input[type="reset"],input[type="checkbox"],input[type="radio"],.wp-block-button__link,.wp-element-button,.ct-button';
        var textual='input[type="text"],input[type="email"],input[type="url"],input[type="tel"],input[type="number"],input[type="password"],input[type="search"],textarea,select,[contenteditable="true"]';
        function move(e){var p=e.getCoalescedEvents?e.getCoalescedEvents():null,last=p&&p.length?p[p.length-1]:e;cursor.style.transform='translate3d('+last.clientX+'px,'+last.clientY+'px,0) translate(-50%,-50%)';root.classList.add('wutm-cursor-enabled','wutm-cursor-visible')}
        document.addEventListener(('onpointerrawupdate' in window)?'pointerrawupdate':'pointermove',move,{passive:true,capture:true});
        document.addEventListener('pointerover',function(e){if(!e.target||!e.target.closest)return;var text=e.target.closest(textual),link=e.target.closest(clickable);root.classList.toggle('wutm-cursor-text',!!text);root.classList.toggle('wutm-cursor-hover',!!link&&!text)},{passive:true});
        document.addEventListener('pointerout',function(e){var target=e.relatedTarget;if(target&&target.closest){var text=target.closest(textual),link=target.closest(clickable);root.classList.toggle('wutm-cursor-text',!!text);root.classList.toggle('wutm-cursor-hover',!!link&&!text)}else{root.classList.remove('wutm-cursor-hover','wutm-cursor-text')}},{passive:true});
        document.addEventListener('pointerdown',function(){root.classList.add('wutm-cursor-down')},{passive:true});
        document.addEventListener('pointerup',function(){root.classList.remove('wutm-cursor-down')},{passive:true});
        document.addEventListener('mouseleave',function(){root.classList.remove('wutm-cursor-visible')},{passive:true});
        document.addEventListener('mouseenter',function(e){if(typeof e.clientX==='number')move(e)},{passive:true});
        window.addEventListener('blur',function(){root.classList.remove('wutm-cursor-visible','wutm-cursor-down')});
    })();
    </script>
    <?php
}, 1);
