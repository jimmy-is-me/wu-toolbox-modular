<?php
defined('ABSPATH') || exit;
/**
 * Wu Contact Form - 可自訂欄位聯絡表單
 * 短碼:[wu_contact_form]
 */

define('WUTM_CF_OPT', 'khmf_fields');

function wutm_cf_default_fields() {
    return [
        ['label' => '姓名',     'type' => 'text',     'required' => 1, 'options' => ''],
        ['label' => 'Email',    'type' => 'email',    'required' => 1, 'options' => ''],
        ['label' => '電話',     'type' => 'tel',      'required' => 0, 'options' => ''],
        ['label' => '留言內容', 'type' => 'textarea', 'required' => 1, 'options' => ''],
    ];
}
function wutm_cf_fields() {
    $f = get_option(WUTM_CF_OPT);
    return (is_array($f) && $f) ? array_values($f) : wutm_cf_default_fields();
}
function wutm_cf_types() {
    return ['text' => '單行文字', 'email' => 'Email', 'tel' => '電話', 'textarea' => '多行文字', 'select' => '下拉選單', 'number' => '數字'];
}

// 1. 後台留言類型
add_action('init', function () {
    register_post_type('wu_entry', [
        'labels' => ['name' => '表單留言', 'singular_name' => '表單留言', 'menu_name' => '表單留言'],
        'public' => false, 'show_ui' => true,
        'show_in_menu' => 'wu-toolbox-modular',
        'supports' => ['title'],
        'capability_type' => 'post', 'capabilities' => ['edit_posts'=>'manage_options','edit_others_posts'=>'manage_options','publish_posts'=>'manage_options','read_private_posts'=>'manage_options','delete_posts'=>'manage_options','delete_others_posts'=>'manage_options','delete_private_posts'=>'manage_options','delete_published_posts'=>'manage_options','edit_private_posts'=>'manage_options','edit_published_posts'=>'manage_options','create_posts'=>'do_not_allow'],
        
        'map_meta_cap' => true,
    ]);
});

// 2. 設定頁
add_action('admin_menu', function () {
    add_submenu_page('wu-toolbox-modular', '聯絡表單', '聯絡表單', 'manage_options', 'wu-contact-form', 'wutm_cf_settings_page');
});

add_action('admin_init', function () {
    register_setting('wutm_cf_group', 'khmf_recipient', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wutm_cf_group', 'khmf_subject', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wutm_cf_group', 'khmf_captcha', ['sanitize_callback' => 'absint']);
    register_setting('wutm_cf_group', WUTM_CF_OPT, ['sanitize_callback' => function ($in) {
        $out = [];
        $types = wutm_cf_types();
        if (!is_array($in)) return wutm_cf_default_fields();
        foreach ($in as $row) {
            if (!is_array($row)) continue;
            $label = sanitize_text_field(is_scalar($row['label']??'')?($row['label']??''):'');
            if ($label === '') continue;
            $type = is_string($row['type'] ?? null) ? $row['type'] : 'text';
            if (!isset($types[$type])) $type = 'text';
            $out[] = [
                'label'    => $label,
                'type'     => $type,
                'required' => !empty($row['required']) ? 1 : 0,
                'options'  => sanitize_textarea_field(is_scalar($row['options']??'')?($row['options']??''):'') ,
            ];
        }
        return $out ?: wutm_cf_default_fields();
    }]);
});

function wutm_cf_row_html($i, $f) {
    $types = wutm_cf_types();
    ob_start(); ?>
    <tr class="khmf-row">
        <td><input type="text" name="<?php echo WUTM_CF_OPT; ?>[<?php echo $i; ?>][label]" value="<?php echo esc_attr($f['label']); ?>" class="regular-text" placeholder="欄位標籤"></td>
        <td><select name="<?php echo WUTM_CF_OPT; ?>[<?php echo $i; ?>][type]">
            <?php foreach ($types as $k => $v) echo '<option value="' . esc_attr($k) . '"' . selected($f['type'], $k, false) . '>' . esc_html($v) . '</option>'; ?>
        </select></td>
        <td style="text-align:center"><input type="checkbox" name="<?php echo WUTM_CF_OPT; ?>[<?php echo $i; ?>][required]" value="1" <?php checked($f['required'], 1); ?>></td>
        <td><textarea name="<?php echo WUTM_CF_OPT; ?>[<?php echo $i; ?>][options]" rows="2" placeholder="下拉選項,一行一個" style="width:100%"><?php echo esc_textarea($f['options']); ?></textarea></td>
        <td>
            <button type="button" class="button khmf-up">↑</button>
            <button type="button" class="button khmf-down">↓</button>
            <button type="button" class="button khmf-del">刪除</button>
        </td>
    </tr>
    <?php return ob_get_clean();
}

