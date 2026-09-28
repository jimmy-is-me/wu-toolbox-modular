<?php
/**
 * Module: visitor-tracking
 * Asynchronous visitor/member analytics, adapted for the WU Toolbox module lifecycle.
 */

defined('ABSPATH') || exit;

defined('WUTM_VT_VERSION') || define('WUTM_VT_VERSION', '1.0.1');
defined('WUTM_VT_MAX_TABLE_MB') || define('WUTM_VT_MAX_TABLE_MB', 80);
defined('WUTM_VT_MAX_TABLE_ROWS') || define('WUTM_VT_MAX_TABLE_ROWS', 80000);
defined('WUTM_VT_MIN_WRITE_INTERVAL') || define('WUTM_VT_MIN_WRITE_INTERVAL', 30);
defined('WUTM_VT_MAX_WRITES_PER_MINUTE') || define('WUTM_VT_MAX_WRITES_PER_MINUTE', 200);
defined('WUTM_VT_QUERY_TIMEOUT_MS') || define('WUTM_VT_QUERY_TIMEOUT_MS', 1500);

function wutm_vt_table_name() {
	global $wpdb;
	// Keep this module's records isolated from the standalone reference plugin.
	return $wpdb->prefix . 'wutm_visitor_logs';
}

function wutm_vt_get_settings() {
	$defaults = [
		'heartbeat_minutes' => 5,
		'retention_days' => 14,
		'exclude_roles' => ['administrator'],
		'exclude_bots' => 1,
		'kill_switch' => 0,
	];
	$saved = get_option('wutm_vt_settings', []);
	$settings = wp_parse_args(is_array($saved) ? $saved : [], $defaults);
	$settings['heartbeat_minutes'] = min(60, max(3, absint($settings['heartbeat_minutes'])));
	$settings['retention_days'] = min(30, max(1, absint($settings['retention_days'])));
	$settings['exclude_roles'] = array_values(array_filter(array_map('sanitize_key', (array) $settings['exclude_roles'])));
	$settings['exclude_bots'] = empty($settings['exclude_bots']) ? 0 : 1;
	$settings['kill_switch'] = empty($settings['kill_switch']) ? 0 : 1;
	return $settings;
}

function wutm_vt_ensure_schema() {
	if (get_option('wutm_vt_schema_version') === WUTM_VT_VERSION) return;
	global $wpdb;
	$table = wutm_vt_table_name();
	$charset_collate = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		visitor_uid varchar(40) NOT NULL,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		ip_address varchar(45) NOT NULL DEFAULT '',
		page_url varchar(300) NOT NULL,
		page_title varchar(150) NOT NULL DEFAULT '',
		visit_date date NOT NULL,
		first_seen datetime NOT NULL,
		last_seen datetime NOT NULL,
		visit_count int unsigned NOT NULL DEFAULT 1,
		PRIMARY KEY  (id),
		KEY visitor_uid (visitor_uid),
		KEY last_seen (last_seen),
		KEY user_id (user_id),
		KEY page_url (page_url(100)),
		KEY visitor_page_date (visitor_uid, page_url(100), visit_date)
	) ENGINE=InnoDB {$charset_collate};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);
	// Preserve rows made by an earlier development build before enabling daily PV buckets.
	$wpdb->query("UPDATE {$table} SET visit_date = DATE(last_seen) WHERE visit_date IS NULL OR visit_date = '0000-00-00'");
	update_option('wutm_vt_schema_version', WUTM_VT_VERSION, false);
	if (false === get_option('wutm_vt_settings', false)) {
		update_option('wutm_vt_settings', wutm_vt_get_settings(), false);
	}
}
add_action('init', 'wutm_vt_ensure_schema', 1);

