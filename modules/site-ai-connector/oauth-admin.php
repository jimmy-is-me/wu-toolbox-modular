<?php
defined( 'ABSPATH' ) || exit;

function sac_oauth_admin_action() {
    if ( ! isset( $_POST['sac_action'] ) ) return [];
    check_admin_referer( 'sac_admin_action' );
    $action = sanitize_key( (string) $_POST['sac_action'] );
    $options = wp_unslash( $_POST );
    if ( $action === 'save_types' ) {
        $policy = [];
        foreach ( sac_content_types() as $slug => $type ) {
            $policy[ $slug ] = array_values( array_intersect( [ 'read', 'create', 'update', 'publish' ], (array) ( $options['scope'][ $slug ] ?? [] ) ) );
        }
        update_option( SAC_OPTION_CONTENT_TYPES, $policy, false );
        return [ '已儲存本站內容權限。', false ];
    }
    if ( $action === 'save_security' ) {
        $scopes = array_values( array_intersect( array_keys( sac_oauth_scope_labels() ), (array) ( $options['oauth_scopes'] ?? [] ) ) );
        if ( isset( $options['enabled'] ) && ! in_array( 'content:read', $scopes, true ) ) return [ '啟用連線時請至少開放內容查詢。', true ];
        update_option( 'sac_gsc_read', ! empty( $options['gsc_read'] ), false );
        update_option( 'sac_oauth_enabled', ! empty( $options['enabled'] ), false );
        update_option( 'sac_oauth_dcr', ! empty( $options['dcr'] ), false );
        update_option( 'sac_oauth_scopes', $scopes, false );
        update_option( SAC_OPTION_ALLOW_PUBLISH, ! empty( $options['allow_publish'] ), false );
        update_option( 'sac_oauth_grant_days', max( 1, min( 90, absint( $options['grant_days'] ?? 30 ) ) ), false );
        update_option( 'sac_oauth_access_seconds', max( 60, min( 3600, absint( $options['access_seconds'] ?? 900 ) ) ), false );
        $redirects = preg_split( '/\R/', trim( (string) ( $options['redirects'] ?? '' ) ), 25 );
        update_option( 'sac_oauth_redirects', array_values( array_filter( array_map( 'trim', $redirects ?: [] ) ) ), false );
        $addresses = preg_split( '/\s+/', trim( (string) ( $options['ips'] ?? '' ) ), 100 );
        $ips = array_values( array_filter( $addresses ?: [], static function ( $ip ) { return filter_var( $ip, FILTER_VALIDATE_IP ); } ) );
        update_option( SAC_OPTION_IP_ALLOWLIST, [ 'enabled' => ! empty( $options['ip_allowlist'] ) && (bool) $ips, 'ips' => $ips ], false );
        return [ '已儲存安全設定。', false ];
    }
    if ( $action === 'test_metadata' ) return sac_oauth_admin_test();
    if ( $action === 'test_login' ) {
        if ( ! sac_oauth_login_destination( sac_oauth_url( 'authorize' ) )['url'] ) {
            return [ 'WordPress 登入網址無法安全跳轉。請檢查 WordPress 網址與登入外掛設定。', true ];
        }
        return sac_oauth_admin_test( true );
    }
    if ( ! sac_oauth_install() ) return [ 'OAuth 資料庫尚未就緒。', true ];
    if ( $action === 'extend_client_scopes' ) {
        $result = sac_oauth_add_client_scopes( absint( $options['row_id'] ?? 0 ), $options['client_scopes'] ?? [] );
        return is_wp_error( $result ) ? [ $result->get_error_message(), true ]
            : [ '已更新客戶端可請求的權限。使用者須在 WordPress 明確同意新授權。', false ];
    }
    if ( $action === 'register_client' ) {
        $result = sac_oauth_client_registration( [
            'client_name' => $options['client_name'] ?? '',
            'redirect_uris' => [ trim( (string) ( $options['redirect_uri'] ?? '' ) ) ],
            'token_endpoint_auth_method' => 'none',
        ], false );
        return is_wp_error( $result ) ? [ $result->get_error_message(), true ] : [ '已建立 PKCE 客戶端。Client ID ' . $result['client_id'], false ];
    }
    if ( in_array( $action, [ 'revoke_grant', 'revoke_client', 'revoke_all' ], true ) ) {
        $id = absint( $options['row_id'] ?? 0 );
        if ( $action !== 'revoke_all' && ! $id ) return [ '授權識別碼無效。', true ];
        $result = sac_oauth_revoke( $action === 'revoke_client' ? 'client' : 'grant', $id );
        return $result === false ? [ '無法更新授權，請重試。', true ] : [ '已撤銷授權。', false ];
    }
    return [];
}

