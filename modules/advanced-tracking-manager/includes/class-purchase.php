<?php
defined('ABSPATH') || exit;

/** Payment evidence and dispatch shared by checkout, order administration and the queue. */
final class WUTM_ATM_Purchase {
    const CONFIRMATION = '_wutm_atm_purchase_confirmation';
    const UNVERIFIED = '_wutm_atm_status_only_payment_date';
    const ID = '_wutm_atm_purchase_event_id';
    const CHECKOUT_URL = '_wutm_atm_purchase_checkout_url';
    const RULES = ['pending:processing','on-hold:processing','pending:completed','on-hold:completed'];
    private static $completing = [];

    public static function active(): bool {
        return WUTM_ATM_Events::settings()['woo'] && WUTM_ATM_Events::is_enabled('Purchase') &&
            (WUTM_ATM_Events::browser_active() || WUTM_ATM_Events::server_active());
    }

    public static function mode(): string {
        return WUTM_ATM_Events::settings()['purchase_mode'];
    }

    public static function gateways(): array {
        if (!function_exists('WC') || !WC()->payment_gateways()) return [];
        $methods = [];
        foreach (WC()->payment_gateways()->payment_gateways() as $id => $gateway) {
            if ($gateway->enabled === 'yes') $methods[$id] = wp_strip_all_tags($gateway->get_title() ?: $gateway->get_method_title());
        }
        return $methods;
    }

    public static function pre_payment($id): void {
        self::$completing[(int) $id] = true;
    }

    public static function observe_payment_save($order): void {
        if (!$order instanceof WC_Order || $order->get_type() !== 'shop_order') return;
        $changes = $order->get_changes();
        if (empty($changes['date_paid']) || empty($changes['status']) || !$order->is_paid()) return;
        if (empty(self::$completing[$order->get_id()]) && !$order->get_transaction_id()) {
            $order->update_meta_data(self::UNVERIFIED, $changes['date_paid']->getTimestamp());
        } else {
            $order->delete_meta_data(self::UNVERIFIED);
        }
    }

    public static function standard_complete($id): void {
        try {
            self::locked((int) $id, static function (WC_Order $order) {
                if ($order->is_paid() && $order->get_date_paid() && $order->get_meta(self::UNVERIFIED)) {
                    $order->delete_meta_data(self::UNVERIFIED);
                    $order->save_meta_data();
                }
                return self::emit($order, true);
            }, static function () use ($id) { self::schedule_retry('wutm_atm_retry_payment_complete', [(int) $id]); });
        } finally {
            unset(self::$completing[(int) $id]);
        }
    }

    public static function status_changed($id, $from, $to): void {
        $pair = $from . ':' . $to;
        $settings = WUTM_ATM_Events::settings();
        if ($settings['purchase_mode'] !== 'transition' || !in_array($pair, self::RULES, true) ||
            !in_array($pair, $settings['purchase_transitions'], true)) return;
        self::locked((int) $id, static function (WC_Order $order) use ($settings, $pair, $to) {
            $method = $order->get_payment_method();
            if (!$order->has_status($to) || !in_array($method, $settings['purchase_gateways'], true) ||
                !isset(self::gateways()[$method]) || !self::valid_total($order)) return;
            if (!self::payment($order)) self::confirm($order, 'transition', $pair);
            return self::emit($order, true);
        }, static function () use ($id, $from, $to) {
            self::schedule_retry('wutm_atm_retry_payment_status', [(int) $id, $from, $to]);
        });
    }

    public static function schedule_retry(string $hook, array $args): void {
        if (!wp_next_scheduled($hook, $args)) wp_schedule_single_event(time() + 15, $hook, $args);
    }

