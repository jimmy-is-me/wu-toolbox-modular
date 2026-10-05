<?php
defined( 'ABSPATH' ) || exit;
/** Admin-only, resumable discovery. No background crawler or front-end polling. */
class WU_AIT_Batched_Scan {
    const JOB = 'wu_ait_scan_job';
    const LOCK = 'wu_ait_scan_lock';

    public static function request( string $operation, string $token, int $revision ): array {
        // A non-autoloaded unique option provides a cross-worker lock, unlike transients.
        $now = time();
        $lock = get_option( self::LOCK, 0 );
        $lock_time = is_array( $lock ) ? (int) $lock['time'] : (int) $lock;
        if ( $lock_time && $lock_time < $now - 180 ) { delete_option( self::LOCK ); }
        $lease = array( 'time' => $now, 'token' => wp_generate_uuid4() );
        if ( ! add_option( self::LOCK, $lease, '', false ) ) {
            throw new RuntimeException( '另一個掃描請求正在處理，請稍後繼續。' );
        }
        try {
            $job = get_option( self::JOB, array() );
            if ( $job && $job['expires'] < $now ) { self::cleanup( $job['token'] ); $job = array(); }
            if ( $operation === 'start' ) {
                if ( $job && $job['phase'] !== 'done' ) {
                    if ( $job['owner'] !== get_current_user_id() ) {
                        throw new RuntimeException( '其他管理員正在掃描，請待掃描完成。' );
                    }
                    return self::status( $job );
                }
                if ( $job ) { self::cleanup( $job['token'] ); }
                $types = array_values( array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) ) );
                $query = new WP_Query( array( 'post_type' => $types ?: array( 'post', 'page' ), 'post_status' => 'publish',
                    'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC',
                    'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false ) );
                $job = array( 'token' => wp_generate_uuid4(), 'owner' => get_current_user_id(), 'expires' => $now + DAY_IN_SECONDS,
                    'ids' => array_map( 'intval', $query->posts ), 'phase' => 'render', 'cursor' => 0, 'revision' => 0,
                    'store' => array(), 'visible' => 0, 'dictionary' => 0 );
                self::save( $job );
                wp_schedule_single_event( $job['expires'], 'wu_ait_scan_cleanup', array( $job['token'] ) );
                return self::status( $job );
            }
            if ( ! $job || ! hash_equals( $job['token'], $token ) || $job['owner'] !== get_current_user_id() ) {
                throw new RuntimeException( '掃描工作已過期或無權存取，請重新開始；原清單未變更。' );
            }
            if ( $operation === 'cancel' ) { self::cleanup( $token ); return array( 'cancelled' => true ); }
            if ( $operation === 'status' || $job['phase'] === 'done' || $revision !== $job['revision'] ) {
                return self::status( $job ); // Replayed requests never advance the same page twice.
            }
            if ( $operation !== 'step' ) { throw new RuntimeException( '無效的掃描操作。' ); }
            $total = count( $job['ids'] );
            $matched_key = null;
            if ( $job['cursor'] < $total ) {
                $id = $job['ids'][ $job['cursor'] ];
                $key = self::page_key( $token, $job['cursor'] );
                if ( $job['phase'] === 'render' ) {
                    $visible = WU_AIT_TranslatePress_Bridge::get_translatable_segments_for_post( $id );
                    $page = array( 'visible' => $visible, 'html' => WU_AIT_TranslatePress_Bridge::scan_html( $id ) );
                    self::write( $key, $page );
                    $job['visible'] += count( $visible );
                } else {
                    $page = get_option( $key, null );
                    if ( ! is_array( $page ) ) { throw new RuntimeException( '掃描暫存不完整，請重新掃描；原清單未變更。' ); }
                    WU_AIT_TranslatePress_Bridge::seed_scan_html( $id, $page['html'] );
                    $dictionary = WU_AIT_TranslatePress_Bridge::get_dictionary_originals_for_post( $id );
                    $job['dictionary'] += count( $dictionary );
                    $segments = WU_AIT_TranslatePress_Bridge::filter_clean_candidates( array_merge( $page['visible'], $dictionary ) );
                    foreach ( $segments as $text ) {
                        $hash = md5( $text );
                        if ( ! isset( $job['store'][ $hash ] ) ) {
                            $job['store'][ $hash ] = array( 'original' => $text, 'post_id' => $id,
                                'first_seen' => current_time( 'mysql' ), 'last_seen' => current_time( 'mysql' ) );
                        }
                    }
                    $job['store'] = array_slice( $job['store'], -WU_AIT_Discovery_Service::MAX_ENTRIES, null, true );
                    $matched_key = $key;
                }
                $job['cursor']++;
            }
            if ( $job['cursor'] >= $total ) {
                if ( $job['phase'] === 'render' ) { $job['phase'] = 'match'; $job['cursor'] = 0; }
                else {
                    // One verified option write publishes the complete list; no partial list is visible.
                    self::assert_lease( $lease );
                    self::write( WU_AIT_Discovery_Service::OPTION_KEY, $job['store'] );
                    $job['count'] = count( $job['store'] );
                    $job['store'] = array();
                    $job['phase'] = 'done';
                }
            }
            $job['revision']++;
            self::assert_lease( $lease );
            self::save( $job );
            // Delete one page only after its checkpoint is durable. Completion has no full-site cleanup loop.
            if ( $matched_key !== null ) { delete_option( $matched_key ); }
            if ( $job['phase'] === 'done' ) {
                wu_ait_add_log( sprintf( '分批全站掃描完成：%d 篇公開內容，已探索字串 %d 筆。', $total, $job['count'] ) );
            }
            return self::status( $job );
        } finally {
            if ( get_option( self::LOCK, null ) === $lease ) { delete_option( self::LOCK ); }
        }
    }

