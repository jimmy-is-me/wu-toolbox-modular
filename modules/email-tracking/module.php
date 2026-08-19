<?php
/**
 * Module: email-tracking
 * Loaded only when enabled in WU Toolbox Modular.
 */

if (!defined('ABSPATH')) exit;

/*
 * WumetaxToolkit - Email Tracking & Management System
 * Version: 3.6
 *
 * 修改紀錄:
 * - 移除密碼保護
 * - 效能優化：DB 查詢快取、合併查詢、減少 SHOW TABLES 呼叫
 */

// ===== Debug 日誌功能 =====

function wu_debug_log($message) {
    if (!get_option('wu_email_debug_enabled', 0)) return;
    $log_file  = WP_CONTENT_DIR . '/wu-email-tracker-debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log('[WU Email Tracker] ' . $message);
    }
}

// ===== 資料表版本管理 =====

define('WU_EMAIL_TRACKER_DB_VERSION', '1.1');

function wu_email_tracker_install() {
    global $wpdb;
    $table_name      = $wpdb->prefix . 'wu_email_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        to_email varchar(255) NOT NULL,
        subject text NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'sent',
        error_message text,
        sent_time datetime DEFAULT CURRENT_TIMESTAMP,
        send_method varchar(50) DEFAULT 'default',
        PRIMARY KEY  (id),
        KEY sent_time (sent_time),
        KEY status (status)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
        wu_email_tracker_check_columns($table_name);
        update_option('wu_email_tracker_db_version', WU_EMAIL_TRACKER_DB_VERSION);
        // 更新快取標記
        set_transient('wu_email_table_ok', 1, WEEK_IN_SECONDS);
    }
}

function wu_email_tracker_check_columns($table_name) {
    global $wpdb;
    $columns = $wpdb->get_col("SHOW COLUMNS FROM $table_name", 0);
    if (!in_array('send_method', $columns, true)) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN send_method varchar(50) DEFAULT 'default' AFTER sent_time");
    }
}

// ✅ 優化：用 transient 快取表存在狀態，避免每次 admin_init 都跑 SHOW TABLES
add_action('admin_init', function() {
    if (get_transient('wu_email_table_ok')) return;
    global $wpdb;
    $table_name = $wpdb->prefix . 'wu_email_logs';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        wu_email_tracker_install();
    } else {
        set_transient('wu_email_table_ok', 1, WEEK_IN_SECONDS);
    }
});

// ✅ 優化：版本检查加快取，避免重複執行
add_action('plugins_loaded', function() {
    $current_version = get_option('wu_email_tracker_db_version', '0');
    if (version_compare($current_version, WU_EMAIL_TRACKER_DB_VERSION, '<')) {
        delete_transient('wu_email_table_ok');
        wu_email_tracker_install();
    }
}, 20);

// ===== Options Initialization =====

// ✅ 優化：改用 wp_cache + 只在尚未設定時才初始化
add_action('admin_init', function() {
    static $initialized = false;
    if ($initialized) return;
    $initialized = true;

    $defaults = [
        'wu_email_tracker_enabled'  => 1,
        'wu_email_daily_limit'      => 20,
        'wu_email_monthly_limit'    => 600,
        'wu_email_discord_webhook'  => '',
        'wu_email_send_method'      => 'default',
        'wu_email_resend_api_key'   => '',
        'wu_email_resend_from_email'=> 'mail@mail.wulk.cc',
        'wu_email_resend_from_name' => get_bloginfo('name'),
        'wu_email_brevo_api_key'    => '',
        'wu_email_brevo_from_email' => '',
        'wu_email_brevo_from_name'  => get_bloginfo('name'),
        'wu_email_smtp_host'        => '',
        'wu_email_smtp_port'        => '587',
        'wu_email_smtp_encryption'  => 'tls',
        'wu_email_smtp_username'    => '',
        'wu_email_smtp_password'    => '',
        'wu_email_smtp_from_email'  => 'mail@mail.wulk.cc',
        'wu_email_smtp_from_name'   => get_bloginfo('name'),
        'wu_email_debug_enabled'    => 0,
    ];
    foreach ($defaults as $key => $value) {
        add_option($key, $value);
    }
}, 5);

global $wu_api_email_sent;
$wu_api_email_sent = false;

// ===== Menu Registration =====

add_action('admin_menu', function() {
    add_submenu_page(
        'wumetax-toolkit',
        '郵件追蹤管理',
        '郵件追蹤管理',
        'manage_options',
        'wu-email-tracking',
        'wu_email_tracker_settings_page'
    );
}, 999);

// ===== Dashboard Widget =====

add_action('wp_dashboard_setup', function() {
    if (!get_option('wu_email_tracker_enabled', 1)) return;
    wp_add_dashboard_widget(
        'wu_email_tracker_dashboard',
        '郵件發送追蹤管理',
        'wu_render_email_tracker_dashboard',
        null, null, 'normal', 'high'
    );
});