function sac_oauth_admin_test( $check_login = false ) {
    if ( ! sac_oauth_transport() ) return [ '請先完成 WordPress HTTPS 設定。', true ];
    $docs = [ [ sac_oauth_metadata_url(), 'issuer', sac_oauth_issuer() ], [ sac_oauth_metadata_url( true ), 'resource', sac_oauth_resource() ] ];
    foreach ( $docs as [ $url, $key, $value ] ) {
        $response = wp_safe_remote_get( $url, [ 'timeout' => 5, 'redirection' => 0, 'limit_response_size' => 16000 ] );
        $status = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $status !== 200 || ( $body[ $key ] ?? '' ) !== $value
            || ( $check_login && ( $body['scopes_supported'] ?? [] ) !== sac_oauth_scopes() ) ) {
            return [ '探索測試失敗 ' . $url . ' · ' . ( is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $status . ' 或 Metadata 不符' ), true ];
        }
    }
    $mcp = wp_safe_remote_post( sac_oauth_resource(), [ 'timeout' => 5, 'redirection' => 0, 'headers' => [ 'Content-Type' => 'application/json' ],
        'body' => '{"jsonrpc":"2.0","id":1,"method":"ping"}' ] );
    if ( wp_remote_retrieve_response_code( $mcp ) !== 401 || strpos( (string) wp_remote_retrieve_header( $mcp, 'www-authenticate' ), 'resource_metadata=' ) === false ) {
        return [ 'MCP 挑戰測試失敗，請確認 REST、Cloudflare 和 Authorization 轉送設定。', true ];
    }
    $token = wp_safe_remote_post( sac_oauth_url( 'token' ), [ 'timeout' => 5, 'redirection' => 0, 'body' => [ 'grant_type' => 'authorization_code', 'resource' => sac_oauth_resource() ] ] );
    $body = json_decode( wp_remote_retrieve_body( $token ), true );
    if ( wp_remote_retrieve_response_code( $token ) !== 401 || ( $body['error'] ?? '' ) !== 'invalid_client' ) return [ 'Token 端點測試失敗。', true ];
    if ( $check_login ) {
        $authorize = wp_safe_remote_get( sac_oauth_url( 'authorize' ), [ 'timeout' => 5, 'redirection' => 0, 'limit_response_size' => 12000 ] );
        $reached = wp_remote_retrieve_response_code( $authorize ) === 400 && wp_remote_retrieve_header( $authorize, 'x-sac-request-id' );
        return $reached
            ? [ '登入網址、探索文件、OAuth 授權與權杖端點均通過檢查。完整登入返回以最近的實際授權紀錄為準。', false ]
            : [ '匿名 OAuth 授權端點尚未通過檢查。請查看登入外掛、CDN 或 WAF 的回應。', true ];
    }
    update_option( 'sac_oauth_last_probe', time(), false );
    return [ '探索文件、MCP 授權挑戰與 Token 端點已回應。此檢查尚未執行使用者授權。', false ];
}

function sac_oauth_admin_rows( $kind ) {
    global $wpdb;
    if ( get_option( 'sac_oauth_schema' ) !== '1' ) return [];
    $table = sac_oauth_table();
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE kind = %s ORDER BY id DESC LIMIT 250", $kind ), ARRAY_A ) ?: [];
    foreach ( $rows as &$row ) $row['payload'] = json_decode( $row['payload'], true ) ?: [];
    unset( $row );
    return $rows;
}