function wutm_vt_sync_schedule($enabled = null) {
	$enabled = null === $enabled ? wutm_is_enabled('visitor-tracking') : (bool) $enabled;
	if (!$enabled) {
		wp_clear_scheduled_hook('wutm_vt_daily_cleanup');
		wp_clear_scheduled_hook('wutm_vt_hourly_health_check');
		return;
	}
	if (!wp_next_scheduled('wutm_vt_daily_cleanup')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wutm_vt_daily_cleanup');
	if (!wp_next_scheduled('wutm_vt_hourly_health_check')) wp_schedule_event(time() + 15 * MINUTE_IN_SECONDS, 'hourly', 'wutm_vt_hourly_health_check');
}
add_action('init', 'wutm_vt_sync_schedule', 5);
add_action('wutm_module_toggled', function ($key, $enabled) {
	if ($key === 'visitor-tracking') {
		wutm_vt_sync_schedule($enabled);
		if (!$enabled) {
			delete_transient('wutm_vt_hard_limit_reached');
			wutm_vt_clear_report_cache();
		}
	}
}, 10, 2);

function wutm_vt_is_hard_limit_reached() {
	$cached = get_transient('wutm_vt_hard_limit_reached');
	if (false !== $cached) return (bool) $cached;
	global $wpdb;
	$table = wutm_vt_table_name();
	$count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
	$size = (float) $wpdb->get_var($wpdb->prepare(
		"SELECT ROUND((data_length + index_length) / 1024 / 1024, 2) FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s",
		DB_NAME,
		$table
	));
	$over = $count >= WUTM_VT_MAX_TABLE_ROWS || $size >= WUTM_VT_MAX_TABLE_MB;
	set_transient('wutm_vt_hard_limit_reached', $over ? 1 : 0, 5 * MINUTE_IN_SECONDS);
	return $over;
}

function wutm_vt_is_global_rate_limited() {
	$key = 'wutm_vt_rate_' . gmdate('YmdHi');
	return (int) get_transient($key) >= WUTM_VT_MAX_WRITES_PER_MINUTE;
}

function wutm_vt_increment_global_rate_counter() {
	$key = 'wutm_vt_rate_' . gmdate('YmdHi');
	set_transient($key, (int) get_transient($key) + 1, 90);
}

function wutm_vt_anonymize_ip($ip = '') {
	$ip = trim((string) $ip);
	if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
		$parts = explode('.', $ip);
		$parts[3] = 'xxx';
		return implode('.', $parts);
	}
	if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
		$packed = inet_pton($ip);
		return $packed ? inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64' : 'masked';
	}
	return '';
}

