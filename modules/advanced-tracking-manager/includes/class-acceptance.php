<?php
defined('ABSPATH') || exit;

final class WUTM_ATM_Acceptance {
    const OPTION = 'wutm_atm_acceptance';

    public static function boot(): void {
        add_action('admin_post_wutm_atm_acceptance', [__CLASS__, 'save']);
        add_action('admin_post_wutm_atm_simulation', [__CLASS__, 'simulate']);
        add_action('admin_post_wutm_atm_technical_retry', [__CLASS__, 'retry']);
    }

    public static function items(): array {
        return [
            'pixel'=>['Meta Pixel 載入','取得同意後，在瀏覽器驗證 Pixel 已載入。'],
            'pageview'=>['PageView','開啟頁面並核對瀏覽位置。'],
            'viewcontent'=>['ViewContent','開啟商品頁並核對商品 ID。'],
            'addtocart'=>['AddToCart','加入購物車並核對數量及來源頁。'],
            'checkout'=>['InitiateCheckout','進入結帳並核對購物車資料。'],
            'purchase'=>['Purchase','以測試站實際完成付款動線，核對兩個通道。'],
            'unpaid'=>['未付款訂單','驗證等待付款、失敗及取消的訂單沒有購買事件。'],
            'manual'=>['人工收款規則','確認已選的付款方式可以人工回傳。'],
            'payment'=>['金流付款完成','在測試環境完成金流回呼並核對付款依據。'],
            'money'=>['金額與幣別','比對實付總額、運費、折扣及幣別。'],
            'event_id'=>['Browser／Server event_id','各自取得事件，核對同一資料集上的事件識別。'],
            'dedup'=>['Meta 實際去重','在事件詳情核對本機證據，再到 Meta 核對並記錄。'],
            'consent'=>['Cookie 同意','確認訪客允許後才按站點設定啟用。'],
            'withdraw'=>['Cookie 拒絕與撤回','確認拒絕或撤回後沒有待送轉換。'],
            'cron'=>['背景排程','檢查網站或主機排程與待送／重試狀態。'],
            'source'=>['事件來源網址','核對頁面及敏感參數的移除結果。'],
            'isolation'=>['測試與正式隔離','使用已授權的獨立測試資料集核對。'],
        ];
    }

    public static function labels(): array {
        return [
            'not_tested'=>'尚未測試','local'=>'本機檢查通過','api'=>'Meta API 接收確認',
            'meta_review'=>'Meta 後台人工核對','manual'=>'需要人工驗證','failed'=>'測試失敗','na'=>'不適用',
        ];
    }

    private static function fingerprint(array $report): string {
        $settings = WUTM_ATM_Events::settings();
        unset($settings['retention'], $settings['test_code']);
        return hash('sha256', wp_json_encode([
            home_url('/'),$report['pixel'],$report['dataset'],$report['test_dataset'],
            $report['platforms'],$report['privacy'],$settings,WUTM_ATM_Observability::meta_marker(),
        ]));
    }

    public static function checklist(array $report, array $rows = []): array {
        $stored = (array) get_option(self::OPTION, []);
        $manual = ($stored['configuration'] ?? '') === self::fingerprint($report) ? (array) ($stored['items'] ?? []) : [];
        $ack = $report['meta']['test'] ?? [];
        $states = [];
        foreach (self::items() as $key=>$item) {
            $states[$key] = $manual[$key] ?? ['state'=>in_array($key, ['dedup','payment','withdraw'], true) ? 'manual' : 'not_tested'];
        }
        if (!isset($manual['pageview']) && !empty($ack['current_config'])) {
            $states['pageview'] = ['state'=>!empty($ack['confirmed']) && !empty($ack['received']) ? 'api' : 'failed',
                'note'=>'合成 PageView 回應；正式瀏覽動線仍需核對。','time'=>$ack['time'] ?? 0];
        }
        foreach ($rows as $row) {
            // Dedup applies to the selected order (same dataset and matching handoff evidence).
            if ((int) ($row->dedup_reviewed ?? 0) && $row->status === 'sent' && (int) $row->received > 0 &&
                $row->server_dataset === $report['dataset'] && $row->browser_pixel === $row->server_dataset &&
                ((int) $row->browser_result & WUTM_ATM_Browser_Evidence::HANDOFF)) {
                if (!isset($manual['dedup']) || $manual['dedup']['state'] === 'meta_review') {
                    $states['dedup'] = ['state'=>'meta_review','note'=>'所選訂單已有管理員核對紀錄。','time'=>(int) $row->dedup_reviewed];
                }
                break;
            }
        }
        return $states;
    }