function wu_render_email_tracker_dashboard() {
    global $wpdb;
    $table              = $wpdb->prefix . 'wu_email_logs';
    $send_method_option = get_option('wu_email_send_method', 'default');

    switch ($send_method_option) {
        case 'resend':
            $send_method = 'Resend API'; $send_status = '已啟用'; $send_color = '#46b450'; break;
        case 'brevo':
            $send_method = 'Brevo API';  $send_status = '已啟用'; $send_color = '#46b450'; break;
        case 'smtp':
            $send_method = '自訂 SMTP';  $send_status = '已啟用'; $send_color = '#46b450'; break;
        default:
            $smtp_info   = wu_detect_smtp_plugin();
            $send_method = $smtp_info['plugin_name'];
            $send_status = $smtp_info['enabled'] ? '正常運作' : '未啟用';
            $send_color  = $smtp_info['enabled'] ? '#46b450' : '#dc3232';
            break;
    }

    // ✅ 優化：合併今日/本月查詢為一次
    $stats = wu_get_email_stats();
    $today_count   = $stats['today_sent'];
    $today_blocked = $stats['today_blocked'];
    $month_count   = $stats['month_sent'];
    $month_blocked = $stats['month_blocked'];

    $daily_limit   = get_option('wu_email_daily_limit', 20);
    $monthly_limit = get_option('wu_email_monthly_limit', 600);
    $daily_percentage  = ($daily_limit > 0)   ? round(($today_count / $daily_limit) * 100, 1)  : 0;
    $monthly_percentage = ($monthly_limit > 0) ? round(($month_count / $monthly_limit) * 100, 1) : 0;
    $daily_status   = wu_get_quota_status($daily_percentage);
    $monthly_status = wu_get_quota_status($monthly_percentage);
    $is_blocked     = ($today_count >= $daily_limit) || ($month_count >= $monthly_limit);
    $daily_reset_time   = date('Y-m-d H:i:s', strtotime('tomorrow 00:00:00'));
    $monthly_reset_time = date('Y-m-d H:i:s', strtotime('first day of next month 00:00:00'));
    ?>
    <div class="wu-dashboard-container">
        <div class="wu-section">
            <h3 class="wu-section-title">
                發信狀態
                <span class="wu-monitoring-badge" style="background:<?php echo $send_color; ?>;"><?php echo $send_status; ?></span>
            </h3>
            <table class="wu-info-table">
                <tbody>
                    <tr>
                        <th>發信方式</th>
                        <td><span class="wu-status-indicator" style="color:<?php echo $send_color; ?>;"><?php echo esc_html($send_method); ?></span></td>
                        <td class="wu-info-meta">
                            <?php
                            $desc_map = [
                                'resend' => '使用 Resend API 服務發送郵件',
                                'brevo'  => '使用 Brevo (Sendinblue) API 發送郵件',
                                'smtp'   => '使用自訂 SMTP 伺服器發送郵件',
                            ];
                            if (isset($desc_map[$send_method_option])) {
                                echo $desc_map[$send_method_option];
                            } else {
                                echo esc_html($smtp_info['description']);
                            }
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="wu-section">
            <h3 class="wu-section-title">發信額度總覽</h3>
            <div style="margin-bottom:25px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <span style="font-size:13px;font-weight:600;color:#1d2327;">今日發送</span>
                    <span style="font-size:13px;color:#50575e;">
                        <strong style="color:<?php echo $daily_status['color']; ?>;"><?php echo $today_count; ?></strong> / <?php echo $daily_limit; ?> 封
                        <?php if ($today_blocked > 0): ?><span style="color:#dc3232;margin-left:10px;">(被阻擋: <?php echo $today_blocked; ?>)</span><?php endif; ?>
                    </span>
                </div>
                <div class="wu-disk-bar"><div class="wu-disk-bar-fill" style="width:<?php echo min($daily_percentage, 100); ?>%;background:<?php echo $daily_status['color']; ?>;"></div></div>
                <div style="margin-top:8px;font-size:12px;color:<?php echo $daily_status['color']; ?>;font-weight:600;"><?php echo $daily_percentage; ?>% - <?php echo $daily_status['text']; ?></div>
                <div style="margin-top:5px;font-size:11px;color:#666;">重設時間: <?php echo $daily_reset_time; ?></div>
            </div>
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <span style="font-size:13px;font-weight:600;color:#1d2327;">本月發送</span>
                    <span style="font-size:13px;color:#50575e;">
                        <strong style="color:<?php echo $monthly_status['color']; ?>;"><?php echo $month_count; ?></strong> / <?php echo $monthly_limit; ?> 封
                        <?php if ($month_blocked > 0): ?><span style="color:#dc3232;margin-left:10px;">(被阻擋: <?php echo $month_blocked; ?>)</span><?php endif; ?>
                    </span>
                </div>
                <div class="wu-disk-bar"><div class="wu-disk-bar-fill" style="width:<?php echo min($monthly_percentage, 100); ?>%;background:<?php echo $monthly_status['color']; ?>;"></div></div>
                <div style="margin-top:8px;font-size:12px;color:<?php echo $monthly_status['color']; ?>;font-weight:600;"><?php echo $monthly_percentage; ?>% - <?php echo $monthly_status['text']; ?></div>
                <div style="margin-top:5px;font-size:11px;color:#666;">重設時間: <?php echo $monthly_reset_time; ?></div>
            </div>
            <?php if ($is_blocked): ?>
            <div class="wu-notice wu-notice-error" style="margin-top:20px;">
                <strong>發信額度已達上限</strong><br>
                <?php if ($today_count >= $daily_limit): ?>今日發信已達到每日限制 (<?php echo $daily_limit; ?> 封)，系統已自動阻擋新郵件發送。<br><?php endif; ?>
                <?php if ($month_count >= $monthly_limit): ?>本月發信已達到每月限制 (<?php echo $monthly_limit; ?> 封)，系統已自動阻擋新郵件發送。<br><?php endif; ?>
            </div>
            <?php elseif ($daily_percentage >= 80 || $monthly_percentage >= 80): ?>
            <div class="wu-notice" style="margin-top:20px;background:#fcf3cf;border-color:#996800;">
                <strong>額度即將達到上限</strong><br>請注意發信數量，避免超過額度限制。
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ===== ✅ 優化：統一查詢函式，減少重複 DB 查詢 =====

function wu_get_email_stats() {
    global $wpdb;
    $table       = $wpdb->prefix . 'wu_email_logs';
    $today_start = date('Y-m-d 00:00:00');
    $month_start = date('Y-m-01 00:00:00');

    // 一次查詢取得所有統計
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT
            SUM(sent_time >= %s AND status = 'sent')    AS today_sent,
            SUM(sent_time >= %s AND status = 'blocked') AS today_blocked,
            SUM(sent_time >= %s AND status = 'sent')    AS month_sent,
            SUM(sent_time >= %s AND status = 'blocked') AS month_blocked,
            COUNT(*) AS total
        FROM $table WHERE sent_time >= %s",
        $today_start, $today_start, $month_start, $month_start, $month_start
    ));

    $row = $rows[0] ?? null;
    return [
        'today_sent'    => (int)($row->today_sent    ?? 0),
        'today_blocked' => (int)($row->today_blocked ?? 0),
        'month_sent'    => (int)($row->month_sent    ?? 0),
        'month_blocked' => (int)($row->month_blocked ?? 0),
        'total'         => (int)($row->total         ?? 0),
    ];
}

// ===== 輔助函式 =====

function wu_detect_smtp_plugin() {
    $info = ['enabled' => true, 'plugin_name' => '系統預設', 'description' => '使用 WordPress 預設郵件功能'];
    if (defined('FLUENTMAIL')) {
        $info['plugin_name'] = 'FluentSMTP';   $info['description'] = '已安裝並啟用 FluentSMTP 外掛';
    } elseif (class_exists('WPMailSMTP\\Options')) {
        $info['plugin_name'] = 'WP Mail SMTP'; $info['description'] = '已安裝並啟用 WP Mail SMTP 外掛';
    } elseif (defined('EASY_WP_SMTP_VERSION')) {
        $info['plugin_name'] = 'Easy WP SMTP'; $info['description'] = '已安裝並啟用 Easy WP SMTP 外掛';
    } elseif (class_exists('PostmanOptions')) {
        $info['plugin_name'] = 'Post SMTP';    $info['description'] = '已安裝並啟用 Post SMTP 外掛';
    }
    return $info;
}

