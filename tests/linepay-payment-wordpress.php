<?php
if ( ! defined( 'WP_CLI' ) || getenv( 'WUTM_MEDIA_INTEGRATION' ) !== '1' || DB_NAME !== 'wci_fixture' ) {
	throw new RuntimeException( 'Disposable WordPress fixture only.' );
}

use Automattic\WooCommerce\Utilities\OrderUtil;

function linepay427_assert( $ok, $message ) {
	if ( ! $ok ) throw new RuntimeException( $message );
}

final class Linepay427_Redirect extends Error {}
final class Linepay427_Ajax_Done extends Error {}

linepay427_assert( defined( 'WUTM_LINEPAY_VERSION' ) && WUTM_LINEPAY_VERSION === '1.4.0', 'Bundled gateway loads version 1.4.0.' );
linepay427_assert( has_action( 'woocommerce_api_linepay_payment' ) && has_action( 'wp_ajax_linepay_confirm' ) && has_action( 'wp_ajax_linepay_check' ), 'Gateway registers real WooCommerce and admin entry points.' );

$transactions = [
	'2026112600000000001', '2026112600000000002', '2026112600000000003',
	'2026112600000000004', '2026112600000000005',
];
$requests = $orders = [];
$check_status = LINEPayStatusCode::AUTHED;
$confirmed = 0;
$admin = get_current_user_id();
$subscriber = 0;
$original_env = LINEPay_TW::$env_status;
$original_channels = LINEPay_TW::$channel_info;
$original_log = LINEPay_TW::$log_enabled;
$original_detail_notes = LINEPay_TW::$detail_payment_status_note_enabled;
$original_get = $_GET;
$original_post = $_POST;
$original_method = $_SERVER['REQUEST_METHOD'] ?? null;
LINEPay_TW::$env_status = WPBR_LINEPay_Const::ENV_SANDBOX;
LINEPay_TW::$channel_info[WPBR_LINEPay_Const::ENV_SANDBOX] = ['channel_id'=>'fixture-channel','channel_secret'=>'fixture-only'];
LINEPay_TW::$log_enabled = false;
LINEPay_TW::$detail_payment_status_note_enabled = false;

$mock = static function ( $response, $args, $url ) use ( &$requests, &$check_status, &$confirmed, $transactions ) {
	$path = wp_parse_url( $url, PHP_URL_PATH );
	if ( wp_parse_url( $url, PHP_URL_HOST ) !== 'sandbox-api-pay.line.me' ) {
		return new WP_Error( 'fixture_only', 'Non-fixture HTTP blocked.' );
	}
	$requests[] = [$path, $args];
	if ( $path === '/v3/payments/request' ) {
		$payload = ['returnCode'=>'0000','info'=>['transactionId'=>$transactions[0],'paymentUrl'=>['web'=>'https://checkout.example.test/linepay']]];
	} elseif ( str_ends_with( $path, '/check' ) ) {
		$payload = ['returnCode'=>$check_status,'returnMessage'=>'Fixture status'];
	} elseif ( str_ends_with( $path, '/confirm' ) ) {
		++$confirmed;
		$transaction = basename( dirname( $path ) );
		$payload = ['returnCode'=>'0000','info'=>['transactionId'=>$transaction,'payInfo'=>[['amount'=>120]]]];
	} elseif ( str_ends_with( $path, '/refund' ) ) {
		$payload = ['returnCode'=>'0000','info'=>['refundTransactionId'=>$transactions[4],'refundAmount'=>20]];
	} else {
		throw new RuntimeException( 'Unexpected mocked LINE Pay request.' );
	}
	return ['response'=>['code'=>200], 'body'=>wp_json_encode( $payload ), 'headers'=>[]];
};
$no_mail = static fn() => true;
$redirect = static function ( $location ) { throw new Linepay427_Redirect( $location ); };
$ajax_die = static fn() => static function () { throw new Linepay427_Ajax_Done(); };
add_filter( 'pre_http_request', $mock, 99, 3 );
add_filter( 'pre_wp_mail', $no_mail );
add_filter( 'wp_redirect', $redirect, 0 );
add_filter( 'wp_die_ajax_handler', $ajax_die );

