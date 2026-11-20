<?php
defined('ABSPATH') || exit;

final class WUTM_ATM_Queue {
    const HOOK = 'wutm_atm_process_events';
    const SCHEDULE = 'wutm_atm_maintain_events';
    const SCHEMA = 'wutm_atm_event_schema';
    const STATES = ['browser_prepared','pending','sending','retry','sent','failed','consent_blocked'];

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'wutm_atm_events';
    }

    public static function boot(): void {
        add_action('init', [__CLASS__, 'ensure'], 5);
        add_action(self::HOOK, [__CLASS__, 'work']);
        add_action(self::SCHEDULE, [__CLASS__, 'work']);
    }

    public static function ensure(): void {
        if (get_option(self::SCHEMA) !== '1') {
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
                tries tinyint unsigned NOT NULL DEFAULT 0,
                due bigint unsigned NOT NULL DEFAULT 0,
                updated bigint unsigned NOT NULL,
                reason varchar(80) NOT NULL DEFAULT '',
                payload longtext NOT NULL,
                PRIMARY KEY  (event_id),
                KEY due (status,due),
                KEY updated (updated),
                KEY event_order (order_id,event_name)
            ) " . $wpdb->get_charset_collate() . ';');
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return;
            update_option(self::SCHEMA, '1', false);
        }
        if (!wp_next_scheduled(self::SCHEDULE)) wp_schedule_event(time() + 300, 'hourly', self::SCHEDULE);
    }

    public static function record(array $event, string $status, bool $browser = false, string $reason = ''): void {
        if (get_option(self::SCHEMA) !== '1') return;
        global $wpdb;
        $table = self::table();
        $time = time();
        $payload = '';
        if ($status === 'pending') {
            $event['dataset'] = WUTM_ATM_Events::dataset();
            $event['test_code'] = WUTM_ATM_Events::settings()['test_code'];
            $payload = wp_json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE);
            if (!is_string($payload)) return;
        }
        // One row for both channels. Repeated callbacks cannot reset a server attempt.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (event_id,event_name,source,event_time,order_id,amount,currency,status,browser,due,updated,reason,payload)
             VALUES (%s,%s,%s,%d,%d,%s,%s,%s,%d,%d,%d,%s,%s)
             ON DUPLICATE KEY UPDATE
               browser=GREATEST(browser,VALUES(browser)),
               payload=IF(status='browser_prepared' AND VALUES(status)='pending',VALUES(payload),payload),
               due=IF(status='browser_prepared' AND VALUES(status)='pending',VALUES(due),due),
               reason=IF(status='browser_prepared' AND VALUES(status) IN ('pending','consent_blocked','failed'),VALUES(reason),reason),
               status=IF(status='browser_prepared' AND VALUES(status) IN ('pending','consent_blocked','failed'),VALUES(status),status)",
            $event['event_id'], $event['event_name'], $event['source'], $event['event_time'], $event['order_id'],
            (string) ($event['custom_data']['value'] ?? ''), $event['custom_data']['currency'] ?? '',
            $status, (int) $browser, $time, $time, $reason, $payload
        ));
        if ($status === 'pending' && !wp_next_scheduled(self::HOOK)) wp_schedule_single_event($time + 10, self::HOOK);
    }

    public static function work(): void {
        if (get_option(self::SCHEMA) !== '1') return;
        global $wpdb;
        $table = self::table();
        $now = time();
        $settings = WUTM_ATM_Events::settings();
        $retention = max(7, min(60, (int) $settings['retention']));
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE updated < %d ORDER BY updated LIMIT 1000", $now - $retention * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET status='failed', reason='expired', payload='', updated=%d
             WHERE status IN ('pending','retry','sending') AND (event_time < %d OR (tries >= 4 AND due <= %d)) LIMIT 200",
            $now, $now - 2 * DAY_IN_SECONDS, $now
        ));
        if (!$settings['enabled'] || !WUTM_ATM_Events::server_active()) return;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_id,tries,payload FROM $table WHERE status IN ('pending','retry','sending') AND due <= %d AND tries < 4 ORDER BY due LIMIT 10",
            $now
        ));
        foreach ((array) $rows as $row) {
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET status='sending', tries=tries+1, due=%d, updated=%d
                 WHERE event_id=%s AND status IN ('pending','retry','sending') AND tries=%d AND due <= %d",
                $now + 300, $now, $row->event_id, $row->tries, $now
            ));
            if ($claimed !== 1) continue;
            $event = json_decode($row->payload, true);
            try {
                [$status, $reason] = self::send($event, $settings);
            } catch (Throwable $error) {
                [$status, $reason] = ['retry','local_error'];
            }
            $tries = (int) $row->tries + 1;
            if ($status === 'retry' && $tries >= 4) $status = 'failed';
            $due = $status === 'retry' ? time() + 60 * ( 4 ** ( $tries - 1 ) ) : 0;
            $wpdb->update($table, [
                'status'=>$status, 'reason'=>$reason, 'due'=>$due, 'updated'=>time(),
                'payload'=>$status === 'retry' ? $row->payload : '',
            ], ['event_id'=>$row->event_id,'tries'=>$tries,'status'=>'sending']);
        }
        $next = $wpdb->get_var("SELECT MIN(due) FROM $table WHERE status IN ('pending','retry','sending')");
        if ($next && !wp_next_scheduled(self::HOOK)) wp_schedule_single_event(max(time() + 10, (int) $next), self::HOOK);
    }

    private static function send(?array $event, array $settings): array {
        if (!$event || $event['dataset'] !== WUTM_ATM_Events::dataset() || !WUTM_ATM_Events::is_enabled($event['event_name'])) {
            return ['failed','configuration_changed'];
        }
        if (!WUTM_ATM_Events::consent_valid(['granted'=>true,'reference'=>$event['consent']], $event)) {
            return ['consent_blocked','consent_revoked_or_unknown'];
        }
        if (!empty($event['order_id']) && $event['source'] === 'woocommerce') {
            if (!class_exists('WUTM_ATM_Woo_Events') || !WUTM_ATM_Woo_Events::paid(wc_get_order($event['order_id']))) return ['failed','order_no_longer_paid'];
        }
        $version = preg_match('/^v[0-9]{1,2}\.0$/D', $settings['version']) ? $settings['version'] : 'v25.0';
        $data = array_intersect_key($event, array_flip(['event_id','event_name','event_time','event_source_url','custom_data','user_data']));
        $data['action_source'] = 'website';
        $body = ['data'=>[$data]];
        if ($event['test_code'] !== '') $body['test_event_code'] = $event['test_code'];
        $response = wp_remote_post('https://graph.facebook.com/' . $version . '/' . $event['dataset'] . '/events', [
            'timeout'=>8, 'redirection'=>0, 'limit_response_size'=>16384,
            'headers'=>['Content-Type'=>'application/json', 'Authorization'=>'Bearer ' . WUTM_ATM_Events::token()],
            'body'=>wp_json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        if (is_wp_error($response)) return ['retry','transport_error'];
        $code = (int) wp_remote_retrieve_response_code($response);
        $result = json_decode(wp_remote_retrieve_body($response), true);
        if ($code >= 200 && $code < 300 && (int) ($result['events_received'] ?? 0) > 0) return ['sent',''];
        if ($code >= 500 || in_array($code, [408,429], true) || !empty($result['error']['is_transient'])) return ['retry','http_' . $code];
        if ($code >= 200 && $code < 300) return ['retry','no_event_acknowledgement'];
        return ['failed','http_' . $code . (empty($result['error']['code']) ? '' : '_meta_' . (int) $result['error']['code'])];
    }
}
