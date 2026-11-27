<?php
defined( 'ABSPATH' ) || exit;

function sac_review_media_preview( $id, $label, $user ) {
    if ( ! $id || ! wp_attachment_is_image( $id ) || ! user_can( $user, 'read_post', $id ) ) return '';
    $item = sac_media_item( $id );
    $rights = (array) $item['rights'];
    $html = '<figure><figcaption>' . esc_html( $label ) . ' · #' . (int) $id . '</figcaption>' . wp_get_attachment_image( $id, 'medium' );
    $html .= '<dl>';
    foreach ( [ '尺寸' => $item['width'] . ' × ' . $item['height'], '檔案大小' => $item['file_size'] ? size_format( $item['file_size'] ) : '—',
        '格式' => $item['mime_type'], 'ALT' => $item['alt_text'], '來源' => $rights['source_url'] ?? $rights['source_type'] ?? '未提供',
        '作者' => $rights['creator'] ?? '未提供', '授權' => $rights['license'] ?? '未確認',
        'AI 生成' => isset( $rights['generated_by_ai'] ) ? ( $rights['generated_by_ai'] ? '是' : '否' ) : '未提供' ] as $key => $value ) {
        $html .= '<dt>' . esc_html( $key ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
    }
    return $html . '</dl><p>請依來源與授權資料確認使用權。</p></figure>';
}

function sac_review_overview( $row, $target ) {
    $post = $target['post'];
    $payload = $row['payload'];
    $fields = $payload['values'];
    $values = $payload['expected'];
    $high = in_array( $post->post_status, [ 'publish', 'future', 'private' ], true )
        || isset( $fields['content']['slug'] ) || isset( $fields['seo']['canonical'] ) || isset( $fields['seo']['noindex'] );
    $count = 0;
    foreach ( $values as $group => $items ) $count += $group === 'faqs' ? 1 : count( $items );
    $context = [ '網站' => get_bloginfo( 'name' ), '文章' => $post->post_title . ' #' . $post->ID,
        '內容類型' => $post->post_type, '授權帳號' => $target['user']->display_name, '審核編號' => '#' . $row['id'],
        '申請時間' => wp_date( 'Y-m-d H:i', $payload['prepared_at'] ?? ( $payload['approval_expires'] - 30 * MINUTE_IN_SECONDS ) ),
        '審核期限' => wp_date( 'Y-m-d H:i', $payload['approval_expires'] ), '變更欄位' => $count, '風險' => $high ? '高' : '一般' ];
    $css = add_query_arg( 'ver', WUTM_VERSION, WUTM_URL . 'modules/site-ai-connector/review.css' );
    $html = '<link rel="stylesheet" href="' . esc_url( $css ) . '"><section class="sac-review"><header><h1>AI 變更審核</h1><p>'
        . esc_html( $payload['purpose'] ?? '核對並套用選取的文章變更' ) . '</p><dl>';
    foreach ( $context as $key => $value ) $html .= '<div><dt>' . esc_html( $key ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
    $html .= '</dl>';
    $original = sac_public_post_url( $post );
    if ( $original ) $html .= '<p><a href="' . esc_url( $original ) . '" target="_blank" rel="noopener">查看目前公開頁面</a></p>';
    $html .= '</header>';
    if ( isset( $fields['seo'] ) ) {
        $seo = $fields['seo'] + sac_get_seo_fields( $post->ID );
        $title = ( $seo['title'] ?? '' ) ?: $post->post_title;
        $description = $seo['description'] ?? '';
        $url = ( $seo['canonical'] ?? '' ) ?: ( $original ?: get_permalink( $post ) );
        $html .= '<details class="sac-review-preview"><summary>搜尋與社群分享模擬</summary><div class="sac-review-serp">';
        foreach ( [ '桌面', '手機' ] as $device ) $html .= '<article><small>' . esc_html( $device . ' · 模擬' ) . '</small><p>' . esc_html( $url )
            . '</p><strong>' . esc_html( $title ) . '</strong><p>' . esc_html( $description ) . '</p></article>';
        $html .= '</div><article><small>Open Graph · 模擬</small><p><strong>' . esc_html( ( $seo['og_title'] ?? '' ) ?: $title )
            . '</strong></p><p>' . esc_html( ( $seo['og_description'] ?? '' ) ?: $description ) . '</p></article></details>';
    }
    $images = [ '目前精選圖片' => $payload['before']['content']['featured_image_id'] ?? 0, '擬用精選圖片' => $values['content']['featured_image_id'] ?? 0,
        '擬用社群分享圖片' => $fields['seo']['og_image_id'] ?? 0, '媒體資料變更' => $fields['media']['id'] ?? 0 ];
    if ( array_filter( $images ) ) {
        $html .= '<details class="sac-review-preview"><summary>圖片與授權</summary><div class="sac-review-media">';
        foreach ( $images as $label => $id ) $html .= sac_review_media_preview( $id, $label, $target['user'] );
        $html .= '</div></details>';
    }
    if ( $payload['blocks'] ) $html .= '<p>以選定的 Gutenberg 區塊位置套用，原有未選取區塊會保留。</p>';
    $html .= '<p>儲存成功後清除 WU 頁面快取中的相關內容。可使用操作編號重試清除並重新驗收公開頁。</p>';
    return $html;
}

function sac_render_change_log() {
    if ( ! current_user_can( 'manage_options' ) || get_option( 'sac_oauth_schema' ) !== '1' ) return;
    global $wpdb;
    $table = sac_oauth_table();
    $filter = static function ( $key ) {
        return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';
    };
    $id = absint( $filter( 'sac_post' ) );
    $owner = absint( $filter( 'sac_owner' ) );
    $status = $filter( 'sac_status' );
    $type = $filter( 'sac_type' );
    $cache = $filter( 'sac_cache' );
    $frontend = $filter( 'sac_frontend' );
    $date = $filter( 'sac_date' );
    $state_labels = [ 'pending' => '待審核', 'applying' => '套用中', 'applied' => '已儲存', 'failed' => '失敗', 'rejected' => '已拒絕',
        'cancelled' => '已取消', 'conflicted' => '有衝突', 'expired' => '已過期', 'revision_requested' => '要求重新調整' ];
    $rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE kind='changes' AND expires>%d AND (%d=0 OR owner=%d) ORDER BY id DESC LIMIT 60", time(), $owner, $owner ) );
    echo '<section class="sac-panel"><h2>AI 變更審核紀錄</h2><form method="get"><input type="hidden" name="page" value="site-ai-connector"><input type="hidden" name="tab" value="logs">';
    foreach ( [ 'sac_post' => [ '文章 ID', $id, 'number' ], 'sac_owner' => [ '使用者 ID', $owner, 'number' ],
        'sac_type' => [ '內容類型代碼', $type, 'text' ], 'sac_cache' => [ '快取狀態代碼', $cache, 'text' ],
        'sac_frontend' => [ '前台驗收狀態代碼', $frontend, 'text' ], 'sac_date' => [ '申請日', $date, 'date' ] ] as $name => [ $label, $value, $input_type ] ) {
        echo '<label>' . esc_html( $label ) . ' <input type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ?: '' ) . '"></label> ';
    }
    echo '<label>審核狀態 <select name="sac_status"><option value="">全部</option>';
    foreach ( $state_labels as $key => $label ) echo '<option value="' . esc_attr( $key ) . '"' . selected( $status, $key, false ) . '>' . esc_html( $label ) . '</option>';
    echo '</select></label> <button class="button">篩選</button></form><p>最近 60 筆保留中的變更。</p><div style="overflow:auto"><table class="widefat striped sac-table">';
    echo '<thead><tr><th>編號／內容</th><th>授權者／核准時間</th><th>狀態</th><th>快取</th><th>前台</th></tr></thead><tbody>';
    $found = false;
    foreach ( $rows as $row_id ) {
        $row = sac_oauth_row( 'changes', (int) $row_id, true );
        if ( ! $row ) continue;
        $data = $row['payload'];
        if ( ! is_array( $data ) || ! current_user_can( 'edit_post', $data['post_id'] ) ) continue;
        $receipt = sac_change_receipt( $row );
        $local = $data['cache']['cache_purge_status'] ?? '';
        $public = $data['last_public_verification']['frontend'] ?? '';
        $created = wp_date( 'Y-m-d', $data['prepared_at'] ?? $data['approval_expires'] - 30 * MINUTE_IN_SECONDS );
        if ( ( $id && $id !== (int) $data['post_id'] ) || ( $type && $type !== $data['post_type'] ) || ( $status && $status !== $receipt['approval_status'] )
            || ( $cache && $cache !== $local ) || ( $frontend && $frontend !== $public ) || ( $date && $date !== $created ) ) continue;
        $found = true;
        $edit = get_edit_post_link( $data['post_id'], 'raw' );
        echo '<tr><td>#' . (int) $row['id'] . ' · ';
        if ( $edit ) echo '<a href="' . esc_url( $edit ) . '">';
        echo esc_html( $data['post_type'] . ' #' . $data['post_id'] );
        if ( $edit ) echo '</a>';
        echo '</td><td>#' . (int) $row['owner'] . '<br>' . esc_html( $data['confirmed_at'] ?? '—' ) . '</td><td>'
            . esc_html( $state_labels[ $receipt['approval_status'] ] ?? $receipt['approval_status'] ) . '</td><td>' . esc_html( $local ?: '—' )
            . '</td><td>' . esc_html( $public ?: '—' ) . '</td></tr>';
    }
    if ( ! $found ) echo '<tr><td colspan="5">沒有符合條件的變更。</td></tr>';
    echo '</tbody></table></div></section>';
}
