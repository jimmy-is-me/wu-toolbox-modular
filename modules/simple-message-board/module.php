<?php
defined('ABSPATH') || exit;

require_once dirname(__DIR__) . '/page-binding-shared.php';

const WUTM_SMB_OPTION = 'wutm_simple_message_board_options';
const WUTM_SMB_POST_TYPE = 'wutm_message';

function wutm_smb_defaults(): array {
    return [
        'page_id' => 0,
        'page_title' => '',
        'max_width' => '760px',
        'form_title' => '聯絡我們',
        'form_intro' => '請留下您的聯絡方式與訊息，我們收到後會儘快回覆。',
        'button_text' => '送出留言',
        'trigger_text' => '聯絡我們',
        'success_message' => '感謝您的留言，我們已收到訊息。',
        'accent_color' => '#111111',
        'button_text_color' => '#ffffff',
    ];
}

function wutm_smb_options(): array {
    return wp_parse_args((array) get_option(WUTM_SMB_OPTION, []), wutm_smb_defaults());
}

function wutm_smb_css_size($value, string $fallback = '760px'): string {
    $value = trim((string) $value);
    if ($value === '100%' || preg_match('/^(?:\d+(?:\.\d+)?)(?:px|rem|em|vw)$/i', $value)) return $value;
    return $fallback;
}

function wutm_smb_sanitize_options($raw): array {
    $raw = is_array($raw) ? $raw : [];
    $defaults = wutm_smb_defaults();
    $binding = wutm_page_binding_sanitize($raw['page_id'] ?? 0, $raw['page_title'] ?? '', WUTM_SMB_OPTION);
    return [
        'page_id' => $binding['page_id'],
        'page_title' => $binding['page_title'],
        'max_width' => wutm_smb_css_size($raw['max_width'] ?? '', $defaults['max_width']),
        'form_title' => sanitize_text_field((string) ($raw['form_title'] ?? $defaults['form_title'])),
        'form_intro' => sanitize_textarea_field((string) ($raw['form_intro'] ?? $defaults['form_intro'])),
        'button_text' => sanitize_text_field((string) ($raw['button_text'] ?? $defaults['button_text'])),
        'trigger_text' => sanitize_text_field((string) ($raw['trigger_text'] ?? $defaults['trigger_text'])),
        'success_message' => sanitize_text_field((string) ($raw['success_message'] ?? $defaults['success_message'])),
        'accent_color' => sanitize_hex_color($raw['accent_color'] ?? '') ?: $defaults['accent_color'],
        'button_text_color' => sanitize_hex_color($raw['button_text_color'] ?? '') ?: $defaults['button_text_color'],
    ];
}

add_action('admin_init', function (): void {
    register_setting('wutm_simple_message_board_group', WUTM_SMB_OPTION, [
        'type' => 'array',
        'sanitize_callback' => 'wutm_smb_sanitize_options',
        'default' => wutm_smb_defaults(),
    ]);
});

add_action('admin_menu', function (): void {
    add_submenu_page(
        'wu-toolbox-modular',
        '簡易留言板',
        '簡易留言板',
        'manage_options',
        'wu-simple-message-board',
        'wutm_smb_settings_page'
    );
}, 30);

