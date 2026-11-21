<?php
defined('ABSPATH') || exit;

final class WUTM_ATM_Observability {
    const META_RESULTS = 'wutm_atm_meta_results';

    public static function table_ready(): bool {
        return get_option(WUTM_ATM_Queue::SCHEMA) === WUTM_ATM_Queue::VERSION && self::has_table(WUTM_ATM_Queue::table());
    }

    private static function has_table(string $name): bool {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($name))) === $name;
    }

    public static function queue(?int $now = null): array {
        global $wpdb;
        $now = $now ?? time();
        $process = wp_next_scheduled(WUTM_ATM_Queue::HOOK);
        $maintenance = wp_next_scheduled(WUTM_ATM_Queue::SCHEDULE);
        $next = min($process ?: PHP_INT_MAX, $maintenance ?: PHP_INT_MAX);
        $hooks = [WUTM_ATM_Queue::HOOK=>0, WUTM_ATM_Queue::SCHEDULE=>0];
        foreach (_get_cron_array() as $tasks) {
            foreach (array_intersect_key($tasks, $hooks) as $hook=>$instances) $hooks[$hook] += count($instances);
        }

        $ready = self::table_ready();
        $counts = array_fill_keys(WUTM_ATM_Queue::STATES, 0);
        $oldest = $stale = $test = $unknown = $last_success = 0;
        $daily = [];
        $sent_today = 0;
        if ($ready) {
            $table = WUTM_ATM_Queue::table();
            $rows = $wpdb->get_results("SELECT status,COUNT(*) AS total,MIN(event_time) AS oldest,
                MAX(updated) AS latest FROM $table GROUP BY status");
            foreach ((array) $rows as $row) {
                if (isset($counts[$row->status])) $counts[$row->status] = (int) $row->total;
                if (in_array($row->status,['pending','retry','sending'],true)) {
                    $oldest = min($oldest ?: PHP_INT_MAX, (int) $row->oldest);
                }
                if ($row->status === 'sent') $last_success = (int) $row->latest;
            }
            $active = "status IN ('pending','retry','sending')";
            $waiting = $wpdb->get_row($wpdb->prepare("SELECT SUM(status='sending' AND due<=%d) AS stale,
                SUM(test_mode=1) AS tests,SUM(test_mode IS NULL) AS unknown FROM $table WHERE $active", $now));
            $stale = (int) ($waiting->stale ?? 0);
            $test = (int) ($waiting->tests ?? 0);
            $unknown = (int) ($waiting->unknown ?? 0);
            $start = (new DateTimeImmutable('@' . $now))->setTimezone(wp_timezone())->setTime(0, 0)->getTimestamp();
            $sent_today = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status='sent' AND updated >= %d", $start));
            $daily = $wpdb->get_results($wpdb->prepare(
                "SELECT event_name,COUNT(*) AS total,SUM(status='sent') AS confirmed FROM $table
                 WHERE event_time >= %d GROUP BY event_name ORDER BY total DESC LIMIT 100", $start
            ), ARRAY_A);
        }

        $disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $waiting = $counts['pending'] + $counts['retry'] + $counts['sending'];
        $status = 'normal';
        if (!$ready || !$maintenance || max($hooks) > 1 || ($waiting && (!WUTM_ATM_Events::server_active() || !$process))) $status = 'check';
        if (($next < $now - 90) || $stale) $status = 'overdue';
        if (!$waiting && !WUTM_ATM_Events::settings()['enabled']) $status = 'inactive';

        return [
            'engine'=>'WordPress WP-Cron','spawn_disabled'=>$disabled,'table_ready'=>$ready,'status'=>$status,
            'process'=>$process ?: 0,'maintenance'=>$maintenance ?: 0,'next'=>$next === PHP_INT_MAX ? 0 : $next,
            'hook_counts'=>$hooks,'counts'=>$counts,'oldest'=>$oldest,'stale'=>$stale,'waiting_test'=>$test,
            'waiting_unknown_mode'=>$unknown,'last_success'=>$last_success,'today'=>$daily,'sent_today'=>$sent_today,
            'last_run'=>(array) get_option(WUTM_ATM_Queue::LAST_RUN, []),
            'last_cron'=>(array) get_option(WUTM_ATM_Queue::LAST_CRON, []),
        ];
    }

    public static function order(int $id): array {
        if (!function_exists('wc_get_order') || !current_user_can('manage_options')) return [];
        $order = wc_get_order($id);
        if (!$order instanceof WC_Order) return [];
        require_once __DIR__ . '/class-woocommerce-events.php';
        global $wpdb;
        $rows = [];
        if (self::table_ready()) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT event_id,status,reason,browser,browser_result,browser_blocked,amount,currency,updated FROM " . WUTM_ATM_Queue::table() .
                " WHERE order_id=%d AND event_name='Purchase' AND source='woocommerce' ORDER BY updated DESC LIMIT 10", $id
            ));
        }
        $key = WUTM_ATM_Events::event_id('Purchase', 'order:' . $id);
        $row = reset($rows) ?: null;
        foreach ($rows as $candidate) if ($candidate->event_id === $key) { $row = $candidate; break; }
        $paid = $order->get_date_paid();
        $consent = (array) apply_filters('wutm_atm_order_consent', (array) $order->get_meta(WUTM_ATM_Woo_Events::META), $order);
        $valid = WUTM_ATM_Events::consent_valid($consent, ['event_name'=>'Purchase','order_id'=>$id,'source'=>'woocommerce']);
        $products = [];
        foreach ($order->get_items() as $item) $products[] = $item->get_variation_id() ?: $item->get_product_id();
        $server = $row && $row->status !== 'browser_prepared';
        return [
            '訂單狀態'=>wc_get_order_status_name($order->get_status()),
            'WooCommerce 付款狀態'=>$order->is_paid() ? '已付款' : '未付款',
            'Purchase 付款條件'=>WUTM_ATM_Woo_Events::paid($order) ? '符合' : '不符合',
            '付款完成時間'=>self::date($paid ? $paid->getTimestamp() : 0),
            '訂單總額'=>$order->get_currency() . ' ' . $order->get_total(),
            '商品／變化商品 ID'=>implode(', ', array_unique($products)) ?: '—',
            '交易同意收據'=>$valid ? '目前可驗證' : '無有效紀錄',
            'Purchase event_id'=>$row ? $row->event_id : '尚無保留的事件紀錄',
            '伺服器狀態'=>$server ? (WUTM_ATM_Event_Admin::labels()[$row->status] ?? '尚未確認') : '尚無伺服器紀錄',
            '瀏覽器狀態'=>$row && ($row->browser || $row->browser_blocked) ? self::browser_label($row) : ($valid ? '尚無瀏覽器紀錄' : '無有效交易同意或尚無瀏覽器紀錄'),
            '已紀錄的事件金額'=>$row && $row->amount !== '' ? $row->currency . ' ' . $row->amount : '—',
            '原因'=>$row ? self::reason($row->reason) : '—',
            '防重複觀察'=>count($rows) > 1 ? '找到同訂單的多個事件 ID，請核對歷史設定與來源。' :
                '同一訂單使用固定事件 ID；其他外掛、不同帳戶或已清理的歷史請另行核對。',
        ];
    }

    public static function version_warning(string $version): string {
        $end = ['v20.0'=>'2026-09-24','v21.0'=>'2027-01-21','v22.0'=>'2027-05-20','v23.0'=>'2027-10-08',
            'v24.0'=>'2028-02-18','v25.0'=>'2028-07-29','v26.0'=>null];
        if (!array_key_exists($version, $end)) return '版本未列於內建相容性清單，請查閱 Meta 官方版本時程。';
        if ($end[$version] && gmdate('Y-m-d') >= $end[$version]) return '此版本已到 Meta 公告的停止支援日期，請確認帳戶支援版本後手動更新。';
        return '';
    }

    public static function report(): array {
        global $wpdb;
        $event = WUTM_ATM_Events::settings();
        $privacy = WUTM_ATM_Consent::settings();
        $platforms = [];
        foreach (['GA4'=>'ga4','Google Ads'=>'google_ads','GTM'=>'gtm','Meta Pixel'=>'fb_pixel'] as $label=>$key) {
            $platforms[$label] = !get_option("atm_{$key}_enabled", 0) ? '已停用' :
                (get_option("atm_{$key}_id", '') ? '已設定' : '尚未設定 ID');
        }
        $platforms['Meta CAPI'] = WUTM_ATM_Events::server_active() ? '已設定' : '未啟用或設定未完成';
        $meta = (array) get_option(self::META_RESULTS, []);
        foreach ($meta as &$result) {
            $result['current_config'] = ($result['dataset'] ?? '') === WUTM_ATM_Events::dataset() &&
                ($result['version'] ?? '') === $event['version'] && !empty($result['config_marker']) &&
                hash_equals(self::meta_marker(), $result['config_marker']);
            unset($result['config_marker']);
        }
        unset($result);
        $latest = (array) get_option('atm_last_diagnostic', []);
        $independent = false;
        foreach ((array) ($latest['items'] ?? []) as $item) {
            if (($item['status'] ?? '') === 'warning' && preg_match('/重複|其他 Meta/', (string) ($item['title'] ?? ''))) $independent = true;
        }

        return [
            'date'=>wp_date(DATE_ATOM),'environment'=>wp_get_environment_type(),'wordpress'=>get_bloginfo('version'),
            'woocommerce'=>defined('WC_VERSION') ? WC_VERSION : null,
            'hpos'=>class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) &&
                \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
            'module'=>WUTM_ATM_VERSION,'platforms'=>$platforms,'pixel'=>WUTM_ATM_Events::pixel(),'dataset'=>WUTM_ATM_Events::dataset(),
            'token_configured'=>WUTM_ATM_Events::token() !== '', 'version'=>$event['version'],
            'version_warning'=>self::version_warning($event['version']),'mode'=>$event['mode'],
            'events_enabled'=>$event['enabled'],'woo_events'=>$event['woo'],'events'=>array_values(array_intersect(WUTM_ATM_Events::names(), (array) $event['events'])),
            'test_mode'=>$event['test_code'] !== '',
            'privacy'=>[
                'built_in'=>(bool) $privacy['enabled'],'mode'=>$privacy['enabled'] ? $privacy['first_visit'] : 'external',
                'analytics_default'=>$privacy['enabled'] && $privacy['first_visit'] === 'opt_out' && $privacy['default_analytics'],
                'marketing_default'=>$privacy['enabled'] && $privacy['first_visit'] === 'opt_out' && $privacy['default_marketing'],
                'server_receipt_required'=>true,
                'table_ready'=>$privacy['enabled'] && self::has_table($wpdb->prefix . 'wutm_atm_consent'),
            ],
            'frontend_check'=>['date'=>$latest['checked_at'] ?? '', 'possible_external_tags'=>$independent,
                'custom_checkout_enabled'=>(bool) get_option('atm_checkout_enabled')],
            'meta'=>$meta,'queue'=>self::queue(),
            'external_verification'=>[
                'Meta 測試事件、實際接收與去重結果'=>'尚未驗證',
                '瀏覽器同意、拒絕、撤回及其他安裝來源'=>'尚未驗證',
                '實際付款、延遲回呼及購物動線'=>'尚未驗證',
            ],
        ];
    }

    public static function meta_marker(): string {
        return hash_hmac('sha256', WUTM_ATM_Events::token(), wp_salt('auth'));
    }

    public static function run_summary(array $run): string {
        $labels = ['ok'=>'檢查完成','inactive'=>'伺服器傳送未啟用，僅檢查紀錄保存期限',
            'locked'=>'已有工作在執行，請稍後再試','unavailable'=>'事件表尚未就緒','error'=>'工作出現資料庫或內部錯誤'];
        return sprintf('%s。處理 %d 筆，確認接收 %d，重試 %d，失敗 %d，受同意限制 %d，逾期 %d。',
            $labels[$run['result'] ?? ''] ?? '尚無執行紀錄', $run['processed'] ?? 0, $run['sent'] ?? 0,
            $run['retry'] ?? 0, $run['failed'] ?? 0, $run['consent_blocked'] ?? 0, $run['expired'] ?? 0);
    }

    public static function date($timestamp): string {
        return $timestamp ? wp_date('Y-m-d H:i:s', (int) $timestamp) : '尚無紀錄';
    }

    public static function reason(string $reason): string {
        $known = ['expired'=>'事件超過發送期限或嘗試上限','configuration_changed'=>'資料集或事件設定已變更',
            'consent_revoked_or_unknown'=>'同意收據已撤回、逾期或無法驗證','order_no_longer_paid'=>'訂單目前不符合付款條件',
            'matching_data_missing'=>'缺少瀏覽器識別資訊或其他可用配對欄位','transport_error'=>'無法取得 API 回應',
            'dataset_unconfirmed'=>'回應無法確認指定資料集','local_error'=>'本機處理暫時失敗','no_event_acknowledgement'=>'回應未包含接收確認',
            'cleared_by_admin'=>'管理員已取消待送事件'];
        if (isset($known[$reason])) return $known[$reason];
        if (!preg_match('/^http_(\d{3})(?:_meta_(\d+))?$/D', $reason, $match)) return '—';
        $summary = ['400'=>'請檢查參數、版本或授權範圍','401'=>'請檢查憑證','403'=>'目前憑證無法執行此操作','429'=>'API 請求超過限制'];
        return 'HTTP ' . $match[1] . (isset($match[2]) ? ' · Meta ' . $match[2] : '') .
            (isset($summary[$match[1]]) ? ' · ' . $summary[$match[1]] : '');
    }

    public static function browser_label($row): string {
        $result = (int) ($row->browser_result ?? 0);
        if ($result & WUTM_ATM_Browser_Evidence::HANDOFF) return '已交由 Pixel 處理；Meta 接收尚未驗證';
        if ($result & WUTM_ATM_Browser_Evidence::LOAD_FAILED) return '瀏覽器回報 Pixel 載入失敗';
        if ($result & WUTM_ATM_Browser_Evidence::QUEUED) return '已進入瀏覽器 Pixel 佇列';
        if (!empty($row->browser)) return '已準備；尚無前端回報';
        return !empty($row->browser_blocked) ? '受 Cookie 同意限制' : '尚無瀏覽器事件';
    }
}