    private static function assert_lease( array $lease ): void {
        if ( get_option( self::LOCK, null ) !== $lease ) {
            throw new RuntimeException( '掃描鎖定已過期，請稍後續掃；原清單未變更。' );
        }
    }

    private static function write( string $key, array $value ): void {
        if ( ! update_option( $key, $value, false ) && get_option( $key, null ) !== $value ) {
            throw new RuntimeException( '掃描資料儲存失敗，請稍後重試。' );
        }
    }
    private static function save( array $job ): void { self::write( self::JOB, $job ); }
    private static function page_key( string $token, int $index ): string { return 'wu_ait_scan_page_' . $token . '_' . $index; }
    private static function status( array $job ): array {
        $total = count( $job['ids'] );
        $done = $job['phase'] === 'done';
        $processed = $done ? $total * 2 : ( $job['phase'] === 'match' ? $total : 0 ) + $job['cursor'];
        return array( 'token' => $job['token'], 'revision' => $job['revision'], 'done' => $done,
            'processed' => $processed, 'total' => $total * 2, 'count' => $done ? $job['count'] : count( $job['store'] ),
            'message' => $done ? sprintf( '掃描完成！%d 篇公開內容，已探索字串：%d 筆。', $total, $job['count'] ) :
                sprintf( '%s：%d / %d 篇；完成前保留舊清單。', $job['phase'] === 'render' ? '讀取頁面' : '比對翻譯字典', $job['cursor'], $total ) );
    }
    public static function cleanup( string $token ): void {
        $job = get_option( self::JOB, array() );
        if ( ! $job || ! hash_equals( $job['token'], $token ) ) { return; }
        foreach ( $job['ids'] as $index => $id ) { delete_option( self::page_key( $token, $index ) ); }
        delete_option( self::JOB );
        wp_clear_scheduled_hook( 'wu_ait_scan_cleanup', array( $token ) );
    }
}
add_action( 'wu_ait_scan_cleanup', function ( $token ) {
    if ( get_option( WU_AIT_Batched_Scan::LOCK, 0 ) ) {
        wp_schedule_single_event( time() + 300, 'wu_ait_scan_cleanup', array( $token ) );
        return;
    }
    WU_AIT_Batched_Scan::cleanup( $token );
} );
add_action( 'admin_enqueue_scripts', function () {
    if ( ! current_user_can( 'manage_options' ) || ( $_GET['page'] ?? '' ) !== 'wu-ai-translate' ) { return; }
    wp_enqueue_script( 'wu-ait-batched-scan', plugin_dir_url( dirname( __DIR__, 2 ) . '/wu-toolbox-modular.php' ) . 'assets/js/ai-translate-scan.js', array(), WUTM_VERSION, true );
    wp_localize_script( 'wu-ait-batched-scan', 'wuAitScanConfig', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'wu_ait_scan_sitewide' ) ) );
} );
