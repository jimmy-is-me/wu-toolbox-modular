<?php

namespace WPBrewer\Payuni\Payment\Admin;

use WPBrewer\Payuni\Payment\PayuniPayment;
use WPBrewer\Payuni\Payment\Utils\OrderMeta;
use WPBrewer\Payuni\Payment\Utils\SingletonTrait;

class OrderList {

    use SingletonTrait;

	/**
	 * Initialize class andd add hooks
	 *
	 * @return void
	 */
	public static function init() {
		self::get_instance();

		add_filter('manage_shop_order_posts_columns', array( self::get_instance(), 'shop_order_columns'), 20, 1);
		add_action('manage_shop_order_posts_custom_column', array( self::get_instance(), 'shop_order_column'), 20, 2);
	}

	public function shop_order_columns($columns) {

        if (  ! PayuniPayment::$einvoice_enabled ) {
            return $columns;
        }

        if (  $columns['wmp_invoice_no'] ) {
            unset($columns['wmp_invoice_no']);
        }

        $add_index = array_search('shipping_address', array_keys( $columns ) ) + 1;
		$pre_array = array_splice($columns, 0, $add_index);
		$new_columns = array(
			'wpbr_payuni_invoice_no' => __( 'Invoice No', 'wpbr-payuni-payment' ),
        );
		return array_merge($pre_array, $new_columns, $columns);
	}

    public function shop_order_column($column, $post_id) {
        if ( 'wpbr_payuni_invoice_no' === $column ) {
            $order = wc_get_order( $post_id );
            $invoice_no = $order->get_meta( OrderMeta::EINVOICE_NO );
            if ( $invoice_no ) {
                echo $invoice_no;
            } else {
                echo __( 'Unissue', 'wpbr-payuni-payment' );
            }
        }
    }
}