function wu_get_quota_status($percentage) {
    if ($percentage < 70) return ['text' => '額度充足', 'color' => '#46b450'];
    if ($percentage < 90) return ['text' => '接近上限', 'color' => '#f0b849'];
    return ['text' => '達到上限', 'color' => '#dc3232'];
}

// ===== Resend API =====

function wu_send_email_via_resend($to, $subject, $message, $headers = '', $attachments = []) {
    $api_key    = get_option('wu_email_resend_api_key', '');
    $from_email = get_option('wu_email_resend_from_email', '');
    $from_name  = get_option('wu_email_resend_from_name', get_bloginfo('name'));

    if (empty($api_key) || empty($from_email)) {
        return ['success' => false, 'error' => 'API Key 或發件者 Email 未設定'];
    }

    $to_email     = is_array($to) ? $to[0] : $to;
    $html_message = wpautop($message);

    $response = wp_remote_post('https://api.resend.com/emails', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => json_encode([
            'from'    => $from_name . ' <' . $from_email . '>',
            'to'      => [$to_email],
            'subject' => $subject,
            'html'    => $html_message,
        ]),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'error' => $response->get_error_message()];
    }

    $body        = json_decode(wp_remote_retrieve_body($response), true);
    $status_code = wp_remote_retrieve_response_code($response);

    if ($status_code === 200 && isset($body['id'])) {
        return ['success' => true, 'id' => $body['id']];
    }

    $error_msg = $body['message'] ?? 'Unknown error';
    if (isset($body['name'])) $error_msg = $body['name'] . ': ' . $error_msg;
    return ['success' => false, 'error' => $error_msg];
}

// ===== Brevo API =====

function wu_send_email_via_brevo($to, $subject, $message, $headers = '', $attachments = []) {
    $api_key    = get_option('wu_email_brevo_api_key', '');
    $from_email = get_option('wu_email_brevo_from_email', '');
    $from_name  = get_option('wu_email_brevo_from_name', get_bloginfo('name'));

    if (empty($api_key) || empty($from_email)) {
        return ['success' => false, 'error' => 'Brevo API Key 或發件者 Email 未設定'];
    }

    $to_email     = is_array($to) ? $to[0] : $to;
    $html_message = wpautop($message);

    $response = wp_remote_post('https://api.brevo.com/v3/smtp/email', [
        'headers' => [
            'api-key'      => $api_key,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
        'body'    => json_encode([
            'sender'      => ['name' => $from_name, 'email' => $from_email],
            'to'          => [['email' => $to_email]],
            'subject'     => $subject,
            'htmlContent' => $html_message,
        ]),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'error' => $response->get_error_message()];
    }

    $resp_body   = json_decode(wp_remote_retrieve_body($response), true);
    $status_code = wp_remote_retrieve_response_code($response);

    if (in_array($status_code, [200, 201], true) && isset($resp_body['messageId'])) {
        return ['success' => true, 'id' => $resp_body['messageId']];
    }

    $error_msg = $resp_body['message'] ?? 'Unknown error (HTTP ' . $status_code . ')';
    if (isset($resp_body['code'])) $error_msg = $resp_body['code'] . ': ' . $error_msg;
    return ['success' => false, 'error' => $error_msg];
}

// ===== SMTP Configuration =====

add_action('phpmailer_init', function($phpmailer) {
    if (get_option('wu_email_send_method', 'default') !== 'smtp') return;

    $smtp_host       = get_option('wu_email_smtp_host', '');
    $smtp_port       = get_option('wu_email_smtp_port', '587');
    $smtp_encryption = get_option('wu_email_smtp_encryption', 'tls');
    $smtp_username   = get_option('wu_email_smtp_username', '');
    $smtp_password   = get_option('wu_email_smtp_password', '');
    $smtp_from_email = get_option('wu_email_smtp_from_email', '');
    $smtp_from_name  = get_option('wu_email_smtp_from_name', get_bloginfo('name'));

    if (empty($smtp_host)) return;

    $phpmailer->isSMTP();
    $phpmailer->Host       = $smtp_host;
    $phpmailer->Port       = (int)$smtp_port;
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Username   = $smtp_username;
    $phpmailer->Password   = $smtp_password;
    $phpmailer->SMTPSecure = ($smtp_encryption === 'none') ? '' : $smtp_encryption;
    $phpmailer->From       = $smtp_from_email;
    $phpmailer->FromName   = $smtp_from_name;
}, 999);

// ===== 核心追蹤邏輯 =====

add_filter('wp_mail', 'wu_intercept_email', 1);

function wu_intercept_email($args) {
    if (!get_option('wu_email_tracker_enabled', 1)) return $args;

    global $wpdb;
    $table = $wpdb->prefix . 'wu_email_logs';

    // ✅ 優化：用靜態變數快取表存在狀態，一次請求內不重複查詢
    static $table_checked = null;
    if ($table_checked === null) {
        $table_checked = ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table);
        if (!$table_checked) {
            wu_email_tracker_install();
            $table_checked = ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table);
        }
    }
    if (!$table_checked) return $args;

    $to_email = is_array($args['to']) ? implode(', ', $args['to']) : $args['to'];
    $subject  = $args['subject'] ?? '(無主旨)';

    // ✅ 優化：合併今日/本月計數為一次查詢
    $today_start = date('Y-m-d 00:00:00');
    $month_start = date('Y-m-01 00:00:00');
    $counts = $wpdb->get_row($wpdb->prepare(
        "SELECT
            SUM(sent_time >= %s AND status = 'sent') AS today_count,
            SUM(sent_time >= %s AND status = 'sent') AS month_count
        FROM $table WHERE sent_time >= %s",
        $today_start, $month_start, $month_start
    ));

    $today_count   = (int)($counts->today_count ?? 0);
    $month_count   = (int)($counts->month_count ?? 0);
    $daily_limit   = (int)get_option('wu_email_daily_limit', 20);
    $monthly_limit = (int)get_option('wu_email_monthly_limit', 600);

    // 超過額度 → 阻擋
    if ($today_count >= $daily_limit || $month_count >= $monthly_limit) {
        $wpdb->insert($table, [
            'to_email'      => $to_email,
            'subject'       => $subject,
            'status'        => 'blocked',
            'error_message' => '超過每日或每月發信額度限制',
            'send_method'   => get_option('wu_email_send_method', 'default'),
            'sent_time'     => current_time('mysql'),
        ], ['%s','%s','%s','%s','%s','%s']);
        wu_send_quota_alert('blocked', $to_email, $subject);
        $args['to'] = '';
        return $args;
    }

    $send_method = get_option('wu_email_send_method', 'default');

    // ── Resend API ──
    if ($send_method === 'resend') {
        $result = wu_send_email_via_resend(
            $args['to'], $args['subject'], $args['message'],
            $args['headers'] ?? '', $args['attachments'] ?? []
        );
        $wpdb->insert($table, [
            'to_email'      => $to_email,
            'subject'       => $subject,
            'status'        => $result['success'] ? 'sent' : 'failed',
            'send_method'   => 'resend_api',
            'error_message' => $result['success'] ? ('Email ID: ' . $result['id']) : ('Resend API: ' . $result['error']),
            'sent_time'     => current_time('mysql'),
        ], ['%s','%s','%s','%s','%s','%s']);
        if ($result['success']) {
            global $wu_api_email_sent;
            $wu_api_email_sent = true;
        }
        wu_check_quota_warning();
        $args['to'] = '';
        return $args;
    }

    // ── Brevo API ──
    if ($send_method === 'brevo') {
        $result = wu_send_email_via_brevo(
            $args['to'], $args['subject'], $args['message'],
            $args['headers'] ?? '', $args['attachments'] ?? []
        );
        $wpdb->insert($table, [
            'to_email'      => $to_email,
            'subject'       => $subject,
            'status'        => $result['success'] ? 'sent' : 'failed',
            'send_method'   => 'brevo_api',
            'error_message' => $result['success'] ? ('Message ID: ' . $result['id']) : ('Brevo API: ' . $result['error']),
            'sent_time'     => current_time('mysql'),
        ], ['%s','%s','%s','%s','%s','%s']);
        if ($result['success']) {
            global $wu_api_email_sent;
            $wu_api_email_sent = true;
        }
        wu_check_quota_warning();
        $args['to'] = '';
        return $args;
    }

    // ── Default / SMTP ──
    $wpdb->insert($table, [
        'to_email'    => $to_email,
        'subject'     => $subject,
        'status'      => 'sent',
        'send_method' => ($send_method === 'smtp') ? 'smtp' : 'default',
        'sent_time'   => current_time('mysql'),
    ], ['%s','%s','%s','%s','%s']);

    wu_check_quota_warning();
    return $args;
}

