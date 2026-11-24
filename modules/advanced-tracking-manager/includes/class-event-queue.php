<?php
defined('ABSPATH') || exit;

final class WUTM_ATM_Queue {
    const HOOK = 'wutm_atm_process_events';
    const SCHEDULE = 'wutm_atm_maintain_events';
    const SCHEMA = 'wutm_atm_event_schema';
    const VERSION = '2';
    const LOCK = 'wutm_atm_worker_lease';
    const LAST_CRON = 'wutm_atm_last_cron';
    const LAST_RUN = 'wutm_atm_worker_result';
    const CAPACITY_CHECK = 'wutm_atm_capacity_check';
    const HISTORY_CLEARED = 'wutm_atm_history_cleared';
    const STATES = ['browser_prepared','pending','sending','retry','sent','failed','consent_blocked'];

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'wutm_atm_events';
    }

    public static function boot(): void {
        add_action('init', [__CLASS__, 'ensure'], 5);
        add_action(self::HOOK, [__CLASS__, 'work']);
        add_action(self::SCHEDULE, [__CLASS__, 'work']);
        add_action('woocommerce_delete_order', [__CLASS__, 'delete_order']);
    }

    public static function ensure(): void {
        if (get_option(self::SCHEMA) !== self::VERSION) {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $table = self::table();
            dbDelta("CREATE TABLE $table (
                event_id varchar(80) NOT NULL,
                event_name varchar(40) NOT NULL,
                source varchar(40) NOT NULL,
                event_time bigint unsigned NOT NULL,
                order_id bigint unsigned NOT NULL DEFAULT 0,
                amount varchar(32) NOT NULL DEFAULT '',
                currency varchar(3) NOT NULL DEFAULT '',
                status varchar(24) NOT NULL,
                browser tinyint unsigned NOT NULL DEFAULT 0,
                browser_blocked tinyint unsigned NOT NULL DEFAULT 0,
                browser_result tinyint unsigned NOT NULL DEFAULT 0,
                browser_pixel varchar(40) NOT NULL DEFAULT '',
                server_dataset varchar(40) NOT NULL DEFAULT '',
                created bigint unsigned NOT NULL DEFAULT 0,
                dedup_reviewed bigint unsigned NOT NULL DEFAULT 0,
                test_mode tinyint unsigned DEFAULT NULL,
                attempted bigint unsigned NOT NULL DEFAULT 0,
                http_code smallint unsigned NOT NULL DEFAULT 0,
                received smallint unsigned NOT NULL DEFAULT 0,
                matching tinyint unsigned NOT NULL DEFAULT 0,
                products varchar(1024) NOT NULL DEFAULT '',
                tries tinyint unsigned NOT NULL DEFAULT 0,
                due bigint unsigned NOT NULL DEFAULT 0,
                updated bigint unsigned NOT NULL,
                reason varchar(80) NOT NULL DEFAULT '',
                payload longtext NOT NULL,
                PRIMARY KEY  (event_id),
                KEY due (status,due),
                KEY updated (updated),
                KEY event_time (event_time),
                KEY event_order (order_id,event_name)
            ) " . $wpdb->get_charset_collate() . ';');
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return;
            $columns = $wpdb->get_col("SHOW COLUMNS FROM $table");
            if (array_diff(['browser_result','browser_blocked','browser_pixel','server_dataset','created','dedup_reviewed','test_mode','attempted','http_code','received','matching','products'], $columns)) return;
            // Older attempts retain their original test code inside the queued payload.
            update_option(self::SCHEMA, self::VERSION, false);
        }
        if (!wp_next_scheduled(self::SCHEDULE)) wp_schedule_event(time() + 300, 'hourly', self::SCHEDULE);
    }

    public static function durable_purchase(): string {
        return "(event_name='Purchase' AND source='woocommerce' AND order_id > 0 AND (COALESCE(test_mode,0)=0 OR browser=1))";
    }

    public static function delete_order($id): void {
        if (!(int) $id || get_option(self::SCHEMA) !== self::VERSION) return;
        global $wpdb;
        $wpdb->delete(self::table(), ['order_id'=>(int) $id,'event_name'=>'Purchase','source'=>'woocommerce']);
    }

    public static function record(array $event, string $status, bool $browser = false, string $reason = ''): bool {
        if (get_option(self::SCHEMA) !== self::VERSION) return false;
        global $wpdb;
        $table = self::table();
        $time = time();
        $payload = '';
        $retry_purchase = !empty($event['retry_purchase']);
        $retry_matching = !empty($event['retry_matching']);
        if ($retry_matching && $status !== 'pending') return false;
        $config = $event['test_configuration'] ?? ['code'=>'','dataset'=>WUTM_ATM_Events::dataset()];
        $server_dataset = !empty($event['server_channel']) || $status === 'pending' ? $config['dataset'] : '';
        $browser_pixel = $browser ? WUTM_ATM_Events::pixel() : '';
        $test = (int) ($config['code'] !== '');
        $products = array_filter((array) ($event['custom_data']['content_ids'] ?? []), static function ($id) {
            return ctype_digit((string) $id) && strlen((string) $id) <= 18;
        });
        $matching = self::matching($event['user_data'] ?? []);
        if ($status === 'pending') {
            $event['dataset'] = $config['dataset'];
            $event['test_code'] = $config['code'];
            unset($event['test_configuration'], $event['retry_purchase'], $event['retry_matching']);
            $payload = wp_json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($payload)) return false;
        }
        // A reviewed retry can only move the original failed row back to the queue.
        if ($retry_matching) {
            $written = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET status='pending',reason='',payload=%s,due=%d,updated=%d,matching=%d
                 WHERE event_id=%s AND status='failed' AND reason='matching_data_missing' AND browser=1 AND tries=0
                   AND order_id=0 AND created >= %d AND server_dataset=%s AND browser_pixel=%s AND test_mode=0
                   AND amount=%s AND currency=%s AND products=%s",
                $payload, $time, $time, $matching, $event['event_id'], $time - 120, $server_dataset, $server_dataset,
                (string) ($event['custom_data']['value'] ?? ''), $event['custom_data']['currency'] ?? '', implode(',', $products)
            ));
        } elseif ($status === 'pending' && !empty($retry_purchase)) {
            $written = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET status='pending',reason='',payload=%s,due=%d,updated=%d
                 WHERE event_id=%s AND status='failed' AND tries < 4 AND server_dataset=%s AND test_mode=0",
                $payload, $time, $time, $event['event_id'], $server_dataset
            ));
        } else {
            $written = $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (event_id,event_name,source,event_time,order_id,amount,currency,status,browser,due,updated,reason,payload,test_mode,matching,products,browser_pixel,server_dataset,created,browser_blocked)
             VALUES (%s,%s,%s,%d,%d,%s,%s,%s,%d,%d,%d,%s,%s,%d,%d,%s,%s,%s,%d,%d)
             ON DUPLICATE KEY UPDATE
               browser=GREATEST(browser,VALUES(browser)),
               browser_blocked=GREATEST(browser_blocked,VALUES(browser_blocked)),
               browser_pixel=IF(browser_pixel='',VALUES(browser_pixel),browser_pixel),
               server_dataset=IF(status='browser_prepared' AND server_dataset='',VALUES(server_dataset),server_dataset),
               payload=IF(status='browser_prepared' AND VALUES(status)='pending',VALUES(payload),payload),
               due=IF(status='browser_prepared' AND VALUES(status)='pending',VALUES(due),due),
               test_mode=IF(status='browser_prepared' AND VALUES(status)='pending',VALUES(test_mode),test_mode),
               matching=GREATEST(matching,VALUES(matching)),
               reason=IF(status='browser_prepared' AND VALUES(status) IN ('pending','consent_blocked','failed'),VALUES(reason),reason),
               status=IF(status='browser_prepared' AND VALUES(status) IN ('pending','consent_blocked','failed'),VALUES(status),status)",
            $event['event_id'], $event['event_name'], $event['source'], $event['event_time'], $event['order_id'],
            (string) ($event['custom_data']['value'] ?? ''), $event['custom_data']['currency'] ?? '',
            $status, (int) $browser, $status === 'pending' ? $time : 0, $time, $reason, $payload,
            $test, $matching, implode(',', $products), $browser_pixel, $server_dataset, $time, (int) !empty($event['browser_blocked'])
            ));
        }
        if (!$written) return false;
        if ($status === 'pending') self::schedule($time + 10);
        return true;
    }

    public static function diagnostic_result(array $config, array $result): void {
        $now = time();
        $status = $result['confirmed'] ? 'sent' : 'failed';
        $event = ['event_id'=>$result['event_id'],'event_name'=>'PageView','source'=>'diagnostic','event_time'=>$now,
            'order_id'=>0,'custom_data'=>[],'test_configuration'=>$config,'server_channel'=>true];
        if (!self::record($event, $status, false, $result['error'])) return;
        global $wpdb;
        $wpdb->update(self::table(), ['attempted'=>$now,'http_code'=>$result['http'],'received'=>$result['received'],'tries'=>1],
            ['event_id'=>$event['event_id']]);
    }

    public static function matching(array $data): int {
        $mask = 0;
        foreach (['client_user_agent','em','fbp','fbc','client_ip_address'] as $bit=>$key) {
            if (!empty($data[$key])) $mask |= 1 << $bit;
        }
        return $mask;
    }

    public static function lock(string $key = self::LOCK): string {
        global $wpdb;
        $lease = (time() + 300) . ':' . wp_generate_uuid4();
        $acquired = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')",
            $key, $lease
        ));
        if ($acquired !== 1) {
            $acquired = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) <= %d",
                $lease, $key, time()
            ));
        }
        return $acquired === 1 ? $lease : '';
    }

    public static function unlock(string $lease, string $key = self::LOCK): void {
        global $wpdb;
        $wpdb->delete($wpdb->options, ['option_name'=>$key,'option_value'=>$lease]);
    }

    public static function work(string $trigger = 'cron'): array {
        $result = ['result'=>'unavailable','trigger'=>$trigger,'time'=>time(),'processed'=>0,'expired'=>0,'removed'=>0,'sent'=>0,'retry'=>0,'failed'=>0,'consent_blocked'=>0];
        if (get_option(self::SCHEMA) !== self::VERSION) return $result;
        $lease = self::lock();
        if ($lease === '') {
            $result['result'] = 'locked';
            return $result;
        }
        try {
            self::process($result);
        } catch (Throwable $error) {
            $result['result'] = 'error';
        } finally {
            $result['finished'] = time();
            update_option(self::LAST_RUN, $result, false);
            if ($trigger === 'cron') update_option(self::LAST_CRON, $result, false);
            self::unlock($lease);
        }
        return $result;
    }

    private static function process(array &$result): void {
        global $wpdb;
        $table = self::table();
        $now = time();
        $deadline = microtime(true) + 18;
        $settings = WUTM_ATM_Events::settings();
        $retention = max(7, min(60, (int) $settings['retention']));
        $durable = self::durable_purchase();
        // Keep one compact, unique receipt per tracked order after log retention.
        $removed = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE updated < %d AND NOT $durable ORDER BY updated LIMIT 1000", $now - $retention * DAY_IN_SECONDS
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET products='',matching=0 WHERE updated < %d AND $durable
             AND status IN ('browser_prepared','sent','failed','consent_blocked')
             AND (products<>'' OR matching<>0) ORDER BY updated LIMIT 1000",
            $now - $retention * DAY_IN_SECONDS
        ));
        $expired = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET status='failed', reason='expired', payload='', updated=%d
             WHERE status IN ('pending','retry','sending') AND (status <> 'sending' OR due <= %d)
               AND (event_time < %d OR (tries >= 4 AND due <= %d)) LIMIT 200",
            $now, $now, $now - 2 * DAY_IN_SECONDS, $now
        ));
        if ($removed === false || $expired === false) { $result['result'] = 'error'; return; }
        $result['removed'] = $removed;
        if ($now - (int) get_option(self::CAPACITY_CHECK, 0) >= HOUR_IN_SECONDS) {
            // Bound archived history without deleting outstanding events or active Purchase keys.
            $archived = "status IN ('browser_prepared','sent','failed','consent_blocked') AND NOT $durable";
            $cutoff = (int) $wpdb->get_var("SELECT updated FROM $table WHERE $archived ORDER BY updated DESC LIMIT 1 OFFSET 49999");
            if ($wpdb->last_error !== '') { $result['result'] = 'error'; return; }
            if ($cutoff) {
                $trimmed = $wpdb->query($wpdb->prepare(
                    "DELETE FROM $table WHERE $archived AND updated < %d AND (event_name <> 'Purchase' OR event_time < %d)
                     ORDER BY updated LIMIT 1000", $cutoff, $now - 2 * DAY_IN_SECONDS
                ));
                if ($trimmed === false) { $result['result'] = 'error'; return; }
                $result['removed'] += $trimmed;
            }
            update_option(self::CAPACITY_CHECK, $now, false);
        }
        $result['expired'] = $expired;
        $result['result'] = 'inactive';
        if (!$settings['enabled'] || !WUTM_ATM_Events::server_active()) return;
        $result['result'] = 'ok';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_id,tries,payload,test_mode FROM $table WHERE status IN ('pending','retry','sending') AND due <= %d AND tries < 4 ORDER BY due LIMIT 10",
            $now
        ));
        if ($wpdb->last_error !== '') { $result['result'] = 'error'; return; }
        foreach ((array) $rows as $row) {
            if (microtime(true) >= $deadline) break;
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET status='sending', tries=tries+1, due=%d, updated=%d, attempted=%d
                 WHERE event_id=%s AND status IN ('pending','retry','sending') AND tries=%d AND due <= %d",
                time() + 300, time(), time(), $row->event_id, $row->tries, $now
            ));
            if ($claimed === false) $result['result'] = 'error';
            if ($claimed !== 1) continue;
            $result['processed']++;
            $event = json_decode($row->payload, true);
            try {
                $outcome = (int) $row->test_mode === 1 && empty($event['test_code'])
                    ? ['failed','test_snapshot_missing'] : self::send($event, $settings);
            } catch (Throwable $error) {
                $outcome = ['retry','local_error'];
            }
            [$status, $reason, $code, $received] = $outcome + [2=>0,3=>0];
            $tries = (int) $row->tries + 1;
            if ($status === 'retry' && $tries >= 4) $status = 'failed';
            $due = $status === 'retry' ? time() + 60 * ( 4 ** ( $tries - 1 ) ) : 0;
            $updated = $wpdb->update($table, [
                'status'=>$status, 'reason'=>$reason, 'due'=>$due, 'updated'=>time(),
                'http_code'=>$code, 'received'=>$received,
                'test_mode'=>is_array($event) ? (int) !empty($event['test_code']) : null,
                'payload'=>$status === 'retry' ? $row->payload : '',
            ], ['event_id'=>$row->event_id,'tries'=>$tries,'status'=>'sending']);
            if ($updated === 1) $result[$status]++;
            else $result['result'] = 'error';
        }
        $next = $wpdb->get_var("SELECT MIN(due) FROM $table WHERE status IN ('pending','retry','sending')");
        if ($next) self::schedule(max(time() + 10, (int) $next));
    }

    private static function schedule(int $due): void {
        $next = wp_next_scheduled(self::HOOK);
        if ($next && $next <= $due) return;
        if ($next && !wp_unschedule_event($next, self::HOOK)) return;
        wp_schedule_single_event($due, self::HOOK);
    }

    private static function send(?array $event, array $settings): array {
        if (!$event || !WUTM_ATM_Events::is_enabled($event['event_name'])) return ['failed','configuration_changed'];
        $test_code = $event['test_code'] ?? '';
        $purchase_test = $event['purchase_test'] ?? '';
        if (($purchase_test !== '' || $test_code !== '') &&
            (!is_string($test_code) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $test_code))) {
            return ['failed','configuration_changed'];
        }
        $expected = $purchase_test !== '' ? ($settings['purchase_test_dataset'] ?: WUTM_ATM_Events::dataset()) : WUTM_ATM_Events::dataset();
        if ($event['dataset'] !== $expected) return ['failed','configuration_changed'];
        if (!WUTM_ATM_Events::consent_valid(['granted'=>true,'reference'=>$event['consent']], $event)) {
            return ['consent_blocked','consent_revoked_or_unknown'];
        }
        if (!empty($event['order_id']) && $event['source'] === 'woocommerce') {
            if (!class_exists('WUTM_ATM_Woo_Events')) return ['failed','configuration_changed'];
            $order = wc_get_order($event['order_id']);
            if ($event['event_name'] === 'Purchase') {
                if (!class_exists('WUTM_ATM_Purchase') || !$order instanceof WC_Order ||
                    !WUTM_ATM_Purchase::valid_at_delivery($event, $order)) return ['failed','order_no_longer_eligible'];
            } elseif (!WUTM_ATM_Woo_Events::paid($order)) {
                return ['failed','order_no_longer_paid'];
            }
        }
        $version = preg_match('/^v[0-9]{1,2}\.0$/D', $settings['version']) ? $settings['version'] : 'v25.0';
        $data = array_intersect_key($event, array_flip(['event_id','event_name','event_time','event_source_url','custom_data','user_data']));
        $data['action_source'] = 'website';
        $body = ['data'=>[$data]];
        if ($test_code !== '') $body['test_event_code'] = $test_code;
        $response = wp_remote_post('https://graph.facebook.com/' . $version . '/' . $event['dataset'] . '/events', [
            'timeout'=>8, 'redirection'=>0, 'limit_response_size'=>16384,
            'headers'=>['Content-Type'=>'application/json', 'Authorization'=>'Bearer ' . WUTM_ATM_Events::token()],
            'body'=>wp_json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        if (is_wp_error($response)) return ['retry','transport_error'];
        $code = (int) wp_remote_retrieve_response_code($response);
        $result = json_decode(wp_remote_retrieve_body($response), true);
        $received = min(65535, max(0, (int) ($result['events_received'] ?? 0)));
        if ($code >= 200 && $code < 300 && $received > 0) return ['sent','',$code,$received];
        if ($code >= 500 || in_array($code, [408,429], true) || !empty($result['error']['is_transient'])) return ['retry','http_' . $code,$code,0];
        if ($code >= 200 && $code < 300) return ['retry','no_event_acknowledgement',$code,0];
        return ['failed','http_' . $code . (empty($result['error']['code']) ? '' : '_meta_' . (int) $result['error']['code']),$code,0];
    }
}
