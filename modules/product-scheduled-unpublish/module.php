<?php
/**
 * WooCommerce 商品排程下架
 * 在商品編輯頁「發佈」區塊加入排程下架時間,時間到後自動轉為草稿
 * 並在商品列表新增「排程下架」欄位,方便一覽與排序
 */

defined( 'ABSPATH' ) || exit;

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
	echo '<p style="font-size: 11px; color: #666; margin: 4px 0 0 0;">時間到後自動轉為草稿</p>';
	echo '</div>';
	echo '</div>';
}

// ------------------------------------------------------------------
// 2. 儲存設定,並設定該商品的「專屬鬧鐘」
// ------------------------------------------------------------------
add_action( 'save_post_product', 'wutm_product_save_and_schedule_unpublish_from_box', 10, 3 );
function wutm_product_save_and_schedule_unpublish_from_box( $post_id, $post, $update ) {

	// 避免在自動儲存時觸發
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	// 驗證自訂 nonce,避免非本表單發出的請求誤寫入
	if ( ! isset( $_POST['wu_unpublish_nonce'] ) ||
		! wp_verify_nonce( $_POST['wu_unpublish_nonce'], 'wu_unpublish_nonce_action' ) ) {
		return;
	}

	// 確保使用者有權限編輯
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// 讀取舊值,只有真正變動時才動 cron,減少批次編輯時不必要的資料庫寫入
	$old_time = get_post_meta( $post_id, '_expiration_datetime', true );
	$new_time = isset( $_POST['_expiration_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['_expiration_datetime'] ) ) : '';

	if ( $old_time === $new_time ) {
		return;
	}

	// 時間有變動,先清除這個商品舊的下架排程 (避免舊鬧鐘還在)
	wp_clear_scheduled_hook( 'wutm_product_execute_single_product_unpublish', array( $post_id ) );

	// 如果清空了欄位,刪除資料並結束
	if ( empty( $new_time ) ) {
		delete_post_meta( $post_id, '_expiration_datetime' );
		return;
	}

	// 驗證時間格式是否合法 (datetime-local 格式為 YYYY-MM-DDTHH:MM)
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $new_time ) ) {
		return;
	}

	update_post_meta( $post_id, '_expiration_datetime', $new_time );

	// 轉換時間格式 (補上秒數,並轉為 WordPress 規定的 GMT 時區)
	$formatted_time = str_replace( 'T', ' ', $new_time ) . ':00';
	$gmt_time_string = get_gmt_from_date( $formatted_time );
	$gmt_timestamp   = strtotime( $gmt_time_string );

	// strtotime 失敗會回傳 false,明確排除避免誤判
	if ( ! $gmt_timestamp ) {
		return;
	}

	// 只要設定的時間大於現在時間,就註冊一個專屬鬧鐘
	if ( $gmt_timestamp > time() ) {
		wp_schedule_single_event(
			$gmt_timestamp,
			'wutm_product_execute_single_product_unpublish',
			array( $post_id )
		);
	}
}

// ------------------------------------------------------------------
// 3. 鬧鐘響起時：執行的下架動作
// ------------------------------------------------------------------
add_action( 'wutm_product_execute_single_product_unpublish', 'wutm_product_do_unpublish_single_product' );
function wutm_product_do_unpublish_single_product( $post_id ) {

	$product = wc_get_product( $post_id );

	// 商品不存在 (可能已被刪除) 直接結束
	if ( ! $product ) {
		wutm_product_log_unpublish_result( $post_id, '商品不存在,跳過下架' );
		return;
	}

	// 檢查目前是否還是發佈狀態,避免重複觸發或誤動已被手動下架的商品
	if ( 'publish' !== $product->get_status() ) {
		wutm_product_log_unpublish_result( $post_id, '目前狀態非 publish (' . $product->get_status() . '),跳過下架' );
		return;
	}

	// 透過 WC_Product 物件變更狀態,確保 HPOS 相容與快取正確清除
	$product->set_status( 'draft' );
	$product->save();

	// 下架完成後清除該筆排程時間紀錄,避免殘留舊資料造成混淆
	delete_post_meta( $post_id, '_expiration_datetime' );

	wutm_product_log_unpublish_result( $post_id, '已成功轉為草稿' );
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
	$display_timestamp = strtotime( str_replace( 'T', ' ', $saved_time ) );

	if ( ! $display_timestamp ) {
		echo '<span style="color:#999;">—</span>';
		return;
	}

	$formatted_display = date_i18n( 'Y-m-d H:i', $display_timestamp );
	$is_past           = $display_timestamp < current_time( 'timestamp' );

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

	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( 'wu_unpublish_schedule' !== $query->get( 'orderby' ) ) {
		return;
	}

	$query->set( 'meta_key', '_expiration_datetime' );
	$query->set( 'orderby', 'meta_value' );
}
