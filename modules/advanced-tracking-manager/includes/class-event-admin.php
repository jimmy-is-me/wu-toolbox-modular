<?php
defined('ABSPATH') || exit;

final class WUTM_ATM_Event_Admin {
    public static function boot(): void {
        add_action('admin_post_wutm_atm_event_settings', [__CLASS__, 'save']);
        add_action('admin_post_wutm_atm_event_diagnostic', [__CLASS__, 'diagnose']);
        add_action('admin_post_wutm_atm_clear_events', [__CLASS__, 'clear']);
        add_action('admin_post_wutm_atm_process_due', [__CLASS__, 'process_due']);
        add_action('admin_post_wutm_atm_export_audit', [__CLASS__, 'export']);
        add_action('admin_post_wutm_atm_clear_test_code', [__CLASS__, 'clear_test_code']);
        add_action('admin_post_wutm_atm_mark_dedup', [__CLASS__, 'mark_dedup']);
    }

    private static function guard(string $action): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die('此操作需要 POST。', '', ['response'=>405]);
        if (!current_user_can('manage_options')) wp_die('權限不足。', '', ['response'=>403]);
        check_admin_referer($action);
    }

    private static function posted(string $key): string {
        return is_string($_POST[$key] ?? null) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    }

    private static function redirect(string $tab): void {
        wp_safe_redirect(add_query_arg(['page'=>'wu-advanced-tracking-manager','atm_tab'=>$tab], admin_url('admin.php')));
        exit;
    }

    public static function save(): void {
        self::guard('wutm_atm_event_settings');
        $version = self::posted('graph_version');
        $mode = self::posted('event_mode');
        require_once __DIR__ . '/class-purchase.php';
        $previous = WUTM_ATM_Events::settings();
        $events = array_values(array_intersect(WUTM_ATM_Events::names(), array_filter((array) ($_POST['events'] ?? []), 'is_string')));
        $purchase = in_array('Purchase', $events, true) ? self::posted('purchase_mode') : '';
        $purchase_settings = [
            'purchase_mode'=>in_array($purchase, ['standard','manual','transition'], true) ? $purchase : $previous['purchase_mode'],
            'purchase_gateways'=>$previous['purchase_gateways'], 'purchase_transitions'=>$previous['purchase_transitions'],
            'purchase_column'=>$previous['purchase_column'],'purchase_test_dataset'=>$previous['purchase_test_dataset'],
        ];
        if ($purchase !== '') {
            $purchase_settings['purchase_gateways'] = array_values(array_intersect(
                array_keys(WUTM_ATM_Purchase::gateways()), array_filter((array) ($_POST['purchase_gateways'] ?? []), 'is_string')
            ));
            $purchase_settings['purchase_transitions'] = array_values(array_intersect(
                WUTM_ATM_Purchase::RULES, array_filter((array) ($_POST['purchase_transitions'] ?? []), 'is_string')
            ));
            $purchase_settings['purchase_column'] = isset($_POST['purchase_column']);
            $dataset = self::posted('purchase_test_dataset');
            if ($dataset !== '' && !preg_match('/^[1-9][0-9]{0,39}$/D', $dataset)) {
                wp_die('請輸入有效的 Purchase 測試資料集 ID。', '', ['response'=>400]);
            }
            $purchase_settings['purchase_test_dataset'] = $dataset;
        }
        $settings = [
            'enabled'=>isset($_POST['enabled']), 'woo'=>isset($_POST['woo']),
            'browser_evidence'=>isset($_POST['browser_evidence']),
            'mode'=>in_array($mode, ['browser','server','both'], true) ? $mode : 'browser',
            'events'=>$events, 'dataset'=>preg_replace('/\D/', '', self::posted('dataset')),
            'version'=>preg_match('/^v[0-9]{1,2}\.0$/D', $version) ? $version : 'v25.0',
            'test_code'=>substr(preg_replace('/[^A-Za-z0-9_-]/', '', self::posted('test_code')), 0, 64),
            'retention'=>max(7,min(60,absint(self::posted('retention')) ?: 14)),
        ];
        if (!defined('WUTM_ATM_CAPI_TOKEN')) {
            if (isset($_POST['clear_token'])) delete_option('atm_fb_capi_token');
            elseif (self::posted('token') !== '') update_option('atm_fb_capi_token', self::posted('token'), false);
        }
        if (function_exists('wp_set_option_autoload')) wp_set_option_autoload('atm_fb_capi_token', false);
        update_option('atm_fb_capi_enabled', isset($_POST['capi']) ? 1 : 0);
        update_option(WUTM_ATM_Events::OPTION, $settings + $purchase_settings, false);
        if ($settings['enabled']) {
            require_once __DIR__ . '/class-event-queue.php';
            WUTM_ATM_Queue::ensure();
        } elseif (get_option('wutm_atm_event_schema')) {
            wp_clear_scheduled_hook('wutm_atm_process_events');
        }
        do_action('wutm_atm_tracking_settings_updated', $settings['enabled']);
        set_transient('wutm_atm_admin_result_' . get_current_user_id(), '已儲存事件設定。若使用頁面快取，請依說明更新排除規則並清除舊快取。', 60);
        self::redirect('events');
    }

    public static function clear(): void {
        self::guard('wutm_atm_clear_events');
        if (WUTM_ATM_Events::settings()['enabled']) wp_die('請先關閉事件管理。');
        if (WUTM_ATM_Observability::table_ready()) {
            global $wpdb;
            $lease = WUTM_ATM_Queue::lock();
            if ($lease === '') wp_die('佇列正在處理，請稍後再試。', '', ['response'=>409]);
            try {
                $table = WUTM_ATM_Queue::table();
                $durable = WUTM_ATM_Queue::durable_purchase();
                $deleted = $wpdb->query("DELETE FROM $table WHERE NOT $durable");
                $discarded = $wpdb->query("UPDATE $table SET status='failed',reason='cleared_by_admin',payload='',due=0
                    WHERE $durable AND status IN ('pending','retry','sending')");
            } finally {
                WUTM_ATM_Queue::unlock($lease);
            }
            if ($deleted === false || $discarded === false) wp_die('無法清除事件紀錄。');
            update_option(WUTM_ATM_Queue::HISTORY_CLEARED, time(), false);
        }
        delete_option('wutm_atm_last_connection');
        delete_option(WUTM_ATM_Observability::META_RESULTS);
        self::redirect('history');
    }

    public static function process_due(): void {
        self::guard('wutm_atm_process_due');
        $run = WUTM_ATM_Queue::work('manual');
        set_transient('wutm_atm_admin_result_' . get_current_user_id(), WUTM_ATM_Observability::run_summary($run), 120);
        self::redirect('overview');
    }

    public static function clear_test_code(): void {
        self::guard('wutm_atm_clear_test_code');
        $settings = (array) get_option(WUTM_ATM_Events::OPTION, []);
        $settings['test_code'] = '';
        update_option(WUTM_ATM_Events::OPTION, $settings + $purchase_settings, false);
        set_transient('wutm_atm_admin_result_' . get_current_user_id(), '已清除設定中的測試代碼。既有待送事件保留原本的發送模式。', 120);
        self::redirect('overview');
    }

    public static function mark_dedup(): void {
        $id = self::posted('event_id');
        if (!preg_match('/^atm-[a-f0-9]{64}$/D', $id)) wp_die('無效的事件 ID。', '', ['response'=>400]);
        self::guard('wutm_atm_mark_dedup_' . $id);
        global $wpdb;
        if (!WUTM_ATM_Observability::table_ready()) wp_die('事件紀錄未就緒。');
        $table = WUTM_ATM_Queue::table();
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET dedup_reviewed=%d WHERE event_id=%s AND status='sent' AND browser_pixel<>'' AND browser_pixel=server_dataset
             AND (browser_result & %d)<>0 AND dedup_reviewed=0",
            time(), $id, WUTM_ATM_Browser_Evidence::HANDOFF
        ));
        if ($updated !== 1) wp_die('目前沒有可核對的新紀錄。');
        wp_safe_redirect(add_query_arg(['page'=>'wu-advanced-tracking-manager','atm_tab'=>'history','event_detail'=>$id], admin_url('admin.php')));
        exit;
    }

    public static function export(): void {
        self::guard('wutm_atm_export_audit');
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="wu-tracking-audit.json"');
        echo wp_json_encode(WUTM_ATM_Observability::report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function diagnose(): void {
        self::guard('wutm_atm_event_diagnostic');
        $kind = self::posted('diagnostic');
        if (!in_array($kind, ['connection','test'], true)) wp_die('無效的診斷動作。', '', ['response'=>400]);
        $message = self::check_meta($kind);
        set_transient('wutm_atm_admin_result_' . get_current_user_id(), $message, 60);
        self::redirect('overview');
    }

    public static function check_meta(string $kind): string {
        $settings = WUTM_ATM_Events::settings();
        $dataset = WUTM_ATM_Events::dataset();
        $token = WUTM_ATM_Events::token();
        if (!current_user_can('manage_options') || !in_array($kind, ['connection','test'], true)) return '權限或操作無效。';
        if (!WUTM_ATM_Events::server_active()) return '請先開啟事件管理與伺服器傳送，並設定資料集和 Token。';
        if ($kind === 'test' && $settings['test_code'] === '') return '請先儲存 Meta 測試事件代碼。';
        $details = ['http'=>0,'received'=>0,'confirmed'=>false,'error'=>'not_configured'];
        $lease = WUTM_ATM_Queue::lock();
        if ($lease === '') return '已有佇列或診斷正在執行，請稍後再試。';
        try {
            if (get_transient('wutm_atm_meta_' . $kind)) return '剛執行過此診斷，請在 30 秒後再試。';
            $version = preg_match('/^v[0-9]{1,2}\.0$/D', $settings['version']) ? $settings['version'] : 'v25.0';
            $base = 'https://graph.facebook.com/' . $version . '/' . $dataset;
            $args = ['timeout'=>8, 'redirection'=>0, 'limit_response_size'=>16384, 'headers'=>['Authorization'=>'Bearer '.$token]];
            set_transient('wutm_atm_meta_' . $kind, 1, 30);
            if ($kind === 'test') {
                // Synthetic data only. This action is explicit and never produces a Purchase.
                $id = WUTM_ATM_Events::event_id('PageView', 'test:' . wp_generate_uuid4());
                $response = wp_remote_post($base . '/events', array_merge($args, [
                    'headers'=>array_merge($args['headers'], ['Content-Type'=>'application/json']),
                    'body'=>wp_json_encode(['test_event_code'=>$settings['test_code'], 'data'=>[[
                        'event_name'=>'PageView', 'event_id'=>$id, 'event_time'=>time(), 'action_source'=>'website',
                        'event_source_url'=>home_url('/'), 'user_data'=>['client_user_agent'=>'WUTM Synthetic Diagnostic', 'em'=>[hash('sha256', 'wutm-test@example.invalid')]],
                    ]]]),
                ]));
                $body = is_wp_error($response) ? [] : json_decode(wp_remote_retrieve_body($response), true);
                $details['event_id'] = $id;
                $details['received'] = max(0, (int) ($body['events_received'] ?? 0));
            } else {
                $response = wp_remote_get($base . '?fields=id', $args);
                $body = is_wp_error($response) ? [] : json_decode(wp_remote_retrieve_body($response), true);
                $details['confirmed'] = (string) ($body['id'] ?? '') === $dataset;
            }
            $details['http'] = (int) wp_remote_retrieve_response_code($response);
            $http_ok = $details['http'] >= 200 && $details['http'] < 300;
            $details['confirmed'] = $http_ok && ($kind === 'test' ? $details['received'] > 0 : $details['confirmed']);
            $meta_code = (int) ($body['error']['code'] ?? 0);
            $details['error'] = $details['confirmed'] ? '' : (is_wp_error($response) ? 'transport_error' :
                ($http_ok ? ($kind === 'test' ? 'no_event_acknowledgement' : 'dataset_unconfirmed') : 'http_' . $details['http'] . ($meta_code ? '_meta_' . $meta_code : '')));
            $message = $kind === 'test'
                ? ($details['confirmed'] ? 'Meta API 已確認接收合成 PageView。請在 Meta 測試事件核對。' : 'Meta API 尚未確認接收合成 PageView。')
                : ($details['confirmed'] ? '已讀取指定資料集。' : '目前無法讀取資料集資訊。事件寫入權限請看獨立的發送測試。');
            $results = (array) get_option(WUTM_ATM_Observability::META_RESULTS, []);
            $results[$kind] = $details + ['time'=>time(),'message'=>$message,'dataset'=>$dataset,'version'=>$version,
                'config_marker'=>WUTM_ATM_Observability::meta_marker()];
            update_option(WUTM_ATM_Observability::META_RESULTS, $results, false);
            return $message;
        } finally {
            WUTM_ATM_Queue::unlock($lease);
        }
    }

    public static function render_settings(): void {
        $settings = WUTM_ATM_Events::settings();
        $result = get_transient('wutm_atm_admin_result_' . get_current_user_id());
        if ($result) {
            delete_transient('wutm_atm_admin_result_' . get_current_user_id());
            echo '<div class="notice notice-info"><p>' . esc_html($result) . '</p></div>';
        }
        $connection = get_option('wutm_atm_last_connection', []);
        $latest = null;
        if (!empty($settings['enabled']) && WUTM_ATM_Observability::table_ready()) {
            global $wpdb;
            require_once __DIR__ . '/class-event-queue.php';
            $latest = $wpdb->get_row('SELECT status,reason,updated FROM ' . WUTM_ATM_Queue::table() . " WHERE tries > 0 ORDER BY updated DESC LIMIT 1");
        }
        ?>
        <div class="atm-card"><div class="atm-card-body">
            <h2>事件總覽</h2>
            <p><?php echo $settings['enabled'] ? '事件管理已開啟' : '事件管理尚未開啟'; ?> ｜ <?php echo class_exists('WooCommerce') ? '已偵測到 WooCommerce' : '未安裝 WooCommerce'; ?></p>
            <p>瀏覽器：<?php echo WUTM_ATM_Events::browser_active() ? '已設定' : '尚未完整設定或已關閉'; ?> ｜ 伺服器：<?php echo WUTM_ATM_Events::server_active() ? '已設定，請執行測試確認連線' : '尚未完整設定或已關閉'; ?> ｜ 測試代碼：<?php echo $settings['test_code'] ? '已設定' : '未設定'; ?></p>
            <?php if ($settings['mode'] === 'both' && WUTM_ATM_Events::pixel() !== WUTM_ATM_Events::dataset()) : ?><p>雙通道需使用同一個 Meta ID 才能去重。</p><?php endif; ?>
            <?php if (!empty($connection['time'])) : ?><p>最近診斷 <?php echo esc_html(wp_date('Y-m-d H:i:s', $connection['time'])); ?>：<?php echo esc_html($connection['message']); ?></p><?php endif; ?>
            <?php if ($latest) : ?><p>最近一次伺服器發送 <?php echo esc_html(wp_date('Y-m-d H:i:s', (int) $latest->updated)); ?>：<?php echo esc_html(self::labels()[$latest->status] ?? $latest->status); ?><?php echo $latest->reason ? ' · ' . esc_html($latest->reason) : ''; ?></p><?php endif; ?>
        </div></div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="atm-card" data-atm-settings>
            <div class="atm-card-body">
            <input type="hidden" name="action" value="wutm_atm_event_settings">
            <?php wp_nonce_field('wutm_atm_event_settings'); ?>
            <h2>事件與 WooCommerce</h2>
            <p><label><input type="checkbox" name="enabled" <?php checked($settings['enabled']); ?>> 啟用事件管理與行銷同意控制</label></p>
            <p><label><input type="checkbox" name="woo" <?php checked($settings['woo']); ?>> 啟用 WooCommerce 事件接點</label></p>
            <p><label>發送模式 <select name="event_mode">
                <?php foreach (['browser'=>'瀏覽器','server'=>'伺服器','both'=>'瀏覽器＋伺服器'] as $key=>$label) : ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($settings['mode'],$key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select></label></p>
            <p><label><input type="checkbox" name="browser_evidence" <?php checked(!empty($settings['browser_evidence'])); ?>> 收集瀏覽器交接證據（驗收用）</label><br><small>只記錄事件 ID 的本機交接結果，建議完成驗收後關閉。</small></p>
            <fieldset><legend>啟用的事件</legend><div class="wutm-atm-event-grid">
                <?php foreach (WUTM_ATM_Events::names() as $name) : ?>
                <label><input type="checkbox" name="events[]" value="<?php echo esc_attr($name); ?>" <?php checked(in_array($name,$settings['events'],true)); ?>> <?php echo esc_html($name); ?></label>
                <?php endforeach; ?>
            </div></fieldset>
            <fieldset data-atm-purchase-settings <?php echo in_array('Purchase', $settings['events'], true) ? '' : 'hidden'; ?>>
                <legend>Purchase 付款觸發設定</legend>
                <?php require_once __DIR__ . '/class-purchase.php'; ?>
                <p><label>付款規則 <select name="purchase_mode">
                <?php foreach (['standard'=>'金流付款成功（預設）','manual'=>'金流付款成功＋人工收款確認','transition'=>'金流付款成功＋指定付款方式的狀態轉換'] as $key=>$label) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($settings['purchase_mode'], $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
                </select></label></p>
                <p>預設採用 WooCommerce 付款完成紀錄。選擇人工確認時，請在訂單頁確認實際收款。</p>
                <details <?php echo $settings['purchase_mode'] === 'transition' ? 'open' : ''; ?>><summary>指定付款方式及狀態轉換</summary>
                    <p>只有選取的付款方式與轉換組合會被視為收款。請選擇確實代表收款的狀態流程。</p>
                    <fieldset><legend>已啟用的付款方式（可複選）</legend>
                    <?php foreach (WUTM_ATM_Purchase::gateways() as $id=>$label) : ?>
                        <label><input type="checkbox" name="purchase_gateways[]" value="<?php echo esc_attr($id); ?>" <?php checked(in_array($id,$settings['purchase_gateways'],true)); ?>> <?php echo esc_html($label); ?></label><br>
                    <?php endforeach; ?>
                    </fieldset>
                    <fieldset><legend>視為收款的狀態轉換（可複選）</legend>
                    <?php foreach (WUTM_ATM_Purchase::RULES as $rule) : [$from, $to] = explode(':', $rule); ?>
                        <label><input type="checkbox" name="purchase_transitions[]" value="<?php echo esc_attr($rule); ?>" <?php checked(in_array($rule,$settings['purchase_transitions'],true)); ?>>
                            <?php echo esc_html((['pending'=>'等待付款','on-hold'=>'保留中'][$from]) . ' → ' . (['processing'=>'處理中','completed'=>'已完成'][$to])); ?></label><br>
                    <?php endforeach; ?>
                    </fieldset>
                </details>
                <p><label><input type="checkbox" name="purchase_column" <?php checked($settings['purchase_column']); ?>> 在 WooCommerce 訂單列表顯示 Meta 購買事件</label></p>
                <p><label>獨立 Purchase 測試資料集 ID <input name="purchase_test_dataset" inputmode="numeric" pattern="[1-9][0-9]{0,39}" value="<?php echo esc_attr($settings['purchase_test_dataset']); ?>"></label><br>
                <small>填寫已授權的測試資料集最清楚。留空會使用正式資料集，Meta 仍可能接收或使用測試事件。</small></p>
            </fieldset>
            <h2>Meta Conversions API</h2>
            <p><label><input type="checkbox" name="capi" <?php checked((bool) get_option('atm_fb_capi_enabled',0)); ?>> 啟用 CAPI</label></p>
            <p><label>資料集 ID <input type="text" inputmode="numeric" name="dataset" value="<?php echo esc_attr($settings['dataset']); ?>" placeholder="沿用 Meta Pixel ID"></label></p>
            <p><label>Graph API 版本 <input name="graph_version" value="<?php echo esc_attr($settings['version']); ?>" pattern="v[0-9]{1,2}\.0"></label></p>
            <p><label>Access Token <input type="password" autocomplete="new-password" name="token" placeholder="<?php echo WUTM_ATM_Events::token() ? '已設定，留空保留' : '尚未設定'; ?>" <?php disabled(defined('WUTM_ATM_CAPI_TOKEN')); ?>></label></p>
            <?php if (!defined('WUTM_ATM_CAPI_TOKEN')) : ?><p><label><input type="checkbox" name="clear_token"> 移除已儲存 Token</label></p><?php endif; ?>
            <p><label>測試事件代碼 <input name="test_code" value="<?php echo esc_attr($settings['test_code']); ?>"></label> <small>僅測試時填寫。新排入的事件會帶此代碼。</small></p>
            <p><label>紀錄保存天數 <input type="number" min="7" max="60" name="retention" value="<?php echo esc_attr($settings['retention']); ?>"></label></p>
            <h2>Cookie 同意</h2>
            <p>請開啟「Cookie 與隱私同意」的內建橫幅，或接上既有同意工具。伺服器事件需要可重新驗證的同意紀錄。</p>
            <button class="button button-primary">儲存事件設定</button>
            </div>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="atm-card"><div class="atm-card-body">
            <input type="hidden" name="action" value="wutm_atm_event_diagnostic">
            <?php wp_nonce_field('wutm_atm_event_diagnostic'); ?>
            <button class="button" name="diagnostic" value="connection">檢查資料集連線</button>
            <button class="button" name="diagnostic" value="test">發送測試 PageView</button>
        </div></form>
        <?php
    }

    public static function labels(): array {
        return ['browser_prepared'=>'已準備瀏覽器事件','pending'=>'等待發送','sending'=>'正在發送','retry'=>'等待重試','sent'=>'Meta 已確認接收','failed'=>'發送失敗','consent_blocked'=>'同意限制'];
    }

    public static function render_overview(): void {
        $report = WUTM_ATM_Observability::report();
        $result = get_transient('wutm_atm_admin_result_' . get_current_user_id());
        delete_transient('wutm_atm_admin_result_' . get_current_user_id());
        require WUTM_ATM_DIR . 'templates/overview.php';
    }

    private static function render_event_evidence($row): void {
        $server = $row->server_dataset !== '' || $row->tries > 0 || in_array($row->status, ['pending','sending','retry','sent'], true);
        $pair = $row->browser && $server;
        $same_dataset = $pair && $row->browser_pixel !== '' && $row->browser_pixel === $row->server_dataset;
        $handoff = (int) $row->browser_result & WUTM_ATM_Browser_Evidence::HANDOFF;
        $matching = [];
        foreach (['瀏覽器識別','Email 雜湊','_fbp','_fbc','IP'] as $bit=>$name) {
            if ((int) $row->matching & (1 << $bit)) $matching[] = $name;
        }
        $entries = [
            '建立時間'=>WUTM_ATM_Observability::date($row->created),
            '瀏覽器 event_id'=>$row->browser ? $row->event_id : '未準備或尚無紀錄',
            '瀏覽器 Pixel／伺服器資料集'=>($row->browser_pixel ?: '—') . ' ／ ' . ($row->server_dataset ?: '—'),
            '伺服器 event_id'=>$server ? $row->event_id : '尚無伺服器發送',
            '最近一次嘗試'=>WUTM_ATM_Observability::date($row->attempted),
            'Meta API 回應'=>$row->http_code ? ('HTTP ' . $row->http_code . ' · events_received ' . $row->received) :
                ($row->status === 'sent' ? '歷史紀錄有接收狀態' : '尚無回應'),
            'API 確認接收時間'=>WUTM_ATM_Observability::date($row->status === 'sent' ? $row->updated : 0),
            '同意驗證'=>$row->status === 'consent_blocked' ? '未通過或已撤回' : ($row->http_code ? '發送時通過驗證' : '尚無發送確認'),
            '配對欄位'=>implode('、', $matching) ?: '尚無記錄',
            '發送模式'=>$row->test_mode === null ? '歷史或未知' : ($row->test_mode ? '測試' : '正式'),
            '去重證據'=>($pair ? '本機 event_id 一致。' : '只取得單一通道或同意受限。') .
                ($same_dataset && $handoff && $row->status === 'sent' ? ' 兩通道使用相同資料集，且有交接與 API 接收紀錄。具備去重核對條件。' : '') .
                ($row->dedup_reviewed ? ' 管理員已在 Meta 後台核對，時間 ' . WUTM_ATM_Observability::date($row->dedup_reviewed) . '。' :
                    ' Meta 實際去重尚未驗證。請比對相同資料集、事件名稱與來源。'),
        ];
        echo '<dl class="wutm-atm-audit-definition">';
        foreach ($entries as $label=>$value) echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd>';
        echo '</dl>';
        if ($same_dataset && $handoff && $row->status === 'sent' && !$row->dedup_reviewed) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                data-atm-confirm="請確認您已在 Meta 後台取得同一事件的去重結果。確定記錄人工核對？">
                <?php wp_nonce_field('wutm_atm_mark_dedup_' . $row->event_id); ?>
                <input type="hidden" name="action" value="wutm_atm_mark_dedup">
                <input type="hidden" name="event_id" value="<?php echo esc_attr($row->event_id); ?>">
                <button class="button">記錄已在 Meta 核對去重</button>
            </form>
        <?php endif;
    }

    public static function render_log(): void {
        if (!WUTM_ATM_Observability::table_ready()) {
            echo '<p>事件管理啟用後會顯示紀錄。</p>';
            return;
        }
        global $wpdb;
        require_once __DIR__ . '/class-event-queue.php';
        $table = WUTM_ATM_Queue::table();
        $field = static function ($key) {
            return is_string($_GET[$key] ?? null) ? substr(sanitize_text_field(wp_unslash($_GET[$key])), 0, 80) : '';
        };
        $search = $field('event_search');
        $type = $field('event_type');
        $status = $field('event_status');
        $detail = $field('event_detail');
        $channel = $field('event_channel');
        $test_mode = $field('event_mode');
        $order = absint($field('event_order'));
        $event_id = $field('event_id');
        $since = $field('event_since');
        $until = $field('event_until');
        $since_clear = (int) get_option(WUTM_ATM_Queue::HISTORY_CLEARED, 0);
        $retention = max(7, min(60, (int) WUTM_ATM_Events::settings()['retention']));
        $where = ['updated > %d'];
        $args = [max($since_clear, time() - $retention * DAY_IN_SECONDS)];
        if ($search !== '') {
            $where[] = '(event_id LIKE %s OR event_name LIKE %s OR order_id = %d)';
            $pattern = '%' . $wpdb->esc_like($search) . '%';
            array_push($args, $pattern, $pattern, ctype_digit($search) ? (int) $search : -1);
        }
        if ($type !== '') { $where[] = 'event_name = %s'; $args[] = $type; }
        if (in_array($status, WUTM_ATM_Queue::STATES, true)) { $where[] = 'status = %s'; $args[] = $status; }
        if ($channel === 'browser') $where[] = '(browser=1 OR browser_blocked=1)';
        if ($channel === 'server') $where[] = "(server_dataset<>'' OR tries>0 OR status IN ('pending','sending','retry','sent'))";
        if ($test_mode === 'test') $where[] = 'test_mode=1';
        if ($test_mode === 'live') $where[] = 'test_mode=0';
        if ($order) { $where[] = 'order_id=%d'; $args[] = $order; }
        if ($event_id !== '') { $where[] = 'event_id=%s'; $args[] = $event_id; }
        foreach ([$since,$until] as $index=>$input) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $input)) continue;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input, wp_timezone());
            if (!$date || $date->format('Y-m-d') !== $input) continue;
            $where[] = $index ? 'event_time < %d' : 'event_time >= %d';
            $args[] = ($index ? $date->modify('+1 day') : $date)->getTimestamp();
        }
        if ($detail !== '') {
            $where = ['event_id = %s'];
            $args = [$detail];
        }
        $sql = "SELECT event_id,event_name,source,event_time,order_id,amount,currency,status,browser,browser_result,browser_blocked,tries,reason,updated,due,test_mode,attempted,http_code,received,matching,products,browser_pixel,server_dataset,created,dedup_reviewed FROM $table WHERE " . implode(' AND ', $where) . ' ORDER BY updated DESC LIMIT 100';
        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql);
        $labels = self::labels();
        $url = add_query_arg(['page'=>'wu-advanced-tracking-manager','atm_tab'=>'history'], admin_url('admin.php'));
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="wutm-atm-event-grid">
            <input type="hidden" name="page" value="wu-advanced-tracking-manager"><input type="hidden" name="atm_tab" value="history">
            <label>事件 ID、名稱或訂單 <input name="event_search" value="<?php echo esc_attr($search); ?>"></label>
            <label>事件類型 <select name="event_type"><option value="">全部</option><?php foreach (WUTM_ATM_Events::names() as $name) : ?><option <?php selected($type,$name); ?>><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
            <label>狀態 <select name="event_status"><option value="">全部</option><?php foreach ($labels as $value=>$label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($status,$value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
            <label>通道 <select name="event_channel"><option value="">全部</option><option value="browser" <?php selected($channel,'browser'); ?>>瀏覽器</option><option value="server" <?php selected($channel,'server'); ?>>伺服器</option></select></label>
            <label>模式 <select name="event_mode"><option value="">全部</option><option value="test" <?php selected($test_mode,'test'); ?>>測試</option><option value="live" <?php selected($test_mode,'live'); ?>>正式</option></select></label>
            <label>起日 <input name="event_since" type="date" value="<?php echo esc_attr($since); ?>"></label>
            <label>迄日 <input name="event_until" type="date" value="<?php echo esc_attr($until); ?>"></label>
            <label>訂單 ID <input name="event_order" type="number" min="1" value="<?php echo esc_attr($order ?: ''); ?>"></label>
            <label>完整 event_id <input name="event_id" value="<?php echo esc_attr($event_id); ?>"></label>
            <button class="button">篩選</button>
        </form>
        <p>顯示最新 100 筆。<a href="<?php echo esc_url(add_query_arg('atm_tab','overview',$url)); ?>">查看下次排程與待發狀態</a>。</p>
        <?php if ($detail !== '') : ?><p><a href="<?php echo esc_url($url); ?>">返回事件紀錄</a></p><?php endif; ?>
        <div class="wutm-atm-event-table"><table class="widefat striped"><thead><tr>
            <?php foreach (['事件／來源','時間','瀏覽器','伺服器','訂單／商品','金額','原因／嘗試次數'] as $column) : ?><th scope="col"><?php echo esc_html($column); ?></th><?php endforeach; ?>
        </tr></thead><tbody>
        <?php foreach ((array) $rows as $row) : ?><tr>
            <td><a href="<?php echo esc_url(add_query_arg('event_detail',$row->event_id,$url)); ?>"><?php echo esc_html($row->event_name); ?></a> · <?php echo esc_html($row->source); ?><?php if ($detail !== '') : ?><br><code><?php echo esc_html($row->event_id); ?></code><?php endif; ?></td>
            <td><?php echo esc_html(wp_date('Y-m-d H:i:s',(int) $row->event_time)); ?></td>
            <td><?php echo esc_html(WUTM_ATM_Observability::browser_label($row)); ?></td>
            <td><?php echo esc_html(($row->status === 'browser_prepared' || ($row->status === 'consent_blocked' && $row->browser_blocked && $row->server_dataset === '')) ? '尚無伺服器事件' : ($labels[$row->status] ?? '尚未確認')); ?>
                <?php if ($row->status === 'retry') : ?><br><?php echo esc_html(WUTM_ATM_Observability::date($row->due)); ?><?php endif; ?></td>
            <td><?php echo esc_html($row->order_id ?: '—'); ?><?php if ($row->products !== '') : ?><br><?php echo esc_html($row->products); ?><?php endif; ?></td>
            <td><?php echo esc_html($row->amount === '' ? '—' : $row->currency . ' ' . $row->amount); ?></td>
            <td><?php echo esc_html(WUTM_ATM_Observability::reason($row->reason) . ' ／ ' . $row->tries); ?></td>
        </tr><?php endforeach; ?>
        <?php if (!$rows) : ?><tr><td colspan="7">目前沒有符合條件的紀錄。</td></tr><?php endif; ?>
        </tbody></table></div>
        <?php if ($detail !== '' && $rows) self::render_event_evidence(reset($rows)); ?>
        <?php if (!WUTM_ATM_Events::settings()['enabled']) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('確定清除紀錄及取消待送事件？訂單的正式 Purchase 防重複識別會保留。');">
                <input type="hidden" name="action" value="wutm_atm_clear_events"><?php wp_nonce_field('wutm_atm_clear_events'); ?>
                <p><button class="button">清除所有事件紀錄及佇列</button></p>
            </form>
        <?php endif; ?>
        <?php
    }
}
