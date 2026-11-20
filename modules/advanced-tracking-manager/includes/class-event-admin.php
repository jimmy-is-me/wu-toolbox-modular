<?php
defined('ABSPATH') || exit;

final class WUTM_ATM_Event_Admin {
    public static function boot(): void {
        add_action('admin_post_wutm_atm_event_settings', [__CLASS__, 'save']);
        add_action('admin_post_wutm_atm_event_diagnostic', [__CLASS__, 'diagnose']);
        add_action('admin_post_wutm_atm_clear_events', [__CLASS__, 'clear']);
    }

    private static function guard(string $action): void {
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
        $events = array_values(array_intersect(WUTM_ATM_Events::names(), array_filter((array) ($_POST['events'] ?? []), 'is_string')));
        $settings = [
            'enabled'=>isset($_POST['enabled']), 'woo'=>isset($_POST['woo']),
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
        update_option(WUTM_ATM_Events::OPTION, $settings, false);
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
        if (get_option('wutm_atm_event_schema') === '1') {
            require_once __DIR__ . '/class-event-queue.php';
            global $wpdb;
            if (false === $wpdb->query('DELETE FROM ' . WUTM_ATM_Queue::table())) wp_die('無法清除事件紀錄。');
        }
        delete_option('wutm_atm_last_connection');
        self::redirect('history');
    }

    public static function diagnose(): void {
        self::guard('wutm_atm_event_diagnostic');
        $settings = WUTM_ATM_Events::settings();
        $dataset = WUTM_ATM_Events::dataset();
        $token = WUTM_ATM_Events::token();
        $message = '請先開啟事件管理與伺服器傳送，並設定資料集和 Token。';
        if ($settings['enabled'] && WUTM_ATM_Events::server_active()) {
            $version = preg_match('/^v[0-9]{1,2}\.0$/D', $settings['version']) ? $settings['version'] : 'v25.0';
            $base = 'https://graph.facebook.com/' . $version . '/' . $dataset;
            $args = ['timeout'=>8, 'redirection'=>0, 'limit_response_size'=>16384, 'headers'=>['Authorization'=>'Bearer '.$token]];
            if (self::posted('diagnostic') === 'test') {
                if ($settings['test_code'] === '') {
                    $message = '請先儲存 Meta 測試事件代碼。';
                    self::finish($message);
                }
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
                $message = !empty($body['events_received']) ? 'Meta 已確認接收測試 PageView。' : 'Meta 尚未確認接收測試事件。';
            } else {
                $response = wp_remote_get($base . '?fields=id', $args);
                $body = is_wp_error($response) ? [] : json_decode(wp_remote_retrieve_body($response), true);
                $message = (string) ($body['id'] ?? '') === $dataset ? '已連線並讀到指定資料集。可使用測試事件確認寫入權限。' : '無法讀取資料集，請檢查版本、ID 及授權。';
            }
            $message .= is_wp_error($response) ? ' 傳輸失敗。' : ' HTTP ' . (int) wp_remote_retrieve_response_code($response) . '。';
        }
        self::finish($message);
    }

    private static function finish(string $message): void {
        update_option('wutm_atm_last_connection', ['message'=>$message,'time'=>time()], false);
        set_transient('wutm_atm_admin_result_' . get_current_user_id(), $message, 60);
        self::redirect('events');
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
        if (!empty($settings['enabled']) && get_option('wutm_atm_event_schema') === '1') {
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
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="atm-card">
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
            <fieldset><legend>啟用的事件</legend><div class="wutm-atm-event-grid">
                <?php foreach (WUTM_ATM_Events::names() as $name) : ?>
                <label><input type="checkbox" name="events[]" value="<?php echo esc_attr($name); ?>" <?php checked(in_array($name,$settings['events'],true)); ?>> <?php echo esc_html($name); ?></label>
                <?php endforeach; ?>
            </div></fieldset>
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

    private static function labels(): array {
        return ['browser_prepared'=>'已準備瀏覽器事件','pending'=>'等待發送','sending'=>'正在發送','retry'=>'等待重試','sent'=>'Meta 已確認接收','failed'=>'發送失敗','consent_blocked'=>'同意限制'];
    }

    public static function render_log(): void {
        if (get_option('wutm_atm_event_schema') !== '1') {
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
        $where = ['1=1'];
        $args = [];
        if ($search !== '') {
            $where[] = '(event_id LIKE %s OR event_name LIKE %s OR order_id = %d)';
            $pattern = '%' . $wpdb->esc_like($search) . '%';
            array_push($args, $pattern, $pattern, ctype_digit($search) ? (int) $search : -1);
        }
        if ($type !== '') { $where[] = 'event_name = %s'; $args[] = $type; }
        if (in_array($status, WUTM_ATM_Queue::STATES, true)) { $where[] = 'status = %s'; $args[] = $status; }
        if ($detail !== '') {
            $where = ['event_id = %s'];
            $args = [$detail];
        }
        $sql = "SELECT event_id,event_name,source,event_time,order_id,amount,currency,status,browser,tries,reason,updated FROM $table WHERE " . implode(' AND ', $where) . ' ORDER BY updated DESC LIMIT 100';
        $rows = $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql);
        $labels = self::labels();
        $url = add_query_arg(['page'=>'wu-advanced-tracking-manager','atm_tab'=>'history'], admin_url('admin.php'));
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="wutm-atm-event-grid">
            <input type="hidden" name="page" value="wu-advanced-tracking-manager"><input type="hidden" name="atm_tab" value="history">
            <label>事件 ID、名稱或訂單 <input name="event_search" value="<?php echo esc_attr($search); ?>"></label>
            <label>事件類型 <select name="event_type"><option value="">全部</option><?php foreach (WUTM_ATM_Events::names() as $name) : ?><option <?php selected($type,$name); ?>><?php echo esc_html($name); ?></option><?php endforeach; ?></select></label>
            <label>狀態 <select name="event_status"><option value="">全部</option><?php foreach ($labels as $value=>$label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($status,$value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
            <button class="button">篩選</button>
        </form>
        <p>顯示最新 100 筆。瀏覽器紀錄表示本機已準備事件。Meta 接收狀態僅由伺服器回應確認。WooCommerce 訂單可輸入訂單編號查詢。</p>
        <?php if ($detail !== '') : ?><p><a href="<?php echo esc_url($url); ?>">返回事件紀錄</a></p><?php endif; ?>
        <div class="wutm-atm-event-table"><table class="widefat striped"><thead><tr>
            <?php foreach (['事件／來源','時間','管道','訂單','金額','狀態','原因／嘗試次數'] as $column) : ?><th scope="col"><?php echo esc_html($column); ?></th><?php endforeach; ?>
        </tr></thead><tbody>
        <?php foreach ((array) $rows as $row) : ?><tr>
            <td><a href="<?php echo esc_url(add_query_arg('event_detail',$row->event_id,$url)); ?>"><?php echo esc_html($row->event_name); ?></a> · <?php echo esc_html($row->source); ?><?php if ($detail !== '') : ?><br><code><?php echo esc_html($row->event_id); ?></code><?php endif; ?></td>
            <td><?php echo esc_html(wp_date('Y-m-d H:i:s',(int) $row->event_time)); ?></td>
            <td><?php echo esc_html($row->browser ? ($row->status === 'browser_prepared' ? '瀏覽器' : '瀏覽器＋伺服器') : '伺服器'); ?></td>
            <td><?php echo esc_html($row->order_id ?: '—'); ?></td>
            <td><?php echo esc_html($row->amount === '' ? '—' : $row->currency . ' ' . $row->amount); ?></td>
            <td><?php echo esc_html($labels[$row->status] ?? $row->status); ?></td>
            <td><?php echo esc_html(($row->reason ?: '—') . ' ／ ' . $row->tries); ?></td>
        </tr><?php endforeach; ?>
        <?php if (!$rows) : ?><tr><td colspan="7">目前沒有符合條件的紀錄。</td></tr><?php endif; ?>
        </tbody></table></div>
        <?php if (!WUTM_ATM_Events::settings()['enabled']) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('確定永久清除事件紀錄及待送事件？');">
                <input type="hidden" name="action" value="wutm_atm_clear_events"><?php wp_nonce_field('wutm_atm_clear_events'); ?>
                <p><button class="button">清除所有事件紀錄及佇列</button></p>
            </form>
        <?php endif; ?>
        <?php
    }
}