function wu_check_quota_warning() {
    $transient_key = 'wu_email_quota_warning_' . date('Y-m-d');
    if (get_transient($transient_key)) return;

    global $wpdb;
    $table = $wpdb->prefix . 'wu_email_logs';
    $counts = $wpdb->get_row($wpdb->prepare(
        "SELECT
            SUM(sent_time >= %s AND status = 'sent') AS today_count,
            SUM(sent_time >= %s AND status = 'sent') AS month_count
        FROM $table WHERE sent_time >= %s",
        date('Y-m-d 00:00:00'), date('Y-m-01 00:00:00'), date('Y-m-01 00:00:00')
    ));

    $daily_pct   = ($d = (int)get_option('wu_email_daily_limit', 20))   ? ((int)$counts->today_count / $d * 100) : 0;
    $monthly_pct = ($m = (int)get_option('wu_email_monthly_limit', 600)) ? ((int)$counts->month_count / $m * 100) : 0;

    if ($daily_pct >= 80 || $monthly_pct >= 80) {
        wu_send_quota_alert('warning', '', '');
        set_transient($transient_key, 1, DAY_IN_SECONDS);
    }
}

function wu_send_quota_alert($type, $to_email = '', $subject = '') {
    $webhook_url = get_option('wu_email_discord_webhook', '');
    if (empty($webhook_url)) return;

    global $wpdb;
    $table  = $wpdb->prefix . 'wu_email_logs';
    $counts = $wpdb->get_row($wpdb->prepare(
        "SELECT
            SUM(sent_time >= %s AND status = 'sent') AS today_count,
            SUM(sent_time >= %s AND status = 'sent') AS month_count
        FROM $table WHERE sent_time >= %s",
        date('Y-m-d 00:00:00'), date('Y-m-01 00:00:00'), date('Y-m-01 00:00:00')
    ));
    $today_count   = (int)($counts->today_count ?? 0);
    $month_count   = (int)($counts->month_count ?? 0);
    $daily_limit   = (int)get_option('wu_email_daily_limit', 20);
    $monthly_limit = (int)get_option('wu_email_monthly_limit', 600);

    if ($type === 'blocked') {
        $title  = '🚫 郵件發送已被阻擋';
        $color  = 15158332;
        $fields = [
            ['name' => '網站',    'value' => home_url(),                       'inline' => false],
            ['name' => '收件者',  'value' => $to_email,                        'inline' => true],
            ['name' => '主旨',    'value' => $subject,                         'inline' => false],
            ['name' => '今日發送','value' => "$today_count / $daily_limit 封",  'inline' => true],
            ['name' => '本月發送','value' => "$month_count / $monthly_limit 封",'inline' => true],
        ];
    } else {
        $title  = '⚠️ 郵件額度警告';
        $color  = 16760576;
        $fields = [
            ['name' => '網站',    'value' => home_url(),                       'inline' => false],
            ['name' => '今日發送','value' => "$today_count / $daily_limit 封",  'inline' => true],
            ['name' => '本月發送','value' => "$month_count / $monthly_limit 封",'inline' => true],
        ];
    }

    wp_remote_post($webhook_url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => json_encode(['embeds' => [['title' => $title, 'color' => $color, 'fields' => $fields, 'timestamp' => current_time('c')]]]),
        'timeout' => 15,
    ]);
}

// ===== AJAX Test Email =====

add_action('wp_ajax_wu_test_email', function() {
    check_ajax_referer('wu_email_test', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => '權限不足']);

    $test_email = sanitize_email($_POST['test_email'] ?? '');
    if (empty($test_email)) wp_send_json_error(['message' => '請輸入測試郵件地址']);

    $send_method = get_option('wu_email_send_method', 'default');

    if ($send_method === 'resend') {
        $result = wu_send_email_via_resend($test_email, '測試郵件 - WU Email Tracker',
            '這是一封測試郵件，用於確認 Resend API 是否正常運作。<br><br>發送時間: ' . current_time('Y-m-d H:i:s') . '<br>網站: ' . home_url());
        wu_log_api_test_result($test_email, $result, 'resend_api');
        $result['success']
            ? wp_send_json_success(['message' => '✅ 測試郵件已透過 Resend API 發送！'])
            : wp_send_json_error(['message' => '❌ 發送失敗：' . $result['error']]);
        return;
    }

    if ($send_method === 'brevo') {
        $result = wu_send_email_via_brevo($test_email, '測試郵件 - WU Email Tracker',
            '這是一封測試郵件，用於確認 Brevo API 是否正常運作。<br><br>發送時間: ' . current_time('Y-m-d H:i:s') . '<br>網站: ' . home_url());
        wu_log_api_test_result($test_email, $result, 'brevo_api');
        $result['success']
            ? wp_send_json_success(['message' => '✅ 測試郵件已透過 Brevo API 發送！'])
            : wp_send_json_error(['message' => '❌ 發送失敗：' . $result['error']]);
        return;
    }

    $result = wp_mail($test_email, '測試郵件 - WordPress 郵件追蹤系統',
        "這是一封測試郵件，用於確認郵件追蹤系統是否正常運作。\n\n發送時間: " . current_time('Y-m-d H:i:s') . "\n網站: " . home_url());

    $result
        ? wp_send_json_success(['message' => '✅ 測試郵件已發送！請重新整理頁面查看記錄。'])
        : wp_send_json_error(['message' => '❌ 測試郵件發送失敗！請檢查 SMTP 設定或啟用 Debug 日誌。']);
});

