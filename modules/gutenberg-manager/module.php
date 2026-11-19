<?php
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', static function () {
    add_submenu_page( 'wu-toolbox-modular', '古騰堡管理', '古騰堡管理', 'manage_options', 'wu-gutenberg-manager', 'wutm_gutenberg_page' );
} );

add_filter( 'allowed_block_types_all', static function ( $allowed ) {
    if ( false === $allowed || ( true !== $allowed && ! is_array( $allowed ) ) ) return $allowed;
    $hidden = (array) get_option( 'wutm_gutenberg_hidden', [] );
    if ( ! get_option( 'wutm_gutenberg_gallery', false ) ) $hidden[] = 'wu-toolbox/advanced-gallery';
    if ( ! $hidden ) return $allowed;
    $types = true === $allowed ? array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ) : $allowed;
    return array_values( array_diff( $types, $hidden ) );
}, PHP_INT_MAX );

add_action( 'init', static function () {
    wp_register_script( 'wutm-gallery-editor', WUTM_URL . 'modules/gutenberg-manager/gallery.js', [ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ], WUTM_VERSION, true );
    wp_register_style( 'wutm-gallery', WUTM_URL . 'modules/gutenberg-manager/gallery.css', [], WUTM_VERSION );
    // Keep the type registered so saved albums remain editable after insertion is disabled.
    register_block_type( 'wu-toolbox/advanced-gallery', [
        'api_version' => 3, 'title' => '進階相簿', 'category' => 'media',
        'attributes' => [ 'zoom' => [ 'type' => 'boolean', 'default' => true ], 'mobileColumns' => [ 'type' => 'number', 'default' => 2 ] ],
        'editor_script' => 'wutm-gallery-editor', 'style' => 'wutm-gallery',
    ] );
} );

function wutm_gutenberg_count_blocks( array $blocks, array &$counts ): void {
    foreach ( $blocks as $block ) {
        if ( ! empty( $block['blockName'] ) ) {
            $name = $block['blockName'];
            $counts[$name] = ( $counts[$name] ?? 0 ) + 1;
        }
        wutm_gutenberg_count_blocks( $block['innerBlocks'] ?? [], $counts );
    }
}

add_action( 'wp_ajax_wutm_gutenberg_scan', static function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );
    check_ajax_referer( 'wutm_gutenberg_scan', 'nonce' );
    global $wpdb;
    $key = 'wutm_gutenberg_scan_' . get_current_user_id();
    $state = isset( $_POST['restart'] ) ? false : get_transient( $key );
    if ( ! $state && ! isset( $_POST['restart'] ) ) wp_send_json_error( [], 409 );
    $state = $state ?: [ 'cursor' => 0, 'counts' => [], 'scanned' => 0, 'limit' => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) ];
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID > %d AND ID <= %d
         AND post_type NOT IN ('revision','attachment','nav_menu_item')
         AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY ID ASC LIMIT 25",
        $state['cursor'], $state['limit']
    ) );
    if ( ! is_array( $rows ) ) wp_send_json_error( [], 500 );
    foreach ( $rows as $row ) {
        wutm_gutenberg_count_blocks( parse_blocks( $row->post_content ), $state['counts'] );
        $state['cursor'] = (int) $row->ID;
        ++$state['scanned'];
    }
    $done = count( $rows ) < 25;
    if ( $done ) {
        update_option( 'wutm_gutenberg_usage', [ 'counts' => $state['counts'], 'time' => time() ], false );
        delete_transient( $key );
    } else {
        set_transient( $key, $state, 30 * MINUTE_IN_SECONDS );
    }
    wp_send_json_success( [ 'done' => $done, 'scanned' => $state['scanned'] ] );
} );