function wutm_vt_get_request_ip() {
	// Do not trust proxy headers unless WordPress is explicitly configured behind a proxy.
	return wutm_vt_anonymize_ip(isset($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : '');
}

function wutm_vt_enqueue_tracker() {
	if (is_admin()) return;
	$settings = wutm_vt_get_settings();
	if (!empty($settings['kill_switch'])) return;
	if (is_user_logged_in()) {
		$user = wp_get_current_user();
		if (array_intersect($settings['exclude_roles'], (array) $user->roles)) return;
	}
	$rest_url = esc_url_raw(rest_url('wutm-vt/v1/track'));
	$nonce = wp_create_nonce('wp_rest');
	$user_id = get_current_user_id();
	$heartbeat_ms = (int) $settings['heartbeat_minutes'] * MINUTE_IN_SECONDS * 1000;
	wp_register_script('wutm-vt-tracker', false, [], WUTM_VT_VERSION, true);
	wp_enqueue_script('wutm-vt-tracker');
	$script = "(function(){'use strict';try{var k='wutm_vt_visitor_uid',uid=localStorage.getItem(k);if(!uid){uid='v_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,18);localStorage.setItem(k,uid)}var view='p_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,14),last=0;function ping(){if(document.hidden||!navigator.onLine||Date.now()-last<30000)return;last=Date.now();fetch('" . esc_js($rest_url) . "',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':'" . esc_js($nonce) . "'},body:JSON.stringify({url:location.href,title:document.title,uid:uid,view_id:view}),keepalive:true}).catch(function(){})}setTimeout(ping,3000);setInterval(ping," . $heartbeat_ms . ");document.addEventListener('visibilitychange',function(){if(!document.hidden)ping()})}catch(e){}})();";
	wp_add_inline_script('wutm-vt-tracker', $script);
}
add_action('wp_enqueue_scripts', 'wutm_vt_enqueue_tracker', 30);

function wutm_vt_register_rest_route() {
	register_rest_route('wutm-vt/v1', '/track', [
		'methods' => 'POST',
		'callback' => 'wutm_vt_handle_tracking_request',
		'permission_callback' => '__return_true',
	]);
}
add_action('rest_api_init', 'wutm_vt_register_rest_route');

function wutm_vt_handle_tracking_request($request) {
	$settings = wutm_vt_get_settings();
	if (!empty($settings['kill_switch'])) return rest_ensure_response(['success' => true, 'skipped' => 'disabled']);
	$nonce = $request->get_header('X-WP-Nonce');
	if (!$nonce || !wp_verify_nonce($nonce, 'wp_rest')) return new WP_REST_Response(['success' => false], 403);
	if (is_user_logged_in()) {
		$current = wp_get_current_user();
		if (array_intersect($settings['exclude_roles'], (array) $current->roles)) return rest_ensure_response(['success' => true, 'skipped' => 'excluded_role']);
	}
	if (wutm_vt_is_hard_limit_reached() || wutm_vt_is_global_rate_limited()) return rest_ensure_response(['success' => true, 'skipped' => 'limit']);
	if (!empty($settings['exclude_bots'])) {
		$ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		if ('' === $ua || preg_match('/bot|crawl|spider|slurp|bing|google|facebookexternalhit|preview/i', $ua)) return rest_ensure_response(['success' => true, 'skipped' => 'bot']);
	}
	$params = $request->get_json_params();
	$uid = isset($params['uid']) ? sanitize_text_field(substr((string) $params['uid'], 0, 40)) : '';
	$view_id = isset($params['view_id']) ? sanitize_text_field(substr((string) $params['view_id'], 0, 40)) : '';
	$url = isset($params['url']) ? esc_url_raw((string) $params['url']) : '';
	$title = isset($params['title']) ? sanitize_text_field(substr((string) $params['title'], 0, 150)) : '';
	$parsed = wp_parse_url($url);
	$home = wp_parse_url(home_url('/'));
	if (!$uid || !preg_match('/^v_[a-z0-9_]+$/i', $uid) || !$view_id || !preg_match('/^p_[a-z0-9_]+$/i', $view_id) || empty($parsed['host']) || empty($home['host']) || strtolower($parsed['host']) !== strtolower($home['host'])) {
		return new WP_REST_Response(['success' => false], 400);
	}
	// Store only same-site path: query arguments can contain secrets and create unbounded URL cardinality.
	$page_url = home_url(isset($parsed['path']) ? $parsed['path'] : '/');
	$throttle = 'wutm_vt_throttle_' . md5($uid . '|' . $page_url);
	if (get_transient($throttle)) return rest_ensure_response(['success' => true, 'skipped' => 'throttled']);
	set_transient($throttle, 1, WUTM_VT_MIN_WRITE_INTERVAL);
	$generation = (int) get_option('wutm_vt_data_generation', 1);
	$pageview_key = 'wutm_vt_pageview_' . md5($generation . '|' . $uid . '|' . $page_url . '|' . $view_id);
	$is_new_pageview = !get_transient($pageview_key);
	global $wpdb;
	$table = wutm_vt_table_name();
	$now = current_time('mysql');
	$visit_date = current_time('Y-m-d');
	$recent_cutoff = current_datetime()->modify('-1 hour')->format('Y-m-d H:i:s');
	$server_info = method_exists($wpdb, 'db_server_info') ? $wpdb->db_server_info() : '';
	if ($server_info && stripos($server_info, 'mariadb') === false && preg_match('/^(\d+\.\d+\.\d+)/', $server_info, $mysql_version) && version_compare($mysql_version[1], '5.7.0', '>=')) {
		// MySQL 5.7+ bounds the endpoint's SELECT work; unsupported database variants are left untouched.
		$wpdb->query('SET SESSION MAX_EXECUTION_TIME = ' . (int) WUTM_VT_QUERY_TIMEOUT_MS);
	}
	$existing_id = $wpdb->get_var($wpdb->prepare(
		"SELECT id FROM {$table} WHERE visitor_uid = %s AND page_url = %s AND visit_date = %s AND last_seen > %s ORDER BY last_seen DESC LIMIT 1",
		$uid,
		$page_url,
		$visit_date,
		$recent_cutoff
	));
	$user_id = get_current_user_id(); // Never accept an identity supplied by the browser.
	if ($existing_id) {
		$updated = $wpdb->query($wpdb->prepare(
		"UPDATE {$table} SET last_seen = %s, user_id = %d, page_title = %s, visit_count = visit_count + %d WHERE id = %d",
		$now,
		$user_id,
		$title,
			$is_new_pageview ? 1 : 0,
			(int) $existing_id
		));
		$saved = false !== $updated;
	} else {
		$saved = false !== $wpdb->insert($table, [
			'visitor_uid' => $uid,
			'user_id' => $user_id,
			'ip_address' => wutm_vt_get_request_ip(),
			'page_url' => $page_url,
			'page_title' => $title,
			'visit_date' => $visit_date,
			'first_seen' => $now,
			'last_seen' => $now,
			'visit_count' => 1,
		], ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d']);
	}
	if (!$saved) {
		delete_transient($throttle);
		return new WP_REST_Response(['success' => false], 500);
	}
	if ($is_new_pageview) set_transient($pageview_key, 1, DAY_IN_SECONDS);
	wutm_vt_increment_global_rate_counter();
	return rest_ensure_response(['success' => true]);
}

add_filter('rest_pre_serve_request', function ($served, $result, $request) {
	if (strpos($request->get_route(), '/wutm-vt/v1/track') !== false) {
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('Pragma: no-cache');
	}
	return $served;
}, 10, 3);

function wutm_vt_range_start($range) {
	if ($range === 'all') return '';
	$days = in_array((string) $range, ['1', '7', '30'], true) ? (int) $range : 7;
	return current_datetime()->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
}

function wutm_vt_get_stats($range = 'overview') {
	$key = 'wutm_vt_stats_' . sanitize_key($range);
	$cached = get_transient($key);
	if (false !== $cached) return $cached;
	global $wpdb;
	$table = wutm_vt_table_name();
	$today = current_time('Y-m-d');
	$week = wutm_vt_range_start('7');
	$minutes = (int) wutm_vt_get_settings()['heartbeat_minutes'];
	$online_cutoff = current_datetime()->modify('-' . $minutes . ' minutes')->format('Y-m-d H:i:s');
	$stats = [
		'today_uv' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT visitor_uid) FROM {$table} WHERE visit_date >= %s", $today)),
		'today_pv' => (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(visit_count),0) FROM {$table} WHERE visit_date >= %s", $today)),
		'today_members' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE visit_date >= %s AND user_id > 0", $today)),
		'week_uv' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT visitor_uid) FROM {$table} WHERE visit_date >= %s", $week)),
		'online_now' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT visitor_uid) FROM {$table} WHERE last_seen >= %s", $online_cutoff)),
	];
	set_transient($key, $stats, 5 * MINUTE_IN_SECONDS);
	return $stats;
}

