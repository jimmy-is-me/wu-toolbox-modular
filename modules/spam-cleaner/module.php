/**
 * Plugin Name: 垃圾機器人帳號清除工具
 * Description: 根據使用者名稱關鍵字批次刪除垃圾機器人帳號，支援預覽全部結果、勾選排除、智能速度調節
 * Version: 2.2
 * Author: Wumetax
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SPAM_CLEANER_OPT_KEYWORDS', 'spam_cleaner_keywords' );
define( 'SPAM_CLEANER_OPT_SPEED',    'spam_cleaner_speed_mode' );

/* ── 預設關鍵字 ───────────────────────────────────────────── */
function spam_cleaner_default_keywords() {
    return implode( "\n", [
        'blogspot',
        'btc',
        'usdt',
        'usd',
        'euro',
        'trade',
        'trading',
        'crypto',
        'forex',
    ] );
}

/* ── 取得關鍵字陣列 ───────────────────────────────────────── */
function spam_cleaner_get_keywords() {
    $raw   = get_option( SPAM_CLEANER_OPT_KEYWORDS, spam_cleaner_default_keywords() );
    $lines = preg_split( '/\r\n|\r|\n/', $raw );
    $out   = [];
    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( $line !== '' && strpos( $line, '#' ) !== 0 ) {
            $out[] = $line;
        }
    }
    return array_values( array_unique( $out ) );
}

/* ── 建立 WHERE 片段（user_login LIKE ...） ───────────────── */
function spam_cleaner_build_where() {
    $keywords = spam_cleaner_get_keywords();
    if ( empty( $keywords ) ) return '';

    global $wpdb;
    $conds = [];
    foreach ( $keywords as $kw ) {
        $conds[] = $wpdb->prepare( 'u.user_login LIKE %s', '%' . $wpdb->esc_like( $kw ) . '%' );
    }
    return implode( ' OR ', $conds );
}