$make_order = static function ( $method, $transaction = '', $payment_state = 'reserved' ) use ( &$orders ) {
	$order = wc_create_order();
	$order->set_payment_method( $method );
	$order->set_currency( 'TWD' );
	$order->set_total( 120 );
	if ( $transaction !== '' ) $order->update_meta_data( '_linepay_reserved_transaction_id', $transaction );
	if ( $payment_state !== '' ) $order->update_meta_data( '_linepay_payment_status', $payment_state );
	$order->save();
	$orders[] = $order->get_id();
	return $order;
};
$customer_return = static function ( array $args ) {
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_GET = $args;
	try {
		do_action( 'woocommerce_api_linepay_payment' );
	} catch ( Linepay427_Redirect $result ) {
		return $result->getMessage();
	}
	throw new RuntimeException( 'Callback must redirect.' );
};
$admin_action = static function ( $action, int $order_id, bool $nonce = true ) {
	if ( ! defined( 'DOING_AJAX' ) ) define( 'DOING_AJAX', true );
	$_POST = ['post_id'=>(string) $order_id];
	if ( $nonce ) $_POST['security'] = wp_create_nonce( 'linepay-' . $action );
	ob_start();
	try {
		do_action( 'wp_ajax_linepay_' . $action );
	} catch ( Linepay427_Ajax_Done $done ) {
		return json_decode( ob_get_contents(), true, 512, JSON_THROW_ON_ERROR );
	} finally {
		ob_end_clean();
	}
	throw new RuntimeException( 'Admin action must return JSON.' );
};

