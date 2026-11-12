<?php
/** Optional Google OAuth/read-only Search Analytics. No credentials required by SEO. */
defined( 'ABSPATH' ) || exit;

trait WUTM_SEO_Search_Console {
    private static function search_console_init() {
        add_action( 'admin_post_wu_seo_google_connect', [ __CLASS__, 'google_connect' ] );
        add_action( 'admin_post_wu_seo_google_callback', [ __CLASS__, 'google_callback' ] );
    }

    private static function google_callback_url() { return admin_url( 'admin-post.php?action=wu_seo_google_callback' ); }

    private static function google_secret( $value, $decrypt = false ) {
        if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) { return ''; }
        $key = hash( 'sha256', wp_salt( 'auth' ) . get_current_blog_id() . home_url( '/' ), true );
        if ( $decrypt ) {
            $raw = base64_decode( $value, true );
            return $raw && strlen( $raw ) > 28 ? ( openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) ) ?: '' ) : '';
        }
        $iv = random_bytes( 12 ); $tag = '';
        $encrypted = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
        return false === $encrypted ? '' : base64_encode( $iv . $tag . $encrypted );
    }

    public static function google_connect() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( '沒有權限。' ); }
        check_admin_referer( 'wu_seo_google_connect' );
        $config = get_option( 'wu_seo_google', [] );
        if ( empty( $config['client_id'] ) || ! self::google_secret( $config['secret'] ?? '', true ) ) { wp_die( '請先設定 Google OAuth 用戶端。' ); }
        $state = wp_generate_password( 40, false, false );
        set_transient( 'wu_seo_google_state_' . get_current_user_id(), hash( 'sha256', $state ), 600 );
        $url = add_query_arg( [ 'client_id' => $config['client_id'], 'redirect_uri' => self::google_callback_url(), 'response_type' => 'code', 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state ], 'https://accounts.google.com/o/oauth2/v2/auth' );
        wp_redirect( $url ); exit;
    }

    public static function google_callback() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( '沒有權限。' ); }
        $key = 'wu_seo_google_state_' . get_current_user_id(); $expected = get_transient( $key ); delete_transient( $key );
        $state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
        if ( ! $expected || ! $state || ! hash_equals( $expected, hash( 'sha256', $state ) ) ) { wp_die( '授權驗證失敗或已逾時，請重新連線。' ); }
        $config = get_option( 'wu_seo_google', [] );
        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [ 'timeout' => 15, 'redirection' => 0, 'body' => [ 'client_id' => $config['client_id'], 'client_secret' => self::google_secret( $config['secret'], true ), 'code' => sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) ), 'grant_type' => 'authorization_code', 'redirect_uri' => self::google_callback_url() ] ] );
        $data = is_wp_error( $response ) ? [] : json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['refresh_token'] ) || 200 !== wp_remote_retrieve_response_code( is_wp_error( $response ) ? [] : $response ) ) { wp_die( 'Google 授權未完成，請確認 OAuth 設定與權限後重試。' ); }
        $config['refresh'] = self::google_secret( $data['refresh_token'] ); update_option( 'wu_seo_google', $config, false );
        wp_safe_redirect( admin_url( 'admin.php?page=wu-seo-tools' ) ); exit;
    }

    private static function google_analytics( $config, $days_ago = 3 ) {
        $cache_key = 'wu_seo_gsc_' . md5( $config['property'] . $config['refresh'] . $days_ago ); $cache = get_transient( $cache_key );
        if ( false !== $cache ) { return $cache; }
        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [ 'timeout' => 15, 'redirection' => 0, 'body' => [ 'client_id' => $config['client_id'], 'client_secret' => self::google_secret( $config['secret'], true ), 'refresh_token' => self::google_secret( $config['refresh'], true ), 'grant_type' => 'refresh_token' ] ] );
        if ( is_wp_error( $response ) ) { return new WP_Error( 'google', '無法連接 Google，請稍後重試。' ); }
        $token = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $token['access_token'] ) ) { return new WP_Error( 'google', '授權已失效或未完成，請重新連線。' ); }
        $output = [];
        foreach ( [ 'query', 'page' ] as $dimension ) {
            $body = [ 'startDate' => gmdate( 'Y-m-d', time() - ( $days_ago + 27 ) * 86400 ), 'endDate' => gmdate( 'Y-m-d', time() - $days_ago * 86400 ), 'dimensions' => [ $dimension ], 'rowLimit' => 25 ];
            $response = wp_remote_post( 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $config['property'] ) . '/searchAnalytics/query', [ 'timeout' => 15, 'redirection' => 0, 'headers' => [ 'Authorization' => 'Bearer ' . $token['access_token'], 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( $body ) ] );
            if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return new WP_Error( 'google', '無法讀取資源。請確認 Search Console 資源網址與目前授權帳號權限。' ); }
            $result = json_decode( wp_remote_retrieve_body( $response ), true );
            $output[ $dimension ] = [ 'dates' => [ $body['startDate'], $body['endDate'] ], 'rows' => $result['rows'] ?? [] ];
        }
        set_transient( $cache_key, $output, 900 ); return $output;
    }

    private static function search_console_page() {
        echo '<h2>Google Search Console（選用）</h2><p>透過 Google 官方唯讀 OAuth 存取您有權限的資源；未連線不影響 SEO。搜尋成效有資料延遲，不能代表即時索引或真正 Googlebot 診斷。</p>';
        $config = get_option( 'wu_seo_google', [] );
        if ( isset( $_POST['google_disconnect'] ) ) { unset( $config['refresh'] ); update_option( 'wu_seo_google', $config, false ); echo '<p>已移除本機授權。您也可至 Google 帳戶撤銷存取權。</p>'; }
        if ( isset( $_POST['google_settings'] ) ) {
            $client = sanitize_text_field( wp_unslash( $_POST['google_client'] ?? '' ) );
            if ( ( $config['client_id'] ?? '' ) !== $client ) { unset( $config['refresh'] ); }
            $config['client_id'] = $client;
            $secret = sanitize_text_field( wp_unslash( $_POST['google_secret'] ?? '' ) );
            if ( $secret ) { $config['secret'] = self::google_secret( $secret ); unset( $config['refresh'] ); }
            $property = sanitize_text_field( wp_unslash( $_POST['google_property'] ?? '' ) );
            if ( preg_match( '~^(https?://[^\s]+|sc-domain:[a-zA-Z0-9.-]+)$~', $property ) ) { $config['property'] = $property; }
            update_option( 'wu_seo_google', $config, false );
        }
        if ( ! function_exists( 'openssl_encrypt' ) ) { echo '<p>主機未啟用 OpenSSL；無法安全儲存授權，整合尚未啟用。</p>'; return; }
        echo '<details><summary>Google OAuth 設定</summary><p>請在 Google Cloud 啟用 Search Console API，建立 Web OAuth 用戶端並設定以下重新導向網址：<code>' . esc_html( self::google_callback_url() ) . '</code>。授權資料僅儲存在目前子網站，變更網站網址後請重新授權。</p><form method="post">'; wp_nonce_field( 'wu_seo_tools' );
        echo '<p><label>用戶端 ID <input name="google_client" value="' . esc_attr( $config['client_id'] ?? '' ) . '" class="large-text"></label></p><p><label>用戶端密鑰（留空保留）<input type="password" name="google_secret" autocomplete="new-password"></label></p><p><label>Search Console 資源（完整 URL 或 sc-domain:網域）<input class="large-text" name="google_property" value="' . esc_attr( $config['property'] ?? '' ) . '"></label></p><button class="button" name="google_settings" value="1">儲存</button></form></details>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'wu_seo_google_connect' ); echo '<input name="action" type="hidden" value="wu_seo_google_connect"><p><button class="button">使用 Google 帳號授權</button></p></form>';
        if ( empty( $config['refresh'] ) ) { echo '<p>尚未授權，外部搜尋數據尚未驗證。</p>'; return; }
        echo '<form method="post">'; wp_nonce_field( 'wu_seo_tools' ); echo '<button class="button" name="google_read" value="1">讀取近 28 天與前期成效（各前 25 筆）</button> <button class="button" name="google_disconnect" value="1">中斷本機授權</button></form>';
        if ( isset( $_POST['google_read'] ) && ! empty( $config['property'] ) ) {
            foreach ( [ 3 => '最近 28 天（截至 3 天前）', 31 => '前 28 天' ] as $ago => $label ) {
                $data = self::google_analytics( $config, $ago );
                echo '<h3>' . esc_html( $label ) . '</h3>';
                if ( is_wp_error( $data ) ) { echo '<p>' . esc_html( $data->get_error_message() ) . '</p>'; continue; }
                foreach ( $data as $dimension => $group ) {
                    echo '<h4>' . esc_html( 'query' === $dimension ? '熱門查詢' : '熱門頁面' ) . '</h4><table class="widefat"><thead><tr><th>項目</th><th>點擊</th><th>曝光</th><th>CTR</th><th>平均排名</th></tr></thead><tbody>';
                    foreach ( $group['rows'] as $row ) { echo '<tr><td>' . esc_html( implode( ' / ', $row['keys'] ) ) . '</td><td>' . esc_html( $row['clicks'] ) . '</td><td>' . esc_html( $row['impressions'] ) . '</td><td>' . esc_html( round( $row['ctr'] * 100, 2 ) . '%' ) . '</td><td>' . esc_html( round( $row['position'], 2 ) ) . '</td></tr>'; }
                    echo '</tbody></table><p>僅回傳的前 25 筆，非全站總計。結果快取 15 分鐘。</p>';
                }
            }
        }
    }
}
