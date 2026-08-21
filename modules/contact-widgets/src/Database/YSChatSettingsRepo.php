<?php
/**
 * 設定值存取（自訂資料表，非 wp_options）
 *
 * @package YangSheep\ChatWidgets\Database
 * @since   1.0.0
 */

namespace YangSheep\ChatWidgets\Database;

defined( 'ABSPATH' ) || exit;

class YSChatSettingsRepo {

    /** @var array<string, mixed> 記憶體快取 */
    private static array $cache = [];

    /**
     * 取得設定值
     *
     * @param string $key     設定鍵名
     * @param mixed  $default 預設值
     * @return mixed
     */
    public static function get( string $key, mixed $default = null ): mixed {
        if ( array_key_exists( $key, self::$cache ) ) {
            return self::$cache[ $key ];
        }

        global $wpdb;
        $table = YSChatTableMaker::table_name();

        // 資料表尚未建立時（例如啟用前）安全返回預設值。
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT setting_val FROM {$table} WHERE setting_key = %s LIMIT 1",
                $key
            )
        );

        if ( null === $value ) {
            return $default;
        }

        $decoded = json_decode( $value, true );
        $result  = ( json_last_error() === JSON_ERROR_NONE ) ? $decoded : $value;

        self::$cache[ $key ] = $result;
        return $result;
    }

    /**
     * 儲存設定值（UPSERT）
     *
     * @param string $key   設定鍵名
     * @param mixed  $value 設定值
     */
    public static function set( string $key, mixed $value ): bool {
        global $wpdb;
        $table = YSChatTableMaker::table_name();

        $encoded = is_string( $value ) ? $value : wp_json_encode( $value );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (setting_key, setting_val) VALUES (%s, %s)
                 ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)",
                $key,
                $encoded
            )
        );

        if ( false !== $result ) {
            self::$cache[ $key ] = $value;
            return true;
        }

        return false;
    }

    /**
     * 刪除設定值
     *
     * @param string $key 設定鍵名
     */
    public static function delete( string $key ): bool {
        global $wpdb;
        $table = YSChatTableMaker::table_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->delete( $table, [ 'setting_key' => $key ], [ '%s' ] );

        unset( self::$cache[ $key ] );

        return false !== $result;
    }

    /**
     * 清除記憶體快取
     */
    public static function flush_cache(): void {
        self::$cache = [];
    }
}