function wutm_vt_register_admin_pages() {
	add_submenu_page('wu-toolbox-modular', '瀏覽追蹤數據', '瀏覽追蹤數據', 'manage_options', 'wu-visitor-tracker', 'wutm_vt_render_overview');
	add_submenu_page('wu-toolbox-modular', '頁面熱門排行', '頁面熱門排行', 'manage_options', 'wu-visitor-ranking', 'wutm_vt_render_ranking');
	add_submenu_page('wu-toolbox-modular', '追蹤設定', '追蹤設定', 'manage_options', 'wu-visitor-settings', 'wutm_vt_render_settings');
	add_submenu_page('wu-toolbox-modular', '追蹤健康監控', '追蹤健康監控', 'manage_options', 'wu-visitor-health', 'wutm_vt_render_health');
}
add_action('admin_menu', 'wutm_vt_register_admin_pages', 80);

function wutm_vt_require_admin() {
	if (!current_user_can('manage_options')) wp_die(esc_html__('您沒有權限檢視此頁面。', 'wu-toolbox-modular'));
}

function wutm_vt_render_overview() {
	wutm_vt_require_admin();
	global $wpdb;
	$table = wutm_vt_table_name();
	$stats = wutm_vt_get_stats();
	$paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
	$per_page = 50;
	$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
	$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY last_seen DESC LIMIT %d OFFSET %d", $per_page, ($paged - 1) * $per_page));
	$export = wp_nonce_url(add_query_arg('wutm_vt_action', 'export_csv', admin_url('admin.php?page=wu-visitor-tracker')), 'wutm_vt_export_csv');
	?>
	<div class="wrap wutm-vt-wrap">
		<h1>瀏覽追蹤數據</h1>
		<p>訪客追蹤在頁面載入 3 秒後以背景請求記錄，不會阻塞網站內容；追蹤期間與資料保留範圍可在「追蹤設定」調整。</p>
		<?php if (!empty(wutm_vt_get_settings()['kill_switch'])) : ?><div class="notice notice-error"><p>追蹤目前已緊急停用，前台不會送出資料。</p></div><?php endif; ?>
		<?php if (wutm_vt_is_hard_limit_reached()) : ?><div class="notice notice-warning"><p>資料表已達內建上限（<?php echo esc_html(number_format(WUTM_VT_MAX_TABLE_ROWS)); ?> 筆或 <?php echo esc_html(WUTM_VT_MAX_TABLE_MB); ?> MB），新資料暫停寫入。可至健康監控檢查並清除舊資料。</p></div><?php endif; ?>
		<p><a class="page-title-action" href="<?php echo esc_url($export); ?>">匯出瀏覽資料 CSV</a></p>
		<div class="wutm-vt-cards">
			<?php foreach (['online_now' => '目前在線', 'today_uv' => '今日獨立訪客（UV）', 'today_pv' => '今日瀏覽次數（PV）', 'today_members' => '今日會員訪問數', 'week_uv' => '近 7 日獨立訪客'] as $key => $label) : ?>
				<div class="wutm-vt-card"><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html(number_format((int) $stats[$key])); ?></strong></div>
			<?php endforeach; ?>
		</div>
		<p class="description">資料保留 <?php echo esc_html(wutm_vt_get_settings()['retention_days']); ?> 天；目前 <?php echo esc_html(number_format($total)); ?> 筆（硬性上限 <?php echo esc_html(number_format(WUTM_VT_MAX_TABLE_ROWS)); ?> 筆／<?php echo esc_html(WUTM_VT_MAX_TABLE_MB); ?> MB）。</p>
		<table class="widefat fixed striped"><thead><tr><th>身分</th><th>遮蔽 IP</th><th>頁面</th><th>網址</th><th>瀏覽次數</th><th>首次瀏覽</th><th>最後活動</th></tr></thead><tbody>
		<?php if ($rows) : foreach ($rows as $row) :
			$user = $row->user_id ? get_userdata($row->user_id) : false;
			$identity = $row->user_id ? ($user ? '會員：' . $user->user_login : '未知會員') : '訪客'; ?>
			<tr><td><?php echo esc_html($identity); ?></td><td><?php echo esc_html($row->ip_address); ?></td><td><?php echo esc_html($row->page_title); ?></td><td><a href="<?php echo esc_url($row->page_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(wp_trim_words($row->page_url, 8)); ?></a></td><td><?php echo esc_html(number_format((int) $row->visit_count)); ?></td><td><?php echo esc_html($row->first_seen); ?></td><td><?php echo esc_html($row->last_seen); ?></td></tr>
		<?php endforeach; else : ?><tr><td colspan="7">目前尚無瀏覽資料。</td></tr><?php endif; ?>
		</tbody></table>
		<?php $pages = (int) ceil($total / $per_page); if ($pages > 1) : ?><div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post(paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages])); ?></div></div><?php endif; ?>
	</div>
	<?php wutm_vt_admin_styles();
}

