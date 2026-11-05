<?php
/**
 * A class to display and handle early renewal requests via the modal.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WCS_Early_Renewal
 * @category   Class
 * @since      2.6.0
 */

use Automattic\WooCommerce_Subscriptions\Internal\Concurrency\Subscription_Lock;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Early_Renewal_Modal_Handler {

	/**
	 * The option name prefix an early renewal lock is stored under.
	 *
	 * @since 9.3.0
	 * @internal This constant may be modified, moved or removed in future releases.
	 */
	public const LOCK_KEY_PREFIX = 'woocommerce_subscriptions_early_renewal_lock_';

	/**
	 * How long an early renewal lock is honoured for, in seconds.
	 *
	 * A lock released too early risks a second charge; a lock held too long only delays a retry after a request which
	 * died mid-payment. Ten minutes is well beyond gateway HTTP timeouts. max_execution_time is no guide, because on
	 * Linux it doesn't count time spent waiting for the gateway to respond.
	 *
	 * @since 9.3.0
	 * @internal This constant may be modified, moved or removed in future releases.
	 */
	public const LOCK_TTL_SECONDS = 10 * MINUTE_IN_SECONDS;

	/**
	 * The meta key linking a renewal order to the subscription it renews.
	 *
	 * @since 9.3.0
	 * @internal This constant may be modified, moved or removed in future releases.
	 */
	public const RENEWAL_RELATION_META_KEY = '_subscription_renewal';

	/**
	 * Attach callbacks.
	 *
	 * @since 2.6.0
	 */
	public static function init() {
		add_action( 'woocommerce_subscription_details_table', array( __CLASS__, 'maybe_print_early_renewal_modal' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'process_early_renewal_request' ), 20 );
	}

	/**
	 * Prints the early renewal modal for a specific subscription. If eligible.
	 *
	 * @since 2.6.0
	 *
	 * @param WC_Subscription $subscription The subscription to print the modal for.
	 */
	public static function maybe_print_early_renewal_modal( $subscription ) {
		if ( ! self::can_user_renew_early_via_modal( $subscription ) ) {
			return;
		}

		$place_order_action = array(
			'text'       => __( 'Pay now', 'woocommerce-subscriptions' ),
			'attributes' => array(
				'id'    => 'early_renewal_modal_submit',
				'class' => 'button alt',
				'href'  => add_query_arg( array(
					'subscription_id'       => $subscription->get_id(),
					'process_early_renewal' => true,
					'wcs_nonce'             => wp_create_nonce( self::get_nonce_action( $subscription ) ),
				) ),
				'data-payment-method' => $subscription->get_payment_method(),
			),
		);

		if ( wc_wp_theme_get_element_class_name( 'button' ) ) {
			$place_order_action['attributes']['class'] .= ' ' . wc_wp_theme_get_element_class_name( 'button' );
			$place_order_action['attributes']['role']   = 'button';
		}

		$callback_args = array(
			'callback'   => array( __CLASS__, 'output_early_renewal_modal' ),
			'parameters' => array( 'subscription' => $subscription ),
		);

		$modal = new WCS_Modal( $callback_args, '.subscription_renewal_early', 'callback', __( 'Renew early', 'woocommerce-subscriptions' ) );
		// Set the modal ID to match the predictable value used for aria-controls in subscription-details.php
		$modal->set_id( 'wcs-early-renewal-modal-' . $subscription->get_id() );
		$modal->add_action( $place_order_action );
		$modal->print_html();
	}

	/**
	 * Prints the early renewal modal HTML.
	 *
	 * @since 2.6.0
	 * @param WC_Subscription $subscription The subscription to print the modal for.
	 */
	public static function output_early_renewal_modal( $subscription ) {
		$totals       = $subscription->get_order_item_totals();
		$date_changes = WCS_Early_Renewal_Manager::get_dates_to_update( $subscription );

		if ( isset( $totals['payment_method'] ) ) {
			$totals['payment_method']['label'] = __( 'Payment:', 'woocommerce-subscriptions' );
		}

		// Convert the new next payment date into the site's timezone.
		if ( ! empty( $date_changes['next_payment'] ) ) {
			$new_next_payment_date = new WC_DateTime( $date_changes['next_payment'], new DateTimeZone( 'UTC' ) );
			$new_next_payment_date->setTimezone( new DateTimeZone( wc_timezone_string() ) );
		} else {
			$new_next_payment_date = null;
		}

		wc_get_template(
			'html-early-renewal-modal-content.php',
			array(
				'subscription'          => $subscription,
				'totals'                => $totals,
				'new_next_payment_date' => $new_next_payment_date,
			),
			'',
			WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'templates/' )
		);
	}

	/**
	 * Processes the request to renew early via the modal.
	 *
	 * @since 2.6.0
	 */
	public static function process_early_renewal_request() {
		if ( ! isset( $_GET['process_early_renewal'], $_GET['subscription_id'], $_GET['wcs_nonce'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is verified below, once the subscription it is tied to is known.
		$subscription = wcs_get_subscription( absint( $_GET['subscription_id'] ) );

		// A request from another site does nothing but redirect. Only a user who may renew this subscription is told
		// their link has expired, which is the only reason their own link fails.
		if ( ! $subscription || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['wcs_nonce'] ) ), self::get_nonce_action( $subscription ) ) ) {
			if ( $subscription && self::can_user_renew_early_via_modal( $subscription ) ) {
				wc_add_notice( __( 'That early renewal link has expired. To renew early, use the "Renew now" button again.', 'woocommerce-subscriptions' ), 'notice' );
			}

			self::redirect();
		}

		// Whether the store offers this at all is two options and two public filters, with no query behind it. It is
		// settled first so that a store with the feature off never pays for the read below, which scans on the classic
		// order store. The cost is that those two filters run inside the window this method otherwise keeps short, and
		// that they run again in the eligibility check further down.
		if ( ! WCS_Early_Renewal_Manager::is_early_renewal_via_modal_enabled() ) {
			self::refuse_ineligible_request();
		}

		// The newest renewal order at the moment this link was accepted, read exactly as the check made once the lock
		// is held, so that the two values are comparable. It is read as early as it can be, because everything between
		// accepting the link and this read is time in which another request can renew unnoticed, and the eligibility
		// check below loads products and the gateway and fires several public filters. Three things precede it and are
		// accepted: verifying the nonce, the woocommerce_subscription_last_order filter which computing that nonce
		// fires, and the store-wide check just above. Every request whose nonce still verifies pays for this read,
		// including one eligibility then refuses.
		$verified_newest_order_id = self::get_newest_renewal_order_id( $subscription );

		if ( null === $verified_newest_order_id ) {
			self::refuse_unreadable_request();
		}

		if ( ! self::can_user_renew_early_via_modal( $subscription ) ) {
			self::refuse_ineligible_request();
		}

		// Only one early renewal made through the modal may be in flight for a subscription at a time. Two requests
		// which arrive together would otherwise each create an order, each charge the payment method on file, and each
		// move the schedule on by a single period: two charges for one period. The lock doesn't cover scheduled
		// renewals or early renewals paid at checkout.
		$lock          = self::get_lock();
		$lock_acquired = $lock->acquire( $subscription->get_id() );

		if ( ! $lock_acquired ) {
			self::refuse_in_progress_request();
		}

		// A gateway callback which exits, or a fatal error, skips the finally block below. Releasing at shutdown too
		// keeps the lock from being held until it expires; releasing a lock twice does nothing.
		register_shutdown_function( array( $lock, 'release' ), $subscription->get_id() );

		// Another request may have created an early renewal order after this one accepted its link but before it took
		// the lock. A read this request can't trust is treated as a renewal in progress: turning a customer away is
		// recoverable, charging them twice is not. A stale read replica is a known limit rather than something this
		// covers - it answers successfully with an older ID, which reads as no new order.
		$newest_order_id = self::get_newest_renewal_order_id( $subscription );

		if ( null === $newest_order_id ) {
			$lock->release( $subscription->get_id() );
			self::refuse_unreadable_request();
		}

		if ( $newest_order_id > $verified_newest_order_id ) {
			$lock->release( $subscription->get_id() );
			self::refuse_in_progress_request();
		}

		// Before processing the request, detach the functions which handle standard renewal orders. Note we don't need to reattach them as this request will terminate soon.
		self::detach_renewal_callbacks();

		try {
			$redirect_url = self::process_early_renewal_payment( $subscription );
		} finally {
			// Released whether or not payment succeeded: a subscription whose payment failed must remain renewable.
			$lock->release( $subscription->get_id() );
		}

		if ( '' !== $redirect_url ) {
			wp_safe_redirect( $redirect_url );
			exit();
		}

		self::redirect();
	}

	/**
	 * Creates an early renewal order, charges it, and updates the subscription when it's paid.
	 *
	 * Callers must hold the subscription's early renewal lock. Customer facing feedback is added as notices.
	 *
	 * @since 9.3.0
	 *
	 * @param WC_Subscription $subscription The subscription being renewed early.
	 * @return string The URL to send the customer to, or an empty string to use the default redirect.
	 */
	private static function process_early_renewal_payment( $subscription ) {
		$renewal_order = wcs_create_renewal_order( $subscription );

		if ( ! wcs_is_order( $renewal_order ) ) {
			wc_add_notice( __( "We couldn't create a renewal order for your subscription, please try again.", 'woocommerce-subscriptions' ), 'error' );
			return '';
		}

		$renewal_order->set_payment_method( wc_get_payment_gateway_by_order( $subscription ) );
		$renewal_order->update_meta_data( '_subscription_renewal_early', $subscription->get_id() );
		$renewal_order->save();

		// Attempt to collect payment with the subscription's current payment method.
		WC_Subscriptions_Payment_Gateways::trigger_gateway_renewal_payment_hook( $renewal_order );

		// Now that we've attempted to process the payment, refresh the order.
		$renewal_order = wc_get_order( $renewal_order->get_id() );

		// Failed early renewals won't place the subscription on-hold so delete unsuccessful early renewal orders and redirect the user to complete the payment via checkout.
		if ( $renewal_order->needs_payment() ) {
			$renewal_order->delete( true );
			wc_add_notice( __( 'Payment for the renewal order was unsuccessful with your payment method on file, please try again.', 'woocommerce-subscriptions' ), 'error' );
			return wcs_get_early_renewal_url( $subscription );
		}

		// Paid early renewals trigger the subscription payment complete hooks, extend next payment dates and reset suspension counts and user roles.
		// Orders which are on-hold (manual payment or auth/capture gateways) will be handled when the order eventually is marked as payment complete (process/completed).
		if ( $renewal_order->is_paid() ) {
			// Trigger the subscription payment complete hooks and reset suspension counts and user roles.
			$subscription->payment_complete();

			wcs_update_dates_after_early_renewal( $subscription, $renewal_order );
			wc_add_notice( __( 'Your early renewal order was successful.', 'woocommerce-subscriptions' ), 'success' );
		}

		return '';
	}

	/**
	 * Returns the nonce action for an early renewal request made via the modal.
	 *
	 * The action includes the subscription's next payment date and its most recent order, so a link only works for the
	 * renewal it was issued for. Creating the early renewal order changes the most recent order, so the link stops
	 * working even while that order awaits payment confirmation, or when the renewal doesn't move the next payment
	 * date (as happens when the next payment is overdue). An order deleted after a failed payment restores the
	 * previous value, so the customer can try again.
	 *
	 * @since 9.3.0
	 * @internal This method may be modified, moved or removed in future releases.
	 *
	 * @param WC_Subscription $subscription The subscription being renewed early.
	 * @return string
	 */
	public static function get_nonce_action( $subscription ) {
		return sprintf(
			'woocommerce_subscriptions_renew_early_modal_%1$d_%2$d_%3$d',
			$subscription->get_id(),
			$subscription->get_time( 'next_payment' ),
			(int) $subscription->get_last_order( 'ids', array( 'parent', 'renewal' ) )
		);
	}

	/**
	 * Returns the ID of the subscription's newest renewal order, read from the database rather than any cache.
	 *
	 * @since 9.3.0
	 *
	 * @param WC_Subscription $subscription The subscription being renewed early.
	 * @return int|null The order ID, 0 if the subscription has no renewal orders, or null if the read failed.
	 */
	private static function get_newest_renewal_order_id( $subscription ) {
		global $wpdb;

		// Read the relation straight from the database, in one query. wc_get_orders() is not usable here: on the
		// classic order store it runs a WP_Query whose results are cached, so the second of these two identical reads
		// is answered from that cache and can only ever return what the first one did.
		//
		// Only the relation meta is matched. Order type and status are left out deliberately: nothing but the renewal
		// machinery writes this key, order IDs only ever climb, and both reads treat every row the same way, so a row
		// either read can see cannot produce a false "no new order".
		//
		// Cost: wc_orders_meta indexes meta_key and meta_value together, so the HPOS read is a lookup. wp_postmeta
		// indexes the key alone, so on the classic store this scans that key's rows, twice per Pay now click.
		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$table  = $wpdb->prefix . 'wc_orders_meta';
			$column = 'order_id';
		} else {
			$table  = $wpdb->postmeta;
			$column = 'post_id';
		}

		$sql = $wpdb->prepare(
			"SELECT {$column} FROM {$table} WHERE meta_key = %s AND meta_value = %s ORDER BY {$column} DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::RENEWAL_RELATION_META_KEY,
			(string) $subscription->get_id()
		);

		// The pairing this read is asking about. Whatever reaches the database has to still contain it: decoration
		// around the statement is fine, a statement that asks something else is not. The subscription is bound as
		// well as the key, because other queries name that key for other subscriptions.
		$relation = $wpdb->prepare( 'meta_key = %s AND meta_value = %s', self::RENEWAL_RELATION_META_KEY, (string) $subscription->get_id() );

		// What the database is finally asked is not always what is written above: core unescapes prepare()'s
		// placeholders on the 'query' filter, and a tracing or routing layer may add to the statement. Running last on
		// that filter records the statement as handed over, so the check below compares like with like instead of
		// refusing every read on such a store.
		$issued  = null;
		$capture = function ( $query ) use ( &$issued ) {
			$issued = $query;

			return $query;
		};

		add_filter( 'query', $capture, PHP_INT_MAX );

		// This assumes $wpdb counts queries and records the last one as core does. A layer which records the statement
		// but answers it from a cache of its own, without counting a query, is caught by the counter; one which counts
		// and records but still answers from a cache would pass both checks and could hand back a stale row.
		$queries_before = $wpdb->num_queries;

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Deliberate: a cached read can't answer whether another request has just created an order, and the statement is prepared above.
			$order_id = $wpdb->get_var( $sql );
		} finally {
			remove_filter( 'query', $capture, PHP_INT_MAX );
		}

		// A database layer which answers queries itself may never fire that filter, in which case what was issued is
		// what was built here.
		$executed = null === $issued ? $sql : $issued;

		// Checked here, immediately after the read, and not moved: an empty result would read as "no renewal order
		// exists", which is exactly the answer that lets a second charge through. wpdb::query() can return before it
		// clears the previous error - when the connection isn't ready, or when a callback on the 'query' filter empties
		// the query - leaving a stale error and a stale result behind. So a usable answer needs two things: a query
		// ran, and what was actually issued still asks about this subscription's renewal orders.
		//
		// On the connection-not-ready path the filter never runs, so the pairing is trivially still there and the
		// counter is the only thing refusing. The pairing stops a statement which drops or retargets it - including
		// one asking the same question about another subscription. What it cannot stop is a callback which keeps this
		// subscription's pairing and still answers something else. Such a callback also sees the lock's own queries on
		// this filter, so it could produce the same duplicate charge without touching this read at all.
		$read_ran = $wpdb->num_queries !== $queries_before && false !== strpos( $executed, $relation );

		if ( ! $read_ran || '' !== $wpdb->last_error ) {
			if ( $wpdb->num_queries === $queries_before ) {
				$reason = 'no query ran';
			} elseif ( ! $read_ran ) {
				$reason = 'the query which ran asked something else: ' . $executed;
			} else {
				$reason = 'the query failed';
			}

			if ( '' !== $wpdb->last_error ) {
				$reason .= ' — ' . $wpdb->last_error;
			}

			wc_get_logger()->error(
				sprintf( 'Could not read the newest renewal order — subscription=%1$d reason=%2$s', $subscription->get_id(), $reason ),
				array(
					'source'          => 'wcs-early-renewal',
					'subscription_id' => $subscription->get_id(),
				)
			);

			return null;
		}

		return null === $order_id ? 0 : (int) $order_id;
	}

	/**
	 * Turns an early renewal request away, telling the customer a renewal is already being processed.
	 *
	 * @since 9.3.0
	 *
	 * @return never
	 */
	private static function refuse_in_progress_request() {
		wc_add_notice( __( 'Your early renewal is already being processed. Please check your account in a moment.', 'woocommerce-subscriptions' ), 'error' );
		self::redirect();
	}

	/**
	 * Turns an early renewal request away because early renewal through the modal isn't available: either the store
	 * has it turned off, or this subscription can't be renewed early right now.
	 *
	 * @since 9.3.0
	 *
	 * @return never
	 */
	private static function refuse_ineligible_request() {
		wc_add_notice( __( "You can't renew the subscription at this time. Please try again.", 'woocommerce-subscriptions' ), 'error' );
		self::redirect();
	}

	/**
	 * Turns an early renewal request away because the store could not be read, so it isn't known whether a renewal
	 * has already been made.
	 *
	 * @since 9.3.0
	 *
	 * @return never
	 */
	private static function refuse_unreadable_request() {
		wc_add_notice( __( "We couldn't check whether your subscription has already been renewed. Please try again in a moment.", 'woocommerce-subscriptions' ), 'error' );
		self::redirect();
	}

	/**
	 * Returns the lock used to serialise early renewal requests for a subscription.
	 *
	 * @since 9.3.0
	 *
	 * @return Subscription_Lock
	 */
	private static function get_lock() {
		return new Subscription_Lock( self::LOCK_KEY_PREFIX, self::LOCK_TTL_SECONDS, 'wcs-early-renewal' );
	}

	/**
	 * Checks if a user can renew a subscription early via the modal window.
	 *
	 * @param int|WC_Subscription $subscription Post ID of a 'shop_subscription' post, or instance of a WC_Subscription object.
	 * @param int $user_id The ID of a user. Defaults to the current user.
	 * @return boolean
	 *
	 * @since 3.0.5
	 */
	public static function can_user_renew_early_via_modal( $subscription, $user_id = 0 ) {
		$user_id      = ! empty( $user_id ) ? absint( $user_id ) : get_current_user_id();
		$subscription = wcs_get_subscription( $subscription );

		if ( ! $subscription ) {
			return false;
		}

		if ( ! WCS_Early_Renewal_Manager::is_early_renewal_via_modal_enabled() || ! wcs_can_user_renew_early( $subscription, $user_id ) ) {
			return false;
		}

		return apply_filters( 'woocommerce_subscriptions_can_user_renew_early_via_modal', $subscription->get_user_id() === $user_id, $subscription, $user_id );
	}

	/**
	 * Redirect the user after processing their early renewal request.
	 *
	 * @since 2.6.0
	 *
	 * @return never
	 */
	private static function redirect() {
		wp_safe_redirect( remove_query_arg( array( 'process_early_renewal', 'subscription_id', 'wcs_nonce' ) ) );
		exit();
	}

	/**
	 * Removes filters which shouldn't run while processing early renewals via the modal.
	 *
	 * @since 2.6.0
	 */
	private static function detach_renewal_callbacks() {
		remove_filter( 'wcs_renewal_order_created', 'WC_Subscriptions_Renewal_Order::add_order_note' );
		remove_filter( 'woocommerce_order_status_changed', 'WC_Subscriptions_Renewal_Order::maybe_record_subscription_payment' );
	}
}