function sac_oauth_admin_overview() {
    $grants = sac_oauth_admin_rows( 'grant' );
    $clients = sac_oauth_admin_rows( 'client' );
    $read = $write = null;
    foreach ( sac_oauth_operation_log() as $record ) {
        if ( ! $record['success'] ) continue;
        if ( in_array( $record['tool'], [ 'verify_connection', 'list_content_types', 'get_content', 'list_content' ], true ) && ! $read ) $read = $record['time'];
        if ( str_starts_with( $record['tool'], 'create_' ) || str_starts_with( $record['tool'], 'update_' ) || $record['tool'] === 'upload_media' ) {
            $write ??= $record['time'];
        }
    }
    $probe = (int) get_option( 'sac_oauth_last_probe' );
    $login = sac_oauth_login_destination( sac_oauth_url( 'authorize' ) );
    $last_return = null;
    foreach ( sac_mcp_diagnostic_rows() as $record ) {
        if ( ( $record['stage'] ?? '' ) !== 'oauth_login_return' ) continue;
        $last_return = $record;
        break;
    }
    $login_labels = [ 'wordpress' => 'WordPress 預設機制', 'wordpress_filtered' => 'WordPress login_url 過濾器', 'toolbox' => 'WU Toolbox 隱藏登入' ];
    ?>
    <section class="sac-panel">
        <h2>本站服務狀態</h2>
        <?php sac_admin_copy( 'sac-mcp-url', 'MCP Server URL', sac_oauth_resource(), 1 ); ?>
        <dl>
            <dt>MCP 與 OAuth</dt><dd><?php echo get_option( 'sac_oauth_enabled', true ) ? '已啟用' : '已暫停'; ?></dd>
            <dt>HTTPS</dt><dd><?php echo sac_oauth_transport() ? '檢查通過' : '需要修正 HTTPS 設定'; ?></dd>
            <dt>授權與註冊</dt><dd>PKCE S256 · <?php echo get_option( 'sac_oauth_dcr', true ) ? '動態註冊與預先註冊' : '預先註冊'; ?></dd>
            <dt>公開端點測試</dt><dd><?php echo esc_html( $probe ? wp_date( 'Y-m-d H:i:s', $probe ) : '尚未測試' ); ?></dd>
        <dt>最近 OAuth 權杖交換</dt><dd><?php $exchange = (int) get_option( 'sac_oauth_last_exchange' ); echo esc_html( $exchange ? wp_date( 'Y-m-d H:i:s', $exchange ) : '尚未交換' ); ?></dd>
        <dt>最近 MCP 協定回應</dt><dd><?php $mcp = (int) get_option( 'sac_oauth_last_mcp' ); echo esc_html( $mcp ? wp_date( 'Y-m-d H:i:s', $mcp ) : '尚未驗證' ); ?></dd>
            <dt>已啟用客戶端／有效授權</dt><dd><?php echo esc_html( count( array_filter( $clients, 'sac_oauth_live' ) ) . '／' . count( array_filter( $grants, 'sac_oauth_valid_grant' ) ) ); ?></dd>
            <dt>工具查詢／寫入</dt><dd><?php echo esc_html( ( $read ?: '尚無查詢' ) . '／' . ( $write ?: '尚無寫入' ) ); ?></dd>
        </dl>
        <form method="post"><?php wp_nonce_field( 'sac_admin_action' ); ?><button class="button button-primary" name="sac_action" value="test_metadata">測試本站端點</button></form>
    </section>
    <section class="sac-panel">
        <h2>登入與 OAuth 相容性檢查</h2>
        <dl>
            <dt>WordPress 登入途徑</dt><dd><?php echo esc_html( $login_labels[ $login['method'] ] ); ?></dd>
            <dt>登入跳轉目標</dt><dd><?php echo $login['url'] ? '通過安全檢查' : '需要檢查登入外掛或 WordPress 網址'; ?></dd>
            <dt>login_url 過濾器</dt><dd><?php echo has_filter( 'login_url' ) ? '有外部過濾器' : '未偵測到'; ?></dd>
            <dt>最近登入返回</dt><dd><?php echo esc_html( $last_return ? ( ( $last_return['return_verified'] ? '已驗證' : '失敗' ) . ' · ' . $last_return['time'] ) : '最近三天尚無紀錄' ); ?></dd>
            <dt>Metadata 權限</dt><dd><code><?php echo esc_html( implode( ' ', sac_oauth_scopes() ) ?: '—' ); ?></code></dd>
            <dt>個別應用程式權限</dt><dd><a href="<?php echo esc_url( sac_admin_url( 'apps' ) ); ?>">比較本站、客戶端與使用者授權</a></dd>
        </dl>
        <form method="post"><?php wp_nonce_field( 'sac_admin_action' ); ?><button class="button" name="sac_action" value="test_login">執行相容性檢查</button></form>
    </section>
    <?php
}