function wutm_vt_render_ranking() {
	wutm_vt_require_admin();
	global $wpdb;
	$table = wutm_vt_table_name();
	$range = isset($_GET['range']) ? sanitize_key(wp_unslash($_GET['range'])) : '7';
	if (!in_array($range, ['1', '7', '30', 'all'], true)) $range = '7';
	$key = 'wutm_vt_ranking_' . $range;
	$rows = get_transient($key);
	if (false === $rows) {
		$start = wutm_vt_range_start($range);
		$where = $start ? $wpdb->prepare(' WHERE visit_date >= %s', $start) : '';
		$rows = $wpdb->get_results("SELECT page_url, MAX(page_title) AS page_title, COUNT(DISTINCT visitor_uid) AS uv, SUM(visit_count) AS pv FROM {$table}{$where} GROUP BY page_url ORDER BY pv DESC LIMIT 50");
		set_transient($key, $rows, 10 * MINUTE_IN_SECONDS);
	}
	$export = wp_nonce_url(add_query_arg(['wutm_vt_action' => 'export_ranking_csv', 'range' => $range], admin_url('admin.php?page=wu-visitor-ranking')), 'wutm_vt_export_ranking_csv');
	?>
	<div class="wrap wutm-vt-wrap"><h1>頁面熱門排行</h1><p><a class="page-title-action" href="<?php echo esc_url($export); ?>">匯出排行 CSV</a></p>
		<ul class="subsubsub"><?php foreach (['1' => '今日', '7' => '近 7 日', '30' => '近 30 日', 'all' => '全部'] as $value => $label) : ?><li><a class="<?php echo $range === $value ? 'current' : ''; ?>" href="<?php echo esc_url(add_query_arg(['page' => 'wu-visitor-ranking', 'range' => $value], admin_url('admin.php'))); ?>"><?php echo esc_html($label); ?></a> | </li><?php endforeach; ?></ul><br class="clear">
		<p class="description">排行榜快取 10 分鐘，以避免在管理頁重複掃描統計資料。</p>
		<table class="widefat fixed striped"><thead><tr><th>排名</th><th>頁面</th><th>網址</th><th>獨立訪客（UV）</th><th>瀏覽次數（PV）</th></tr></thead><tbody>
		<?php if ($rows) : $rank = 1; foreach ($rows as $row) : ?><tr><td><?php echo esc_html('#' . $rank++); ?></td><td><?php echo esc_html($row->page_title ?: '（無標題）'); ?></td><td><a href="<?php echo esc_url($row->page_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(wp_trim_words($row->page_url, 10)); ?></a></td><td><?php echo esc_html(number_format((int) $row->uv)); ?></td><td><?php echo esc_html(number_format((int) $row->pv)); ?></td></tr><?php endforeach; else : ?><tr><td colspan="5">此區間尚無資料。</td></tr><?php endif; ?>
		</tbody></table>
	</div><?php wutm_vt_admin_styles();
}