/* ── 查詢（支援 offset 分頁） ────────────────────────────── */
function spam_cleaner_get_users( $limit = 100, $offset = 0 ) {
    $where = spam_cleaner_build_where();
    if ( ! $where ) return [];

    global $wpdb;
    $limit  = max( 1, intval( $limit ) );
    $offset = max( 0, intval( $offset ) );

    return $wpdb->get_results( "
        SELECT DISTINCT u.ID, u.user_login, u.user_email, u.user_registered
        FROM {$wpdb->users} u
        INNER JOIN {$wpdb->usermeta} cap ON u.ID = cap.user_id
        WHERE cap.meta_key   = '{$wpdb->prefix}capabilities'
          AND cap.meta_value LIKE '%subscriber%'
          AND ( {$where} )
        ORDER BY u.ID ASC
        LIMIT {$limit} OFFSET {$offset}
    " );
}

/* ── 計算總筆數 ───────────────────────────────────────────── */
function spam_cleaner_count_all() {
    $where = spam_cleaner_build_where();
    if ( ! $where ) return 0;

    global $wpdb;
    return (int) $wpdb->get_var( "
        SELECT COUNT(DISTINCT u.ID)
        FROM {$wpdb->users} u
        INNER JOIN {$wpdb->usermeta} cap ON u.ID = cap.user_id
        WHERE cap.meta_key   = '{$wpdb->prefix}capabilities'
          AND cap.meta_value LIKE '%subscriber%'
          AND ( {$where} )
    " );
}

/* ── 速度設定 ─────────────────────────────────────────────── */
function spam_cleaner_speed_config( $mode ) {
    $mem_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
    $mem_usage = memory_get_usage( true );
    $mem_free  = $mem_limit > 0 ? ( $mem_limit - $mem_usage ) : 256 * 1024 * 1024;
    $load      = function_exists( 'sys_getloadavg' ) ? sys_getloadavg() : [ 0 ];
    $load1     = (float) ( $load[0] ?? 0 );

    $resolved = $mode;
    if ( $mode === 'auto' ) {
        $resolved = ( $mem_free < 32 * 1024 * 1024 || $load1 > 3 ) ? 'slow'
                  : ( ( $mem_free < 128 * 1024 * 1024 || $load1 > 1.5 ) ? 'normal' : 'fast' );
    }

    $cfg = [
        'slow'   => [ 'batch' => 50,  'sleep' => 2, 'label' => '保守 (50筆/批，間隔2秒)' ],
        'normal' => [ 'batch' => 200, 'sleep' => 1, 'label' => '標準 (200筆/批，間隔1秒)' ],
        'fast'   => [ 'batch' => 500, 'sleep' => 0, 'label' => '積極 (500筆/批，無間隔)' ],
    ];

    $result          = $cfg[ $resolved ] ?? $cfg['normal'];
    $result['mode']  = $resolved;
    $result['diag']  = sprintf(
        '可用記憶體：%s MB｜伺服器負載：%.2f｜實際模式：%s',
        round( $mem_free / 1024 / 1024 ), $load1, $resolved
    );
    return $result;
}

/* ── 選單 ─────────────────────────────────────────────────── */
add_action( 'admin_menu', 'spam_cleaner_menu' );
function spam_cleaner_menu() {
    add_submenu_page( 'wu-toolbox-modular', '垃圾帳號清除', '垃圾帳號清除', 'manage_options', 'wu-spam-cleaner', 'spam_cleaner_page' );
}

/* ── Ajax：取得使用者清單（分頁） ────────────────────────── */
add_action( 'wp_ajax_spam_cleaner_list_users', 'spam_cleaner_ajax_list_users' );
function spam_cleaner_ajax_list_users() {
    check_ajax_referer( 'spam_cleaner_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );

    $page     = max( 1, intval( $_POST['page'] ?? 1 ) );
    $per_page = 200;
    $offset   = ( $page - 1 ) * $per_page;
    $users    = spam_cleaner_get_users( $per_page, $offset );
    $total    = spam_cleaner_count_all();

    $rows = [];
    foreach ( $users as $u ) {
        $rows[] = [
            'id'         => (int) $u->ID,
            'user_login' => $u->user_login,
            'user_email' => $u->user_email,
            'registered' => $u->user_registered,
        ];
    }

    wp_send_json_success( [
        'rows'      => $rows,
        'total'     => $total,
        'page'      => $page,
        'per_page'  => $per_page,
        'has_more'  => ( $offset + $per_page ) < $total,
    ] );
}

/* ── Ajax：刪除指定 ID 清單 ──────────────────────────────── */
add_action( 'wp_ajax_spam_cleaner_delete_ids', 'spam_cleaner_ajax_delete_ids' );
function spam_cleaner_ajax_delete_ids() {
    check_ajax_referer( 'spam_cleaner_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );

    $ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : [];
    if ( empty( $ids ) ) wp_send_json_error( '沒有提供 ID' );

    require_once ABSPATH . 'wp-admin/includes/user.php';

    $deleted = 0;
    $skipped = 0;
    foreach ( $ids as $uid ) {
        if ( $uid <= 0 ) continue;
        if ( user_can( $uid, 'manage_options' ) ) { $skipped++; continue; }
        wp_delete_user( $uid, 1 );
        $deleted++;
    }

    wp_send_json_success( [
        'deleted'   => $deleted,
        'skipped'   => $skipped,
        'remaining' => spam_cleaner_count_all(),
    ] );
}

/* ── Ajax：批次刪除（自動速度） ──────────────────────────── */
add_action( 'wp_ajax_spam_cleaner_delete_batch', 'spam_cleaner_ajax_delete_batch' );
function spam_cleaner_ajax_delete_batch() {
    check_ajax_referer( 'spam_cleaner_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );

    $speed_mode = sanitize_text_field( wp_unslash( $_POST['speed_mode'] ?? 'auto' ) );
    update_option( SPAM_CLEANER_OPT_SPEED, $speed_mode );
    $cfg   = spam_cleaner_speed_config( $speed_mode );
    $users = spam_cleaner_get_users( $cfg['batch'] );

    require_once ABSPATH . 'wp-admin/includes/user.php';

    $deleted = 0;
    $skipped = 0;
    foreach ( $users as $user ) {
        if ( user_can( $user->ID, 'manage_options' ) ) { $skipped++; continue; }
        wp_delete_user( $user->ID, 1 );
        $deleted++;
    }

    if ( $cfg['sleep'] > 0 ) sleep( $cfg['sleep'] );

    wp_send_json_success( [
        'deleted'   => $deleted,
        'skipped'   => $skipped,
        'remaining' => spam_cleaner_count_all(),
        'mode'      => $cfg['label'],
        'diag'      => $cfg['diag'],
    ] );
}

/* ── Ajax：儲存關鍵字 ────────────────────────────────────── */
add_action( 'wp_ajax_spam_cleaner_save_keywords', 'spam_cleaner_ajax_save_keywords' );
function spam_cleaner_ajax_save_keywords() {
    check_ajax_referer( 'spam_cleaner_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );

    $raw = sanitize_textarea_field( wp_unslash( $_POST['keywords'] ?? '' ) );
    update_option( SPAM_CLEANER_OPT_KEYWORDS, $raw );
    wp_send_json_success( [ 'total' => spam_cleaner_count_all() ] );
}

/* ── Ajax：重置關鍵字 ────────────────────────────────────── */
add_action( 'wp_ajax_spam_cleaner_reset_keywords', 'spam_cleaner_ajax_reset_keywords' );
function spam_cleaner_ajax_reset_keywords() {
    check_ajax_referer( 'spam_cleaner_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '權限不足' );

    delete_option( SPAM_CLEANER_OPT_KEYWORDS );
    wp_send_json_success( [
        'keywords' => spam_cleaner_default_keywords(),
        'total'    => spam_cleaner_count_all(),
    ] );
}

/* ── 後台頁面 ─────────────────────────────────────────────── */
function spam_cleaner_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( '權限不足' );

    $ajax_nonce   = wp_create_nonce( 'spam_cleaner_ajax_nonce' );
    $keywords_raw = get_option( SPAM_CLEANER_OPT_KEYWORDS, spam_cleaner_default_keywords() );
    $total        = spam_cleaner_count_all();
    $speed_mode   = get_option( SPAM_CLEANER_OPT_SPEED, 'auto' );
    $speed_cfg    = spam_cleaner_speed_config( $speed_mode );
    ?>
<!DOCTYPE html>
<style>
#scw{max-width:960px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
#scw .card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px}
#scw h1{font-size:22px;margin-bottom:20px}
#scw h2{font-size:15px;font-weight:600;margin:0 0 12px}
#scw textarea{width:100%;min-height:200px;font-family:monospace;font-size:13px;border:1px solid #ccc;border-radius:4px;padding:8px;resize:vertical;box-sizing:border-box}
.sc-btn{display:inline-block;padding:8px 16px;border-radius:4px;font-size:13px;font-weight:600;cursor:pointer;border:none;line-height:1.4}
.sc-btn-primary{background:#0073aa;color:#fff}.sc-btn-primary:hover{background:#005d8c}
.sc-btn-danger{background:#d63638;color:#fff}.sc-btn-danger:hover{background:#a82020}
.sc-btn-warning{background:#ff8c00;color:#fff}.sc-btn-warning:hover{background:#cc7000}
.sc-btn-ghost{background:#f0f0f0;color:#333;border:1px solid #ccc}.sc-btn-ghost:hover{background:#e0e0e0}
.sc-btn:disabled{opacity:.45;cursor:not-allowed}
#scw .speed-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
#scw .speed-opt{border:2px solid #ddd;border-radius:6px;padding:12px;cursor:pointer;text-align:center;transition:.15s}
#scw .speed-opt:hover{border-color:#0073aa}
#scw .speed-opt.active{border-color:#0073aa;background:#e8f4fb}
#scw .speed-opt .ico{font-size:20px}
#scw .speed-opt .nm{font-weight:600;font-size:13px;margin:3px 0 2px}
#scw .speed-opt .ds{font-size:11px;color:#666}
.sc-pb-bg{background:#f0f0f0;border-radius:99px;height:16px;overflow:hidden}
.sc-pb{background:#0073aa;height:100%;width:0%;transition:width .3s;border-radius:99px}
.sc-log{background:#1e1e1e;color:#d4d4d4;font-family:monospace;font-size:12px;padding:12px;border-radius:4px;max-height:200px;overflow-y:auto;margin-top:10px;display:none}
.sc-log .ok{color:#4ec9b0}.sc-log .warn{color:#ce9178}.sc-log .info{color:#9cdcfe}
.sc-diag{font-size:11px;color:#888;margin-top:8px;font-family:monospace}
.sc-total{font-size:26px;font-weight:700;color:#d63638}
.sc-total-label{font-size:13px;color:#666}
/* ── 使用者清單表格 ── */
#user-list-table{width:100%;border-collapse:collapse;font-size:12px}
#user-list-table th{background:#f6f7f7;padding:8px 10px;border-bottom:2px solid #ddd;text-align:left;white-space:nowrap}
#user-list-table td{padding:7px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle;word-break:break-all}
#user-list-table tr:hover td{background:#fafafa}
#user-list-table .excl{color:#aaa;text-decoration:line-through}
.sc-badge-excl{display:inline-block;font-size:10px;padding:1px 6px;border-radius:99px;background:#eee;color:#888;vertical-align:middle;margin-left:4px}
#sc-list-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
#sc-list-info{font-size:12px;color:#666}
#sc-pagination{display:flex;gap:6px;align-items:center;margin-top:10px;flex-wrap:wrap}
.sc-page-btn{padding:4px 10px;border-radius:4px;border:1px solid #ccc;background:#fff;cursor:pointer;font-size:12px}
.sc-page-btn:hover{background:#e8f4fb;border-color:#0073aa}
.sc-page-btn.active{background:#0073aa;color:#fff;border-color:#0073aa}
#sc-loading{display:none;color:#0073aa;font-size:13px;margin:10px 0}
.sc-inline-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px}
#sc-kw-hint{font-size:12px;color:#666;margin-top:6px;line-height:1.6}
</style>

<div id="scw" class="wrap">
<h1>🧹 垃圾機器人帳號清除工具 v2.2</h1>

<!-- 統計 -->
<div class="card" style="display:flex;align-items:center;gap:30px;">
    <div>
        <div class="sc-total" id="sc-total-count"><?php
/** WU Toolbox Modular module: merged spam_cleaner + spam-user-cleaner functionality. */
if ( ! defined( 'ABSPATH' ) ) exit;
echo number_format( $total ); ?></div>
        <div class="sc-total-label">筆符合條件的垃圾帳號</div>
    </div>
    <div style="flex:1;font-size:13px;color:#555;line-height:1.8">
        搜尋欄位：<code>user_login（使用者名稱）</code><br>
        限制角色：<code>subscriber</code><br>
        保護對象：<code>manage_options</code> 管理員永不刪除
    </div>
</div>

<!-- 關鍵字 -->
<div class="card">
    <h2>📝 使用者名稱關鍵字（每行一個，# 開頭為註解）</h2>
    <textarea id="sc-keywords"><?php echo esc_textarea( $keywords_raw ); ?></textarea>
    <div id="sc-kw-hint">帳號名稱包含任一關鍵字即列入候選。輸入 <code>btc</code> 會匹配 <code>abcbtc99</code>、<code>btc_trader</code> 等。</div>
    <div class="sc-inline-row">
        <button class="sc-btn sc-btn-primary" id="sc-save-kw-btn">💾 儲存並重新計算</button>
        <button class="sc-btn sc-btn-ghost"   id="sc-reset-kw-btn">↩ 還原預設</button>
        <span id="sc-kw-msg" style="font-size:13px"></span>
    </div>
</div>

<!-- 使用者清單（全部搜尋結果） -->
<div class="card">
    <h2>🔍 搜尋結果（所有符合帳號）</h2>
    <p style="font-size:13px;color:#555;margin-bottom:10px">
        以下為系統搜尋到的帳號，請仔細確認。若有<strong>不想刪除</strong>的帳號，請點「排除」按鈕，排除後的帳號不會被刪除。確認無誤後再至下方執行批次刪除。
    </p>

    <div id="sc-list-toolbar">
        <button class="sc-btn sc-btn-ghost" id="sc-load-list-btn" style="font-size:12px;">📋 載入 / 重新整理清單</button>
        <button class="sc-btn sc-btn-ghost" id="sc-select-all-btn" style="font-size:12px;display:none">✅ 全選</button>
        <button class="sc-btn sc-btn-ghost" id="sc-deselect-all-btn" style="font-size:12px;display:none">⬜ 全不選</button>
        <button class="sc-btn sc-btn-warning" id="sc-delete-selected-btn" style="font-size:12px;display:none">🗑️ 刪除勾選的帳號</button>
        <span id="sc-list-info"></span>
    </div>

    <div id="sc-loading">⏳ 載入中...</div>

    <div style="overflow-x:auto">
        <table id="user-list-table" style="display:none">
            <thead>
                <tr>
                    <th><input type="checkbox" id="sc-check-all" title="全選/全不選"></th>
                    <th>ID</th>
                    <th>使用者名稱</th>
                    <th>Email</th>
                    <th>註冊日期</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody id="sc-user-tbody"></tbody>
        </table>
    </div>

    <div id="sc-pagination"></div>
    <div id="sc-empty-msg" style="display:none;color:green;padding:10px 0">✅ 目前沒有符合條件的帳號。</div>
</div>

<!-- 速度設定 -->
<div class="card">
    <h2>⚡ 智能速度調節</h2>
    <p style="font-size:13px;color:#666;margin-bottom:12px">自動模式依伺服器記憶體與 CPU 負載即時調整批次大小。</p>
    <div class="speed-grid">
        <?php
        $modes = [
            'auto'   => [ '🤖', '自動',   '依伺服器狀態智能調節' ],
            'slow'   => [ '🐢', '保守',   '50筆/批・間隔2秒' ],
            'normal' => [ '🚗', '標準',   '200筆/批・間隔1秒' ],
            'fast'   => [ '🚀', '積極',   '500筆/批・無間隔' ],
        ];
        foreach ( $modes as $k => [ $ico, $nm, $ds ] ) {
            $cls = $speed_mode === $k ? 'active' : '';
            echo "<div class='speed-opt {$cls}' data-mode='" . esc_attr($k) . "'>
                    <div class='ico'>" . esc_html($ico) . "</div>
                    <div class='nm'>"  . esc_html($nm)  . "</div>
                    <div class='ds'>"  . esc_html($ds)  . "</div>
                  </div>";
        }
        ?>
    </div>
    <div class="sc-diag" id="sc-diag">📊 <?php echo esc_html( $speed_cfg['diag'] ); ?></div>
</div>

<!-- 批次刪除 -->
<div class="card" style="background:#fff8e1;border-color:#ffb900">
    <h2>⚠️ 批次刪除執行（全部符合 → 自動分批清除）</h2>
    <p style="font-size:13px;color:#555;margin-bottom:12px;line-height:1.7">
        此按鈕會忽略「排除」標記，依照關鍵字搜尋結果全部自動刪除，直到清空為止。<br>
        <strong>若只想刪除特定帳號，請使用上方「刪除勾選的帳號」按鈕。</strong>
    </p>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button class="sc-btn sc-btn-danger" id="sc-start-btn" <?php echo $total===0 ? 'disabled' : ''; ?>>
            🗑️ 全部批次刪除（共 <span id="sc-btn-total"><?php echo number_format($total); ?></span> 筆）
        </button>
        <button class="sc-btn sc-btn-ghost" id="sc-stop-btn" style="display:none">⏹ 暫停</button>
    </div>

    <div id="sc-progress-wrap" style="display:none;margin-top:14px">
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
            <span id="sc-progress-label">準備中...</span>
            <span id="sc-progress-pct">0%</span>
        </div>
        <div class="sc-pb-bg"><div class="sc-pb" id="sc-progress-bar"></div></div>
        <div style="font-size:12px;color:#666;margin-top:6px">
            累計刪除：<strong id="sc-deleted-sum">0</strong> 筆 ／
            跳過管理員：<strong id="sc-skipped-sum">0</strong> 筆 ／
            剩餘：<strong id="sc-remaining">—</strong> 筆
        </div>
    </div>
    <div class="sc-log" id="sc-log"></div>
</div>

</div><!-- #scw -->

<script>
(function(){
'use strict';
const ajaxUrl   = <?php echo wp_json_encode( admin_url('admin-ajax.php') ); ?>;
const nonce     = <?php echo wp_json_encode( $ajax_nonce ); ?>;
let speedMode   = <?php echo wp_json_encode( $speed_mode ); ?>;
let totalInit   = <?php echo (int) $total; ?>;
let running     = false;
let stopped     = false;
let deletedSum  = 0;
let skippedSum  = 0;

/* 儲存「排除」的 user_id（頁面層級） */
let excludedIds = new Set();

/* 當前載入的清單資料（分頁） */
let currentPage = 1;
const perPage   = 200;
let allLoadedRows = [];  /* 所有已載入的 rows（跨頁）*/

/* ── 工具函式 ─────────────────────────────────────────────── */
function post(action, data){
    const body = new URLSearchParams(Object.assign({ action, nonce }, data));
    return fetch(ajaxUrl, {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body
    }).then(r => r.json());
}

function updateTotalUI(n){
    n = Number(n);
    document.getElementById('sc-total-count').textContent = n.toLocaleString();
    document.getElementById('sc-btn-total').textContent   = n.toLocaleString();
    document.getElementById('sc-start-btn').disabled      = n <= 0;
}

function appendLog(type, msg){
    const log = document.getElementById('sc-log');
    log.style.display = 'block';
    const ts = new Date().toLocaleTimeString();
    log.innerHTML += `<div class="${type}">[${ts}] ${msg}</div>`;
    log.scrollTop = log.scrollHeight;
}

/* ── 速度選擇 ─────────────────────────────────────────────── */
document.querySelectorAll('.speed-opt').forEach(el => {
    el.addEventListener('click', function(){
        document.querySelectorAll('.speed-opt').forEach(e => e.classList.remove('active'));
        this.classList.add('active');
        speedMode = this.dataset.mode;
    });
});

/* ── 儲存關鍵字 ───────────────────────────────────────────── */
document.getElementById('sc-save-kw-btn').addEventListener('click', function(){
    const msg = document.getElementById('sc-kw-msg');
    msg.textContent = '儲存中...'; msg.style.color = '#333';
    post('spam_cleaner_save_keywords', { keywords: document.getElementById('sc-keywords').value })
    .then(res => {
        if(res.success){
            msg.textContent = '✅ 已儲存！符合條件：' + Number(res.data.total).toLocaleString() + ' 筆';
            msg.style.color = 'green';
            updateTotalUI(res.data.total);
            totalInit = Number(res.data.total);
            /* 清空清單，讓使用者重新載入 */
            resetListUI();
        } else {
            msg.textContent = '❌ 儲存失敗'; msg.style.color = 'red';
        }
    });
});

/* ── 還原預設關鍵字 ───────────────────────────────────────── */
document.getElementById('sc-reset-kw-btn').addEventListener('click', function(){
    if(!confirm('確定還原為預設關鍵字？')) return;
    post('spam_cleaner_reset_keywords', {}).then(res => {
        if(res.success){
            document.getElementById('sc-keywords').value = res.data.keywords;
            updateTotalUI(res.data.total);
            resetListUI();
            document.getElementById('sc-kw-msg').textContent = '✅ 已還原';
            document.getElementById('sc-kw-msg').style.color = 'green';
        }
    });
});

/* ── 清單 UI 重置 ─────────────────────────────────────────── */
function resetListUI(){
    allLoadedRows = [];
    excludedIds = new Set();
    currentPage = 1;
    document.getElementById('user-list-table').style.display = 'none';
    document.getElementById('sc-user-tbody').innerHTML = '';
    document.getElementById('sc-pagination').innerHTML = '';
    document.getElementById('sc-empty-msg').style.display = 'none';
    document.getElementById('sc-list-info').textContent = '';
    document.getElementById('sc-select-all-btn').style.display = 'none';
    document.getElementById('sc-deselect-all-btn').style.display = 'none';
    document.getElementById('sc-delete-selected-btn').style.display = 'none';
}

/* ── 載入清單 ─────────────────────────────────────────────── */
document.getElementById('sc-load-list-btn').addEventListener('click', function(){
    allLoadedRows = [];
    excludedIds   = new Set();
    currentPage   = 1;
    loadPage(1);
});

function loadPage(page){
    document.getElementById('sc-loading').style.display = 'block';
    document.getElementById('user-list-table').style.display = 'none';
    document.getElementById('sc-empty-msg').style.display = 'none';

    post('spam_cleaner_list_users', { page })
    .then(res => {
        document.getElementById('sc-loading').style.display = 'none';
        if(!res.success){ alert('載入失敗：' + JSON.stringify(res.data)); return; }

        const d = res.data;
        if(d.total === 0){
            document.getElementById('sc-empty-msg').style.display = 'block';
            return;
        }

        /* 合併 rows（載入所有頁面後才能全選刪除） */
        allLoadedRows = allLoadedRows.concat(d.rows);

        renderTable(d.rows, d.total, d.page, d.per_page, d.has_more);
        renderPagination(d.page, d.per_page, d.total, d.has_more);
    })
    .catch(err => {
        document.getElementById('sc-loading').style.display = 'none';
        alert('載入錯誤：' + err);
    });
}

function renderTable(rows, total, page, pp, hasMore){
    const tbody = document.getElementById('sc-user-tbody');

    if(page === 1) tbody.innerHTML = '';

    rows.forEach(u => {
        const excl = excludedIds.has(u.id);
        const tr = document.createElement('tr');
        tr.dataset.uid = u.id;
        if(excl) tr.classList.add('excl');
        tr.innerHTML = `
            <td><input type="checkbox" class="sc-row-check" data-uid="${u.id}" ${excl?'disabled':''} ${!excl?'checked':''}></td>
            <td>${u.id}</td>
            <td class="sc-login">${escHtml(u.user_login)}${excl?'<span class="sc-badge-excl">已排除</span>':''}</td>
            <td>${escHtml(u.user_email)}</td>
            <td>${escHtml(u.registered)}</td>
            <td>
                ${excl
                    ? `<button class="sc-btn sc-btn-ghost sc-undo-excl-btn" data-uid="${u.id}" style="font-size:11px;padding:3px 8px">↩ 取消排除</button>`
                    : `<button class="sc-btn sc-btn-ghost sc-excl-btn" data-uid="${u.id}" style="font-size:11px;padding:3px 8px;color:#d63638">🚫 排除</button>`
                }
            </td>`;
        tbody.appendChild(tr);
    });

    bindRowButtons();

    document.getElementById('user-list-table').style.display = 'table';
    document.getElementById('sc-select-all-btn').style.display = 'inline-block';
    document.getElementById('sc-deselect-all-btn').style.display = 'inline-block';
    document.getElementById('sc-delete-selected-btn').style.display = 'inline-block';

    const loaded = tbody.rows.length;
    document.getElementById('sc-list-info').textContent =
        `共 ${total.toLocaleString()} 筆，已顯示 ${loaded} 筆`;
}

function renderPagination(page, pp, total, hasMore){
    const pages = Math.ceil(total / pp);
    const pg    = document.getElementById('sc-pagination');
    pg.innerHTML = '';
    if(pages <= 1) return;

    const info = document.createElement('span');
    info.style.fontSize = '12px';
    info.style.color = '#666';
    info.textContent = `第 ${page} / ${pages} 頁`;
    pg.appendChild(info);

    for(let i=1; i<=pages; i++){
        const btn = document.createElement('button');
        btn.className = 'sc-page-btn' + (i===page?' active':'');
        btn.textContent = i;
        btn.addEventListener('click', (function(p){ return function(){ loadPage(p); }; })(i));
        pg.appendChild(btn);
    }
}

/* ── 排除 / 取消排除 ─────────────────────────────────────── */
function bindRowButtons(){
    document.querySelectorAll('.sc-excl-btn').forEach(btn => {
        btn.addEventListener('click', function(){
            const uid = parseInt(this.dataset.uid);
            excludedIds.add(uid);
            refreshRow(uid);
        });
    });
    document.querySelectorAll('.sc-undo-excl-btn').forEach(btn => {
        btn.addEventListener('click', function(){
            const uid = parseInt(this.dataset.uid);
            excludedIds.delete(uid);
            refreshRow(uid);
        });
    });
}

function refreshRow(uid){
    const tr   = document.querySelector(`#sc-user-tbody tr[data-uid="${uid}"]`);
    if(!tr) return;
    const excl = excludedIds.has(uid);
    const u    = allLoadedRows.find(r => r.id === uid);
    if(!u) return;

    tr.className = excl ? 'excl' : '';
    tr.innerHTML = `
        <td><input type="checkbox" class="sc-row-check" data-uid="${uid}" ${excl?'disabled':''} ${!excl?'checked':''}></td>
        <td>${uid}</td>
        <td class="sc-login">${escHtml(u.user_login)}${excl?'<span class="sc-badge-excl">已排除</span>':''}</td>
        <td>${escHtml(u.user_email)}</td>
        <td>${escHtml(u.registered)}</td>
        <td>
            ${excl
                ? `<button class="sc-btn sc-btn-ghost sc-undo-excl-btn" data-uid="${uid}" style="font-size:11px;padding:3px 8px">↩ 取消排除</button>`
                : `<button class="sc-btn sc-btn-ghost sc-excl-btn" data-uid="${uid}" style="font-size:11px;padding:3px 8px;color:#d63638">🚫 排除</button>`
            }
        </td>`;
    bindRowButtons();
}

/* ── 全選 / 全不選 ────────────────────────────────────────── */
document.getElementById('sc-select-all-btn').addEventListener('click', function(){
    document.querySelectorAll('.sc-row-check:not(:disabled)').forEach(cb => cb.checked = true);
    document.getElementById('sc-check-all').checked = true;
});
document.getElementById('sc-deselect-all-btn').addEventListener('click', function(){
    document.querySelectorAll('.sc-row-check:not(:disabled)').forEach(cb => cb.checked = false);
    document.getElementById('sc-check-all').checked = false;
});
document.getElementById('sc-check-all').addEventListener('change', function(){
    document.querySelectorAll('.sc-row-check:not(:disabled)').forEach(cb => cb.checked = this.checked);
});

/* ── 刪除勾選的帳號 ───────────────────────────────────────── */
document.getElementById('sc-delete-selected-btn').addEventListener('click', function(){
    const checked = [...document.querySelectorAll('.sc-row-check:checked')];
    const ids = checked.map(cb => parseInt(cb.dataset.uid)).filter(id => !isNaN(id) && !excludedIds.has(id));

    if(ids.length === 0){ alert('沒有勾選任何帳號。'); return; }
    if(!confirm(`確定要刪除勾選的 ${ids.length} 個帳號？\n此操作無法復原，請確認已備份資料庫。`)) return;

    this.disabled = true;
    this.textContent = '刪除中...';

    /* 分批送出，每批 200 個 ID */
    const chunks = [];
    for(let i=0; i<ids.length; i+=200) chunks.push(ids.slice(i,i+200));

    let totalDel = 0;
    let idx = 0;

    function next(){
        if(idx >= chunks.length){
            updateTotalUI(Number(document.getElementById('sc-total-count').textContent.replace(/,/g,'')) - totalDel);
            alert(`✅ 完成！共刪除 ${totalDel} 個帳號。`);
            document.getElementById('sc-delete-selected-btn').disabled = false;
            document.getElementById('sc-delete-selected-btn').textContent = '🗑️ 刪除勾選的帳號';
            loadPage(1);
            return;
        }

        const params = new URLSearchParams({ action:'spam_cleaner_delete_ids', nonce });
        chunks[idx].forEach(id => params.append('ids[]', id));

        fetch(ajaxUrl, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:params })
        .then(r => r.json())
        .then(res => {
            if(res.success){ totalDel += res.data.deleted; }
            idx++;
            next();
        })
        .catch(() => { idx++; next(); });
    }
    next();
});

/* ── 全部批次刪除 ─────────────────────────────────────────── */
document.getElementById('sc-start-btn').addEventListener('click', function(){
    if(running) return;
    if(!confirm('確定要刪除所有符合使用者名稱關鍵字的訂閱者帳號？\n（已排除的帳號不在此保護範圍內）\n此操作無法復原！')) return;
    stopped=false; running=true; deletedSum=0; skippedSum=0;
    totalInit = parseInt((document.getElementById('sc-total-count').textContent||'0').replace(/,/g,''),10)||1;
    document.getElementById('sc-progress-wrap').style.display='block';
    document.getElementById('sc-start-btn').style.display='none';
    document.getElementById('sc-stop-btn').style.display='inline-block';
    appendLog('info','開始批次刪除，初始：'+totalInit.toLocaleString()+' 筆');
    runBatch();
});

document.getElementById('sc-stop-btn').addEventListener('click', function(){
    stopped=true;
    appendLog('warn','⏹ 已暫停');
    finishBatchUI();
});

function runBatch(){
    if(stopped){ finishBatchUI(); return; }
    post('spam_cleaner_delete_batch',{speed_mode:speedMode})
    .then(res => {
        if(!res.success){ appendLog('warn','❌ '+JSON.stringify(res.data)); finishBatchUI(); return; }
        const d = res.data;
        deletedSum  += Number(d.deleted||0);
        skippedSum  += Number(d.skipped||0);
        const rem = Number(d.remaining||0);
        document.getElementById('sc-deleted-sum').textContent = deletedSum.toLocaleString();
        document.getElementById('sc-skipped-sum').textContent = skippedSum.toLocaleString();
        document.getElementById('sc-remaining').textContent   = rem.toLocaleString();
        document.getElementById('sc-diag').textContent = '📊 '+d.diag;
        updateTotalUI(rem);

        const pct = Math.min(100, Math.round((1-(rem/totalInit))*100));
        document.getElementById('sc-progress-bar').style.width = pct+'%';
        document.getElementById('sc-progress-pct').textContent = pct+'%';
        document.getElementById('sc-progress-label').textContent = '本批刪除 '+d.deleted+' 筆，'+d.mode;

        appendLog('ok','✅ 本批刪除 '+d.deleted+'，剩餘 '+rem.toLocaleString()+'｜'+d.mode);

        if(rem<=0 || Number(d.deleted)===0){
            appendLog('ok','🎉 完成！共刪除 '+deletedSum.toLocaleString()+' 筆');
            document.getElementById('sc-progress-label').textContent='✅ 全部完成';
            finishBatchUI();
            setTimeout(()=>loadPage(1), 1000);
        } else {
            setTimeout(runBatch, 300);
        }
    })
    .catch(err =>{ appendLog('warn','網路錯誤，3秒後重試：'+err); setTimeout(runBatch,3000); });
}

function finishBatchUI(){
    running=false;
    document.getElementById('sc-start-btn').style.display='inline-block';
    document.getElementById('sc-stop-btn').style.display='none';
}

function escHtml(s){
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

})();
</script>
<?php
}