function wu_log_api_test_result($to_email, $result, $method) {
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'wu_email_logs', [
        'to_email'      => $to_email,
        'subject'       => '[TEST] 測試郵件 - WU Email Tracker',
        'status'        => $result['success'] ? 'sent' : 'failed',
        'send_method'   => $method,
        'error_message' => $result['success'] ? ('Message ID: ' . $result['id']) : ('Error: ' . $result['error']),
        'sent_time'     => current_time('mysql'),
    ], ['%s','%s','%s','%s','%s','%s']);
}

// ===== Settings Page =====

function wu_email_tracker_settings_page() {
    if (!current_user_can('manage_options')) wp_die('權限不足');

    // 下載 Debug 日誌
    if (isset($_GET['wu_download_log'])) {
        $log_file = WP_CONTENT_DIR . '/wu-email-tracker-debug.log';
        if (file_exists($log_file)) {
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="wu-email-tracker-debug-' . date('Y-m-d-His') . '.log"');
            readfile($log_file);
            exit;
        }
    }

    // 清除 Debug 日誌
    if (isset($_POST['wu_email_clear_log'])) {
        check_admin_referer('wu_email_clear_log');
        $log_file = WP_CONTENT_DIR . '/wu-email-tracker-debug.log';
        if (file_exists($log_file)) unlink($log_file);
        echo '<div class="notice notice-success is-dismissible"><p><strong>Debug 日誌已清除</strong></p></div>';
    }

    // 儲存設定
    if (isset($_POST['wu_email_save'])) {
        check_admin_referer('wu_email_settings');
        $fields = [
            'wu_email_tracker_enabled'   => ['type' => 'bool',  'post' => 'enabled'],
            'wu_email_daily_limit'        => ['type' => 'int',   'post' => 'daily_limit',        'default' => 20],
            'wu_email_monthly_limit'      => ['type' => 'int',   'post' => 'monthly_limit',      'default' => 600],
            'wu_email_discord_webhook'    => ['type' => 'url',   'post' => 'discord_webhook'],
            'wu_email_send_method'        => ['type' => 'text',  'post' => 'send_method',        'default' => 'default'],
            'wu_email_debug_enabled'      => ['type' => 'bool',  'post' => 'debug_enabled'],
            'wu_email_resend_api_key'     => ['type' => 'text',  'post' => 'resend_api_key'],
            'wu_email_resend_from_email'  => ['type' => 'email', 'post' => 'resend_from_email'],
            'wu_email_resend_from_name'   => ['type' => 'text',  'post' => 'resend_from_name'],
            'wu_email_brevo_api_key'      => ['type' => 'text',  'post' => 'brevo_api_key'],
            'wu_email_brevo_from_email'   => ['type' => 'email', 'post' => 'brevo_from_email'],
            'wu_email_brevo_from_name'    => ['type' => 'text',  'post' => 'brevo_from_name'],
            'wu_email_smtp_host'          => ['type' => 'text',  'post' => 'smtp_host'],
            'wu_email_smtp_port'          => ['type' => 'text',  'post' => 'smtp_port',          'default' => '587'],
            'wu_email_smtp_encryption'    => ['type' => 'text',  'post' => 'smtp_encryption',    'default' => 'tls'],
            'wu_email_smtp_username'      => ['type' => 'text',  'post' => 'smtp_username'],
            'wu_email_smtp_password'      => ['type' => 'text',  'post' => 'smtp_password'],
            'wu_email_smtp_from_email'    => ['type' => 'email', 'post' => 'smtp_from_email'],
            'wu_email_smtp_from_name'     => ['type' => 'text',  'post' => 'smtp_from_name'],
        ];
        foreach ($fields as $option => $cfg) {
            $val = $_POST[$cfg['post']] ?? ($cfg['default'] ?? '');
            switch ($cfg['type']) {
                case 'bool':  $val = isset($_POST[$cfg['post']]) ? 1 : 0; break;
                case 'int':   $val = intval($val); break;
                case 'url':   $val = esc_url_raw($val); break;
                case 'email': $val = sanitize_email($val); break;
                default:      $val = sanitize_text_field($val); break;
            }
            update_option($option, $val);
        }
        // 重置表格快取（避免設定改變後未重新偵測）
        delete_transient('wu_email_table_ok');
        echo '<div class="notice notice-success is-dismissible"><p><strong>✅ 設定已儲存</strong></p></div>';
    }

    // 清除發信紀錄
    if (isset($_POST['wu_email_clear'])) {
        check_admin_referer('wu_email_clear');
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'wu_email_logs');
        echo '<div class="notice notice-success is-dismissible"><p><strong>發信紀錄已清除</strong></p></div>';
    }

    // 讀取選項
    $enabled           = get_option('wu_email_tracker_enabled', 1);
    $daily_limit       = get_option('wu_email_daily_limit', 20);
    $monthly_limit     = get_option('wu_email_monthly_limit', 600);
    $discord_webhook   = get_option('wu_email_discord_webhook', '');
    $send_method       = get_option('wu_email_send_method', 'default');
    $debug_enabled     = get_option('wu_email_debug_enabled', 0);
    $resend_api_key    = get_option('wu_email_resend_api_key', '');
    $resend_from_email = get_option('wu_email_resend_from_email', 'mail@mail.wulk.cc');
    $resend_from_name  = get_option('wu_email_resend_from_name', get_bloginfo('name'));
    $brevo_api_key     = get_option('wu_email_brevo_api_key', '');
    $brevo_from_email  = get_option('wu_email_brevo_from_email', '');
    $brevo_from_name   = get_option('wu_email_brevo_from_name', get_bloginfo('name'));
    $smtp_host         = get_option('wu_email_smtp_host', '');
    $smtp_port         = get_option('wu_email_smtp_port', '587');
    $smtp_encryption   = get_option('wu_email_smtp_encryption', 'tls');
    $smtp_username     = get_option('wu_email_smtp_username', '');
    $smtp_password     = get_option('wu_email_smtp_password', '');
    $smtp_from_email   = get_option('wu_email_smtp_from_email', 'mail@mail.wulk.cc');
    $smtp_from_name    = get_option('wu_email_smtp_from_name', get_bloginfo('name'));

    // ✅ 優化：用統一查詢函式
    $stats       = wu_get_email_stats();
    $today_sent    = $stats['today_sent'];
    $today_blocked = $stats['today_blocked'];
    $month_sent    = $stats['month_sent'];
    $month_blocked = $stats['month_blocked'];
    $total_count   = isset($stats['total']) ? $stats['total'] : (int)(isset($wpdb) ? $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'wu_email_logs') : 0);

    $method_labels = ['resend' => 'Resend API', 'brevo' => 'Brevo API', 'smtp' => '自訂 SMTP'];
    $current_method_label = $method_labels[$send_method] ?? wu_detect_smtp_plugin()['plugin_name'];

    $log_file    = WP_CONTENT_DIR . '/wu-email-tracker-debug.log';
    $log_exists  = file_exists($log_file);
    $log_size_kb = $log_exists ? round(filesize($log_file) / 1024, 2) : 0;
    $db_version  = get_option('wu_email_tracker_db_version', 'none');
    ?>

    <div class="wrap">
        <h1>郵件追蹤管理 <span style="font-size:14px;color:#666;font-weight:normal;">(v3.6)</span></h1>

        <!-- 當前狀態 -->
        <div style="background:#fff;padding:20px;border:1px solid #ddd;margin-top:20px;border-left:4px solid #0073aa;">
            <h2 style="margin-top:0;">當前發信狀態</h2>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:20px;">
                <div style="padding:15px;background:#f9f9f9;border-left:3px solid #2271b1;">
                    <div style="font-size:11px;color:#666;margin-bottom:5px;">發信方式</div>
                    <div style="font-size:16px;font-weight:700;color:#1d2327;"><?php echo esc_html($current_method_label); ?></div>
                </div>
                <div style="padding:15px;background:#f9f9f9;border-left:3px solid #46b450;">
                    <div style="font-size:11px;color:#666;margin-bottom:5px;">今日發送</div>
                    <div style="font-size:22px;font-weight:700;"><?php echo $today_sent; ?> <span style="font-size:14px;color:#666;">/ <?php echo $daily_limit; ?></span></div>
                    <?php if ($today_blocked > 0): ?><div style="font-size:11px;color:#dc3232;margin-top:5px;">被阻擋: <?php echo $today_blocked; ?> 封</div><?php endif; ?>
                </div>
                <div style="padding:15px;background:#f9f9f9;border-left:3px solid #f0b849;">
                    <div style="font-size:11px;color:#666;margin-bottom:5px;">本月發送</div>
                    <div style="font-size:22px;font-weight:700;"><?php echo $month_sent; ?> <span style="font-size:14px;color:#666;">/ <?php echo $monthly_limit; ?></span></div>
                    <?php if ($month_blocked > 0): ?><div style="font-size:11px;color:#dc3232;margin-top:5px;">被阻擋: <?php echo $month_blocked; ?> 封</div><?php endif; ?>
                </div>
                <div style="padding:15px;background:#f9f9f9;border-left:3px solid #0073aa;">
                    <div style="font-size:11px;color:#666;margin-bottom:5px;">總發送數</div>
                    <div style="font-size:22px;font-weight:700;color:#0073aa;"><?php echo number_format($total_count); ?></div>
                </div>
            </div>
            <p style="margin:0;font-size:12px;color:#666;">DB 版本: <?php echo esc_html($db_version); ?></p>
        </div>

        <!-- 測試發信 -->
        <div style="background:#fff;padding:25px;border:1px solid #ddd;margin-top:20px;border-left:4px solid #00a32a;">
            <h2 style="color:#00a32a;">測試發信功能</h2>
            <p>發送一封測試郵件以確認追蹤系統是否正常運作。</p>
            <table class="form-table">
                <tr>
                    <th><label for="test_email">測試郵件地址</label></th>
                    <td>
                        <input type="email" id="test_email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" class="regular-text">
                        <p class="description">將發送測試郵件到此地址 (使用當前設定: <strong><?php echo esc_html($current_method_label); ?></strong>)</p>
                    </td>
                </tr>
            </table>
            <button type="button" id="wu_test_email_btn" class="button button-secondary">發送測試郵件</button>
            <div id="wu_test_email_result" style="margin-top:15px;"></div>
        </div>

        <!-- 設定表單 -->
        <form method="post" style="background:#fff;padding:25px;border:1px solid #ddd;margin-top:20px;">
            <?php wp_nonce_field('wu_email_settings'); ?>

            <h2>基本設定</h2>
            <table class="form-table">
                <tr>
                    <th><label>啟用郵件追蹤</label></th>
                    <td>
                        <label><input type="checkbox" name="enabled" value="1" <?php checked(1, $enabled); ?>> <strong>啟用郵件追蹤與額度管理</strong></label>
                        <p class="description">關閉後將不再追蹤郵件發送，也不會進行額度限制</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="daily_limit">每日發信額度</label></th>
                    <td>
                        <input type="number" id="daily_limit" name="daily_limit" value="<?php echo esc_attr($daily_limit); ?>" class="regular-text" min="1" step="1">
                        <p class="description">網站每日最多發送郵件數量 (預設: 20 封)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="monthly_limit">每月發信額度</label></th>
                    <td>
                        <input type="number" id="monthly_limit" name="monthly_limit" value="<?php echo esc_attr($monthly_limit); ?>" class="regular-text" min="1" step="1">
                        <p class="description">網站每月最多發送郵件數量 (預設: 600 封)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="discord_webhook">Discord Webhook (選填)</label></th>
                    <td>
                        <input type="url" id="discord_webhook" name="discord_webhook" value="<?php echo esc_attr($discord_webhook); ?>" class="large-text" placeholder="https://discord.com/api/webhooks/...">
                        <p class="description">當郵件被阻擋或接近額度時，發送 Discord 通知</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Debug 日誌</label></th>
                    <td>
                        <label><input type="checkbox" name="debug_enabled" value="1" <?php checked(1, $debug_enabled); ?>> <strong>啟用 Debug 日誌記錄</strong></label>
                        <p class="description">啟用後會記錄詳細的郵件追蹤日誌 (預設: 關閉)</p>
                    </td>
                </tr>
            </table>

            <h2>發信方式設定</h2>
            <table class="form-table">
                <tr>
                    <th><label>選擇發信方式</label></th>
                    <td>
                        <label style="display:block;margin-bottom:10px;"><input type="radio" name="send_method" value="default" <?php checked('default', $send_method); ?>> <strong>使用系統預設或外掛設定</strong></label>
                        <label style="display:block;margin-bottom:10px;"><input type="radio" name="send_method" value="resend"  <?php checked('resend',  $send_method); ?>> <strong>使用 Resend API</strong> <span style="color:#666;font-size:12px;">(免費 3,000 封/月)</span></label>
                        <label style="display:block;margin-bottom:10px;"><input type="radio" name="send_method" value="brevo"   <?php checked('brevo',   $send_method); ?>> <strong>使用 Brevo API</strong> <span style="color:#0092ff;font-size:12px;">(免費 300 封/天)</span></label>
                        <label style="display:block;"><input type="radio" name="send_method" value="smtp" <?php checked('smtp', $send_method); ?>> <strong>使用自訂 SMTP</strong></label>
                    </td>
                </tr>
            </table>

            <!-- Resend API 設定 -->
            <div class="wu-method-section" id="resend_section" style="display:none;">
                <h3>🔑 Resend API 設定</h3>
                <table class="form-table">
                    <tr>
                        <th><label for="resend_api_key">Resend API Key</label></th>
                        <td>
                            <input type="text" id="resend_api_key" name="resend_api_key" value="<?php echo esc_attr($resend_api_key); ?>" class="large-text" placeholder="re_...">
                            <p class="description">請至 <a href="https://resend.com/api-keys" target="_blank">Resend Dashboard</a> 取得 API Key</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="resend_from_email">發件者 Email</label></th>
                        <td>
                            <input type="email" id="resend_from_email" name="resend_from_email" value="<?php echo esc_attr($resend_from_email); ?>" class="regular-text" placeholder="mail@mail.wulk.cc">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="resend_from_name">發件者名稱</label></th>
                        <td>
                            <input type="text" id="resend_from_name" name="resend_from_name" value="<?php echo esc_attr($resend_from_name); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Brevo API 設定 -->
            <div class="wu-method-section" id="brevo_section" style="display:none;">
                <h3>🔑 Brevo API 設定 <span style="font-size:12px;color:#0092ff;font-weight:normal;">(原 Sendinblue)</span></h3>
                <table class="form-table">
                    <tr>
                        <th><label for="brevo_api_key">Brevo API Key</label></th>
                        <td>
                            <input type="text" id="brevo_api_key" name="brevo_api_key" value="<?php echo esc_attr($brevo_api_key); ?>" class="large-text" placeholder="xkeysib-...">
                            <p class="description">請至 <a href="https://app.brevo.com/settings/keys/api" target="_blank">Brevo → 設定 → API Keys</a> 取得</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="brevo_from_email">發件者 Email</label></th>
                        <td>
                            <input type="email" id="brevo_from_email" name="brevo_from_email" value="<?php echo esc_attr($brevo_from_email); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="brevo_from_name">發件者名稱</label></th>
                        <td>
                            <input type="text" id="brevo_from_name" name="brevo_from_name" value="<?php echo esc_attr($brevo_from_name); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
                <div style="background:#e8f4fd;padding:12px 15px;border-left:4px solid #0092ff;margin-top:10px;font-size:13px;">
                    📘 <strong>Brevo 免費方案：</strong>每天最多 300 封，每月 9,000 封，無需信用卡。
                </div>
            </div>

            <!-- SMTP 設定 -->
            <div class="wu-method-section" id="smtp_section" style="display:none;">
                <h3>⚙️ SMTP 伺服器設定</h3>
                <table class="form-table">
                    <tr>
                        <th><label for="smtp_host">SMTP 主機</label></th>
                        <td><input type="text" id="smtp_host" name="smtp_host" value="<?php echo esc_attr($smtp_host); ?>" class="regular-text" placeholder="smtp.example.com"></td>
                    </tr>
                    <tr>
                        <th><label for="smtp_port">SMTP 埠號</label></th>
                        <td><input type="text" id="smtp_port" name="smtp_port" value="<?php echo esc_attr($smtp_port); ?>" class="small-text" placeholder="587"></td>
                    </tr>
                    <tr>
                        <th><label for="smtp_encryption">加密方式</label></th>
                        <td>
                            <select id="smtp_encryption" name="smtp_encryption">
                                <option value="tls"  <?php selected('tls',  $smtp_encryption); ?>>TLS</option>
                                <option value="ssl"  <?php selected('ssl',  $smtp_encryption); ?>>SSL</option>
                                <option value="none" <?php selected('none', $smtp_encryption); ?>>無加密</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="smtp_username">SMTP 帳號</label></th>
                        <td><input type="text" id="smtp_username" name="smtp_username" value="<?php echo esc_attr($smtp_username); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="smtp_password">SMTP 密碼</label></th>
                        <td><input type="password" id="smtp_password" name="smtp_password" value="<?php echo esc_attr($smtp_password); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="smtp_from_email">發件者 Email</label></th>
                        <td><input type="email" id="smtp_from_email" name="smtp_from_email" value="<?php echo esc_attr($smtp_from_email); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="smtp_from_name">發件者名稱</label></th>
                        <td><input type="text" id="smtp_from_name" name="smtp_from_name" value="<?php echo esc_attr($smtp_from_name); ?>" class="regular-text"></td>
                    </tr>
                </table>
            </div>

            <?php submit_button('儲存設定', 'primary large', 'wu_email_save'); ?>
        </form>

        <?php if ($debug_enabled && $log_exists): ?>
        <div style="background:#fff3cd;padding:20px;border:1px solid #ffc107;margin-top:20px;border-left:4px solid #ff9800;">
            <h2 style="margin-top:0;color:#856404;">🐛 Debug 日誌</h2>
            <p><strong>日誌檔案:</strong> <code><?php echo $log_file; ?></code> (大小: <?php echo $log_size_kb; ?> KB)</p>
            <div style="margin-top:15px;">
                <a href="<?php echo admin_url('admin.php?page=wu-email-tracker&wu_download_log=1'); ?>" class="button button-secondary">📥 下載日誌檔案</a>
                <form method="post" style="display:inline-block;margin-left:10px;" onsubmit="return confirm('確定要清除 Debug 日誌嗎?');">
                    <?php wp_nonce_field('wu_email_clear_log'); ?>
                    <?php submit_button('🗑️ 清除日誌', 'secondary', 'wu_email_clear_log', false); ?>
                </form>
            </div>
            <div style="margin-top:20px;background:#fff;padding:15px;border:1px solid #ddd;max-height:300px;overflow-y:auto;">
                <h4 style="margin-top:0;">最近 50 行日誌:</h4>
                <pre style="font-size:11px;line-height:1.4;margin:0;"><?php
                $lines        = file($log_file);
                $recent_lines = array_slice($lines, -50);
                echo esc_html(implode('', $recent_lines));
                ?></pre>
            </div>
        </div>
        <?php endif; ?>

        <!-- 清除紀錄 -->
        <form method="post" style="background:#fff;padding:25px;border:1px solid #ddd;margin-top:20px;border-left:4px solid #dc3232;" onsubmit="return confirm('確定要清除所有發信紀錄嗎?此操作無法復原!');">
            <?php wp_nonce_field('wu_email_clear'); ?>
            <h2 style="color:#dc3232;">危險操作</h2>
            <p>清除所有發信紀錄。統計數字將會歸零。</p>
            <?php submit_button('清除所有發信紀錄', 'delete', 'wu_email_clear', false); ?>
        </form>

        <!-- 詳細紀錄 -->
        <div style="background:#fff;padding:25px;border:1px solid #ddd;margin-top:20px;">
            <h2>發信紀錄詳細 (最近 50 筆)</h2>
            <?php
            global $wpdb;
            $table     = $wpdb->prefix . 'wu_email_logs';
            $all_emails = $wpdb->get_results("SELECT * FROM $table ORDER BY sent_time DESC LIMIT 50");
            if (!empty($all_emails)):
            ?>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width:180px;">發送時間</th>
                        <th style="width:220px;">收件者</th>
                        <th>主旨</th>
                        <th style="width:100px;">發送方式</th>
                        <th style="width:80px;">狀態</th>
                        <th style="width:200px;">備註</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_emails as $email):
                        $method_map = [
                            'resend_api' => '<span style="color:#2271b1;font-weight:600;">Resend API</span>',
                            'brevo_api'  => '<span style="color:#0092ff;font-weight:600;">Brevo API</span>',
                            'smtp'       => '<span style="color:#00a32a;font-weight:600;">SMTP</span>',
                            'default'    => '<span style="color:#646970;">預設</span>',
                        ];
                        $status_map = [
                            'sent'    => '<span style="display:inline-block;padding:3px 8px;background:#d7f0dd;color:#1d8a3f;font-size:11px;font-weight:600;border-radius:3px;">成功</span>',
                            'blocked' => '<span style="display:inline-block;padding:3px 8px;background:#fcf3cf;color:#996800;font-size:11px;font-weight:600;border-radius:3px;">已阻擋</span>',
                        ];
                    ?>
                    <tr>
                        <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($email->sent_time))); ?></td>
                        <td><code style="font-size:11px;"><?php echo esc_html($email->to_email); ?></code></td>
                        <td><?php echo esc_html($email->subject); ?></td>
                        <td><?php echo $method_map[$email->send_method] ?? '<span style="color:#646970;">預設</span>'; ?></td>
                        <td><?php echo $status_map[$email->status] ?? '<span style="display:inline-block;padding:3px 8px;background:#fcdbdb;color:#b32d2e;font-size:11px;font-weight:600;border-radius:3px;">失敗</span>'; ?></td>
                        <td><small style="color:#666;"><?php echo esc_html($email->error_message); ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="color:#666;padding:40px;text-align:center;">目前尚無發信紀錄</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        function toggleMethodSections() {
            $('.wu-method-section').hide();
            var method = $('input[name="send_method"]:checked').val();
            if (method === 'resend') $('#resend_section').show();
            else if (method === 'brevo') $('#brevo_section').show();
            else if (method === 'smtp') $('#smtp_section').show();
        }
        toggleMethodSections();
        $('input[name="send_method"]').on('change', toggleMethodSections);

        $('#wu_test_email_btn').on('click', function() {
            var $btn    = $(this);
            var $result = $('#wu_test_email_result');
            var email   = $('#test_email').val();
            if (!email) { $result.html('<div class="notice notice-error inline"><p>請輸入測試郵件地址</p></div>'); return; }
            $btn.prop('disabled', true).text('發送中...');
            $result.html('');
            $.post(ajaxurl, {
                action: 'wu_test_email',
                nonce: '<?php echo wp_create_nonce('wu_email_test'); ?>',
                test_email: email
            }, function(res) {
                if (res.success) {
                    $result.html('<div class="notice notice-success inline"><p>' + res.data.message + '</p></div>');
                    setTimeout(function(){ location.reload(); }, 2000);
                } else {
                    $result.html('<div class="notice notice-error inline"><p>' + res.data.message + '</p></div>');
                }
            }).fail(function() {
                $result.html('<div class="notice notice-error inline"><p>發送失敗，請稍後再試</p></div>');
            }).always(function() {
                $btn.prop('disabled', false).text('發送測試郵件');
            });
        });
    });
    </script>

    <style>
    .wu-method-section { background:#f9f9f9; padding:20px; margin-top:20px; border-left:4px solid #2271b1; }
    #brevo_section { border-left-color:#0092ff; }
    #smtp_section  { border-left-color:#00a32a; }
    .notice.inline { display:inline-block; margin:0; padding:10px 15px; }
    </style>
    <?php
}