function wutm_gutenberg_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $registry = WP_Block_Type_Registry::get_instance()->get_all_registered();
    $hidden = (array) get_option( 'wutm_gutenberg_hidden', [] );
    if ( isset( $_POST['wutm_save_blocks'] ) ) {
        check_admin_referer( 'wutm_save_blocks' );
        $requested = json_decode( (string) wp_unslash( $_POST['hidden_blocks'] ?? '[]' ), true );
        if ( is_array( $requested ) ) {
            $hidden = array_values( array_intersect( array_filter( $requested, 'is_string' ), array_keys( $registry ) ) );
            update_option( 'wutm_gutenberg_hidden', $hidden, false );
            update_option( 'wutm_gutenberg_gallery', isset( $_POST['gallery'] ), false );
        }
    }
    $usage = get_option( 'wutm_gutenberg_usage', [] );
    $names = array_unique( array_merge( array_keys( $registry ), array_keys( $usage['counts'] ?? [] ) ) );
    sort( $names );
    wutm_admin_page_open( '古騰堡管理', '管理新增區塊清單與進階相簿。隱藏設定會保留現有區塊及其內容。' );
    echo '<p>掃描資料庫已儲存的內容及巢狀區塊。同步版型本體計一次；佈景檔案中的預設範本與動態產生的區塊不計。零次表示上次掃描時未找到。</p>';
    if ( ! empty( $usage['time'] ) ) echo '<p>上次掃描：' . esc_html( wp_date( 'Y-m-d H:i', $usage['time'] ) ) . '</p>';
    echo '<button type="button" class="button" id="wutm-block-scan" data-nonce="' . esc_attr( wp_create_nonce( 'wutm_gutenberg_scan' ) ) . '">掃描使用量</button> <span id="wutm-block-status" role="status"></span>';
    echo '<form method="post" id="wutm-block-settings">';
    wp_nonce_field( 'wutm_save_blocks' );
    echo '<input type="hidden" name="hidden_blocks" value="' . esc_attr( wp_json_encode( $hidden ) ) . '">';
    echo '<p><label><input type="checkbox" name="gallery" ' . checked( get_option( 'wutm_gutenberg_gallery', false ), true, false ) . '> 開放插入進階相簿</label></p>';
    echo '<p><label>搜尋區塊 <input id="wutm-block-search" type="search"></label> ';
    echo '<label>狀態 <select id="wutm-block-state"><option value="">全部</option><option value="hidden">已勾選隱藏</option><option value="visible">未勾選隱藏</option><option value="unused">未使用</option></select></label> ';
    echo '<button type="button" class="button" id="wutm-block-hide-unused"' . disabled( empty( $usage['time'] ), true, false ) . '>勾選未使用區塊</button> ';
    echo '<button type="button" class="button" id="wutm-block-show-all">清除隱藏勾選</button></p>';
    echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>區塊</th><th>來源</th><th>使用量</th><th>從插入器隱藏</th></tr></thead><tbody>';
    foreach ( $names as $name ) {
        $block = $registry[$name] ?? null;
        $title = $block->title ?? $name;
        $count = $usage['counts'][$name] ?? 0;
        echo '<tr><td>' . esc_html( $title ) . ' <code>' . esc_html( $name ) . '</code></td><td>' . esc_html( explode( '/', $name )[0] ) . '</td><td>' . esc_html( empty( $usage['time'] ) ? '未掃描' : $count ) . '</td><td>';
        if ( $block && 'wu-toolbox/advanced-gallery' !== $name ) {
            echo '<input type="checkbox" data-block="' . esc_attr( $name ) . '" data-count="' . esc_attr( empty( $usage['time'] ) ? '' : $count ) . '" aria-label="' . esc_attr( '隱藏 ' . $title ) . '" ' . checked( in_array( $name, $hidden, true ), true, false ) . '>';
        } else {
            echo $block ? '由上方相簿開關控制' : '未註冊';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div><p><button class="button button-primary" name="wutm_save_blocks" value="1">儲存設定</button></p></form>';
    wutm_admin_page_close();
}

add_action( 'admin_enqueue_scripts', static function () {
    if ( isset( $_GET['page'] ) && 'wu-gutenberg-manager' === $_GET['page'] ) {
        wp_enqueue_script( 'wutm-gutenberg-admin', WUTM_URL . 'modules/gutenberg-manager/admin.js', [], WUTM_VERSION, true );
    }
} );