function wutm_vt_render_settings() {
	wutm_vt_require_admin();
	$settings = wutm_vt_get_settings();
	$roles = wp_roles()->get_names();
	?>
	<div class="wrap wutm-vt-wrap"><h1>瀏覽追蹤設定</h1>
		<?php if (isset($_GET['updated'])) : ?><div class="notice notice-success is-dismissible"><p>追蹤設定已儲存。</p></div><?php endif; ?>
		<?php if (isset($_GET['cleared'])) : ?><div class="notice notice-success is-dismissible"><p>追蹤資料已清空。</p></div><?php endif; ?>
		<div class="notice notice-info inline"><p>追蹤請求由瀏覽器延遲 3 秒後以非同步方式傳送。伺服器端另設每分鐘寫入上限、單訪客／頁面 30 秒節流、資料表 80,000 筆／80 MB 上限，超限自動暫停寫入。</p></div>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="wutm_vt_save_settings"><?php wp_nonce_field('wutm_vt_save_settings'); ?>
		<table class="form-table"><tbody>
		<tr><th scope="row">緊急停用</th><td><label><input type="checkbox" name="kill_switch" value="1" <?php checked($settings['kill_switch']); ?>> 停止前台追蹤請求</label></td></tr>
		<tr><th scope="row"><label for="wutm-vt-heartbeat">心跳間隔</label></th><td><input id="wutm-vt-heartbeat" class="small-text" type="number" min="3" max="60" name="heartbeat_minutes" value="<?php echo esc_attr($settings['heartbeat_minutes']); ?>"> 分鐘（至少 3 分鐘）</td></tr>
		<tr><th scope="row"><label for="wutm-vt-retention">資料保留天數</label></th><td><input id="wutm-vt-retention" class="small-text" type="number" min="1" max="30" name="retention_days" value="<?php echo esc_attr($settings['retention_days']); ?>"> 天（最多 30 天）</td></tr>
		<tr><th scope="row">排除角色</th><td><?php foreach ($roles as $role => $label) : ?><label class="wutm-vt-role"><input type="checkbox" name="exclude_roles[]" value="<?php echo esc_attr($role); ?>" <?php checked(in_array($role, $settings['exclude_roles'], true)); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?><p class="description">預設排除管理員，避免自己的測試和維護操作進入流量統計。</p></td></tr>
		<tr><th scope="row">機器人過濾</th><td><label><input type="checkbox" name="exclude_bots" value="1" <?php checked($settings['exclude_bots']); ?>> 排除常見搜尋引擎、爬蟲與預覽機器人</label></td></tr>
		</tbody></table><?php submit_button('儲存設定'); ?></form>
		<hr><h2>清空追蹤資料</h2><p>此操作會永久刪除訪客紀錄，不能復原；模組功能與設定不會被刪除。</p>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('確定永久刪除所有瀏覽追蹤資料？此操作無法復原。');"><input type="hidden" name="action" value="wutm_vt_clear_data"><?php wp_nonce_field('wutm_vt_clear_data'); ?><?php submit_button('清空所有追蹤資料', 'delete'); ?></form>
		<h2>快取服務注意事項</h2><p>若全頁快取層攔截 REST 請求，請將下列路徑設為不快取：<code><?php echo esc_html(rest_url('wutm-vt/v1/track')); ?></code></p>
	</div><?php wutm_vt_admin_styles();
}

function wutm_vt_save_settings() {
	if (!current_user_can('manage_options')) wp_die(esc_html__('您沒有權限執行此操作。', 'wu-toolbox-modular'));
	check_admin_referer('wutm_vt_save_settings');
	$roles = isset($_POST['exclude_roles']) ? array_map('sanitize_key', (array) wp_unslash($_POST['exclude_roles'])) : [];
	update_option('wutm_vt_settings', [
		'heartbeat_minutes' => min(60, max(3, absint($_POST['heartbeat_minutes'] ?? 5))),
		'retention_days' => min(30, max(1, absint($_POST['retention_days'] ?? 14))),
		'exclude_roles' => array_values(array_intersect($roles, array_keys(wp_roles()->get_names()))),
		'exclude_bots' => isset($_POST['exclude_bots']) ? 1 : 0,
		'kill_switch' => isset($_POST['kill_switch']) ? 1 : 0,
	], false);
	wp_safe_redirect(add_query_arg(['page' => 'wu-visitor-settings', 'updated' => '1'], admin_url('admin.php')));
	exit;
}
add_action('admin_post_wutm_vt_save_settings', 'wutm_vt_save_settings');