function wutm_smb_settings_page(): void {
    if (!current_user_can('manage_options')) return;
    $o = wutm_smb_options();
    ?>
    <div class="wrap wutm-smb-admin">
        <h1>簡易留言板設定</h1>
        <p>將 <code>[wutm_message_board]</code> 放入頁面，即可顯示聯絡留言表單。留言只會儲存在網站後台，不會公開顯示。</p>
        <p><a class="button button-secondary" href="<?php echo esc_url(admin_url('edit.php?post_type=' . WUTM_SMB_POST_TYPE)); ?>">查看客戶留言</a></p>
        <?php settings_errors(WUTM_SMB_OPTION); ?>
        <form method="post" action="options.php">
            <?php settings_fields('wutm_simple_message_board_group'); ?>
            <?php wutm_page_binding_render(WUTM_SMB_OPTION, $o, 'wutm_message_board'); ?>
            <section class="wutm-smb-panel">
                <h2>表單與版面</h2>
                <div class="wutm-smb-grid">
                    <label>區塊最大寬度
                        <input name="<?php echo esc_attr(WUTM_SMB_OPTION); ?>[max_width]" value="<?php echo esc_attr($o['max_width']); ?>" placeholder="760px">
                        <small>可輸入 760px、48rem 或 100%；手機版會自動縮至畫面寬度。</small>
                    </label>
                    <label>表單標題
                        <input name="<?php echo esc_attr(WUTM_SMB_OPTION); ?>[form_title]" value="<?php echo esc_attr($o['form_title']); ?>">
                    </label>
                    <label class="wutm-smb-wide">表單說明
                        <textarea name="<?php echo esc_attr(WUTM_SMB_OPTION); ?>[form_intro]" rows="3"><?php echo esc_textarea($o['form_intro']); ?></textarea>
                    </label>
                    <label>送出按鈕文字
                        <input name="<?php echo esc_attr(WUTM_SMB_OPTION); ?>[button_text]" value="<?php echo esc_attr($o['button_text']); ?>">
                    </label>
                    <label>頁首聯絡按鈕文字
                        <input name="<?php echo esc_attr(WUTM_SMB_OPTION); ?>[trigger_text]" value="<?php echo esc_attr($o['trigger_text']); ?>">
                    </label>
                    <label>送出成功訊息
                        <input name="<?php echo esc_attr(WUTM_SMB_OPTION); ?>[success_message]" value="<?php echo esc_attr($o['success_message']); ?>">
                    </label>
                    <label>主色
                        <input type="color" name="<?php echo esc_attr(WUTM_SMB_OPTION); ?>[accent_color]" value="<?php echo esc_attr($o['accent_color']); ?>">
                    </label>
                    <label>按鈕文字顏色
                        <input type="color" name="<?php echo esc_attr(WUTM_SMB_OPTION); ?>[button_text_color]" value="<?php echo esc_attr($o['button_text_color']); ?>">
                    </label>
                </div>
            </section>
            <section class="wutm-smb-panel">
                <h2>使用方式</h2>
                <ul>
                    <li><code>[wutm_message_board]</code>：直接顯示完整留言表單。</li>
                    <li><code>[wutm_message_board mode="popup"]</code>：顯示按鈕，點擊後以彈出視窗開啟表單。</li>
                    <li><code>[wutm_message_board mode="popup" display="icon"]</code>：彈出按鈕只顯示圖示；<code>display</code> 也可設為 <code>text</code> 或 <code>both</code>。</li>
                </ul>
                <p>公開表單已包含安全驗證、隱藏欄位與短時間重複送出限制。第一次在留言中儲存回覆時，若顧客留下有效 Email，系統會寄出回覆通知。</p>
            </section>
            <?php submit_button('儲存設定'); ?>
        </form>
    </div>
    <style>
        .wutm-smb-admin{max-width:1180px}.wutm-smb-admin>p{font-size:15px}.wutm-smb-panel,.wutm-smb-admin .wutm-page-binding{margin:20px 0;padding:22px 26px;border:1px solid #dcdcde;border-radius:9px;background:#fff}.wutm-smb-panel h2,.wutm-smb-admin .wutm-page-binding>h2{margin:0 0 20px;padding-bottom:12px;border-bottom:1px solid #e5e7eb;font-size:18px}.wutm-smb-grid,.wutm-smb-admin .wutm-page-binding-grid{display:grid;grid-template-columns:repeat(2,minmax(240px,1fr));gap:20px 28px}.wutm-smb-grid label,.wutm-smb-admin .wutm-page-binding-grid label{font-weight:600}.wutm-smb-grid input:not([type=color]),.wutm-smb-grid textarea,.wutm-smb-admin .wutm-page-binding-grid input,.wutm-smb-admin .wutm-page-binding-grid select{display:block;width:100%;max-width:none;margin-top:8px;min-height:42px}.wutm-smb-grid input[type=color]{display:block;width:90px;height:42px;margin-top:8px}.wutm-smb-grid small{display:block;margin-top:6px;color:#646970;font-weight:400}.wutm-smb-wide{grid-column:1/-1}.wutm-smb-panel li{margin:9px 0}@media(max-width:720px){.wutm-smb-grid,.wutm-smb-admin .wutm-page-binding-grid{grid-template-columns:1fr}.wutm-smb-wide{grid-column:auto}}
    </style>
    <?php
}

add_action('init', function (): void {
    register_post_type(WUTM_SMB_POST_TYPE, [
        'labels' => [
            'name' => '客戶留言',
            'singular_name' => '客戶留言',
            'menu_name' => '客戶留言',
            'all_items' => '所有留言',
            'edit_item' => '查看／回覆留言',
            'search_items' => '搜尋留言',
            'not_found' => '目前沒有留言',
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'wu-toolbox-modular',
        'supports' => ['title', 'author'],
        'capability_type' => 'post',
        'capabilities' => [
            'edit_post' => 'manage_options',
            'read_post' => 'manage_options',
            'delete_post' => 'manage_options',
            'edit_posts' => 'manage_options',
            'edit_others_posts' => 'manage_options',
            'publish_posts' => 'manage_options',
            'read_private_posts' => 'manage_options',
            'delete_posts' => 'manage_options',
            'delete_private_posts' => 'manage_options',
            'delete_published_posts' => 'manage_options',
            'delete_others_posts' => 'manage_options',
            'edit_private_posts' => 'manage_options',
            'edit_published_posts' => 'manage_options',
            'create_posts' => 'do_not_allow',
        ],
        'map_meta_cap' => false,
        'menu_icon' => 'dashicons-email-alt',
    ]);
});

add_action('add_meta_boxes_' . WUTM_SMB_POST_TYPE, function (): void {
    add_meta_box('wutm_smb_detail', '留言內容與回覆', 'wutm_smb_meta_box', WUTM_SMB_POST_TYPE, 'normal', 'high');
});

function wutm_smb_meta_box(WP_Post $post): void {
    wp_nonce_field('wutm_smb_save_reply', 'wutm_smb_reply_nonce');
    $method = get_post_meta($post->ID, '_wutm_smb_method', true);
    $contact = get_post_meta($post->ID, '_wutm_smb_contact', true);
    $message = get_post_meta($post->ID, '_wutm_smb_message', true);
    $reply = get_post_meta($post->ID, '_wutm_smb_reply', true);
    ?>
    <table class="widefat striped" style="margin-bottom:18px"><tbody>
        <tr><th style="width:150px">聯絡方式</th><td><?php echo esc_html($method === 'phone' ? '電話' : 'Email'); ?></td></tr>
        <tr><th>聯絡資料</th><td><?php echo esc_html($contact); ?></td></tr>
        <tr><th>留言內容</th><td><?php echo nl2br(esc_html($message)); ?></td></tr>
    </tbody></table>
    <p><label for="wutm_smb_reply"><strong>管理員回覆</strong></label></p>
    <textarea id="wutm_smb_reply" name="wutm_smb_reply" rows="7" style="width:100%"><?php echo esc_textarea($reply); ?></textarea>
    <p class="description">第一次儲存回覆時，若顧客留下有效 Email，系統會寄出回覆通知；電話留言只保留後台回覆紀錄。</p>
    <?php
}

add_action('save_post_' . WUTM_SMB_POST_TYPE, function (int $post_id): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id) || !current_user_can('manage_options')) return;
    $nonce = isset($_POST['wutm_smb_reply_nonce']) && is_string($_POST['wutm_smb_reply_nonce']) ? sanitize_text_field(wp_unslash($_POST['wutm_smb_reply_nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'wutm_smb_save_reply')) return;

    $reply = isset($_POST['wutm_smb_reply']) && is_string($_POST['wutm_smb_reply']) ? sanitize_textarea_field(wp_unslash($_POST['wutm_smb_reply'])) : '';
    update_post_meta($post_id, '_wutm_smb_reply', $reply);

    if ($reply === '' || get_post_meta($post_id, '_wutm_smb_email_sent', true)) return;
    if (get_post_meta($post_id, '_wutm_smb_method', true) !== 'email') return;
    $recipient = sanitize_email((string) get_post_meta($post_id, '_wutm_smb_contact', true));
    if (!$recipient || !is_email($recipient)) return;

    $subject = sprintf('[%s] 您的留言已有回覆', wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
    $body = "您好：\n\n您的留言：\n" . get_post_meta($post_id, '_wutm_smb_message', true) . "\n\n回覆：\n" . $reply . "\n\n謝謝您。";
    if (wp_mail($recipient, $subject, $body)) update_post_meta($post_id, '_wutm_smb_email_sent', current_time('mysql'));
});

add_filter('manage_' . WUTM_SMB_POST_TYPE . '_posts_columns', function (array $columns): array {
    return [
        'cb' => $columns['cb'] ?? '<input type="checkbox">',
        'title' => '留言編號',
        'wutm_smb_contact' => '聯絡資料',
        'wutm_smb_message' => '留言摘要',
        'wutm_smb_status' => '回覆狀態',
        'date' => '留言時間',
    ];
});

add_action('manage_' . WUTM_SMB_POST_TYPE . '_posts_custom_column', function (string $column, int $post_id): void {
    if ($column === 'wutm_smb_contact') {
        $method = get_post_meta($post_id, '_wutm_smb_method', true) === 'phone' ? '電話' : 'Email';
        echo '<strong>' . esc_html($method) . '</strong><br>' . esc_html((string) get_post_meta($post_id, '_wutm_smb_contact', true));
    } elseif ($column === 'wutm_smb_message') {
        echo esc_html(wp_trim_words((string) get_post_meta($post_id, '_wutm_smb_message', true), 24, '…'));
    } elseif ($column === 'wutm_smb_status') {
        $replied = trim((string) get_post_meta($post_id, '_wutm_smb_reply', true)) !== '';
        echo $replied ? '<span style="color:#008a20;font-weight:600">已回覆</span>' : '<span style="color:#996800">待回覆</span>';
    }
}, 10, 2);

function wutm_smb_client_key(string $contact = ''): string {
    $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    return 'wutm_smb_rate_' . md5($ip . '|' . $agent . '|' . strtolower($contact));
}

function wutm_smb_submit(): void {
    check_ajax_referer('wutm_smb_submit', 'nonce');
    $honeypot = isset($_POST['website']) && is_string($_POST['website']) ? trim(wp_unslash($_POST['website'])) : '';
    if ($honeypot !== '') wp_send_json_error(['message' => '無法送出留言。'], 400);
    $method = isset($_POST['method']) && is_string($_POST['method']) ? sanitize_key(wp_unslash($_POST['method'])) : '';
    $contact_raw = isset($_POST['contact']) && is_string($_POST['contact']) ? trim(wp_unslash($_POST['contact'])) : '';
    $message = isset($_POST['message']) && is_string($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
    if (!in_array($method, ['email', 'phone'], true)) wp_send_json_error(['message' => '請選擇聯絡方式。'], 400);

    if ($method === 'email') {
        $contact = sanitize_email($contact_raw);
        if (!$contact || !is_email($contact)) wp_send_json_error(['message' => '請輸入有效的 Email。'], 400);
    } else {
        $contact = sanitize_text_field($contact_raw);
        if (!preg_match('/^[0-9+()#\-\s]{6,30}$/', $contact)) wp_send_json_error(['message' => '請輸入有效的聯絡電話。'], 400);
    }
    if ($message === '') wp_send_json_error(['message' => '請輸入留言內容。'], 400);
    if (strlen($message) > 6000) wp_send_json_error(['message' => '留言內容過長，請縮短後再送出。'], 400);
    $rate_key = wutm_smb_client_key($contact);
    if (get_transient($rate_key)) wp_send_json_error(['message' => '請稍候片刻再送出留言。'], 429);

    $post_id = wp_insert_post([
        'post_type' => WUTM_SMB_POST_TYPE,
        'post_status' => 'publish',
        'post_title' => '留言 ' . current_time('Y-m-d H:i:s'),
        'post_author' => get_current_user_id(),
    ], true);
    if (is_wp_error($post_id)) wp_send_json_error(['message' => '暫時無法儲存留言，請稍後再試。'], 500);

    update_post_meta($post_id, '_wutm_smb_method', $method);
    update_post_meta($post_id, '_wutm_smb_contact', $contact);
    update_post_meta($post_id, '_wutm_smb_message', $message);
    set_transient($rate_key, 1, 30);
    wp_send_json_success(['message' => wutm_smb_options()['success_message']]);
}
add_action('wp_ajax_wutm_smb_submit', 'wutm_smb_submit');
add_action('wp_ajax_nopriv_wutm_smb_submit', 'wutm_smb_submit');

function wutm_smb_shortcode($atts): string {
    $o = wutm_smb_options();
    $atts = shortcode_atts([
        'mode' => 'inline',
        'display' => 'both',
        'button_text' => $o['button_text'],
        'accent_color' => $o['accent_color'],
        'button_text_color' => $o['button_text_color'],
        'btn_text' => '',
        'color' => '',
        'text_color' => '',
        'icon_color' => '',
        'trigger_color' => '',
        'trigger_text' => $o['trigger_text'],
    ], $atts, 'wutm_message_board');
    $mode = $atts['mode'] === 'popup' ? 'popup' : 'inline';
    $display = in_array($atts['display'], ['icon', 'text', 'both'], true) ? $atts['display'] : 'both';
    $accent = sanitize_hex_color($atts['color']) ?: (sanitize_hex_color($atts['accent_color']) ?: $o['accent_color']);
    $button_color = sanitize_hex_color($atts['text_color']) ?: (sanitize_hex_color($atts['button_text_color']) ?: $o['button_text_color']);
    $icon_color = sanitize_hex_color($atts['trigger_color']) ?: (sanitize_hex_color($atts['icon_color']) ?: $accent);
    $legacy_button_text = sanitize_text_field((string) $atts['btn_text']);
    $button_text = $legacy_button_text ?: (sanitize_text_field((string) $atts['button_text']) ?: $o['button_text']);
    $trigger_text = sanitize_text_field((string) $atts['trigger_text']) ?: $o['trigger_text'];
    $uid = wp_unique_id('wutm-smb-');
    $form_id = $uid . '-form';
    ob_start();
    ?>
    <div id="<?php echo esc_attr($uid); ?>" class="wutm-smb <?php echo $mode === 'popup' ? 'is-popup' : 'is-inline'; ?>" style="--wutm-smb-width:<?php echo esc_attr(wutm_smb_css_size($o['max_width'])); ?>;--wutm-smb-accent:<?php echo esc_attr($accent); ?>;--wutm-smb-button-color:<?php echo esc_attr($button_color); ?>;--wutm-smb-icon-color:<?php echo esc_attr($icon_color); ?>">
        <?php if ($mode === 'popup'): ?>
            <button type="button" class="wutm-smb-trigger" aria-haspopup="dialog">
                <svg class="wutm-smb-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6A8.38 8.38 0 0 1 12.5 3h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
                <span class="wutm-smb-trigger-text"><?php echo esc_html($trigger_text); ?></span>
            </button>
            <div class="wutm-smb-modal" hidden><div class="wutm-smb-backdrop"></div><div class="wutm-smb-dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($uid); ?>-title"><button type="button" class="wutm-smb-close" aria-label="關閉">×</button>
        <?php endif; ?>
        <div class="wutm-smb-card">
            <h2 id="<?php echo esc_attr($uid); ?>-title"><?php echo esc_html($o['form_title']); ?></h2>
            <?php if ($o['form_intro'] !== ''): ?><p class="wutm-smb-intro"><?php echo nl2br(esc_html($o['form_intro'])); ?></p><?php endif; ?>
            <form id="<?php echo esc_attr($form_id); ?>" class="wutm-smb-form">
                <fieldset><legend>聯絡方式</legend><div class="wutm-smb-methods"><label><input type="radio" name="method" value="email" checked> Email</label><label><input type="radio" name="method" value="phone"> 電話</label></div></fieldset>
                <label class="wutm-smb-field"><span class="wutm-smb-contact-label">Email <b>*</b></span><input class="wutm-smb-contact" type="email" name="contact" autocomplete="email" required maxlength="190" placeholder="name@example.com"></label>
                <label class="wutm-smb-field"><span>留言內容 <b>*</b></span><textarea name="message" rows="6" required maxlength="3000" placeholder="請輸入想詢問的內容"></textarea></label>
                <label class="wutm-smb-hp" aria-hidden="true">網站<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                <button type="submit" class="wutm-smb-submit"><?php echo esc_html($button_text); ?></button>
                <div class="wutm-smb-status" role="status" aria-live="polite"></div>
            </form>
        </div>
        <?php if ($mode === 'popup'): ?></div></div><?php endif; ?>
    </div>
    <style>
        #<?php echo esc_attr($uid); ?>{width:100%;max-width:var(--wutm-smb-width);margin:24px auto;box-sizing:border-box;font:inherit;color:inherit}#<?php echo esc_attr($uid); ?> *{box-sizing:border-box}#<?php echo esc_attr($uid); ?> .wutm-smb-card{padding:30px;border:1px solid #e1e4e8;border-radius:14px;background:#fff;box-shadow:0 8px 28px rgba(20,30,50,.06)}#<?php echo esc_attr($uid); ?> h2{margin:0 0 8px;font-size:26px;line-height:1.35}#<?php echo esc_attr($uid); ?> .wutm-smb-intro{margin:0 0 24px;color:#5f6875}#<?php echo esc_attr($uid); ?> fieldset{display:flex!important;align-items:center!important;gap:20px!important;margin:0 0 20px!important;padding:0!important;border:0!important;min-width:0!important}#<?php echo esc_attr($uid); ?> legend{display:block!important;width:100%!important;margin:0 0 9px!important;padding:0!important;font-weight:700!important;line-height:24px!important}#<?php echo esc_attr($uid); ?> fieldset label{display:inline-flex!important;align-items:center!important;gap:6px!important;height:24px!important;margin:0!important;padding:0!important;line-height:24px!important;cursor:pointer}#<?php echo esc_attr($uid); ?> fieldset input[type=radio]{display:inline-block!important;flex:0 0 16px!important;width:16px!important;height:16px!important;min-width:16px!important;min-height:16px!important;margin:0!important;padding:0!important;vertical-align:middle!important}#<?php echo esc_attr($uid); ?> .wutm-smb-field{display:block;margin-bottom:18px;font-weight:600}#<?php echo esc_attr($uid); ?> .wutm-smb-field>span{display:block;margin-bottom:8px}#<?php echo esc_attr($uid); ?> b{color:#d63638}#<?php echo esc_attr($uid); ?> input[type=email],#<?php echo esc_attr($uid); ?> input[type=tel],#<?php echo esc_attr($uid); ?> textarea{display:block;width:100%;padding:12px 14px;border:1px solid #cfd5dc;border-radius:8px;background:#fff;color:#1d2327;font:inherit;line-height:1.5}#<?php echo esc_attr($uid); ?> input:focus,#<?php echo esc_attr($uid); ?> textarea:focus{border-color:var(--wutm-smb-accent);outline:2px solid color-mix(in srgb,var(--wutm-smb-accent) 20%,transparent);outline-offset:1px}#<?php echo esc_attr($uid); ?> .wutm-smb-submit{padding:12px 24px;border:0;border-radius:8px;background:var(--wutm-smb-accent);color:var(--wutm-smb-button-color);font:inherit;font-weight:700;cursor:pointer}#<?php echo esc_attr($uid); ?> .wutm-smb-submit:disabled{opacity:.6;cursor:wait}#<?php echo esc_attr($uid); ?> .wutm-smb-status{margin-top:14px;font-weight:600}#<?php echo esc_attr($uid); ?> .wutm-smb-status.is-success{color:#008a20}#<?php echo esc_attr($uid); ?> .wutm-smb-status.is-error{color:#b32d2e}#<?php echo esc_attr($uid); ?> .wutm-smb-hp{position:absolute!important;left:-10000px!important;width:1px!important;height:1px!important;overflow:hidden!important}#<?php echo esc_attr($uid); ?> .wutm-smb-trigger{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:6px!important;vertical-align:middle!important;margin:0!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;color:var(--wutm-smb-icon-color)!important;font:inherit!important;line-height:1!important;text-decoration:none!important;cursor:pointer!important}#<?php echo esc_attr($uid); ?> .wutm-smb-trigger:hover,#<?php echo esc_attr($uid); ?> .wutm-smb-trigger:focus{background:transparent!important;color:var(--wutm-smb-icon-color)!important}#<?php echo esc_attr($uid); ?> .wutm-smb-icon{display:block!important;flex:0 0 22px!important;width:22px!important;height:22px!important;margin:0!important;padding:0!important;color:inherit!important}#<?php echo esc_attr($uid); ?> .wutm-smb-trigger-text{margin:0!important;padding:0!important;background:transparent!important;color:inherit!important;font-size:15px!important;font-weight:500!important;line-height:1!important}<?php if ($display === 'icon'): ?>#<?php echo esc_attr($uid); ?> .wutm-smb-trigger-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}<?php elseif ($display === 'text'): ?>#<?php echo esc_attr($uid); ?> .wutm-smb-icon{display:none!important}<?php endif; ?>#<?php echo esc_attr($uid); ?> .wutm-smb-modal{position:fixed;z-index:999999;inset:0;padding:24px;overflow:auto}#<?php echo esc_attr($uid); ?> .wutm-smb-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.56)}#<?php echo esc_attr($uid); ?> .wutm-smb-dialog{position:relative;z-index:1;width:min(100%,var(--wutm-smb-width));margin:5vh auto}#<?php echo esc_attr($uid); ?> .wutm-smb-close{position:absolute;z-index:2;top:10px;right:12px;width:40px;height:40px;padding:0;border:0;background:transparent;color:#555;font-size:30px;line-height:1;cursor:pointer}@media(max-width:600px){#<?php echo esc_attr($uid); ?>{padding:0 12px}#<?php echo esc_attr($uid); ?> .wutm-smb-card{padding:22px 18px}#<?php echo esc_attr($uid); ?> .wutm-smb-modal{padding:10px}#<?php echo esc_attr($uid); ?> .wutm-smb-dialog{margin:2vh auto}}
        #<?php echo esc_attr($uid); ?> fieldset{display:block!important}#<?php echo esc_attr($uid); ?> .wutm-smb-methods{display:flex!important;align-items:center!important;gap:20px!important;height:24px!important;margin:0!important;padding:0!important}
        #<?php echo esc_attr($uid); ?>.is-popup{display:inline-block!important;width:auto!important;max-width:none!important;margin:0!important;padding:0!important;vertical-align:middle!important}
    </style>
    <script>
    (function(){
        const root=document.getElementById(<?php echo wp_json_encode($uid); ?>);if(!root)return;
        const form=root.querySelector('.wutm-smb-form'),status=root.querySelector('.wutm-smb-status'),contact=form.querySelector('.wutm-smb-contact'),contactLabel=form.querySelector('.wutm-smb-contact-label');
        form.addEventListener('change',function(e){if(e.target.name!=='method')return;const email=e.target.value==='email';contact.type=email?'email':'tel';contact.autocomplete=email?'email':'tel';contact.placeholder=email?'name@example.com':'0912-345-678';contactLabel.innerHTML=(email?'Email':'電話')+' <b>*</b>';});
        form.addEventListener('submit',async function(e){e.preventDefault();if(!form.reportValidity())return;const button=form.querySelector('.wutm-smb-submit');button.disabled=true;status.className='wutm-smb-status';status.textContent='留言送出中…';const data=new FormData(form);data.append('action','wutm_smb_submit');data.append('nonce',<?php echo wp_json_encode(wp_create_nonce('wutm_smb_submit')); ?>);try{const response=await fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,{method:'POST',credentials:'same-origin',body:data}),json=await response.json();if(!json.success)throw new Error(json.data&&json.data.message?json.data.message:'送出失敗，請稍後再試。');status.classList.add('is-success');status.textContent=json.data.message;form.reset();contact.type='email';contact.autocomplete='email';contact.placeholder='name@example.com';contactLabel.innerHTML='Email <b>*</b>';}catch(error){status.classList.add('is-error');status.textContent=error.message||'送出失敗，請稍後再試。';}finally{button.disabled=false;}});
        const trigger=root.querySelector('.wutm-smb-trigger'),modal=root.querySelector('.wutm-smb-modal');if(!trigger||!modal)return;let previous=null;function open(){previous=document.activeElement;modal.hidden=false;document.body.style.overflow='hidden';modal.querySelector('.wutm-smb-close').focus();}function close(){modal.hidden=true;document.body.style.overflow='';if(previous&&previous.focus)previous.focus();}trigger.addEventListener('click',open);modal.querySelector('.wutm-smb-close').addEventListener('click',close);modal.querySelector('.wutm-smb-backdrop').addEventListener('click',close);document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!modal.hidden)close();});
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}
add_shortcode('wutm_message_board', 'wutm_smb_shortcode');
add_shortcode('simple_contact_form', 'wutm_smb_shortcode');