    public static function save(): void {
        WUTM_ATM_Event_Admin::guard('wutm_atm_acceptance');
        $key = WUTM_ATM_Event_Admin::posted('check');
        $state = WUTM_ATM_Event_Admin::posted('state');
        $allowed = ['not_tested','local','meta_review','manual','failed','na'];
        if (!isset(self::items()[$key]) || !in_array($state, $allowed, true) ||
            ($key === 'dedup' && in_array($state, ['local','meta_review'], true))) {
            wp_die('此項目需要對應的驗收證據。', '', ['response'=>400]);
        }
        $config = self::fingerprint(WUTM_ATM_Observability::report());
        if (!hash_equals($config, WUTM_ATM_Event_Admin::posted('review_configuration'))) {
            wp_die('設定已變更，請重新開啟驗收清單。', '', ['response'=>409]);
        }
        $prior = (array) get_option(self::OPTION, []);
        $entries = ($prior['configuration'] ?? '') === $config ? (array) ($prior['items'] ?? []) : [];
        if ($state === 'not_tested') unset($entries[$key]);
        else $entries[$key] = ['state'=>$state,'time'=>time(),'user'=>get_current_user_id()];
        update_option(self::OPTION, ['configuration'=>$config,'items'=>$entries], false);
        WUTM_ATM_Event_Admin::redirect('overview', self::order_args());
    }

    public static function review_configuration(array $report): string {
        return self::fingerprint($report);
    }

    public static function order_args(): array {
        $id = absint(WUTM_ATM_Event_Admin::posted('atm_order'));
        return $id ? ['atm_order'=>$id] : [];
    }

    public static function simulate(): void {
        $args = self::order_args();
        $id = $args['atm_order'] ?? 0;
        WUTM_ATM_Event_Admin::guard('wutm_atm_simulation_' . $id);
        if (!$id || WUTM_ATM_Event_Admin::posted('verified') !== 'yes') wp_die('請先核對並確認測試訂單。', '', ['response'=>400]);
        require_once __DIR__ . '/class-purchase.php';
        $run = WUTM_ATM_Purchase::simulate($id, WUTM_ATM_Event_Admin::posted('shared_target') === 'yes',
            WUTM_ATM_Event_Admin::posted('reviewed_settings'));
        $messages = [
            'test'=>'模擬 Purchase 已排入測試佇列。','existing'=>'此訂單已有相同測試設定的事件。','consent'=>'此訂單沒有有效的行銷同意。',
            'invalid_total'=>'訂單金額或幣別無效。','unavailable'=>'Purchase、同意或測試設定需要檢查。','busy'=>'訂單目前無法處理，請稍後重試。',
        ];
        set_transient('wutm_atm_admin_result_' . get_current_user_id(), $messages[$run['result']], 120);
        WUTM_ATM_Event_Admin::redirect('overview', isset($run['id']) ? $args + ['atm_test'=>$run['id']] : $args);
    }

    public static function retry(): void {
        $args = self::order_args();
        $id = $args['atm_order'] ?? 0;
        WUTM_ATM_Event_Admin::guard('wutm_atm_technical_retry_' . $id);
        require_once __DIR__ . '/class-purchase.php';
        $result = WUTM_ATM_Purchase::act($id, 'retry');
        set_transient('wutm_atm_admin_result_' . get_current_user_id(),
            $result === 'queued' ? '已將原事件排入重試。' : '此事件目前不符合重試條件。', 90);
        WUTM_ATM_Event_Admin::redirect('overview', $args);
    }

    public static function history_url($row): string {
        return add_query_arg(['page'=>'wu-advanced-tracking-manager','atm_tab'=>'history','event_detail'=>$row->event_id], admin_url('admin.php'));
    }

    public static function rows(int $limit = 200): array {
        if (!WUTM_ATM_Observability::table_ready()) return [];
        global $wpdb;
        $cutoff = max((int) get_option(WUTM_ATM_Queue::HISTORY_CLEARED, 0),
            time() - (int) WUTM_ATM_Events::settings()['retention'] * DAY_IN_SECONDS);
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT event_id,event_name,source,order_id,status,event_time,attempted,due,updated,amount,currency,test_mode,
                browser_pixel,browser_result,server_dataset,http_code,received,tries,reason,dedup_reviewed FROM ' . WUTM_ATM_Queue::table() .
            ' WHERE updated > %d ORDER BY updated DESC LIMIT %d', $cutoff, $limit
        ), ARRAY_A);
    }

    public static function export_csv(): void {
        $rows = self::rows(1000);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="wu-tracking-events.csv"');
        $stream = fopen('php://output', 'w');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['事件識別','事件','來源','訂單 ID','狀態','事件時間','最近發送','下次發送','更新','金額','幣別','測試',
            'Pixel','瀏覽器交接','資料集','HTTP','接收數','嘗試次數','原因','去重核對時間'], ',', '"', '');
        foreach ($rows as $row) {
            $cells = array_map(static function ($value) {
                $cell = (string) $value;
                return preg_match('/^[\x00-\x20]*[=+\-@\x09\x0D\x0A]/', $cell) ? "'" . $cell : $cell;
            }, array_values($row));
            fputcsv($stream, $cells, ',', '"', '');
        }
        fclose($stream);
        exit;
    }

    public static function export_html(bool $technical, int $id): void {
        $report = WUTM_ATM_Observability::report();
        $order = $id ? WUTM_ATM_Observability::order($id) : [];
        $rows = [];
        if ($order) $rows = WUTM_ATM_Purchase::rows([$id]);
        $list = self::checklist($report, $rows);
        $labels = self::labels();
        $title = $technical ? '追蹤驗收報告 · 技術維護版' : '追蹤驗收報告 · 客戶交付版';
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        require WUTM_ATM_DIR . 'templates/acceptance-report.php';
        exit;
    }
}