function sac_oauth_admin_connect() {
    $fields = [
        '名稱' => get_bloginfo( 'name' ) . ' AI 連接器', '說明' => '依 WordPress 使用者權限查詢和管理本站內容。',
        'Authentication' => 'OAuth',
        'Authorization URL' => sac_oauth_url( 'authorize' ), 'Token URL' => sac_oauth_url( 'token' ),
        'Registration URL' => sac_oauth_url( 'register' ), 'Revocation URL' => sac_oauth_url( 'revoke' ),
        'Authorization Server Base URL' => sac_oauth_issuer(), 'Resource' => sac_oauth_resource(),
        'Scope' => implode( ' ', sac_oauth_scopes() ), 'PKCE' => 'S256',
        'Authorization Server Metadata' => sac_oauth_metadata_url(), 'Protected Resource Metadata' => sac_oauth_metadata_url( true ),
    ];
    ?>
    <div class="sac-connection-grid">
    <section class="sac-panel sac-connection-endpoints">
        <h2>MCP 連線資料</h2>
        <?php sac_admin_copy( 'sac-mcp-connect', 'MCP Server URL', sac_oauth_resource(), 1 ); ?>
        <details class="sac-inspector"><summary>手動設定與探索網址</summary>
            <div class="sac-inspector-fields">
            <?php foreach ( $fields as $label => $value ) sac_admin_copy( 'sac-oauth-' . substr( md5( $label ), 0, 10 ), $label, $value, 1 ); ?>
            </div>
        </details>
    </section>
    <section class="sac-panel sac-connection-guide">
        <h2>ChatGPT 自訂 MCP</h2>
        <ol><li>在 ChatGPT 的 Plugins 開啟新增自訂 MCP 伺服器。</li>
            <li>貼上 MCP Server URL，身分驗證選擇 OAuth，再建立私人連線。</li>
            <li>依畫面提示登入本站 WordPress，核對應用程式、回呼網址及權限後，同意授權。</li>
            <li>在對話選用連線，呼叫 verify_connection。寫入可用性依帳號方案、工作區政策與本站權限。</li></ol>
    </section>
    </div>
    <?php
}

