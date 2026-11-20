<?php
defined('ABSPATH') || exit;

final class WUTM_ATM_Consent_Store {
    const SCHEMA = 'wutm_atm_consent_schema';
    const CRON = 'wutm_atm_expire_consent';
    const REFERENCE = 'wuatmc_';

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'wutm_atm_consent';
    }

    public static function install(): bool {
        global $wpdb;
        if (get_option(self::SCHEMA) !== '1') {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $table = self::table();
            dbDelta("CREATE TABLE $table (
                visitor char(64) NOT NULL,
                receipt varchar(64) DEFAULT NULL,
                policy varchar(32) NOT NULL,
                analytics tinyint unsigned NOT NULL,
                marketing tinyint unsigned NOT NULL,
                updated bigint unsigned NOT NULL,
                expires bigint unsigned NOT NULL,
                PRIMARY KEY  (visitor),
                UNIQUE KEY receipt (receipt),
                KEY expires (expires)
            ) " . $wpdb->get_charset_collate() . ';');
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return false;
            update_option(self::SCHEMA, '1', false);
        }
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON);
        return true;
    }

    public static function read(string $token, string $policy): ?array {
        if ($token === '' || get_option(self::SCHEMA) !== '1') return null;
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT receipt,analytics,marketing,expires FROM $table WHERE visitor=%s AND policy=%s AND expires>%d",
            hash('sha256', $token), $policy, time()
        ), ARRAY_A);
    }

    public static function save(string $token, string $policy, bool $analytics, bool $marketing, int $expires): bool {
        if ($token === '' || !self::install()) return false;
        global $wpdb;
        $table = self::table();
        $now = time();
        $receipt = self::REFERENCE . bin2hex(random_bytes(24));
        // Renewals with continuous permission retain the order receipt. Re-grants use a different receipt.
        return false !== $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (visitor,receipt,policy,analytics,marketing,updated,expires)
             VALUES (%s,IF(%d,%s,NULL),%s,%d,%d,%d,%d)
             ON DUPLICATE KEY UPDATE
                receipt=IF(marketing=1 AND VALUES(marketing)=1 AND expires>VALUES(updated) AND policy=VALUES(policy),receipt,VALUES(receipt)),
                policy=VALUES(policy),analytics=VALUES(analytics),marketing=VALUES(marketing),updated=VALUES(updated),expires=VALUES(expires)",
            hash('sha256', $token), (int) $marketing, $receipt, $policy, (int) $analytics, (int) $marketing, $now, $expires
        ));
    }

    public static function valid(string $reference, string $policy): bool {
        if (!preg_match('/^wuatmc_[a-f0-9]{48}$/D', $reference) || get_option(self::SCHEMA) !== '1') return false;
        global $wpdb;
        $table = self::table();
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM $table WHERE receipt=%s AND policy=%s AND marketing=1 AND expires>%d",
            $reference, $policy, time()
        ));
    }

    public static function cleanup(): void {
        if (get_option(self::SCHEMA) !== '1') return;
        global $wpdb;
        $table = self::table();
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE expires<=%d ORDER BY expires LIMIT 2000", time()));
    }
}