try {
	$method = WPBR_LINEPay_Const::ID;
	wp_set_current_user( 0 );
	$new = $make_order( $method, '', '' );
	$request = new LINEPay_TW_Request( new LINEPay_TW_Payment() );
	$result = $request->request( $new->get_id() );
	linepay427_assert( $result['redirect'] === 'https://checkout.example.test/linepay', 'Valid payment request returns the mocked checkout destination.' );
	$body = json_decode( $requests[0][1]['body'], true, 512, JSON_THROW_ON_ERROR );
	$valid = [];
	foreach ( ['confirm','cancel'] as $action ) {
		parse_str( wp_parse_url( $body['redirectUrls'][$action . 'Url'], PHP_URL_QUERY ), $valid[$action] );
		linepay427_assert( $valid[$action]['key'] === $new->get_order_key(), 'Payment creation attaches the order credential to both provider return URLs.' );
	}
	$confirm = $valid['confirm'] + ['transactionId'=>$transactions[0]];
	$before = count( $requests );
	// Invalid fixture credentials cannot reach the gateway or reveal the order through a redirect.
	foreach ( [
		array_diff_key( $confirm, ['key'=>true] ),
		array_replace( $confirm, ['transactionId'=>$transactions[1]] ),
		array_diff_key( $confirm, ['transactionId'=>true] ),
		array_replace( $confirm, ['key'=>[$new->get_order_key()]] ),
		array_replace( $confirm, ['order_id'=>(string) PHP_INT_MAX] ),
	] as $unverified ) {
		linepay427_assert( $customer_return( $unverified ) === wc_get_cart_url(), 'Unverified return leads to the same cart destination.' );
	}
	linepay427_assert( count( $requests ) === $before && wc_get_order( $new->get_id() )->has_status( 'pending' ), 'Rejected requests never reach LINE Pay or change the pending order.' );

	$destination = $customer_return( $confirm );
	$paid = wc_get_order( $new->get_id() );
	linepay427_assert( $paid->is_paid() && $paid->get_transaction_id() === $transactions[0] &&
		$destination === $paid->get_checkout_order_received_url(), 'Verified guest payment is recorded with the exact transaction ID.' );
	linepay427_assert( $customer_return( $confirm ) === $destination && $confirmed === 1, 'A repeated completed return does not confirm again.' );

	$pending = $make_order( $method, $transactions[1] );
	$cancel = ['order_id'=>(string) $pending->get_id(), 'request_type'=>'cancel','key'=>$pending->get_order_key(),'transactionId'=>$transactions[1]];
	linepay427_assert( $customer_return( array_diff_key( $cancel, ['transactionId'=>true] ) ) === wc_get_cart_url() &&
		wc_get_order( $pending->get_id() )->get_meta( '_linepay_payment_status' ) === 'reserved', 'Incomplete cancellation cannot change a reservation.' );
	linepay427_assert( $customer_return( $cancel ) === $pending->get_checkout_order_received_url() &&
		wc_get_order( $pending->get_id() )->get_meta( '_linepay_payment_status' ) === 'cancelled' &&
		wc_get_order( $pending->get_id() )->has_status( 'pending' ), 'Authenticated cancellation ends the reservation and leaves the order payable.' );

	$other = $make_order( 'bacs', $transactions[2], 'authed' );
	$manual = $make_order( $method, $transactions[3] );
	$before = count( $requests );
	$subscriber = wp_create_user( 'linepay427_fixture', wp_generate_password(), 'linepay427@example.test' );
	if ( is_wp_error( $subscriber ) ) throw new RuntimeException( $subscriber->get_error_message() );
	( new WP_User( $subscriber ) )->set_role( 'subscriber' );
	wp_set_current_user( $subscriber );
	linepay427_assert( ! $admin_action( 'confirm', $manual->get_id() )['success'] &&
		! $admin_action( 'check', $manual->get_id() )['success'], 'A valid nonce never confers WooCommerce order edit permission.' );

	wp_set_current_user( $admin );
	linepay427_assert( current_user_can( 'edit_shop_orders' ), 'Fixture administrator can edit WooCommerce orders.' );
	linepay427_assert( ! $admin_action( 'confirm', $manual->get_id(), false )['success'] &&
		! $admin_action( 'check', $manual->get_id(), false )['success'] &&
		! $admin_action( 'confirm', $other->get_id() )['success'] && count( $requests ) === $before, 'Missing nonce and unrelated gateways cannot call LINE Pay.' );

	linepay427_assert( $admin_action( 'check', $manual->get_id() )['success'] &&
		wc_get_order( $manual->get_id() )->get_meta( '_linepay_payment_status' ) === 'authed' &&
		! wc_get_order( $manual->get_id() )->is_paid() && $confirmed === 1, 'Admin status query can record authorization without capturing.' );
	linepay427_assert( $admin_action( 'confirm', $manual->get_id() )['success'] &&
		wc_get_order( $manual->get_id() )->is_paid() && $confirmed === 2, 'Authorized operator can confirm a verified reservation.' );

	$retry = $make_order( $method, $transactions[4], 'authed' );
	LINEPay_TW::get_instance()->handle_confirm_retry( $retry->get_id() );
	LINEPay_TW::get_instance()->handle_confirm_retry( $retry->get_id() );
	linepay427_assert( wc_get_order( $retry->get_id() )->is_paid() && $confirmed === 3, 'Background retry confirms an authorized order exactly once after success.' );
	linepay427_assert( $request->refund( $paid->get_id(), 20 ) === true &&
		in_array( $transactions[4], array_map( 'strval', (array) wc_get_order( $paid->get_id() )->get_meta( '_linepay_refund_transaction_id' ) ), true ),
		'Provider refund still records the transaction in WooCommerce.' );

	$debug_request = new ReflectionMethod( LINEPay_TW_Request::class, 'get_loggable_request_args' );
	$debug_response = new ReflectionMethod( LINEPay_TW_Request::class, 'get_loggable_response' );
	$args = $debug_request->invoke( $request, $requests[0][1], $new );
	$summary = $debug_response->invoke( null, json_decode( wp_json_encode( [
		'returnCode'=>'0000','info'=>['transactionId'=>$transactions[0],'paymentUrl'=>['web'=>'fixture-secret'],
			'paymentAccessToken'=>'fixture-token','payInfo'=>[['maskedCreditCardNumber'=>'fixture-card']]],
	] ) ) );
	$log = wp_json_encode( [$args,$summary] );
	linepay427_assert( ! isset( $args['headers'] ) && ! str_contains( $log, $new->get_order_key() ) &&
		! str_contains( $log, $transactions[0] ) && ! str_contains( $log, 'fixture-token' ) && ! str_contains( $log, 'fixture-card' ) &&
		! str_contains( $log, 'fixture-secret' ), 'Gateway logs omit authorization, order credentials, tokens and card details.' );
} finally {
	remove_filter( 'pre_http_request', $mock, 99 );
	remove_filter( 'pre_wp_mail', $no_mail );
	remove_filter( 'wp_redirect', $redirect, 0 );
	remove_filter( 'wp_die_ajax_handler', $ajax_die );
	LINEPay_TW::$env_status = $original_env;
	LINEPay_TW::$channel_info = $original_channels;
	LINEPay_TW::$log_enabled = $original_log;
	LINEPay_TW::$detail_payment_status_note_enabled = $original_detail_notes;
	$_GET = $original_get;
	$_POST = $original_post;
	if ( $original_method === null ) unset( $_SERVER['REQUEST_METHOD'] );
	else $_SERVER['REQUEST_METHOD'] = $original_method;
	wp_set_current_user( $admin );
	foreach ( $orders as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) $order->delete( true );
	}
	if ( $subscriber && ! is_wp_error( $subscriber ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $subscriber );
	}
}

echo 'LINE Pay callback, guest checkout, admin authorization, and log protection passed (' .
	( OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'legacy' ) . ").\n";