function sac_oauth_admin_apps() {
    $clients = array_column( sac_oauth_admin_rows( 'client' ), null, 'id' );
    ?>
    <section class="sac-panel"><h2>使用者授權</h2>
    <table class="widefat striped sac-table"><thead><tr><th>應用程式</th><th>帳號</th><th>權限</th><th>授權／最近使用</th><th>狀態</th><th>操作</th></tr></thead><tbody>
    <?php foreach ( sac_oauth_admin_rows( 'grant' ) as $row ) :
        $client = $clients[ $row['client'] ] ?? null;
        $user = get_userdata( $row['owner'] );
        $live = sac_oauth_live( $client ) && sac_oauth_valid_grant( $row ); ?>
        <tr><td><?php echo esc_html( $client['payload']['client_name'] ?? '—' ); ?></td>
        <td><?php echo esc_html( $user ? $user->user_login : '—' ); ?></td>
        <td><details><summary>比較權限</summary>
        <?php $report = sac_oauth_scope_report( $client, $row['payload']['scopes'] ?? [], null, sac_oauth_scopes() );
        foreach ( [ 'site_allowed_scopes' => '本站開放', 'client_registered_scopes' => '客戶端註冊', 'client_allowed_scopes' => '客戶端可請求',
            'grant_scopes' => '使用者已同意', 'missing_scopes' => '相對本站開放的缺少權限', 'client_blocked_scopes' => '先更新客戶端設定' ] as $key => $label ) : ?>
            <p><strong><?php echo esc_html( $label ); ?></strong><br><code><?php echo esc_html( implode( ' ', $report[ $key ] ) ?: '—' ); ?></code></p>
        <?php endforeach; ?>
        <?php if ( $report['missing_scopes'] ) : ?><p>只需為此次操作需要的權限追加授權。</p><?php endif; ?>
        </details></td>
        <td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) ( $row['payload']['created'] ?? 0 ) ) . '／' . wp_date( 'Y-m-d H:i', (int) $row['touched'] ) ); ?></td>
        <td><?php echo esc_html( $live ? '有效至 ' . wp_date( 'Y-m-d H:i', (int) $row['expires'] ) : '已失效' ); ?></td>
        <td><?php if ( $live ) sac_oauth_admin_revoke_button( 'revoke_grant', (int) $row['id'], '撤銷授權' ); ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <p><?php sac_oauth_admin_revoke_button( 'revoke_all', 0, '撤銷全部使用者授權' ); ?></p></section>
    <section class="sac-panel"><h2>OAuth 客戶端</h2>
    <?php foreach ( $clients as $client ) : ?>
        <details><summary><?php echo esc_html( $client['payload']['client_name'] . ( sac_oauth_live( $client ) ? '' : ' · 已撤銷' ) ); ?></summary>
        <p><code><?php echo esc_html( $client['payload']['client_id'] ); ?></code></p>
        <p><?php echo esc_html( implode( '、', $client['payload']['redirect_uris'] ) ); ?></p>
        <?php $extra = array_diff( sac_oauth_scopes(), sac_oauth_client_ceiling( $client ) ); ?>
        <?php if ( $extra && sac_oauth_live( $client ) ) : ?>
            <form method="post"><?php wp_nonce_field( 'sac_admin_action' ); ?>
                <input type="hidden" name="row_id" value="<?php echo (int) $client['id']; ?>">
                <fieldset><legend>允許此客戶端請求額外權限</legend>
                <?php foreach ( $extra as $scope ) : ?>
                    <label><input type="checkbox" name="client_scopes[]" value="<?php echo esc_attr( $scope ); ?>"> <?php echo esc_html( $scope ); ?></label>
                <?php endforeach; ?>
                </fieldset>
                <button class="button" name="sac_action" value="extend_client_scopes">儲存客戶端權限</button>
            </form>
        <?php endif; ?>
        <?php if ( sac_oauth_live( $client ) ) sac_oauth_admin_revoke_button( 'revoke_client', (int) $client['id'], '撤銷此客戶端及其授權' ); ?>
        </details>
    <?php endforeach; ?>
    <h3>預先註冊 PKCE 客戶端</h3>
    <form method="post"><?php wp_nonce_field( 'sac_admin_action' ); ?>
        <p><label>應用程式名稱 <input  name="client_name" required maxlength="100"></label></p>
        <p><label>完整回呼網址 <input name="redirect_uri" type="url" required maxlength="1000"></label></p>
        <button class="button" name="sac_action" value="register_client">新增客戶端</button>
    </form></section>
    <?php
}

function sac_oauth_admin_revoke_button( $action, $id, $label ) {
    ?><form method="post" onsubmit="return confirm('確定撤銷此授權範圍？')">
        <?php wp_nonce_field( 'sac_admin_action' ); ?>
        <input type="hidden" name="row_id" value="<?php echo (int) $id; ?>">
        <button class="button" name="sac_action" value="<?php echo esc_attr( $action ); ?>"><?php echo esc_html( $label ); ?></button>
    </form><?php
}

