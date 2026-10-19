<?php
/**
 * WooCommerce 商品排程下架
 * 在商品編輯頁「發佈」區塊加入排程下架時間,時間到後自動轉為草稿
 * 並在商品列表新增「排程下架」欄位,方便一覽與排序
 */

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/scheduler.php';

// ------------------------------------------------------------------
// 1. 在「發佈」區塊內加入「排程下架」設定
// ------------------------------------------------------------------
add_action( 'post_submitbox_misc_actions', 'wutm_product_add_unpublish_field_to_publish_box' );
function wutm_product_add_unpublish_field_to_publish_box( $post ) {

	// 只在 WooCommerce 的「商品(product)」頁面顯示
	if ( 'product' !== $post->post_type ) {
		return;
	}

	// 取得先前儲存的時間
	$saved_time = get_post_meta( $post->ID, '_expiration_datetime', true );

	// 自訂 nonce,獨立於編輯頁整體 nonce,避免第三方繞過寫入
	wp_nonce_field( 'wu_unpublish_nonce_action', 'wu_unpublish_nonce' );

	echo '<div class="misc-pub-section misc-pub-unpublish" style="border-top: 1px solid #eee; margin-top: 10px; padding-top: 10px;">';
	echo '<span class="dashicons dashicons-clock" style="color: #82878c; line-height: 20px; padding-right: 3px;"></span> ';
	echo '<label for="_expiration_datetime"><strong>排程下架：</strong></label>';
	echo '<div style="margin-top: 5px;">';
	echo '<input type="datetime-local" id="_expiration_datetime" name="_expiration_datetime" value="' . esc_attr( $saved_time ) . '" style="width: 100%;" />';
	echo '<p style="font-size:11px;color:#666;margin:4px 0">依網站時區：' . esc_html(wp_timezone_string()) . '。儲存更新後生效，到期轉為草稿；清空可取消。</p>';
    $timestamp = wutm_product_unpublish_timestamp($saved_time);
    $next = wp_next_scheduled(WUTM_UNPUBLISH_HOOK, [(int) $post->ID]);
    if ($timestamp) {
        $hint = get_post_status($post->ID) !== 'publish' ? '商品目前未發佈；重新發佈時會重新檢查。' : ($timestamp <= time() ? '已到期，等待下架／補執行。' : ($next ? '已建立排程：' . wp_date('Y-m-d H:i', $next) : '排程尚未建立，請重新儲存「更新」以修復。'));
        echo '<p class="description">' . esc_html($hint) . '</p>';
    }
    $result = get_post_meta($post->ID, '_wutm_unpublish_result', true);
    if (is_array($result) && !empty($result['message'])) echo '<p class="description">最近處理：' . esc_html($result['message']) . '（' . esc_html(wp_date('Y-m-d H:i', (int) $result['time'])) . '）</p>';
    echo '<p class="description">WP-Cron 需網站請求觸發，非精準計時器；需要準時下架時，請由主機每分鐘執行 WordPress 排程，並確認頁面／CDN 快取清除正常。</p>';
    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) echo '<p style="color:#b32d2e">此站已停用內建 WP-Cron，請確認主機排程已設定；否則背景下架可能延遲。</p>';
	echo '</div>';
	echo '</div>';
}

// ------------------------------------------------------------------
// 4. 簡易 Log,方便排查鬧鐘是否確實執行
// ------------------------------------------------------------------
function wutm_product_log_unpublish_result( $post_id, $message ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( sprintf(
			'[排程下架] 商品 ID %d - %s - 時間：%s',
			$post_id,
			$message,
			current_time( 'Y-m-d H:i:s' )
		) );
	}
}

// ------------------------------------------------------------------
// 5. 商品列表新增「排程下架」欄位
// ------------------------------------------------------------------
add_filter( 'manage_edit-product_columns', 'wutm_product_add_unpublish_column_to_product_list' );
function wutm_product_add_unpublish_column_to_product_list( $columns ) {

	$new_columns = array();

	foreach ( $columns as $key => $label ) {
		$new_columns[ $key ] = $label;

		// 插入在「日期」欄位之前,若找不到日期欄位則最後補上
		if ( 'date' === $key ) {
			$new_columns['wu_unpublish_schedule'] = '排程下架';
		}
	}

	if ( ! isset( $new_columns['wu_unpublish_schedule'] ) ) {
		$new_columns['wu_unpublish_schedule'] = '排程下架';
	}

	return $new_columns;
}

add_action( 'manage_product_posts_custom_column', 'wutm_product_render_unpublish_column_content', 10, 2 );
function wutm_product_render_unpublish_column_content( $column, $post_id ) {

	if ( 'wu_unpublish_schedule' !== $column ) {
		return;
	}

	$saved_time = get_post_meta( $post_id, '_expiration_datetime', true );

	if ( empty( $saved_time ) ) {
		echo '<span style="color:#999;">—</span>';
		return;
	}

	// 轉換成易讀格式顯示
	$display_timestamp = wutm_product_unpublish_timestamp($saved_time);

	if ( ! $display_timestamp ) {
		echo '<span style="color:#999;">—</span>';
		return;
	}

	$formatted_display = wp_date( 'Y-m-d H:i', $display_timestamp );
	$is_past           = $display_timestamp <= time();

	// 確認該商品目前是否還是 publish 狀態,搭配時間判斷顯示不同顏色提示
	$status = get_post_status( $post_id );

	if ( 'publish' !== $status ) {
		echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span> 已下架';
	} elseif ( $is_past ) {
		echo '<span class="dashicons dashicons-warning" style="color:#dc3232;"></span> ' . esc_html( $formatted_display ) . '（逾時待執行）';
	} else {
		echo '<span class="dashicons dashicons-clock" style="color:#82878c;"></span> ' . esc_html( $formatted_display );
	}
}

// ------------------------------------------------------------------
// 6. 讓「排程下架」欄位可排序
// ------------------------------------------------------------------
add_filter( 'manage_edit-product_sortable_columns', 'wutm_product_make_unpublish_column_sortable' );
function wutm_product_make_unpublish_column_sortable( $columns ) {
	$columns['wu_unpublish_schedule'] = 'wu_unpublish_schedule';
	return $columns;
}

add_action( 'pre_get_posts', 'wutm_product_handle_unpublish_column_sorting' );
function wutm_product_handle_unpublish_column_sorting( $query ) {

	if ( ! is_admin() || ! $query->is_main_query() || $query->get('post_type') !== 'product' ) {
		return;
	}

	if ( 'wu_unpublish_schedule' !== $query->get( 'orderby' ) ) {
		return;
	}

	$query->set( 'meta_key', '_expiration_datetime' );
	$query->set( 'orderby', 'meta_value' );
}