    public static function payment($order): array {
        if (!$order instanceof WC_Order || $order->get_type() !== 'shop_order' ||
            $order->has_status(['cancelled','failed','refunded','checkout-draft','trash']) ||
            $order->get_total_refunded() > 0) return [];
        $date = $order->get_date_paid();
        $standard = !$order->has_status('pending') && $order->is_paid() && $date &&
            ((int) $order->get_meta(self::UNVERIFIED) !== $date->getTimestamp() || $order->get_transaction_id());
        try {
            if (!$order->has_status('pending') && true === apply_filters('wutm_atm_order_paid', (bool) $standard, $order)) {
                $date = $date ?: $order->get_date_created();
                return $date ? ['source'=>$standard ? 'woocommerce' : 'integration','time'=>$date->getTimestamp()] : [];
            }
            // A site integration can still veto a verified WooCommerce payment.
            if ($standard) return [];
        } catch (Throwable $error) {
            return [];
        }
        $evidence = (array) $order->get_meta(self::CONFIRMATION);
        if (empty($evidence['time']) || !in_array($evidence['source'] ?? '', ['manual','transition'], true) ||
            ($evidence['mode'] ?? '') !== 'production' || ($evidence['method'] ?? null) !== $order->get_payment_method() ||
            ($evidence['currency'] ?? null) !== $order->get_currency() || !isset($evidence['total']) ||
            (float) $evidence['total'] !== (float) $order->get_total()) return [];
        return $evidence;
    }

    public static function consent(WC_Order $order): array {
        try {
            return (array) apply_filters('wutm_atm_order_consent', (array) $order->get_meta(WUTM_ATM_Woo_Events::META), $order);
        } catch (Throwable $error) {
            return [];
        }
    }

    public static function consent_valid(WC_Order $order): bool {
        try {
            return WUTM_ATM_Events::consent_valid(self::consent($order), [
                'event_name'=>'Purchase', 'order_id'=>$order->get_id(), 'source'=>'woocommerce',
            ]);
        } catch (Throwable $error) {
            return false;
        }
    }

    public static function valid_total(WC_Order $order): bool {
        $total = $order->get_total();
        return is_numeric($total) && is_finite((float) $total) && (float) $total >= 0 &&
            preg_match('/^[A-Z]{3}$/D', $order->get_currency());
    }

    public static function manual_available(WC_Order $order): bool {
        if (self::mode() !== 'manual') return false;
        $methods = WUTM_ATM_Events::settings()['purchase_manual_gateways'];
        // An existing manual-mode site keeps its policy until it saves a payment-method selection.
        $eligible = $methods === null || (in_array($order->get_payment_method(), (array) $methods, true) &&
            isset(self::gateways()[$order->get_payment_method()]));
        return $eligible && $order->has_status(['pending','on-hold','processing','completed']) &&
            $order->get_total_refunded() <= 0;
    }

    public static function fresh(array $payment): bool {
        return ($payment['time'] ?? 0) >= time() - 2 * DAY_IN_SECONDS;
    }

    public static function simulation_id(int $id, array $config): string {
        return WUTM_ATM_Events::event_id('Purchase', 'order-test:' . $id . ':simulation:' . $config['dataset'] . ':' . $config['code']);
    }

    public static function review_key(WC_Order $order, array $config): string {
        return hash_hmac('sha256', wp_json_encode([
            self::simulation_id($order->get_id(), $config), $order->get_total(), $order->get_currency(),
            self::items($order),
        ]), wp_salt('nonce'));
    }

