<?php
class WU_NP_NewebPay_Shipping_Meta_Box {

	public static function add_meta_box( $post_type, $post ) {
		// HPOS 相容：同時支援 shop_order 和 HPOS screen ID
		if ( in_array( $post_type, WU_NP_HPOS_Helper::get_order_screen_ids(), true ) ) {
			global $theorder;
			if ( ! is_object( $theorder ) ) {
				$order = WU_NP_HPOS_Helper::get_order( $post );
				if ( $order ) {
					$theorder = $order;
				}
			}

			if ( ! is_object( $theorder ) ) {
				return;
			}

			foreach ( $theorder->get_items( 'shipping' ) as $item_id => $item ) {
				if ( WU_NP_NewebPay_Shipping::get_order_support_shipping( $item ) !== false ) {
					// HPOS 相容：為所有 screen ID 註冊 meta box
					foreach ( WU_NP_HPOS_Helper::get_order_screen_ids() as $screen ) {
						add_meta_box( 'wu-np-newebpay-shipping-info', __( 'NewebPay shipping info', 'wu-newebpay-tools' ), [ __CLASS__, 'output' ], $screen, 'normal', 'high' );
					}
					break;
				}
			}
		}
	}

	public static function output( $post ) {
		global $theorder;

		// HPOS 相容：使用 Helper 取得訂單物件
		if ( ! is_object( $theorder ) ) {
			$theorder = WU_NP_HPOS_Helper::get_order( $post );
		}

		if ( ! $theorder ) {
			return;
		}

		$shipping_list = $theorder->get_meta( '_wu_np_newebpay_shipping_info', true );
		if ( ! is_array( $shipping_list ) ) {
			$shipping_list = [];
		} ?>
<table cellpadding="0" cellspacing="0" class="widefat">
	<thead>
		<tr>
			<th><?php echo __( 'NewebPay shipping ID', 'wu-newebpay-tools' ); ?>
			</th>
			<th><?php echo __( 'Store ID', 'wu-newebpay-tools' ); ?>
			</th>
			<th><?php echo __( 'declare amount', 'wu-newebpay-tools' ); ?>
			</th>
			<th><?php echo __( 'Collection of money', 'wu-newebpay-tools' ); ?>
			</th>
			<th><?php echo __( 'Shipping status last change time', 'wu-newebpay-tools' ); ?>
			</th>
			<th><?php echo __( 'Shipping create time', 'wu-newebpay-tools' ); ?>
			</th>
		</tr>
	</thead>
	<tbody>
		<?php
		foreach ( $shipping_list as $item ) {
			$item['edit']   = wc_string_to_datetime( $item['edit'] );
			$item['create'] = wc_string_to_datetime( $item['create'] );
			?>
		<tr>
			<td><?php echo $item['ID']; ?>
			</td>
			<td><?php echo $item['store_ID']; ?>
			</td>
			<td><?php echo $item['amount']; ?>
			</td>
			<td><?php echo ( $item['IsCollection'] == '1' ) ? __( 'Yes' ) : __( 'No' ); ?>
			</td>
			<?php /* translators: %1$s: date %2$s: time */ ?>
			<td><?php printf( _x( '%1$s %2$s', 'Datetime', 'wu-newebpay-tools' ), $item['edit']->date_i18n( wc_date_format() ), $item['edit']->date_i18n( wc_time_format() ) ); ?>
			</td>
			<td><?php printf( _x( '%1$s %2$s', 'Datetime', 'wu-newebpay-tools' ), $item['create']->date_i18n( wc_date_format() ), $item['create']->date_i18n( wc_time_format() ) ); ?>
			</td>
		</tr>
			<?php
		}
		?>
	</tbody>
</table>
		<?php
	}
}
