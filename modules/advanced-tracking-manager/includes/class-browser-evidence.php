<?php
defined('ABSPATH') || exit;

final class WUTM_ATM_Browser_Evidence {
    const ACTION = 'wutm_atm_browser_evidence';
    const QUEUED = 1;
    const HANDOFF = 2;
    const LOAD_FAILED = 4;

    public static function boot(): void {
        if (empty(WUTM_ATM_Events::settings()['browser_evidence'])) return;
        add_action('wp_ajax_' . self::ACTION, [__CLASS__, 'report']);
        add_action('wp_ajax_nopriv_' . self::ACTION, [__CLASS__, 'report']);
    }

    public static function ticket(string $id): array {
        $expires = time() + 300;
        $pixel = WUTM_ATM_Events::pixel();
        return ['expires'=>$expires, 'pixel'=>$pixel, 'proof'=>self::sign($id, $pixel, $expires),
            'url'=>add_query_arg('action', self::ACTION, admin_url('admin-ajax.php', 'relative'))];
    }

    private static function sign(string $id, string $pixel, int $expires): string {
        return hash_hmac('sha256', "$id|$pixel|$expires", wp_salt('nonce'));
    }

    public static function report(): void {
        nocache_headers();
        $origin = get_http_origin();
        $expected = wp_parse_url(home_url('/'));
        $actual = $origin ? wp_parse_url($origin) : [];
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_WUTM_EVIDENCE'] ?? '') !== '1' ||
            !$actual || ($actual['host'] ?? '') !== $expected['host'] || ($actual['scheme'] ?? '') !== $expected['scheme'] ||
            ($actual['port'] ?? null) !== ($expected['port'] ?? null) ||
            !WUTM_ATM_Events::browser_active() || !(WUTM_ATM_Events::consent() || WUTM_ATM_Consent::allows_default_visit())) {
            wp_send_json_error(null, 403);
        }
        $items = json_decode(file_get_contents('php://input', false, null, 0, 8192), true);
        if (!is_array($items) || count($items) > 20 || get_option(WUTM_ATM_Queue::SCHEMA) !== WUTM_ATM_Queue::VERSION) {
            wp_send_json_error(null, 400);
        }
        global $wpdb;
        $table = WUTM_ATM_Queue::table();
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $id = $item['id'] ?? '';
            $pixel = $item['pixel'] ?? '';
            $expires = (int) ($item['expires'] ?? 0);
            $flag = $item['flag'] ?? 0;
            $proof = $item['proof'] ?? '';
            if (!is_string($id) || !preg_match('/^atm-[a-f0-9]{64}$/D', $id) || !is_string($proof) ||
                !is_string($pixel) || !preg_match('/^\d{1,40}$/D', $pixel) ||
                !in_array($flag, [self::QUEUED,self::HANDOFF,self::LOAD_FAILED], true) ||
                $expires < time() || $expires > time() + 300 || !hash_equals(self::sign($id,$pixel,$expires), $proof)) continue;
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET browser_result=browser_result | %d WHERE event_id=%s AND browser_pixel=%s AND browser=1 AND (browser_result & %d) = 0",
                $flag, $id, $pixel, $flag
            ));
        }
        wp_send_json_success();
    }
}