function wutm_cf_settings_page() { if (!current_user_can('manage_options')) return; wutm_admin_page_open('聯絡表單', '短碼：[wu_contact_form]。');
    $fields = wutm_cf_fields(); ?>
    <div class="wrap">
        <h1>表單設定</h1>
        <p>短碼:<code>[wu_contact_form]</code></p>
        <form method="post" action="options.php">
            <?php settings_fields('wutm_cf_group'); ?>
            <h2>基本設定</h2>
            <table class="form-table">
                <tr><th>收件 Email</th><td><input type="text" name="khmf_recipient" class="regular-text" value="<?php echo esc_attr(get_option('khmf_recipient', get_option('admin_email'))); ?>"><p class="description">多個信箱用逗號分隔</p></td></tr>
                <tr><th>郵件主旨</th><td><input type="text" name="khmf_subject" class="regular-text" value="<?php echo esc_attr(get_option('khmf_subject', '網站聯絡表單')); ?>"></td></tr>
                <tr><th>算術驗證</th><td><label><input type="checkbox" name="khmf_captcha" value="1" <?php checked(get_option('khmf_captcha', 1), 1); ?>> 啟用(例如 3+5=?)</label></td></tr>
            </table>
            <h2>欄位管理</h2>
            <div class="wutm-ui-table-scroll"><table class="widefat" style="max-width:1000px">
                <thead><tr><th>標籤</th><th>類型</th><th>必填</th><th>下拉選項</th><th>操作</th></tr></thead>
                <tbody id="khmf-body">
                    <?php foreach ($fields as $i => $f) echo wutm_cf_row_html($i, $f); ?>
                </tbody>
            </table>
            </div><p><button type="button" class="button" id="khmf-add">+ 新增欄位</button></p>
            <?php submit_button(); ?>
        </form>
    </div>
    <script type="text/template" id="khmf-tpl"><?php echo wutm_cf_row_html('__I__', ['label' => '', 'type' => 'text', 'required' => 0, 'options' => '']); ?></script>
    <script>
    (function(){
        var body = document.getElementById('khmf-body');
        var tpl = document.getElementById('khmf-tpl').innerHTML;
        function reindex(){
            body.querySelectorAll('tr').forEach(function(tr, i){
                tr.querySelectorAll('[name]').forEach(function(el){
                    el.name = el.name.replace(/\[\d+\]|\[__I__\]/, '[' + i + ']');
                });
            });
        }
        document.getElementById('khmf-add').onclick = function(){
            body.insertAdjacentHTML('beforeend', tpl.replace(/__I__/g, body.children.length));
            reindex();
        };
        body.addEventListener('click', function(e){
            var tr = e.target.closest('tr'); if(!tr) return;
            if (e.target.classList.contains('khmf-del')) { tr.remove(); }
            if (e.target.classList.contains('khmf-up') && tr.previousElementSibling) { body.insertBefore(tr, tr.previousElementSibling); }
            if (e.target.classList.contains('khmf-down') && tr.nextElementSibling) { body.insertBefore(tr.nextElementSibling, tr); }
            reindex();
        });
    })();
    </script>
<?php wutm_admin_page_close(); }

// 3. 後台列表欄位
add_filter('manage_wu_entry_posts_columns', function () {
    return ['cb' => '<input type="checkbox">', 'title' => '標題', 'wutm_cf_summary' => '內容摘要', 'date' => '時間'];
});
add_action('manage_wu_entry_posts_custom_column', function ($col, $id) {
    if ($col !== 'wutm_cf_summary') return;
    $data = get_post_meta($id, '_khmf_data', true);
    if (!is_array($data)) return;
    $parts = [];
    foreach ($data as $row) $parts[] = $row['label'] . ':' . wp_trim_words($row['value'], 10);
    echo esc_html(implode(' | ', $parts));
}, 10, 2);

