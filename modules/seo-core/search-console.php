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

    private static function google_scope() {
        $config = (array) get_option( 'wu_seo_google', [] );
        return empty( $config['refresh'] ) || empty( $config['property'] ) ? '' : hash( 'sha256', $config['property'] . '|' . $config['refresh'] );
    }

    private static function google_problem( $response, $data, $oauth = false ) {
        $http = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        $error = is_array( $data['error'] ?? null ) ? $data['error'] : [];
        $reason = $error['errors'][0]['reason'] ?? $error['status'] ?? '';
        $reason = is_string( $reason ) ? sanitize_key( $reason ) : '';
        if ( 429 === $http || in_array( $reason, [ 'ratelimitexceeded','userratelimitexceeded','quotaexceeded','dailylimitexceeded','resource_exhausted' ], true ) ) {
            return new WP_Error( 'quota', 'Google 查詢配額或頻率達上限。', [ 'http'=>$http, 'reason'=>$reason, 'retry'=>true ] );
        }
        if ( ( $oauth || 401 === $http ) && $http && $http < 500 && 408 !== $http ) {
            return new WP_Error( 'auth', 'Google 授權已失效或設定不完整，請重新連線。', [ 'http'=>$http, 'reason'=>$reason ] );
        }
        if ( 403 === $http ) return new WP_Error( 'permission', '此 Google 帳號無法讀取該資源，請確認屬性與權限。', [ 'http'=>$http, 'reason'=>$reason ] );
        return new WP_Error( 'google', 'Google 查詢失敗，請稍後重試。', [ 'http'=>$http, 'reason'=>$reason, 'retry'=>!$http || 408 === $http || $http >= 500 ] );
    }

    private static function google_call( $path, $body = null, $inspection = false ) {
        $config = (array) get_option( 'wu_seo_google', [] );
        $scope = self::google_scope();
        if ( ! $scope ) return new WP_Error( 'auth', '尚未連結 Search Console。' );
        $key = 'wu_seo_token_' . substr( $scope, 0, 40 );
        $cached = get_transient( $key );
        $token = is_string( $cached ) ? self::google_secret( $cached, true ) : '';
        if ( ! $token ) {
            $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [ 'timeout'=>6, 'redirection'=>0, 'limit_response_size'=>32768, 'body'=>[
                'client_id'=>$config['client_id'] ?? '', 'client_secret'=>self::google_secret( $config['secret'] ?? '', true ),
                'refresh_token'=>self::google_secret( $config['refresh'], true ), 'grant_type'=>'refresh_token',
            ] ] );
            $data = is_wp_error( $response ) ? [] : json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $data['access_token'] ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) return self::google_problem( $response, $data, true );
            $token = (string) $data['access_token'];
            set_transient( $key, self::google_secret( $token ), max( 1, min( 3300, (int) ( $data['expires_in'] ?? 3600 ) - 60 ) ) );
        }
        $url = $inspection ? 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect' :
            'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $config['property'] ) . $path;
        $args = [ 'timeout'=>6,'redirection'=>0,'limit_response_size'=>1048576,'headers'=>[ 'Authorization'=>'Bearer ' . $token ] ];
        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
            $args['headers']['Content-Type'] = 'application/json';
        }
        $response = null === $body ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args );
        $status = (int) wp_remote_retrieve_response_code( $response );
        $data = is_wp_error( $response ) ? [] : json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 401 === $status ) delete_transient( $key );
        if ( 200 !== $status || ! is_array( $data ) ) return self::google_problem( $response, $data );
        return $data;
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
        echo '<form method="post">'; wp_nonce_field( 'wu_seo_tools' );
        echo '<button class="button" name="google_disconnect" value="1">中斷本機授權</button></form>';
    }
}
