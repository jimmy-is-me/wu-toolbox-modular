<?php
/**
 * WooCommerce 商品排程下架
 * 在商品編輯頁「發佈」區塊設定購買截止，時間到保持發佈、只停止購買
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

    $timestamp = wutm_product_unpublish_timestamp($saved_time);
    $text = (string) get_post_meta($post->ID, '_wutm_expired_button_text', true);
    if ($text === '') $text = '此商品已無法購買';
    echo '<div class="misc-pub-section"><span class="dashicons dashicons-clock" style="color:#8c8f94"></span> 購買截止：<b>' . ($timestamp ? esc_html(wp_date(__('M j, Y @ H:i'), $timestamp)) : '未設定') . '</b> <a href="#wutm-cutoff-editor" onclick="document.getElementById(\'wutm-cutoff-editor\').hidden=!document.getElementById(\'wutm-cutoff-editor\').hidden;return false">編輯</a>';
    echo '<div id="wutm-cutoff-editor" hidden style="padding-top:8px"><label class="screen-reader-text" for="_expiration_datetime">購買截止時間</label><input type="datetime-local" id="_expiration_datetime" name="_expiration_datetime" value="' . esc_attr($saved_time) . '" style="width:100%">';
    echo '<p class="description">依網站時區 ' . esc_html(wp_timezone_string()) . '；清空可取消。更新商品後生效。</p><label for="_wutm_expired_button_text">截止後按鈕文字</label><input type="text" id="_wutm_expired_button_text" name="_wutm_expired_button_text" value="' . esc_attr($text) . '" style="width:100%"></div>';
    echo '<p class="description">到期維持已發佈，只停止購買。</p></div>';

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
			$new_columns['wu_unpublish_schedule'] = '購買截止';
		}
	}

	if ( ! isset( $new_columns['wu_unpublish_schedule'] ) ) {
		$new_columns['wu_unpublish_schedule'] = '購買截止';
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
		echo '<span class="dashicons dashicons-warning" style="color:#dc3232;"></span> ' . esc_html( $formatted_display ) . '（已截止購買）';
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
