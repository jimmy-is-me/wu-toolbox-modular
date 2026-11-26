<?php
defined( 'ABSPATH' ) || exit;

function sac_admin_url( $tab = 'overview', $group = '' ) {
    $args = [ 'page' => 'site-ai-connector', 'tab' => $tab ];
    if ( $group ) $args['group'] = $group;
    return add_query_arg( $args, admin_url( 'admin.php' ) );
}

function sac_legacy_admin_destinations() {
    return [
        'wu-ai-content-tools' => [ 'tools', 'content' ],
        'wu-ai-seo-tools' => [ 'tools', 'seo' ],
        'wu-ai-media-tools' => [ 'tools', 'media' ],
        'wu-ai-commerce-tools' => [ 'tools', 'commerce' ],
        'wu-ai-site-tools' => [ 'tools', 'site' ],
        'wu-ai-operation-log' => [ 'logs' ],
    ];
}

// WordPress checks plugin-page access while building the menu, before admin_init.
add_action( 'admin_menu', static function () {
    $page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
    $destinations = sac_legacy_admin_destinations();
    if ( ! isset( $destinations[ $page ] ) || ! current_user_can( 'manage_options' ) ) return;
    wp_safe_redirect( sac_admin_url( ...$destinations[ $page ] ) );
    exit;
}, 1 );

function sac_admin_platforms() {
    return [
        'chatgpt' => [ 'ChatGPT Plugins（MCP）', '適用已提供 Plugins 與自訂 MCP 入口的帳號，工作區政策及方案會影響權限。本站金鑰須由支援自訂標頭的橋接服務傳送，或由 OAuth 橋接轉送；請在建立連線頁確認認證選項。', 'https://developers.openai.com/api/docs/guides/custom-mcp-server' ],
        'actions' => [ 'GPT Actions（OpenAPI）', '適用仍可編輯 Actions 的帳號與 GPT。匯入 OpenAPI 並選 API Key、自訂標頭 X-SAC-Key。帳號若已遷移至 Plugins，請改選 MCP 設定。', 'https://help.openai.com/en/articles/9442513-configuring-actions-in-gpts' ],
        'claude' => [ 'Claude API（MCP）', '在支援遠端 MCP 的 Claude Messages API 設定 mcp_servers 及 mcp_toolset，透過 authorization_token 傳送本站金鑰。Claude 網頁或桌面版請以實際提供的遠端連接器與認證選項為準。', 'https://platform.claude.com/docs/en/agents-and-tools/mcp-connector' ],
        'gemini' => [ 'Gemini API（Function calling）', '將函式定義交給 Gemini API。由你的應用程式依 OpenAPI 路徑送出 REST 呼叫，使用 X-SAC-Key，並將結果回傳給模型。', 'https://ai.google.dev/gemini-api/docs/function-calling' ],
        'mcp' => [ '其他支援自訂標頭的 MCP 用戶端', '使用 Streamable HTTP，透過 X-SAC-Key 或 Authorization: Bearer 傳送金鑰。', 'https://modelcontextprotocol.io/specification/2025-11-25/basic/transports' ],
    ];
}