function wutm_vt_clear_data() {
	if (!current_user_can('manage_options')) wp_die(esc_html__('您沒有權限執行此操作。', 'wu-toolbox-modular'));
	check_admin_referer('wutm_vt_clear_data');
	global $wpdb;
	$wpdb->query('TRUNCATE TABLE ' . wutm_vt_table_name());
	update_option('wutm_vt_data_generation', (int) get_option('wutm_vt_data_generation', 1) + 1, false);
	delete_transient('wutm_vt_hard_limit_reached');
	wutm_vt_clear_report_cache();
	wp_safe_redirect(add_query_arg(['page' => 'wu-visitor-settings', 'cleared' => '1'], admin_url('admin.php')));
	exit;
}
add_action('admin_post_wutm_vt_clear_data', 'wutm_vt_clear_data');

function wutm_vt_clear_report_cache() {
	foreach (['1', '7', '30', 'all', 'overview', 'dashboard'] as $range) {
		delete_transient('wutm_vt_stats_' . $range);
		delete_transient('wutm_vt_ranking_' . $range);
	}
}

function wutm_vt_render_health() {
	wutm_vt_require_admin();
	global $wpdb;
	$table = wutm_vt_table_name();
	$count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
	$size = $wpdb->get_row($wpdb->prepare("SELECT ROUND(data_length/1024/1024,2) AS data_mb, ROUND(index_length/1024/1024,2) AS index_mb FROM information_schema.TABLES WHERE table_schema=%s AND table_name=%s", DB_NAME, $table));
	$total_mb = $size ? (float) $size->data_mb + (float) $size->index_mb : 0;
	$rate = (int) get_transient('wutm_vt_rate_' . gmdate('YmdHi'));
	$limited = wutm_vt_is_hard_limit_reached();
	?>
	<div class="wrap wutm-vt-wrap"><h1>追蹤健康監控</h1>
		<div class="notice <?php echo $limited ? 'notice-error' : 'notice-success'; ?> inline"><p><?php echo $limited ? '已達資料表硬性上限，新資料已暫停寫入。' : '資料表在安全容量內，追蹤功能可正常寫入。'; ?></p></div>
		<table class="widefat striped" style="max-width:720px"><tbody>
		<tr><th>資料筆數</th><td><?php echo esc_html(number_format($count) . ' / ' . number_format(WUTM_VT_MAX_TABLE_ROWS)); ?></td></tr>
		<tr><th>資料表大小</th><td><?php echo esc_html(number_format($total_mb, 2) . ' MB / ' . WUTM_VT_MAX_TABLE_MB . ' MB'); ?></td></tr>
		<tr><th>本分鐘寫入量</th><td><?php echo esc_html($rate . ' / ' . WUTM_VT_MAX_WRITES_PER_MINUTE); ?></td></tr>
		<tr><th>單一訪客／頁面最短寫入間隔</th><td><?php echo esc_html(WUTM_VT_MIN_WRITE_INTERVAL . ' 秒'); ?></td></tr>
		<tr><th>資料保留</th><td><?php echo esc_html(wutm_vt_get_settings()['retention_days'] . ' 天；每日批次清理'); ?></td></tr>
		</tbody></table></div>
	<?php wutm_vt_admin_styles();
}

function wutm_vt_handle_csv_export() {
	if (!isset($_GET['wutm_vt_action']) || !current_user_can('manage_options')) return;
	global $wpdb;
	$table = wutm_vt_table_name();
	$action = sanitize_key(wp_unslash($_GET['wutm_vt_action']));
	if ($action === 'export_csv') {
		check_admin_referer('wutm_vt_export_csv');
		$rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY last_seen DESC LIMIT 5000");
		$filename = 'visitor-logs-' . gmdate('Ymd-His') . '.csv';
		$headers = ['身分', 'User ID', '遮蔽 IP', '頁面標題', '網址', '瀏覽次數', '首次瀏覽', '最後活動'];
	} elseif ($action === 'export_ranking_csv') {
		check_admin_referer('wutm_vt_export_ranking_csv');
		$range = isset($_GET['range']) ? sanitize_key(wp_unslash($_GET['range'])) : '7';
		if (!in_array($range, ['1', '7', '30', 'all'], true)) $range = '7';
		$start = wutm_vt_range_start($range);
		$where = $start ? $wpdb->prepare(' WHERE visit_date >= %s', $start) : '';
		$rows = $wpdb->get_results("SELECT page_url, MAX(page_title) AS page_title, COUNT(DISTINCT visitor_uid) AS uv, SUM(visit_count) AS pv FROM {$table}{$where} GROUP BY page_url ORDER BY pv DESC LIMIT 500");
		$filename = 'page-ranking-' . gmdate('Ymd-His') . '.csv';
		$headers = ['排名', '頁面標題', '網址', '獨立訪客（UV）', '瀏覽次數（PV）'];
	} else {
		return;
	}
	if (headers_sent()) wp_die(esc_html__('無法輸出 CSV，頁面已開始輸出。', 'wu-toolbox-modular'));
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename=' . $filename);
	$out = fopen('php://output', 'w');
	fwrite($out, "\xEF\xBB\xBF");
	fputcsv($out, $headers);
	$rank = 1;
	foreach ((array) $rows as $row) {
		if ($action === 'export_csv') {
			$user = $row->user_id ? get_userdata($row->user_id) : false;
			$data = [$row->user_id ? '會員（' . ($user ? $user->user_login : '未知') . '）' : '訪客', $row->user_id, $row->ip_address, $row->page_title, $row->page_url, $row->visit_count, $row->first_seen, $row->last_seen];
		} else {
			$data = [$rank++, $row->page_title, $row->page_url, $row->uv, $row->pv];
		}
		foreach ($data as &$cell) {
			if (is_string($cell) && preg_match('/^[=+@\-\t\r]/', $cell)) $cell = "'" . $cell;
		}
		unset($cell);
		fputcsv($out, $data);
	}
	fclose($out);
	exit;
}
add_action('admin_init', 'wutm_vt_handle_csv_export');

