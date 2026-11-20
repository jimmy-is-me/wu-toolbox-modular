<?php
defined('ABSPATH') || exit;

final class WUTM_KB_AI_Quota {
    const OPTION = 'wutm_kb_ai_quota_v1';
    const CONCURRENCY = 3;

    private static function mutate(callable $change) {
        global $wpdb;
        $name = self::OPTION;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            // Read directly: the WordPress option cache cannot arbitrate concurrent writers.
            $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name));
            $state = is_string($raw) ? json_decode($raw, true) : [];
            if (!is_array($state)) return new WP_Error('quota_unavailable', '暫時無法確認 AI 額度。');
            $now = time();
            $today = wp_date('Y-m-d', $now);
            $leases = array_filter($state['leases'] ?? [], static function ($until) use ($now) { return $until > $now; });
            if (($state['date'] ?? '') !== $today) {
                $state = ['date'=>$today,'total'=>0,'sessions'=>[],'ips'=>[],'cooldowns'=>[]];
            }
            $state['leases'] = $leases;
            $result = $change($state, $now);
            if (is_wp_error($result)) return $result;
            $next = wp_json_encode($state);
            if ($raw === $next) return $result;
            $saved = $raw === null
                ? $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')", $name, $next))
                : $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND BINARY option_value=BINARY %s", $next, $name, $raw));
            if ($saved === 1) return $result;
            if ($saved === false) break;
        }
        return new WP_Error('quota_unavailable', 'AI 請求較多，請稍候再試。');
    }

    public static function reserve(string $session, string $ip, array $settings) {
        return self::mutate(static function (array &$state, int $now) use ($session, $ip, $settings) {
            $session_count = (int) ($state['sessions'][$session] ?? 0);
            $ip_count = (int) ($state['ips'][$ip] ?? 0);
            $cooldown = $session . ':' . $ip;
            if ($state['total'] >= (int) $settings['site_daily_limit']) return new WP_Error('site_limit', '今日 AI 協助額度已達上限。');
            if ($session_count >= (int) $settings['session_limit']) return new WP_Error('session_limit', '本次瀏覽的 AI 問答額度已用完。');
            if ($ip_count >= (int) $settings['ip_daily_limit']) return new WP_Error('ip_limit', '今日的 AI 問答額度已用完。');
            if (($state['cooldowns'][$cooldown] ?? 0) > $now) return new WP_Error('cooldown', '請稍候再送出下一個問題。');
            if (count($state['leases']) >= self::CONCURRENCY) return new WP_Error('busy', '目前已有多個問題正在處理，請稍後再試。');
            $lease = wp_generate_uuid4();
            $state['leases'][$lease] = $now + 120; // Exceeds the 45-second provider timeout.
            $state['total']++;
            $state['sessions'][$session] = $session_count + 1;
            $state['ips'][$ip] = $ip_count + 1;
            if ((int) $settings['cooldown'] > 0) $state['cooldowns'][$cooldown] = $now + (int) $settings['cooldown'];
            return ['lease'=>$lease,'session_n'=>$session_count + 1,'ip_n'=>$ip_count + 1,'site_n'=>$state['total']];
        });
    }

    public static function release(string $lease): void {
        self::mutate(static function (array &$state) use ($lease) {
            unset($state['leases'][$lease]);
            return true;
        });
    }
}