function sac_oauth_admin_security() {
    ?>
    <section class="sac-panel"><h2>進階安全設定</h2><form method="post">
        <?php wp_nonce_field( 'sac_admin_action' ); ?>
        <p><label><input type="checkbox" name="enabled" value="1" <?php checked( get_option( 'sac_oauth_enabled', true ) ); ?>> 啟用 OAuth 和 MCP 服務</label></p>
        <p><label><input type="checkbox" name="dcr" value="1" <?php checked( get_option( 'sac_oauth_dcr', true ) ); ?>> 允許動態註冊</label></p>
        <p><label>動態註冊允許的完整回呼網址（每行一筆）<br><textarea class="large-text" rows="3" name="redirects"><?php echo esc_textarea( implode( "\n", (array) get_option( 'sac_oauth_redirects', [ 'https://chatgpt.com/connector_platform_oauth_redirect' ] ) ) ); ?></textarea></label></p>
        <p><label>授權期限（天） <input type="number" name="grant_days" min="1" max="90" value="<?php echo (int) get_option( 'sac_oauth_grant_days', 30 ); ?>"></label></p>
        <p><label>Access Token 期限（秒） <input type="number" name="access_seconds" min="60" max="3600" value="<?php echo (int) get_option( 'sac_oauth_access_seconds', 900 ); ?>"></label></p>
        <p><label><input type="checkbox" name="allow_publish" value="1" <?php checked( get_option( SAC_OPTION_ALLOW_PUBLISH, false ) ); ?>> 允許原授權者確認後發佈草稿</label></p>
        <p><label><input type="checkbox" name="gsc_read" value="1" <?php checked( get_option( 'sac_gsc_read', false ) ); ?>> 允許具 SEO 查詢權限的網站管理員透過 AI 讀取 WU SEO 的 Search Console 資料</label></p>
        <fieldset><legend>本站開放的 OAuth Scope</legend>
            <?php foreach ( sac_oauth_scope_labels() as $scope => $label ) : ?>
                <p><label><input type="checkbox" name="oauth_scopes[]" value="<?php echo esc_attr( $scope ); ?>" <?php checked( in_array( $scope, sac_oauth_scopes(), true ) ); ?>> <?php echo esc_html( $label . ' · ' . $scope ); ?></label></p>
            <?php endforeach; ?>
        </fieldset>
        <?php $allowlist = (array) get_option( SAC_OPTION_IP_ALLOWLIST, [] ); ?>
        <p><label><input type="checkbox" name="ip_allowlist" value="1" <?php checked( ! empty( $allowlist['enabled'] ) ); ?>> 限制工具呼叫的來源 IP</label></p>
        <p><label>伺服器看到的來源 IP（每行一筆）<br><textarea class="large-text" name="ips" rows="3"><?php echo esc_textarea( implode( "\n", $allowlist['ips'] ?? [] ) ); ?></textarea></label></p>
        <p>動態註冊限制每個來源每小時 10 次，全站每小時 60 次。權杖交換有獨立頻率上限。</p>
        <button class="button button-primary" name="sac_action" value="save_security">儲存安全設定</button>
    </form></section>
    <?php
}


add_action( 'show_user_profile', static function ( $user ) {
    if ( get_option( 'sac_oauth_schema' ) !== '1' || $user->ID !== get_current_user_id() ) return;
    global $wpdb;
    $table = sac_oauth_table();
    $grants = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE kind = 'grant' AND owner = %d AND state = 0 AND expires > %d ORDER BY id DESC LIMIT 100",
        $user->ID, time() ), ARRAY_A );
    if ( ! $grants ) return;
    echo '<h2>AI 應用程式授權</h2><ul>';
    foreach ( $grants as $grant ) {
        $client = sac_oauth_row( 'client', $grant['client'], true );
        if ( ! sac_oauth_live( $client ) ) continue;
        $id = (int) $grant['id'];
        $url = wp_nonce_url( add_query_arg( [ 'action' => 'sac_revoke_own', 'grant' => $id ], admin_url( 'admin-post.php' ) ), 'sac_revoke_own_' . $id );
        echo '<li>' . esc_html( $client['payload']['client_name'] ) . ' <a href="' . esc_url( $url ) . '">撤銷此授權</a></li>';
    }
    echo '</ul>';
} );

add_action( 'admin_post_sac_revoke_own', static function () {
    if ( get_option( 'sac_oauth_schema' ) !== '1' ) wp_die( '找不到有效授權。', '', [ 'response' => 404 ] );
    $id = absint( $_REQUEST['grant'] ?? 0 );
    check_admin_referer( 'sac_revoke_own_' . $id );
    $grant = sac_oauth_row( 'grant', $id, true );
    if ( ! $grant || (int) $grant['owner'] !== get_current_user_id() ) wp_die( '找不到有效授權。', '', [ 'response' => 404 ] );
    if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' ) {
        if ( sac_oauth_revoke( 'grant', $id ) === false ) wp_die( '撤銷未完成。請重試。', '', [ 'response' => 500 ] );
        wp_safe_redirect( admin_url( 'profile.php' ) );
        exit;
    }
    nocache_headers();
    ob_start();
    echo '<form method="post"><p>撤銷後需重新授權，應用程式才能繼續存取本站。</p>';
    wp_nonce_field( 'sac_revoke_own_' . $id );
    echo '<button class="button button-primary">確定撤銷授權</button></form>';
    wp_die( ob_get_clean(), '撤銷 AI 授權', [ 'response' => 200 ] );
} );