    public static function items(WC_Order $order): array {
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = ['id'=>$item->get_variation_id() ?: $item->get_product_id(), 'quantity'=>$item->get_quantity()];
        }
        return $items;
    }

    public static function simulate(int $id, bool $shared_consent, string $reviewed_settings): array {
        if (!current_user_can('manage_options')) return ['result'=>'unavailable'];
        return self::locked($id, static function (WC_Order $order) use ($shared_consent, $reviewed_settings) {
            $config = WUTM_ATM_Events::test_configuration();
            if (!$config || !hash_equals(self::review_key($order, $config), $reviewed_settings) ||
                ($config['shared'] && !$shared_consent)) return ['result'=>'unavailable'];
            if (!self::consent_valid($order)) return ['result'=>'consent'];
            if (!self::valid_total($order)) return ['result'=>'invalid_total'];
            $id = self::simulation_id($order->get_id(), $config);
            foreach (self::channels($order)[1] as $row) {
                if ($row->event_id === $id) return ['result'=>'existing','id'=>$id];
            }
            return self::emit($order, true, $config) ? ['result'=>'test','id'=>$id] : ['result'=>'unavailable'];
        }) ?? ['result'=>'busy'];
    }

    public static function rows(array $ids, bool $include_tests = true): array {
        if (!$ids || get_option(WUTM_ATM_Queue::SCHEMA) !== WUTM_ATM_Queue::VERSION) return [];
        global $wpdb;
        $table = WUTM_ATM_Queue::table();
        $ids = array_values(array_unique(array_map('absint', $ids)));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $test_check = $include_tests ? '' : ' AND COALESCE(test_mode,0)=0';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_id,order_id,amount,currency,status,browser,browser_result,browser_blocked,reason,
                updated,event_time,attempted,due,tries,http_code,received,test_mode,server_dataset,browser_pixel,dedup_reviewed
             FROM $table WHERE order_id IN ($placeholders) AND event_name='Purchase' AND source='woocommerce'$test_check
             ORDER BY updated DESC,event_id", ...$ids
        ));
        return (array) $rows;
    }

    public static function channels(WC_Order $order, ?array $rows = null): array {
        $official = null;
        $tests = [];
        foreach ($rows ?? self::rows([$order->get_id()]) as $row) {
            if ((int) $row->order_id !== $order->get_id()) continue;
            if ((int) $row->test_mode === 1) $tests[] = $row;
            elseif (!$official || $row->status === 'sent') $official = $row;
        }
        return [$official, $tests];
    }

    public static function has_legacy_browser(array $tests): bool {
        foreach ($tests as $test) if ($test->browser) return true;
        return false;
    }

    private static function official_id(WC_Order $order, $row, array $tests): string {
        $stored = $order->get_meta(self::ID);
        if ($row) return $row->event_id;
        if (is_string($stored) && preg_match('/^atm-[a-f0-9]{64}$/D', $stored)) return $stored;
        $key = 'order:' . $order->get_id();
        $legacy = WUTM_ATM_Events::event_id('Purchase', $key);
        foreach ($tests as $test) {
            if ($test->event_id === $legacy) return WUTM_ATM_Events::event_id('Purchase', $key . ':live');
        }
        return $legacy;
    }

    private static function confirm(WC_Order $order, string $source, string $transition = ''): void {
        [$row, $tests] = self::channels($order);
        $confirmation = [
            'time'=>time(),'user_id'=>get_current_user_id(),'source'=>$source,'transition'=>$transition,
            'method'=>$order->get_payment_method(),'total'=>$order->get_total(),'currency'=>$order->get_currency(),
            'status'=>$order->get_status(),'event_id'=>self::official_id($order, $row, $tests),'mode'=>'production',
        ];
        $order->update_meta_data(self::CONFIRMATION, $confirmation);
        $order->save_meta_data();
    }

    public static function locked(int $id, callable $operation, ?callable $on_contention = null) {
        if (!self::active() || !$id || get_option(WUTM_ATM_Queue::SCHEMA) !== WUTM_ATM_Queue::VERSION) return null;
        $key = 'wutm_atm_purchase_lease_' . $id;
        $lease = WUTM_ATM_Queue::lock($key);
        if ($lease === '') {
            if ($on_contention) $on_contention();
            return null;
        }
        try {
            $order = wc_get_order($id);
            if (!$order instanceof WC_Order || $order->get_type() !== 'shop_order') return null;
            $order->read_meta_data(true);
            return $operation($order);
        } catch (Throwable $error) {
            // Payment and order saves remain owned by WooCommerce.
            return null;
        } finally {
            WUTM_ATM_Queue::unlock($lease, $key);
        }
    }

    public static function act(int $id, string $action): string {
        return self::locked($id, static function (WC_Order $order) use ($action) {
            if (!current_user_can('edit_shop_orders') || !current_user_can('edit_shop_order', $order->get_id())) return 'unavailable';
            if (!self::consent_valid($order)) return 'consent';
            if (!self::valid_total($order)) return 'invalid_total';
            [$row, $tests] = self::channels($order);
            if (self::has_legacy_browser($tests)) return 'existing';
            if ($action === 'confirm') {
                if ($row || self::payment($order) || !self::manual_available($order)) return 'unavailable';
                self::confirm($order, 'manual');
                return WUTM_ATM_Events::server_active() && self::emit($order, true) ? 'queued' : 'confirmed';
            }
            if (!WUTM_ATM_Events::server_active()) return 'unavailable';
            if (!self::fresh(self::payment($order))) return 'unpaid';
            if ($action === 'retry' && self::retryable($row, $order)) {
                return self::emit($order, true, [], $row) ? 'queued' : 'unavailable';
            }
            if ($action !== 'send' || ($row && $row->status !== 'browser_prepared')) return 'existing';
            return self::emit($order, true) ? 'queued' : 'unavailable';
        }) ?? 'busy';
    }

    public static function retryable($row, WC_Order $order): bool {
        return $row && $row->status === 'failed' && (int) $row->tries < 4 &&
            (int) $row->event_time >= time() - 2 * DAY_IN_SECONDS &&
            !in_array($row->reason, ['expired','cleared_by_admin'], true) &&
            $row->server_dataset === WUTM_ATM_Events::dataset() && self::matches_total($row, $order);
    }

    public static function matches_total($record, WC_Order $order): bool {
        return self::valid_total($order) && is_numeric($record->amount) &&
            (float) $record->amount === (float) $order->get_total() && $record->currency === $order->get_currency();
    }

    public static function emit(WC_Order $order, bool $server, array $test = [], $retry = null): ?string {
        if (!self::active() || !self::valid_total($order) || !self::consent_valid($order)) return null;
        $payment = self::payment($order);
        $simulation = (bool) $test;
        if (!$simulation && !self::fresh($payment)) return null;
        [$row, $tests] = self::channels($order);
        if ($simulation) {
            $id = self::simulation_id($order->get_id(), $test);
        } else {
            if ($row && (!self::matches_total($row, $order) ||
                (!$retry && ($server ? $row->status !== 'browser_prepared' : $row->browser)))) return null;
            // v3.3.0 could attach a test server payload to an already prepared production Pixel event.
            // Keep that order closed to new production activity instead of allocating a second ID.
            if (self::has_legacy_browser($tests)) return null;
            $id = self::official_id($order, $row, $tests);
            if ($order->get_meta(self::ID) !== $id) {
                $order->update_meta_data(self::ID, $id);
                $order->save_meta_data();
            }
        }
        $ids = [];
        $quantity = 0;
        foreach (self::items($order) as $item) {
            $ids[] = (string) $item['id'];
            $quantity += $item['quantity'];
        }
        $time = $retry ? (int) $retry->event_time : ($simulation ? time() : $payment['time']);
        $user = $simulation
            ? ['email'=>'','user_agent'=>'WU Toolbox Purchase Test','ip'=>'','fbp'=>'',
                'external_id'=>hash_hmac('sha256', $id, wp_salt('auth'))]
            : ['email'=>$order->get_billing_email(),'user_agent'=>$order->get_customer_user_agent(),
                'ip'=>$order->get_customer_ip_address(),'fbp'=>''];
        return WUTM_ATM_Events::track('Purchase', [
            'order_id'=>(string) $order->get_id(), 'content_ids'=>array_unique($ids), 'content_type'=>'product',
            'value'=>$order->get_total(), 'currency'=>$order->get_currency(), 'num_items'=>$quantity,
        ], [
            'source'=>'woocommerce','key'=>'order:' . $order->get_id(),'purchase_id'=>$id,
            'order_id'=>$order->get_id(),'time'=>$time,'url'=>$order->get_meta(self::CHECKOUT_URL) ?: wc_get_checkout_url(),
            'recorded_consent'=>self::consent($order),'server_only'=>$server,'browser_only'=>!$server,
            'purchase_test'=>$simulation ? 'simulation' : '',
            'test_configuration'=>$simulation ? $test : ['code'=>'','dataset'=>WUTM_ATM_Events::dataset()],
            'retry_purchase'=>(bool) $retry,'user'=>$user,
        ]);
    }

    public static function details(WC_Order $order, $row, array $tests, array $paid, bool $consent): array {
        $confirmation = (array) $order->get_meta(self::CONFIRMATION);
        $source = ['woocommerce'=>'WooCommerce 付款完成','integration'=>'網站付款整合',
            'manual'=>'管理員確認收款','transition'=>'指定付款方式與訂單狀態轉換'];
        $statuses = WUTM_ATM_Event_Admin::labels();
        $date = $order->get_date_paid();
        $confirmed = $row && $row->status === 'sent' && (int) $row->received > 0;
        $status = $confirmed ? 'Meta 已確認接收' : ($row ? ($statuses[$row->status] ?? '尚未確認') : '');
        if ($row && $row->status === 'sent' && !$confirmed) $status = '尚無 Meta 接收確認';
        if (!$confirmed && !$consent) $status = '不符合行銷同意條件';
        if ($status === '') $status = $paid ? '尚未發送' : (self::manual_available($order) ? '等待確認收款' : '尚未付款');
        if ($status === $statuses['browser_prepared']) $status = '瀏覽器事件尚未驗證';
        if (!$row && self::has_legacy_browser($tests)) $status = '既有瀏覽器事件尚未驗證';
        $test_results = [];
        foreach (array_slice($tests, 0, 5) as $test) {
            $test_results[] = WUTM_ATM_Observability::date($test->updated) . ' · ' .
                ($statuses[$test->status] ?? '尚未確認') . ' · ' . $test->event_id;
        }
        return [
            '購買事件狀態'=>$status,
            '訂單狀態'=>wc_get_order_status_name($order->get_status()),
            'WooCommerce 付款狀態'=>$order->is_paid() ? 'WooCommerce 標示已付款' : 'WooCommerce 標示未付款',
            '訂單總額'=>$order->get_currency() . ' ' . $order->get_total(),
            '付款完成時間'=>WUTM_ATM_Observability::date($date ? $date->getTimestamp() : 0),
            'Purchase 付款條件'=>$paid ? (self::fresh($paid) ? '符合' : '已確認付款，超過發送期限') : '不符合',
            '付款確認來源'=>$source[$paid['source'] ?? ''] ?? '尚未確認收款',
            '人工收款紀錄'=>empty($confirmation['time']) ? '尚無紀錄' : WUTM_ATM_Observability::date($confirmation['time']) .
                ' · ' . ($source[$confirmation['source']] ?? '已記錄') . ' · ' .
                ($confirmation['user_id'] ? '管理員 #' . $confirmation['user_id'] : '背景流程'),
            '交易同意收據'=>$consent ? '目前可驗證' : '無有效紀錄',
            '瀏覽器狀態'=>$row ? WUTM_ATM_Observability::browser_label($row) : '瀏覽器事件尚未驗證',
            '伺服器狀態'=>$row && $row->status !== 'browser_prepared'
                ? ($row->status === 'sent' && !$confirmed ? '尚無 Meta 接收確認' : ($statuses[$row->status] ?? '尚未確認'))
                : '尚無伺服器紀錄',
            '最近發送時間'=>WUTM_ATM_Observability::date($row->attempted ?? 0),
            'HTTP 與接收數'=>$row && $row->http_code ? 'HTTP ' . $row->http_code . ' · ' . $row->received . ' 筆' : '尚無回應',
            '商品 ID 與數量'=>implode('、', array_map(static function ($item) {
                return '#' . $item['id'] . ' × ' . $item['quantity'];
            }, self::items($order))) ?: '—',
            'Meta 接收結果'=>$confirmed ? '已接收 · ' . WUTM_ATM_Observability::date($row->updated) : '尚無 Meta 接收確認',
            'Purchase event_id'=>$row->event_id ?? ($order->get_meta(self::ID) ?: '尚未建立正式事件'),
            '已紀錄的事件金額'=>isset($row->amount) && $row->amount !== '' ? $row->currency . ' ' . $row->amount : '—',
            '原因'=>$row ? WUTM_ATM_Observability::reason($row->reason) : '—',
            '測試事件'=>$test_results ? implode(' ｜ ', $test_results) : '尚無測試事件',
            '防重複觀察'=>$row ? '訂單已有固定識別，重試沿用原事件。' : '正式事件建立後會保留固定識別。',
        ];
    }

    public static function valid_at_delivery(array $event, WC_Order $order): bool {
        $record = (object) ['amount'=>$event['custom_data']['value'] ?? null,'currency'=>$event['custom_data']['currency'] ?? null];
        $consent = self::consent($order);
        return self::active() && self::matches_total($record, $order) &&
            !empty($consent['granted']) && ($consent['reference'] ?? '') === $event['consent'] &&
            (($event['purchase_test'] ?? '') === 'simulation' || (bool) self::payment($order));
    }
}