add_action('add_meta_boxes', function () {
    add_meta_box('wutm_cf_detail', '留言內容', function ($post) {
        $data = get_post_meta($post->ID, '_khmf_data', true);
        if (is_array($data)) foreach ($data as $row) {
            echo '<p><strong>' . esc_html($row['label']) . ':</strong><br>' . nl2br(esc_html($row['value'])) . '</p>';
        }
        echo '<p><strong>IP:</strong> ' . esc_html(get_post_meta($post->ID, '_khmf_ip', true)) . '</p>';
    }, 'wu_entry', 'normal', 'high');
});

// 4. 算術驗證(用簽章 token,不需要 session,也不怕多人同時使用)
function wutm_cf_make_captcha() {
    $a = wp_rand(1, 9); $b = wp_rand(1, 9);
    $exp = time() + 3600;
    $sig = wp_hash($a + $b . '|' . $exp);
    return ['q' => "$a + $b", 'token' => ($a + $b) . '|' . $exp . '|' . $sig];
}
function wutm_cf_check_captcha($token, $answer) {
    if (!is_string($token)||!is_scalar($answer)||!preg_match('/^\d+$/D',(string)$answer)) return false;
    $p = explode('|', (string) $token);
    if (count($p) !== 3) return false;
    list($sum, $exp, $sig) = $p;
    if ((int) $exp < time()) return false;
    if (!hash_equals(wp_hash($sum . '|' . $exp), $sig)) return false;
    return (int) $answer === (int) $sum;
}

