<?php
/**
 * This template can be overridden by copying it to yourtheme/woocommerce/order/order-newebpay-payment-info-atm.php
 *
 * HOWEVER, on occasion WU NewebPay Tools will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @version 1.2.1
 */
defined( 'ABSPATH' ) || exit;

if ( $order->get_payment_method() != 'wu_np_newebpay_atm' ) {
	return;
}

if ( $order->get_meta( '_newebpay_payment_type' ) != 'VACC' ) {
	return;
}
?>
<section class="woocommerce-order-details">
	<h2 class="woocommerce-order-details__title">
		<?php echo __( 'Payment details', 'wu-newebpay-tools' ); ?>
	</h2>
	<table class="woocommerce-table woocommerce-table--payment-details payment_details">
		<tbody>
			<tr>
				<td>
					<?php echo __( 'Bank', 'wu-newebpay-tools' ); ?>
				</td>
				<td>
					<?php echo _x( $order->get_meta( '_newebpay_atm_BankCode' ), 'Bank code', 'wu-newebpay-tools' ); ?>
				</td>
			</tr>
			<tr>
				<td>
					<?php echo __( 'Bank code', 'wu-newebpay-tools' ); ?>
				</td>
				<td>
					<?php echo $order->get_meta( '_newebpay_atm_BankCode' ); ?>
				</td>
			</tr>
			<tr>
				<td>
					<?php echo __( 'ATM Bank account', 'wu-newebpay-tools' ); ?>
				</td>
				<td>
					<?php echo wordwrap( $order->get_meta( '_newebpay_atm_vAccount' ), 4, '<span> </span>', true ); ?>
				</td>
			</tr>
			<tr>
				<td>
					<?php echo __( 'Payment deadline', 'wu-newebpay-tools' ); ?>
				</td>
				<td>
					<?php $expireDate = wc_string_to_datetime( $order->get_meta( '_newebpay_atm_ExpireDate' ) ); ?>
					<?php echo $expireDate->date_i18n( wc_date_format() ); ?>
				</td>
			</tr>
		</tbody>
	</table>
</section>