function sac_admin_action( $platform ) {
    if ( ! isset( $_POST['sac_action'] ) ) return [];
    check_admin_referer( 'sac_admin_action' );
    $input = wp_unslash( $_POST );
    $keys = get_option( SAC_OPTION_KEYS, [] );
    $selected = sanitize_key( $input['key_id'] ?? '' );
    $token = '';
    foreach ( $keys as $key => $record ) {
        if ( hash_equals( sac_audit_key_id( $key ), $selected ) ) { $token = $key; break; }
    }
    switch ( $input['sac_action'] ) {
        case 'add_key':
            $level = ( $input['sac_level'] ?? '' ) === 'write' ? 'write' : 'read';
            $key = ( $level === 'write' ? 'sac_rw_' : 'sac_ro_' ) . wp_generate_password( 32, false );
            $scope = sac_site_content_policy();
            if ( $level === 'read' ) {
                $scope = array_map( static function ( $operations ) { return array_values( array_intersect( $operations, [ 'read' ] ) ); }, $scope );
            }
            $keys[ $key ] = [ 'level' => $level, 'created' => current_time( 'mysql' ), 'user_id' => get_current_user_id(), 'content_scope' => $scope ];
            update_option( SAC_OPTION_KEYS, $keys, false );
            return [ '已產生金鑰，請現在複製並保存。', $key ];
        case 'revoke_key':
            if ( ! $token ) break;
            unset( $keys[ $token ] );
            update_option( SAC_OPTION_KEYS, $keys, false );
            $verified = get_option( 'sac_platform_verification', [] );
            unset( $verified[ $selected ] );
            update_option( 'sac_platform_verification', $verified, false );
            delete_transient( 'sac_verify_' . $selected );
            return [ '已撤銷金鑰。' ];
        case 'save_types':
        case 'save_key_scope':
            $scope = [];
            foreach ( sac_content_types() as $slug => $type ) {
                $operations = array_values( array_intersect( [ 'read', 'create', 'update' ], (array) ( $input['scope'][ $slug ] ?? [] ) ) );
                if ( $operations ) $scope[ $slug ] = $operations;
            }
            if ( $input['sac_action'] === 'save_types' ) {
                update_option( SAC_OPTION_CONTENT_TYPES, $scope, false );
                return [ '已儲存本站的內容類型權限。' ];
            }
            if ( ! $token ) break;
            $user_id = absint( $input['user_id'] ?? 0 );
            if ( ! $user_id || ! is_user_member_of_blog( $user_id ) || ! user_can( $user_id, 'read' ) ) return [ '請輸入具備本站讀取能力的 WordPress 使用者 ID。', '', true ];
            if ( sac_get_key_level( $keys[ $token ] ) !== 'write' ) {
                $scope = array_map( static function ( $operations ) { return array_values( array_intersect( $operations, [ 'read' ] ) ); }, $scope );
            }
            $keys[ $token ]['user_id'] = $user_id;
            $keys[ $token ]['content_scope'] = $scope;
            update_option( SAC_OPTION_KEYS, $keys, false );
            return [ '已更新金鑰的綁定使用者與內容權限。' ];
        case 'clear_log':
            update_option( SAC_LOG_OPTION, [], false );
            return [ '已清空操作紀錄。' ];
        case 'save_advanced':
            $lines = preg_split( '/\R/', sanitize_textarea_field( $input['sac_ip_list'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
            $ips = array_values( array_unique( array_map( 'trim', $lines ) ) );
            if ( ( ! empty( $input['sac_ip_enabled'] ) && ! $ips ) || array_filter( $ips, static function ( $ip ) { return ! filter_var( $ip, FILTER_VALIDATE_IP ); } ) ) {
                return [ '請每行輸入一個有效的 IP 位址，啟用白名單前至少填入一個位址。', '', true ];
            }
            update_option( SAC_OPTION_IP_ALLOWLIST, [ 'enabled' => ! empty( $input['sac_ip_enabled'] ), 'ips' => $ips ], false );
            update_option( SAC_OPTION_ALLOW_PUBLISH, ! empty( $input['sac_allow_publish'] ), false );
            update_option( SAC_OPTION_ALLOW_COUPON_PUBLISH, ! empty( $input['sac_allow_coupon_publish'] ), false );
            return [ '已更新 IP 白名單與發佈設定。' ];
        case 'test_endpoint':
            if ( ! $token ) break;
            $response = wp_remote_get( rest_url( SAC_NAMESPACE . '/verify' ), [
                'headers' => [ 'X-SAC-Key' => $token ], 'timeout' => 8, 'redirection' => 0,
            ] );
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            $code = $data['code'] ?? '';
            $success = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 && ! empty( $data['connected'] );
            $notice = $success ? '本站端點測試成功。' : sac_admin_failure( $code ?: 'sac_rest_unreachable' );
            update_option( 'sac_endpoint_verification', [ 'time' => current_time( 'mysql' ), 'success' => $success, 'message' => $notice, 'key_id' => $selected ], false );
            return [ $notice, '', ! $success ];
        case 'platform_challenge':
            if ( ! $token ) break;
            $challenge = wp_generate_password( 18, false, false );
            set_transient( 'sac_verify_' . $selected, [ 'challenge' => $challenge, 'platform' => $platform ], 10 * MINUTE_IN_SECONDS );
            return [ '已準備驗證指令，有效時間為 10 分鐘。', '', false, '請呼叫本站的 verify_connection，並將 challenge 設為 ' . $challenge . '。回報工具實際傳回的連線狀態。' ];
    }
    return [ '請選擇有效的金鑰。', '', true ];
}

function sac_admin_failure( $code ) {
    $messages = [
        'sac_rest_unreachable' => 'REST 無法連線。請確認本站 REST API 可用、HTTPS 正常，並檢查防火牆、Cloudflare 或主機的站內回連限制。',
        'sac_ip_blocked' => '來源 IP 遭白名單阻擋。請到「連線設定」確認站內或平台的出口 IP。',
        'sac_invalid_key' => '金鑰無效或已撤銷。請在「連線設定」確認金鑰並更新平台認證。',
        'sac_no_key' => '未收到金鑰。請確認主機或平台有轉送 X-SAC-Key 或 Bearer 認證標頭。',
        'sac_forbidden' => '此金鑰為唯讀。請使用已授權此類型的讀寫金鑰。',
        'sac_content_scope' => '內容類型未授權。請到「內容類型與權限」查核。',
        'sac_content_type_mismatch' => '內容 ID 與類型不符，請先查詢目標。',
        'sac_required_field' => '缺少必填欄位，請依工具結果補齊。',
        'sac_publish_disabled' => '本站已關閉 AI 直接發佈。請建立草稿。',
        'sac_publish_capability' => '金鑰綁定的使用者無法發佈此內容。',
        'sac_partial_save' => '部分變更可能已儲存，請開啟內容核對。',
        'sac_field_save_failed' => '欄位儲存失敗，本次新增的內容已移除。',
    ];
    return $messages[ $code ] ?? $code;
}

function sac_admin_key_picker( $keys, $selected = '' ) {
    ?>
    <label>使用金鑰 <select name="key_id" required>
        <option value="">請選擇</option>
        <?php foreach ( $keys as $key => $data ) :
            $id = sac_audit_key_id( $key ); ?>
            <option value="<?php echo esc_attr( $id ); ?>" <?php selected( $selected, $id ); ?>><?php echo esc_html( $id . ( sac_get_key_level( $data ) === 'write' ? ' · 讀寫' : ' · 唯讀' ) ); ?></option>
        <?php endforeach; ?>
    </select></label>
    <?php
}

function sac_admin_copy( $id, $label, $value, $rows = 3 ) {
    ?>
    <label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
    <textarea class="large-text code" id="<?php echo esc_attr( $id ); ?>" rows="<?php echo (int) $rows; ?>" readonly><?php echo esc_textarea( $value ); ?></textarea>
    <button class="button sac-copy" type="button" data-copy="<?php echo esc_attr( $id ); ?>">複製</button>
    <?php
}

function sac_render_connection_page( $keys, $platform, $challenge ) {
    $platforms = sac_admin_platforms();
    $ip = get_option( SAC_OPTION_IP_ALLOWLIST, [] );
    $selected = sanitize_key( wp_unslash( $_POST['key_id'] ?? '' ) );
    ?>
    <section class="sac-panel">
        <h2>選擇平台</h2>
        <div class="sac-links"><?php foreach ( $platforms as $id => $entry ) : ?>
            <a class="button <?php echo $id === $platform ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'platform', $id, sac_admin_url( 'connection' ) ) ); ?>"><?php echo esc_html( $entry[0] ); ?></a>
        <?php endforeach; ?></div>
        <p><?php echo esc_html( $platforms[ $platform ][1] ); ?> <a href="<?php echo esc_url( $platforms[ $platform ][2] ); ?>">平台官方說明</a></p>
    </section>
    <section class="sac-panel">
        <h2>選擇金鑰權限</h2>
        <p>唯讀金鑰可查詢內容。建立草稿需使用已授權此類型的讀寫金鑰。新增金鑰會依目前開放的站點內容權限設定，可到「內容類型與權限」調整。</p>
        <form method="post">
            <?php wp_nonce_field( 'sac_admin_action' ); ?>
            <label>權限 <select name="sac_level"><option value="read">唯讀</option><option value="write">讀寫</option></select></label>
            <button class="button button-primary" name="sac_action" value="add_key">新增金鑰</button>
        </form>
    </section>
    <section class="sac-panel">
        <h2>複製設定</h2>
        <?php if ( $platform === 'actions' ) :
            sac_admin_copy( 'sac-schema-url', 'OpenAPI 網址', rest_url( SAC_NAMESPACE . '/openapi.json' ), 1 );
            echo '<p>認證標頭 <code>X-SAC-Key</code>。若匯入工具找不到路由，請確認 WordPress 已開啟永久連結且公開 REST 路由可由平台存取。</p>';
        elseif ( $platform === 'gemini' ) :
            sac_admin_copy( 'sac-gemini-url', 'Gemini 函式定義網址', rest_url( SAC_NAMESPACE . '/gemini-tools-schema' ), 1 );
            echo '<p><a href="' . esc_url( rest_url( SAC_NAMESPACE . '/openapi.json' ) ) . '">查看 REST 路徑與認證格式</a></p>';
        else :
            sac_admin_copy( 'sac-mcp-url', 'MCP 端點', rest_url( SAC_NAMESPACE . '/mcp' ), 1 );
            if ( $platform === 'claude' ) {
                $claude = [
                    'mcp_servers' => [ [ 'type' => 'url', 'name' => 'site-ai', 'url' => rest_url( SAC_NAMESPACE . '/mcp' ) ] ],
                    'tools' => [ [ 'type' => 'mcp_toolset', 'mcp_server_name' => 'site-ai' ] ],
                ];
                echo '<p>將以下欄位加入 Messages API 請求。請從秘密變數填入 <code>mcp_servers[0].authorization_token</code>。</p>';
                sac_admin_copy( 'sac-claude-settings', 'Claude Messages API 欄位', wp_json_encode( $claude, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), 10 );
            }
        endif; ?>
    </section>
    <section class="sac-panel">
        <h2 id="sac-verify">驗證連線</h2>
        <?php if ( $keys ) : ?>
            <form method="post">
                <?php wp_nonce_field( 'sac_admin_action' ); sac_admin_key_picker( $keys, $selected ); ?>
                <button class="button" name="sac_action" value="test_endpoint">測試本站端點</button>
                <button class="button button-primary" name="sac_action" value="platform_challenge">產生平台驗證指令</button>
            </form>
            <?php if ( $challenge ) : ?>
                <p>在已連線的 AI 平台執行下方指令。工具回應成功後，<a href="<?php echo esc_url( add_query_arg( 'platform', $platform, sac_admin_url( 'connection' ) ) . '#sac-verify' ); ?>">重新查看驗證結果</a>。</p>
                <?php sac_admin_copy( 'sac-check-prompt', '平台驗證指令', $challenge ); ?>
            <?php endif; ?>
        <?php else : ?><p>請先新增唯讀或讀寫金鑰。</p><?php endif; ?>
        <?php sac_render_verification_summary( $keys, $platform ); ?>
    </section>
    <details class="sac-panel">
        <summary>進階設定</summary>
        <form method="post">
            <?php wp_nonce_field( 'sac_admin_action' ); ?>
            <p><label><input type="checkbox" name="sac_ip_enabled" value="1" <?php checked( ! empty( $ip['enabled'] ) ); ?>>啟用 IP 白名單</label></p>
            <label for="sac-allowlist">允許的來源 IP，每行一個</label>
            <textarea class="large-text code" name="sac_ip_list" id="sac-allowlist" rows="4"><?php echo esc_textarea( implode( "\n", $ip['ips'] ?? [] ) ); ?></textarea>
            <p><label><input type="checkbox" name="sac_allow_publish" value="1" <?php checked( get_option( SAC_OPTION_ALLOW_PUBLISH, false ) ); ?>>允許具備 WordPress 發佈權限的金鑰直接發佈</label></p>
            <p><label><input type="checkbox" name="sac_allow_coupon_publish" value="1" <?php checked( get_option( SAC_OPTION_ALLOW_COUPON_PUBLISH, false ) ); ?>>允許 AI 直接公開優惠券</label></p>
            <button class="button" name="sac_action" value="save_advanced">儲存進階設定</button>
        </form>
    </details>
    <?php
}

function sac_render_verification_summary( $keys, $platform = '' ) {
    $endpoint = get_option( 'sac_endpoint_verification', [] );
    $verified = get_option( 'sac_platform_verification', [] );
    $labels = sac_admin_platforms();
    ?>
    <ul class="ul-disc">
        <li><?php echo $keys ? esc_html( sprintf( '已產生 %d 把有效金鑰。', count( $keys ) ) ) : '尚未產生金鑰。'; ?></li>
        <li><?php echo esc_html( isset( $endpoint['time'] ) ? $endpoint['message'] . ' ' . $endpoint['time'] . ' · ' . $endpoint['key_id'] : '本站端點尚未測試。' ); ?></li>
        <?php
        $seen = false;
        foreach ( $keys as $token => $data ) :
            foreach ( $verified[ sac_audit_key_id( $token ) ] ?? [] as $platform_id => $time ) :
                if ( ! isset( $labels[ $platform_id ] ) || ( $platform && $platform !== $platform_id ) ) continue;
                $seen = true;
                ?>
                <li><?php echo esc_html( $labels[ $platform_id ][0] . ' 已收到平台驗證呼叫。' . $time . ' · ' . sac_audit_key_id( $token ) ); ?></li>
            <?php endforeach;
        endforeach;
        if ( ! $seen ) : ?><li>尚未收到所選 AI 平台的驗證呼叫。</li><?php endif; ?>
    </ul>
    <?php
}

function sac_render_scope_table( $scope, $key = null ) {
    $ops = [ 'read' => '查詢', 'create' => '建立', 'update' => '更新' ];
    ?>
    <table class="widefat striped sac-table">
        <thead><tr><th scope="col">內容類型</th><?php foreach ( $ops as $label ) : ?><th scope="col"><?php echo esc_html( $label ); ?></th><?php endforeach; ?><?php if ( $key ) : ?><th scope="col">目前有效權限</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ( sac_content_types() as $slug => $type ) : ?>
            <tr><th scope="row"><?php echo esc_html( $type->label ); ?> <code><?php echo esc_html( $slug ); ?></code></th>
                <?php foreach ( $ops as $operation => $label ) : ?>
                    <td><input type="checkbox" name="scope[<?php echo esc_attr( $slug ); ?>][]" value="<?php echo esc_attr( $operation ); ?>" aria-label="<?php echo esc_attr( $type->label . $label ); ?>" <?php checked( in_array( $operation, $scope[ $slug ] ?? [], true ) ); ?> <?php disabled( $key && $operation !== 'read' && sac_get_key_level( $key ) !== 'write' ); ?>></td>
                <?php endforeach;
                if ( $key ) :
                    $effective = array_intersect_key( $ops, array_flip( sac_content_operations( $type, $key ) ) ); ?>
                    <td><?php echo esc_html( $effective ? implode( '、', $effective ) : '尚未授權' ); ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

function sac_render_permissions_page( $keys ) {
    ?>
    <section class="sac-panel">
        <h2>本站允許的操作</h2>
        <p>先開放內容類型與操作，再設定要使用的金鑰。商品、媒體與表單送件由各自的工具處理。</p>
        <form method="post">
            <?php wp_nonce_field( 'sac_admin_action' ); sac_render_scope_table( sac_site_content_policy() ); ?>
            <p><button class="button button-primary" name="sac_action" value="save_types">儲存本站權限</button></p>
        </form>
    </section>
    <section class="sac-panel">
        <h2>各金鑰的有效權限</h2>
        <p>以本站設定、金鑰範圍和綁定使用者的 WordPress 權限共同判定。較早建立的金鑰預設沿用文章權限。</p>
        <?php foreach ( $keys as $token => $key ) :
            $id = sac_audit_key_id( $token ); ?>
            <details>
                <summary><?php echo esc_html( $id . ' · ' . ( sac_get_key_level( $key ) === 'write' ? '讀寫' : '唯讀' ) . ' · ' . ( $key['created'] ?? '' ) ); ?></summary>
                <form method="post">
                    <?php wp_nonce_field( 'sac_admin_action' ); ?>
                    <input type="hidden" name="key_id" value="<?php echo esc_attr( $id ); ?>">
                    <p><label>綁定 WordPress 使用者 ID <input name="user_id" type="number" min="1" value="<?php echo (int) ( $key['user_id'] ?? 0 ); ?>" required></label></p>
                    <?php sac_render_scope_table( $key['content_scope'] ?? [ 'post' => sac_get_key_level( $key ) === 'write' ? [ 'read', 'create', 'update' ] : [ 'read' ] ], $key ); ?>
                    <p><button class="button" name="sac_action" value="save_key_scope">儲存金鑰權限</button>
                    <button class="button" name="sac_action" value="revoke_key" onclick="return confirm('確定撤銷此金鑰？');">撤銷金鑰</button></p>
                </form>
            </details>
        <?php endforeach; ?>
    </section>
    <section class="sac-panel"><h2>本站欄位與分類法</h2>
        <?php foreach ( sac_content_types() as $slug => $type ) :
            $description = sac_content_description( [ 'type' => $type, 'user' => wp_get_current_user(), 'post' => null ] ); ?>
            <details>
                <summary><?php echo esc_html( $type->label ); ?> <code><?php echo esc_html( $slug ); ?></code></summary>
                <p><?php echo esc_html( '原生欄位 ' . implode( '、', array_keys( array_filter( $description['fields'] ) ) ) ); ?></p>
                <p><?php echo esc_html( '分類法 ' . ( implode( '、', array_keys( (array) $description['taxonomies'] ) ) ?: '無' ) ); ?></p>
                <p><?php echo esc_html( '支援功能 ' . ( implode( '、', array_keys( array_filter( $description['features'] ) ) ) ?: '無' ) ); ?></p>
                <?php foreach ( $description['custom_fields'] as $name => $field ) : ?>
                    <p><code><?php echo esc_html( $name ); ?></code> <?php echo esc_html( $field['label'] . ' · ' . $field['type'] . ( $field['required'] ? ' · 必填' : '' ) . ( $field['help'] ? ' · ' . $field['help'] : '' ) ); ?></p>
                <?php endforeach; ?>
                <p>若 ACF、Pods 或自製欄位缺少綁定，請從 WordPress 內容編輯頁補齊，或由維護者登錄欄位定義。</p>
            </details>
        <?php endforeach; ?>
    </section>
    <?php
}

function sac_render_task_builder( $task ) {
    $updating = $task === 'update';
    $operation = $updating ? 'update' : 'create';
    $types = [];
    foreach ( sac_content_types() as $slug => $type ) {
        if ( ! in_array( $operation, sac_site_content_policy()[ $slug ] ?? [], true ) ) continue;
        $types[ $slug ] = sac_content_description( [ 'type' => $type, 'user' => wp_get_current_user(), 'post' => null ] );
    }
    if ( ! $types ) {
        echo '<p>請先在「內容類型與權限」開放至少一個類型及操作。</p>';
        return;
    }
    ?>
    <div class="sac-builder" data-task="<?php echo esc_attr( $task ); ?>" data-types="<?php echo esc_attr( wp_json_encode( (object) $types ) ); ?>">
        <h3><?php echo $updating ? '更新既有內容' : '建立內容草稿'; ?></h3>
        <p><?php echo $updating ? '指定類型與 ID，再填寫要變更的欄位。' : '選擇類型、查看必填欄位，再填入草稿需求。'; ?></p>
        <label>內容類型 <select class="sac-builder-type">
            <?php foreach ( $types as $slug => $item ) : ?><option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $item['label'] . ' (' . $slug . ')' ); ?></option><?php endforeach; ?>
        </select></label>
        <?php if ( $updating ) : ?><label>內容 ID <input type="number" min="1" class="sac-builder-id"></label><?php endif; ?>
        <p class="sac-builder-fields" aria-live="polite"></p>
        <label>需求<textarea class="large-text sac-builder-request" rows="3" placeholder="輸入主題、指定欄位及需要補充的資料"></textarea></label>
        <?php sac_admin_copy( 'sac-prompt-' . $task, '貼到已連線的 AI', '', 7 ); ?>
    </div>
    <?php
}

function sac_render_tools_page( $group ) {
    $groups = [
        'content' => [ '文章與 FAQ' ], 'seo' => [ 'SEO' ], 'media' => [ '圖片上傳' ],
        'commerce' => [ 'WooCommerce 商品', '營運資料', '優惠券管理' ],
        'site' => [ '選單管理', '轉址管理', '表單送件', '網站連線' ],
    ];
    $labels = [ 'content' => '內容與 FAQ', 'seo' => 'SEO', 'media' => '媒體', 'commerce' => '電商', 'site' => '網站工具' ];
    if ( ! isset( $groups[ $group ] ) ) $group = 'content';
    $templates = sac_get_prompt_templates();
    ?>
    <section class="sac-panel">
        <div class="sac-links"><?php foreach ( $labels as $id => $label ) : ?>
            <a class="button <?php echo $id === $group ? 'button-primary' : ''; ?>" href="<?php echo esc_url( sac_admin_url( 'tools', $id ) ); ?>"><?php echo esc_html( $label ); ?></a>
        <?php endforeach; ?></div>
        <?php if ( $group === 'commerce' && ! class_exists( 'WooCommerce' ) ) : ?><p>WooCommerce 未啟用，電商工具目前無法使用。</p><?php endif; ?>
        <?php if ( $group === 'content' ) : ?>
            <h2 id="sac-content-tasks">常用任務</h2>
            <?php sac_admin_copy( 'sac-query-prompt', '查詢本站內容', '請先呼叫 list_content_types，列出可查詢的類型，再用 describe_content_type 及 list_content 查找我指定的內容。回報標題、類型、ID、狀態與編輯連結。' ); ?>
            <?php sac_render_task_builder( 'create' ); sac_render_task_builder( 'update' ); ?>
        <?php endif; ?>
        <h2>可複製的功能指令</h2>
        <?php foreach ( $templates['quick'] as $id => $item ) :
            if ( ! in_array( $item['category'], $groups[ $group ], true ) || ( $group === 'commerce' && ! class_exists( 'WooCommerce' ) ) ) continue; ?>
            <details><summary><?php echo esc_html( $item['label'] ); ?></summary><?php sac_admin_copy( 'sac-quick-' . $id, '操作指令', $item['prompt'], 8 ); ?></details>
        <?php endforeach; ?>
        <details><summary>完整工具表與技術參數</summary>
            <?php foreach ( sac_get_tool_definitions() as $tool ) :
                if ( ! in_array( $tool['group'], $groups[ $group ], true ) ) continue; ?>
                <details class="sac-tool">
                    <summary><?php echo esc_html( $tool['summary'] ); ?> <code><?php echo esc_html( $tool['name'] ); ?></code></summary>
                    <dl>
                        <dt>可以做什麼</dt><dd><?php echo esc_html( $tool['description'] ); ?></dd>
                        <dt>要先準備什麼</dt><dd><?php echo esc_html( ! $tool['available'] ? '請確認對應外掛或功能已啟用。' : ( $tool['need_write'] ? '已連線、有該內容或功能的讀寫權限，並已確認變更。' : '已連線且具備查詢權限。' ) ); ?></dd>
                        <dt>怎麼操作</dt><dd><?php echo esc_html( $tool['scenario'] ); ?></dd>
                        <dt>成功後會得到什麼</dt><dd><?php echo $tool['need_write'] ? '取得變更結果及適用的操作紀錄。' : '取得工具實際查詢結果。'; ?></dd>
                        <dt>失敗時去哪裡處理</dt><dd><?php echo $tool['need_write'] ? '到「操作紀錄」查看錯誤代碼，並檢查金鑰及依賴設定。' : '依回傳原因到「連線設定」或「內容類型與權限」檢查。'; ?></dd>
                    </dl>
                    <?php if ( $tool['available'] ) sac_admin_copy( 'sac-tool-' . $tool['name'], '範例指令', $templates['tool_prompts'][ $tool['name'] ] ?? $tool['prompt'] ); ?>
                    <p><code><?php echo esc_html( $tool['method'] . ' ' . $tool['path'] ); ?></code></p>
                    <ul class="ul-disc"><?php foreach ( $tool['params'] as $name => $param ) : ?>
                        <li><code><?php echo esc_html( $name ); ?></code> <?php echo esc_html( $param['type'] . ( empty( $param['required'] ) ? '' : ' · 必填' ) . ' · ' . ( $param['description'] ?? $param['in'] ) ); ?></li>
                    <?php endforeach; ?></ul>
                </details>
            <?php endforeach; ?>
        </details>
    </section>
    <?php
}

function sac_render_operation_log() {
    $logs = array_reverse( get_option( SAC_LOG_OPTION, [] ) );
    ?>
    <section class="sac-panel">
        <h2>最近的寫入操作</h2>
        <table class="widefat striped sac-table">
            <thead><tr><th>時間</th><th>操作</th><th>內容類型與 ID</th><th>結果</th><th>金鑰識別碼</th></tr></thead>
            <tbody>
            <?php if ( ! $logs ) : ?><tr><td colspan="5">目前沒有寫入紀錄。</td></tr><?php endif; ?>
            <?php foreach ( $logs as $log ) :
                $target = $log['target_type'] ?? '';
                $id = $log['target_id'] ?? '';
                $success = $log['success'] ?? null;
                $saved = $success || ( $log['failure_reason'] ?? '' ) === 'sac_partial_save';
                $actual = $saved && $id && preg_match( '/^[0-9]+$/D', (string) $id ) ? get_post_type( (int) $id ) : false;
                $types = [ 'product' => 'product', 'coupon' => 'shop_coupon', 'media' => 'attachment' ];
                $edit = $actual && ( $types[ $target ] ?? $target ) === $actual ? get_edit_post_link( (int) $id ) : ''; ?>
                <tr>
                    <td><?php echo esc_html( $log['time'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $log['tool'] ?? $log['action'] ?? '' ); ?></td>
                    <td><?php echo esc_html( trim( $target . ' ' . $id ) ); ?><?php if ( $edit ) : ?> <a href="<?php echo esc_url( $edit ); ?>">編輯</a><?php endif; ?></td>
                    <td><?php echo esc_html( $success === null ? '舊版紀錄' : ( $success ? '成功' : sac_admin_failure( $log['failure_reason'] ?? '' ) ) ); ?></td>
                    <td><code><?php echo esc_html( $log['key_id'] ?? '—' ); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <form method="post">
            <?php wp_nonce_field( 'sac_admin_action' ); ?>
            <p><button class="button" name="sac_action" value="clear_log" onclick="return confirm('確定清空操作紀錄？此操作無法復原。');">清空紀錄</button></p>
        </form>
    </section>
    <?php
}

function sac_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( '權限不足' );
    $tabs = [ 'overview' => '總覽', 'connection' => '連線設定', 'permissions' => '內容類型與權限', 'tools' => '功能與指令', 'logs' => '操作紀錄' ];
    $tab = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );
    if ( ! isset( $tabs[ $tab ] ) ) $tab = 'overview';
    $platform = sanitize_key( wp_unslash( $_GET['platform'] ?? '' ) );
    if ( ! isset( sac_admin_platforms()[ $platform ] ) ) $platform = 'chatgpt';
    [ $notice, $secret, $error, $challenge ] = array_pad( sac_admin_action( $platform ), 4, null );
    $keys = get_option( SAC_OPTION_KEYS, [] );
    wutm_admin_page_open( 'AI 連接器' );
    ?>
    <div class="sac-admin">
        <nav class="nav-tab-wrapper" aria-label="AI 連接器設定">
            <?php foreach ( $tabs as $id => $label ) : ?>
                <a class="nav-tab <?php echo $id === $tab ? 'nav-tab-active' : ''; ?>" <?php echo $id === $tab ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( sac_admin_url( $id ) ); ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ( $notice ) : ?>
            <div class="notice <?php echo $error ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html( $notice ); ?></p>
            <?php if ( $secret ) sac_admin_copy( 'sac-new-key', '新金鑰', $secret, 1 ); ?></div>
        <?php endif;
        switch ( $tab ) {
            case 'connection': sac_render_connection_page( $keys, $platform, $challenge ); break;
            case 'permissions': sac_render_permissions_page( $keys ); break;
            case 'tools': sac_render_tools_page( sanitize_key( wp_unslash( $_GET['group'] ?? '' ) ) ); break;
            case 'logs': sac_render_operation_log(); break;
            default: ?>
                <section class="sac-panel">
                    <h2>常用任務</h2>
                    <div class="sac-links">
                        <a class="button button-primary" href="<?php echo esc_url( sac_admin_url( 'connection' ) . '#sac-verify' ); ?>">測試連線</a>
                        <a class="button" href="<?php echo esc_url( sac_admin_url( 'tools', 'content' ) . '#sac-content-tasks' ); ?>">查詢本站內容</a>
                        <a class="button" href="<?php echo esc_url( sac_admin_url( 'tools', 'content' ) . '#sac-prompt-create' ); ?>">建立內容草稿</a>
                    </div>
                </section>
                <section class="sac-panel"><h2>站點內容</h2>
                    <p><?php
                    $types = array_intersect_key( sac_content_types(), array_filter( sac_site_content_policy() ) );
                    echo esc_html( $types ? implode( '、', wp_list_pluck( $types, 'label' ) ) : '本站尚未開放內容類型。' );
                    ?></p>
                    <a href="<?php echo esc_url( sac_admin_url( 'permissions' ) ); ?>">查看內容類型與權限</a>
                </section>
                <section class="sac-panel"><h2>連線狀態</h2><?php sac_render_verification_summary( $keys ); ?></section>
                <?php break;
        } ?>
    </div>
    <?php
    wutm_admin_page_close();
}

add_action( 'admin_enqueue_scripts', static function ( $hook ) {
    if ( ! str_ends_with( $hook, '_page_site-ai-connector' ) ) return;
    $url = plugin_dir_url( __FILE__ );
    wp_enqueue_style( 'sac-admin', $url . 'admin.css', [], WUTM_VERSION );
    wp_enqueue_script( 'sac-admin', $url . 'admin.js', [], WUTM_VERSION, true );
} );