// 5. 短碼
add_shortcode('wu_contact_form', function () {
    $msg = '';
    $s = $_GET['khmf'] ?? '';
    if ($s === 'ok')  $msg = '<div class="khmf-notice khmf-ok">感謝您的來信,我們會盡快回覆。</div>';
    if ($s === 'err') $msg = '<div class="khmf-notice khmf-err">送出失敗,請檢查欄位與驗證答案後再試一次。</div>';

    $fields = wutm_cf_fields();
    $cap = get_option('khmf_captcha', 1) ? wutm_cf_make_captcha() : null;

    ob_start(); ?>
    <style>
        .khmf-form{max-width:600px}
        .khmf-form p{margin:0 0 14px}
        .khmf-form label{display:block;margin-bottom:4px;font-weight:600}
        .khmf-form input[type=text],.khmf-form input[type=email],.khmf-form input[type=tel],.khmf-form input[type=number],.khmf-form select,.khmf-form textarea{width:100%;padding:10px;border:1px solid #ccc;border-radius:4px;box-sizing:border-box}
        .khmf-form button{padding:10px 28px;background:#222;color:#fff;border:0;border-radius:4px;cursor:pointer}
        .khmf-notice{padding:10px 14px;margin-bottom:14px;border-radius:4px}
        .khmf-ok{background:#e6f6e6;color:#1a6b1a}
        .khmf-err{background:#fde8e8;color:#a11}
        .khmf-hp{position:absolute;left:-9999px}
    </style>
    <?php echo $msg; ?>
    <form class="khmf-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="wutm_cf_submit">
        <input type="hidden" name="wutm_cf_back" value="<?php echo esc_url(get_permalink() ?: home_url('/')); ?>">
        <?php wp_nonce_field('wutm_cf_submit', 'wutm_cf_nonce'); ?>
        <div class="khmf-hp"><input type="text" name="wutm_cf_website" tabindex="-1" autocomplete="off"></div>
        <?php foreach ($fields as $i => $f):
            $req = $f['required'] ? ' required' : '';
            $name = 'wutm_cf_f_' . $i;
            echo '<p><label>' . esc_html($f['label']) . ($f['required'] ? ' *' : '') . '</label>';
            if ($f['type'] === 'textarea') {
                echo '<textarea name="' . $name . '" rows="6"' . $req . '></textarea>';
            } elseif ($f['type'] === 'select') {
                echo '<select name="' . $name . '"' . $req . '><option value="">請選擇</option>';
                foreach (array_filter(array_map('trim', explode("\n", $f['options']))) as $o) echo '<option value="' . esc_attr($o) . '">' . esc_html($o) . '</option>';
                echo '</select>';
            } else {
                echo '<input type="' . esc_attr($f['type']) . '" name="' . $name . '"' . $req . '>';
            }
            echo '</p>';
        endforeach; ?>
        <?php if ($cap): ?>
            <p><label>驗證:<?php echo esc_html($cap['q']); ?> = ? *</label>
            <input type="number" name="wutm_cf_answer" required>
            <input type="hidden" name="wutm_cf_token" value="<?php echo esc_attr($cap['token']); ?>"></p>
        <?php endif; ?>
        <p><button type="submit">送出</button></p>
    </form>
    <?php return ob_get_clean();
});

// 6. 處理送出
add_action('admin_post_nopriv_wutm_cf_submit', 'wutm_cf_handle');
add_action('admin_post_wutm_cf_submit', 'wutm_cf_handle');
function wutm_cf_handle() {
    foreach ($_POST as $value) if (!is_scalar($value)) {wp_safe_redirect(home_url('/'));exit;}
    $back = isset($_POST['wutm_cf_back']) ? esc_url_raw(wp_unslash($_POST['wutm_cf_back'])) : home_url('/');
    $back = wp_validate_redirect($back, home_url('/'));
    $fail = add_query_arg('khmf', 'err', $back);
    $ok   = add_query_arg('khmf', 'ok', $back);

    if (!isset($_POST['wutm_cf_nonce']) || !wp_verify_nonce($_POST['wutm_cf_nonce'], 'wutm_cf_submit')) { wp_safe_redirect($fail); exit; }
    if (!empty($_POST['wutm_cf_website'])) { wp_safe_redirect($ok); exit; }

    if (get_option('khmf_captcha', 1)) {
        if (!wutm_cf_check_captcha(wp_unslash($_POST['wutm_cf_token'] ?? ''), wp_unslash($_POST['wutm_cf_answer'] ?? ''))) { wp_safe_redirect($fail); exit; }
    }

    $data = [];
    $first_email = '';
    $first_text = '';
    foreach (wutm_cf_fields() as $i => $f) {
        $raw = wp_unslash($_POST['wutm_cf_f_' . $i] ?? '');
        switch ($f['type']) {
            case 'email':    $v = sanitize_email($raw); if ($v && !is_email($v)) $v = ''; break;
            case 'textarea': $v = sanitize_textarea_field($raw); break;
            case 'select':
                $opts = array_map('trim', explode("\n", $f['options']));
                $v = in_array($raw, $opts, true) ? $raw : ''; break;
            case 'number':   $v = is_numeric($raw) ? (string) $raw : ''; break;
            default:         $v = sanitize_text_field($raw);
        }
        if ($f['required'] && $v === '') { wp_safe_redirect($fail); exit; }
        $data[] = ['label' => $f['label'], 'value' => $v];
        if (!$first_email && $f['type'] === 'email' && $v) $first_email = $v;
        if (!$first_text && $f['type'] === 'text' && $v) $first_text = $v;
    }

    $ip  = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
    $key = 'wutm_cf_rl_' . md5($ip);
    if (get_transient($key)) { wp_safe_redirect($fail); exit; }
    set_transient($key, 1, 30);

    $title = $first_text ?: ('留言 ' . current_time('Y-m-d H:i'));
    $id = wp_insert_post(['post_type' => 'wu_entry', 'post_status' => 'publish', 'post_title' => $title]);
    if (!$id || is_wp_error($id)) {wp_safe_redirect($fail);exit;}
    if ($id && !is_wp_error($id)) {
        update_post_meta($id, '_khmf_data', $data);
        update_post_meta($id, '_khmf_ip', $ip);
    }

    $to = array_values(array_filter(array_map('sanitize_email', explode(',', get_option('khmf_recipient', get_option('admin_email')))),'is_email'));
    $subject = get_option('khmf_subject', '網站聯絡表單') . ' - ' . $title;
    $body = '';
    foreach ($data as $row) $body .= $row['label'] . ':' . $row['value'] . "\n";
    $body .= "\nIP:$ip";
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    if ($first_email) $headers[] = 'Reply-To: ' . $first_email;
    wp_mail($to, $subject, $body, $headers);

    wp_safe_redirect($ok);
    exit;
}
