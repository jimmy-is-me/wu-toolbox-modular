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

require_once __DIR__ . '/oauth-admin.php';

function sac_admin_failure( $code ) {
    $messages = [
        'sac_rest_unreachable' => 'REST 無法連線。請確認本站 REST API 可用、HTTPS 正常，並檢查防火牆、Cloudflare 或主機的站內回連限制。',
        'sac_ip_blocked' => '來源 IP 遭白名單阻擋。請到「連線設定」確認站內或平台的出口 IP。',
        'sac_content_scope' => '內容類型未授權。請到「內容類型與權限」查核。',
        'sac_content_type_mismatch' => '內容 ID 與類型不符，請先查詢目標。',
        'sac_required_field' => '缺少必填欄位，請依工具結果補齊。',
        'sac_publish_disabled' => '本站已關閉 AI 直接發佈。請建立草稿。',
        'sac_publish_capability' => '授權的使用者無法發佈此內容。',
        'sac_partial_save' => '部分變更可能已儲存，請開啟內容核對。',
        'sac_field_save_failed' => '欄位儲存失敗，本次新增的內容已移除。',
    ];
    return $messages[ $code ] ?? $code;
}

function sac_admin_copy( $id, $label, $value, $rows = 3 ) {
    ?>
    <div class="sac-copy-field<?php echo $rows > 1 ? ' sac-copy-field--multiline' : ''; ?>">
        <label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
        <?php if ( $rows === 1 ) : ?>
            <input class="sac-copy-value" type="text" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>" readonly>
        <?php else : ?>
            <textarea class="sac-copy-value" id="<?php echo esc_attr( $id ); ?>" rows="<?php echo (int) $rows; ?>" readonly><?php echo esc_textarea( $value ); ?></textarea>
        <?php endif; ?>
        <button class="sac-copy" type="button" data-copy="<?php echo esc_attr( $id ); ?>">複製</button>
    </div>
    <?php
}

function sac_render_permissions_page() {
    $policy = sac_site_content_policy();
    $ops = [ 'read' => '查詢', 'create' => '建立草稿', 'update' => '更新', 'publish' => '發佈' ];
    ?>
    <section class="sac-panel"><h2>本站允許的操作</h2>
        <p>OAuth Scope、使用者同意的類型與 WordPress 帳號權限共同決定可用操作。新增權限後請重新授權。</p>
        <form method="post"><?php wp_nonce_field( 'sac_admin_action' ); ?>
        <table class="widefat striped sac-table"><thead><tr><th>內容類型</th>
        <?php foreach ( $ops as $label ) : ?><th><?php echo esc_html( $label ); ?></th><?php endforeach; ?>
        </tr></thead><tbody>
        <?php foreach ( sac_content_types() as $slug => $type ) : ?>
            <tr><th scope="row"><?php echo esc_html( $type->label ); ?> <code><?php echo esc_html( $slug ); ?></code></th>
            <?php foreach ( $ops as $operation => $label ) : ?>
                <td><input type="checkbox" name="scope[<?php echo esc_attr( $slug ); ?>][]" value="<?php echo esc_attr( $operation ); ?>"
                    aria-label="<?php echo esc_attr( $type->label . ' ' . $label ); ?>" <?php checked( in_array( $operation, $policy[ $slug ] ?? [], true ) ); ?>></td>
            <?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody></table>
        <p><button class="button button-primary" name="sac_action" value="save_types">儲存內容類型權限</button></p>
        </form></section>
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
                        <dt>失敗時去哪裡處理</dt><dd><?php echo $tool['need_write'] ? '到「操作紀錄」查看錯誤代碼，並檢查授權及依賴設定。' : '依回傳原因到「連線設定」或「內容類型與權限」檢查。'; ?></dd>
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
    sac_render_change_log();
    $diagnostics = sac_mcp_diagnostic_rows();
    echo '<details class="sac-panel"><summary>AI MCP 診斷</summary><p>最近 40 筆，保留 3 天。可複製請求編號及錯誤資訊。</p>';
    if ( $diagnostics ) sac_admin_copy( 'sac_mcp_diagnostic_json', '診斷紀錄', wp_json_encode( $diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ), 8 );
    else echo '<p>目前沒有診斷紀錄。</p>';
    echo '</details>';
    $logs = array_merge( sac_oauth_operation_log(), array_reverse( (array) get_option( SAC_LOG_OPTION, [] ) ) );
    ?>
    <section class="sac-panel">
        <h2>最近的寫入操作</h2>
        <table class="widefat striped sac-table">
            <thead><tr><th>時間</th><th>操作</th><th>內容類型與 ID</th><th>結果</th><th>授權／操作 ID</th></tr></thead>
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
                    <td><code><?php echo esc_html( isset( $log['grant_id'] ) ? $log['grant_id'] . '／' . ( $log['operation_id'] ?? '—' ) : ( $log['key_id'] ?? '—' ) ); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php
}

function sac_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( '權限不足' );
    $tabs = [ 'overview' => '連線總覽', 'connection' => '平台連線設定', 'apps' => '已授權的應用程式',
        'permissions' => '內容類型與權限', 'tools' => '功能與指令', 'logs' => '操作紀錄', 'security' => '進階安全設定' ];
    $requested = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );
    $tab = isset( $tabs[ $requested ] ) ? $requested : 'overview';
    [ $notice, $error ] = array_pad( sac_oauth_admin_action(), 2, null );
    wutm_admin_page_open( 'AI 連接器' );
    ?>
    <div class="sac-admin">
    <div class="sac-browser">
        <div class="sac-browser-bar" aria-hidden="true">
            <span class="sac-browser-controls"><i></i><i></i><i></i></span>
            <span class="sac-browser-tab">AI 連接器</span>
        </div>
        <div class="sac-browser-heading"><h2><?php echo esc_html( $tabs[ $tab ] ); ?></h2></div>
        <nav class="sac-nav" aria-label="AI 連接器設定">
    <?php foreach ( $tabs as $id => $label ) : ?>
        <a <?php echo $id === $tab ? 'aria-current="page"' : ''; ?> href="<?php echo esc_url( sac_admin_url( $id ) ); ?>"><?php echo esc_html( $label ); ?></a>
    <?php endforeach; ?>
        </nav>
    </div>
    <div class="sac-content">
        <p class="screen-reader-text sac-copy-status" role="status"></p>
    <?php if ( $notice ) : ?><div class="notice <?php echo $error ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html( $notice ); ?></p></div><?php endif;
    switch ( $tab ) {
        case 'connection': sac_oauth_admin_connect(); break;
        case 'apps': sac_oauth_admin_apps(); break;
        case 'permissions': sac_render_permissions_page(); break;
        case 'tools': sac_render_tools_page( sanitize_key( wp_unslash( $_GET['group'] ?? '' ) ) ); break;
        case 'logs': sac_render_operation_log(); break;
        case 'security': sac_oauth_admin_security(); break;
        default: sac_oauth_admin_overview(); break;
    }
    ?>
    </div>
    </div>
    <?php wutm_admin_page_close();
}

add_action( 'admin_enqueue_scripts', static function ( $hook ) {
    if ( ! str_ends_with( $hook, '_page_site-ai-connector' ) ) return;
    $url = plugin_dir_url( __FILE__ );
    wp_enqueue_style( 'sac-admin', $url . 'admin.css', [], WUTM_VERSION );
    wp_enqueue_script( 'sac-admin', $url . 'admin.js', [], WUTM_VERSION, true );
} );