function wutm_vt_run_daily_cleanup() {
	global $wpdb;
	$days = (int) wutm_vt_get_settings()['retention_days'];
	$table = wutm_vt_table_name();
	$cutoff = current_datetime()->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
	do {
		$deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE last_seen < %s LIMIT 2000", $cutoff));
		if ($deleted > 0) usleep(150000);
	} while ($deleted >= 2000);
	delete_transient('wutm_vt_hard_limit_reached');
	wutm_vt_clear_report_cache();
}
add_action('wutm_vt_daily_cleanup', 'wutm_vt_run_daily_cleanup');

function wutm_vt_run_health_check() {
	// The capacity query is transient-cached; this cron refreshes it outside visitor requests.
	delete_transient('wutm_vt_hard_limit_reached');
	wutm_vt_is_hard_limit_reached();
}
add_action('wutm_vt_hourly_health_check', 'wutm_vt_run_health_check');

function wutm_vt_dashboard_widget() {
	if (!current_user_can('manage_options')) return;
	$stats = wutm_vt_get_stats('dashboard');
	echo '<p>以下數據在 5 分鐘內快取，追蹤資料不會在後台首頁即時掃描整張資料表。</p><div class="wutm-vt-cards wutm-vt-dashboard-cards">';
	foreach (['online_now' => '目前在線', 'today_uv' => '今日 UV', 'today_pv' => '今日 PV', 'week_uv' => '近 7 日 UV'] as $key => $label) {
		echo '<div class="wutm-vt-card"><span>' . esc_html($label) . '</span><strong>' . esc_html(number_format((int) $stats[$key])) . '</strong></div>';
	}
	echo '</div><p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=wu-visitor-tracker')) . '">查看瀏覽追蹤數據</a></p>';
}
add_action('wp_dashboard_setup', function () {
	if (!current_user_can('manage_options')) return;
	// Registered after the operations widget at the same priority so it appears directly beneath it.
	wp_add_dashboard_widget('wutm_visitor_tracking_dashboard', '瀏覽追蹤數據', 'wutm_vt_dashboard_widget', null, null, 'normal', 'high');
}, 20);

function wutm_vt_admin_styles() {
	static $printed = false;
	if ($printed) return;
	$printed = true;
	?>
	<style>
	.wutm-vt-wrap .wutm-vt-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:18px 0}
	.wutm-vt-wrap .wutm-vt-card{background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;padding:14px 16px;box-shadow:0 1px 2px rgba(0,0,0,.04);display:flex;flex-direction:column;gap:8px}
	.wutm-vt-wrap .wutm-vt-card span{color:#646970}.wutm-vt-wrap .wutm-vt-card strong{font-size:24px;color:#1d2327}
	.wutm-vt-wrap .wutm-vt-role{display:inline-block;margin:0 16px 8px 0}
	.wutm-vt-dashboard-cards{grid-template-columns:repeat(auto-fit,minmax(120px,1fr))}
	.wutm-vt-dashboard-cards .wutm-vt-card{padding:10px 12px}.wutm-vt-dashboard-cards .wutm-vt-card strong{font-size:20px}
	</style>
	<?php
}

add_action('admin_head', function () {
	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if ($screen && ($screen->id === 'dashboard' || strpos($screen->id, 'wu-visitor-') !== false)) wutm_vt_admin_styles();
});
