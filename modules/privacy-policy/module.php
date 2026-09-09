<?php
defined('ABSPATH') || exit;
require_once dirname(__DIR__) . '/policy-shortcode-shared.php';
wutm_register_policy_module('privacy', 'wu-privacy-policy');
add_action('admin_init', function (): void {
    if (get_option('wutm_privacy_defaults_migrated_211')) return;
    $saved = get_option(wutm_policy_option_name('privacy'), null);
    if (is_array($saved) && ($saved['intro'] ?? '') === '我們重視您的個人資料與隱私，以下說明本站蒐集、處理及利用資料的方式。') {
        update_option(wutm_policy_option_name('privacy'), wutm_policy_defaults('privacy'), false);
    }
    update_option('wutm_privacy_defaults_migrated_211', 1, false);
});
add_action('admin_init', function (): void {
    if (get_option('wutm_privacy_single_editor_214')) return;
    $name = wutm_policy_option_name('privacy');
    $saved = get_option($name, null);
    if (is_array($saved) && empty($saved['content'])) {
        $content = '';
        if (!empty($saved['intro'])) $content .= wpautop(wp_kses_post($saved['intro']));
        if (!empty($saved['items']) && is_array($saved['items'])) {
            $content .= '<ul>';
            foreach ($saved['items'] as $item) {
                $title = sanitize_text_field($item['title'] ?? '');
                $body = wp_kses_post($item['content'] ?? '');
                if ($title !== '' || trim(wp_strip_all_tags($body)) !== '') $content .= '<li><strong>' . esc_html($title) . ($title !== '' ? '：' : '') . '</strong>' . $body . '</li>';
            }
            $content .= '</ul>';
        }
        $saved['content'] = $content !== '' ? $content : wutm_policy_defaults('privacy')['content'];
        update_option($name, $saved, false);
    }
    update_option('wutm_privacy_single_editor_214', 1, false);
});