// ===== Auto Clean Old Records =====

add_action('wp_scheduled_delete', function() {
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}wu_email_logs WHERE sent_time < %s",
        date('Y-m-d H:i:s', strtotime('-90 days'))
    ));
});

// ===== Dashboard Styles =====

add_action('admin_head', function() {
    if (!get_option('wu_email_tracker_enabled', 1)) return;
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'dashboard') return;
    ?>
    <style>
    #wu_email_tracker_dashboard{width:100%!important;grid-column:1/-1!important}
    #wu_email_tracker_dashboard .inside{padding:0!important;margin:0!important}
    .wu-dashboard-container{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
    .wu-section{background:#fff;border:1px solid #ddd;padding:20px;margin-bottom:15px}
    .wu-section-title{margin:0 0 15px;font-size:16px;font-weight:600;color:#1d2327;display:flex;align-items:center;justify-content:space-between}
    .wu-monitoring-badge{font-size:11px;color:#fff;padding:4px 10px;border-radius:3px;font-weight:500}
    .wu-info-table{width:100%;border-collapse:collapse}
    .wu-info-table th{width:140px;padding:12px 15px;text-align:left;font-weight:600;color:#1d2327;background:#f6f7f7;border-top:1px solid #ddd;font-size:13px}
    .wu-info-table td{padding:12px 15px;border-top:1px solid #ddd;font-size:13px;color:#1d2327}
    .wu-info-meta{color:#646970!important;font-size:12px!important}
    .wu-status-indicator{font-weight:600}
    .wu-disk-bar{height:12px;background:#f0f0f1;border-radius:6px;overflow:hidden;margin-bottom:10px}
    .wu-disk-bar-fill{height:100%;transition:width .3s ease;border-radius:6px}
    .wu-notice{padding:15px;border-left:4px solid;margin-top:15px;background:#fff}
    .wu-notice-error{border-color:#d63638;background:#fcf0f1}
    </style>
    <?php
});

// ✅ 移除：wu_email_verify_password 函式（密碼保護已全面移除）
