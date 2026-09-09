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
