<?php
/**
 * Download Handler for WooCommerce Subscriptions
 *
 * Functions for download related things within the Subscription Extension.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WCS_Download_Handler
 * @category   Class
 * @author     Prospress
 * @since      1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Download_Handler {
	/**
	 * Subscription meta storing permission IDs to revoke during the next permission sync.
	 */
	private const PENDING_DOWNLOAD_PERMISSION_REVOCATIONS_META_KEY = '_woocommerce_subscriptions_pending_download_permission_revocations';

	/**
	 * Initialize filters and hooks for class.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function init() {
		add_action( 'woocommerce_grant_product_download_permissions', __CLASS__ . '::save_downloadable_product_permissions' );
		add_action( 'woocommerce_before_delete_order_item', __CLASS__ . '::queue_download_permissions_for_deleted_subscription_item' );

		add_filter( 'woocommerce_get_item_downloads', __CLASS__ . '::get_item_downloads', 10, 3 );

		add_action( 'woocommerce_process_shop_order_meta', __CLASS__ . '::repair_permission_data', 60, 1 );

		add_action( 'woocommerce_admin_created_subscription', array( __CLASS__, 'grant_download_permissions' ) );

		add_action( 'woocommerce_loaded', [ __CLASS__, 'attach_wc_dependent_hooks' ] );

		add_action( 'woocommerce_process_product_file_download_paths', __CLASS__ . '::grant_new_file_product_permissions', 11, 3 );
	}

	/**
	 * Attach hooks that depend on WooCommerce being loaded.
	 *
	 * @since 5.2
	 */
	public static function attach_wc_dependent_hooks() {
		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			add_action( 'woocommerce_delete_subscription', [ __CLASS__, 'delete_subscription_download_permissions' ] );
		} else {
			add_action( 'deleted_post', [ __CLASS__, 'delete_subscription_permissions' ] );
		}
	}

	/**
	 * Save the download permissions on the individual subscriptions as well as the order. Hooked into
	 * 'woocommerce_grant_product_download_permissions', which is strictly after the order received all the info
	 * it needed, so we don't need to play with priorities.
	 *
	 * @param integer $order_id the ID of the order. At this point it is guaranteed that it has files in it and that it hasn't been granted permissions before
	 */
	public static function save_downloadable_product_permissions( $order_id ) {
		global $wpdb;
		$order = wc_get_order( $order_id );

		if ( wcs_is_subscription( $order ) ) {
			self::revoke_pending_download_permissions( $order );
			return;
		}

		if ( wcs_order_contains_subscription( $order, 'any' ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'any' ) ) );
		} else {
			return;
		}

		foreach ( $subscriptions as $subscription ) {
			if ( sizeof( $subscription->get_items() ) > 0 ) {
				foreach ( $subscription->get_items() as $item ) {
					$_product = $item->get_product();

					if ( $_product && $_product->exists() && $_product->is_downloadable() ) {
						$downloads  = wcs_get_objects_property( $_product, 'downloads' );
						$product_id = wcs_get_canonical_product_id( $item );

						foreach ( array_keys( $downloads ) as $download_id ) {
							// grant access on subscription if it does not already exist
							if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT download_id FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE `order_id` = %d AND `product_id` = %d AND `download_id` = %s", $subscription->get_id(), $product_id, $download_id ) ) ) {
								wc_downloadable_file_permission( $download_id, $product_id, $subscription, $item['qty'] );
							}
							self::revoke_downloadable_file_permission( $product_id, $order_id, $order->get_user_id() );
						}
					}
				}
			}

			$subscription->get_data_store()->set_download_permissions_granted( $subscription, true );
			self::revoke_pending_download_permissions( $subscription );
		}
	}

	/**
	 * Queue downloadable product permissions for revocation during the next permission sync.
	 *
	 * @internal This method is public only so that it can be invoked via action hooks, but is not intended for use by plugins
	 *           and may be removed without future notice,
	 *
	 * @param int $item_id Order item ID.
	 */
	public static function queue_download_permissions_for_deleted_subscription_item( $item_id ) {
		$item = WC_Order_Factory::get_order_item( $item_id );

		if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
			return;
		}

		$subscription = wcs_get_subscription( $item->get_order_id() );

		if ( ! $subscription ) {
			return;
		}

		$product_id = wcs_get_canonical_product_id( $item );

		if ( ! $product_id ) {
			return;
		}

		if ( self::subscription_has_downloadable_product_item( $subscription, $product_id, $item->get_id() ) ) {
			return;
		}

		/** @var WC_Customer_Download_Data_Store $data_store */
		$data_store     = WC_Data_Store::load( 'customer-download' );
		$permission_ids = $data_store->get_downloads(
			array(
				'order_id'   => $subscription->get_id(),
				'product_id' => $product_id,
				'return'     => 'ids',
			)
		);
		$permission_ids = array_values( array_filter( array_map( 'absint', $permission_ids ) ) );

		if ( empty( $permission_ids ) ) {
			return;
		}

		$pending_revocations = $subscription->get_meta( self::PENDING_DOWNLOAD_PERMISSION_REVOCATIONS_META_KEY, true, 'edit' );
		$pending_revocations = is_array( $pending_revocations ) ? $pending_revocations : array();
		$queued_ids          = isset( $pending_revocations[ $product_id ] ) && is_array( $pending_revocations[ $product_id ] )
			? array_map( 'absint', $pending_revocations[ $product_id ] )
			: array();

		$pending_revocations[ $product_id ] = array_values( array_unique( array_merge( $queued_ids, $permission_ids ) ) );
		$subscription->update_meta_data( self::PENDING_DOWNLOAD_PERMISSION_REVOCATIONS_META_KEY, $pending_revocations );
		$subscription->save_meta_data();
	}

	/**
	 * Check if a subscription has another downloadable line item for a canonical product ID.
	 *
	 * @param WC_Subscription $subscription     Subscription object.
	 * @param int             $product_id       Product ID.
	 * @param int             $excluded_item_id Optional order item ID to ignore.
	 *
	 * @return bool
	 */
	private static function subscription_has_downloadable_product_item( $subscription, $product_id, $excluded_item_id = 0 ) {
		foreach ( $subscription->get_items() as $item_id => $item ) {
			/** @var WC_Order_Item_Product $item */
			if ( $excluded_item_id && absint( $item_id ) === absint( $excluded_item_id ) ) {
				continue;
			}

			if ( absint( $product_id ) === absint( wcs_get_canonical_product_id( $item ) ) ) {
				$product = $item->get_product();

				return $product && $product->exists() && $product->is_downloadable();
			}
		}

		return false;
	}

	/**
	 * Check if a product is still linked to a subscription line item.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @param int             $product_id   Downloadable product ID.
	 *
	 * @return bool
	 */
	private static function subscription_has_linked_download( $subscription, $product_id ) {
		if (
			! class_exists( 'WC_Subscription_Downloads' )
			|| ! class_exists( 'WC_Subscription_Downloads_Settings' )
			|| ! WC_Subscription_Downloads_Settings::is_enabled()
		) {
			return false;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->exists() || ! $product->is_downloadable() ) {
			return false;
		}

		// Mirror the linked-downloads feature's own status gate (@see WC_Subscription_Downloads_Products::assess_downloadable_product_status()):
		// permissions for non-public products are revoked, and re-granted on republish, by that feature's
		// status-transition sync, so only a publicly visible product still confers a linked entitlement here.
		$status_object = get_post_status_object( $product->get_status() );

		if ( ! $status_object || ! $status_object->public ) {
			return false;
		}

		foreach ( $subscription->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$linked_product_ids = WC_Subscription_Downloads::get_downloadable_products( $item->get_product_id(), $item->get_variation_id() );

			foreach ( $linked_product_ids as $linked_product_id ) {
				if ( absint( $product_id ) === absint( $linked_product_id ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Revoke queued permissions that no longer represent a current subscription entitlement.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 */
	private static function revoke_pending_download_permissions( $subscription ) {
		$pending_revocations = $subscription->get_meta( self::PENDING_DOWNLOAD_PERMISSION_REVOCATIONS_META_KEY, true, 'edit' );

		if ( empty( $pending_revocations ) ) {
			return;
		}

		if ( ! is_array( $pending_revocations ) ) {
			$subscription->delete_meta_data( self::PENDING_DOWNLOAD_PERMISSION_REVOCATIONS_META_KEY );
			$subscription->save_meta_data();
			return;
		}

		/** @var WC_Customer_Download_Data_Store $data_store */
		$data_store = WC_Data_Store::load( 'customer-download' );

		foreach ( $pending_revocations as $product_id => $permission_ids ) {
			$product_id = absint( $product_id );

			if (
				! $product_id
				|| self::subscription_has_downloadable_product_item( $subscription, $product_id )
				|| self::subscription_has_linked_download( $subscription, $product_id )
			) {
				continue;
			}

			foreach ( array_unique( array_filter( array_map( 'absint', (array) $permission_ids ) ) ) as $permission_id ) {
				$data_store->delete_by_id( $permission_id );
			}
		}

		$subscription->delete_meta_data( self::PENDING_DOWNLOAD_PERMISSION_REVOCATIONS_META_KEY );
		$subscription->save_meta_data();
	}

	/**
	 * Revokes download permissions from permissions table if a file has permissions on a subscription. If a product has
	 * multiple files, all permissions will be revoked from the original order.
	 *
	 * @param int $product_id the ID for the product (the downloadable file)
	 * @param int $order_id the ID for the original order
	 * @param int $user_id the user we're removing the permissions from
	 * @return boolean true on success, false on error
	 */
	public static function revoke_downloadable_file_permission( $product_id, $order_id, $user_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';

		$where = array(
			'product_id' => $product_id,
			'order_id'   => $order_id,
			'user_id'    => $user_id,
		);

		$format = array( '%d', '%d', '%d' );

		return $wpdb->delete( $table, $where, $format );
	}

	/**
	 * Revokes a product's download permissions on a subscription, whichever user holds them.
	 *
	 * The subscription's customer is not always the user a permission belongs to. A gifted subscription's
	 * permissions are granted to its recipient, and may later be held by a former recipient or by a user an
	 * administrator granted access to.
	 *
	 * @since 9.3.0
	 *
	 * @param int             $product_id   The ID for the product (the downloadable file).
	 * @param WC_Subscription $subscription The subscription the permissions were granted against.
	 */
	public static function revoke_subscription_download_permissions( $product_id, $subscription ) {
		global $wpdb;

		if ( ! $subscription instanceof WC_Subscription ) {
			return;
		}

		$wpdb->delete(
			$wpdb->prefix . 'woocommerce_downloadable_product_permissions',
			array(
				'product_id' => $product_id,
				'order_id'   => $subscription->get_id(),
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * WooCommerce's function receives the original order ID, the item and the list of files. This does not work for
	 * download permissions stored on the subscription rather than the original order as the URL would have the wrong order
	 * key. This function takes the same parameters, but queries the database again for download ids belonging to all the
	 * subscriptions that were in the original order. Then for all subscriptions, it checks all items, and if the item
	 * passed in here is in that subscription, it creates the correct download link to be passed to the email.
	 *
	 * @param array $files List of files already included in the list
	 * @param array $item An item (you get it by doing $order->get_items())
	 * @param WC_Order $order The original order
	 * @return array List of files with correct download urls
	 */
	public static function get_item_downloads( $files, $item, $order ) {
		global $wpdb;

		if ( wcs_order_contains_subscription( $order, array( 'parent', 'renewal', 'switch' ) ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'parent', 'renewal', 'switch' ) ) );
		} else {
			return $files;
		}

		$product_id = wcs_get_canonical_product_id( $item );

		foreach ( $subscriptions as $subscription ) {
			foreach ( $subscription->get_items() as $subscription_item ) {
				if ( wcs_get_canonical_product_id( $subscription_item ) === $product_id ) {
					if ( is_callable( array( $subscription_item, 'get_item_downloads' ) ) ) { // WC 3.0+
						$files = $subscription_item->get_item_downloads( $subscription_item );
					} else { // WC < 3.0
						$files = $subscription->get_item_downloads( $subscription_item );
					}
				}
			}
		}

		return $files;
	}

	/**
	 * Repairs a glitch in WordPress's save function. You cannot save a null value on update, see
	 * https://github.com/woocommerce/woocommerce/issues/7861 for more info on this.
	 *
	 * @param integer $id The ID of the subscription
	 */
	public static function repair_permission_data( $id ) {
		if ( absint( $id ) !== $id ) {
			return;
		}

		if ( 'shop_subscription' !== WC_Data_Store::load( 'subscription' )->get_order_type( $id ) ) {
			return;
		}

		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"
				UPDATE {$wpdb->prefix}woocommerce_downloadable_product_permissions
				SET access_expires = null
				WHERE order_id = %d
				AND access_expires = %s
				",
				$id,
				'0000-00-00 00:00:00'
			)
		);
	}

	/**
	 * Gives customers access to downloadable products in a subscription.
	 * Hooked into 'woocommerce_admin_created_subscription' to grant permissions to admin created subscriptions.
	 *
	 * @param WC_Subscription $subscription
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.2
	 */
	public static function grant_download_permissions( $subscription ) {
		wc_downloadable_product_permissions( $subscription->get_id() );
	}

	/**
	 * Remove download permissions attached to a subscription when it is permanently deleted.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 *
	 * @param $id The ID of the subscription whose downloadable product permission being deleted.
	 */
	public static function delete_subscription_permissions( $id ) {
		if ( 'shop_subscription' === WC_Data_Store::load( 'subscription' )->get_order_type( $id ) ) {
			self::delete_subscription_download_permissions( $id );
		}
	}

	/**
	 * Remove download permissions attached to a subscription when it is permanently deleted.
	 *
	 * @since 5.2.0
	 *
	 * @param $id The ID of the subscription whose downloadable product permission being deleted.
	 */
	public static function delete_subscription_download_permissions( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE order_id = %d", $id ) );
	}

	/**
	 * Grant downloadable file access to any newly added files on any existing subscriptions
	 * which don't have existing permissions pre WC3.0 and all subscriptions post WC3.0.
	 *
	 * @param int $product_id
	 * @param int $variation_id
	 * @param array $downloadable_files product downloadable files
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.18
	 */
	public static function grant_new_file_product_permissions( $product_id, $variation_id, $downloadable_files ) {
		global $wpdb;

		$product_id            = ( $variation_id ) ? $variation_id : $product_id;
		$product               = wc_get_product( $product_id );
		$existing_download_ids = array_keys( (array) wcs_get_objects_property( $product, 'downloads' ) );
		$downloadable_ids      = array_keys( (array) $downloadable_files );
		$new_download_ids      = array_filter( array_diff( $downloadable_ids, $existing_download_ids ) );

		if ( ! empty( $new_download_ids ) ) {

			$existing_permissions = $wpdb->get_results( $wpdb->prepare( "SELECT order_id, download_id from {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE product_id = %d", $product_id ) );
			$subscriptions        = wcs_get_subscriptions_for_product( $product_id );

			// Arrange download id permissions by order id
			$permissions_by_order_id = array();

			foreach ( $existing_permissions as $permission_data ) {

				$permissions_by_order_id[ $permission_data->order_id ][] = $permission_data->download_id;
			}

			foreach ( $subscriptions as $subscription_id ) {

				// Grant permissions to subscriptions which have no permissions for this product, pre WC3.0, or all subscriptions, post WC3.0, as WC doesn't grant them retrospectively anymore.
				if ( ! in_array( $subscription_id, array_keys( $permissions_by_order_id ) ) || false === wcs_is_woocommerce_pre( '3.0' ) ) {
					$subscription = wcs_get_subscription( $subscription_id );

					foreach ( $new_download_ids as $download_id ) {

						$has_permission = isset( $permissions_by_order_id[ $subscription_id ] ) && in_array( $download_id, $permissions_by_order_id[ $subscription_id ] );

						if ( $subscription && ! $has_permission && apply_filters( 'woocommerce_process_product_file_download_paths_grant_access_to_new_file', true, $download_id, $product_id, $subscription ) ) {
							wc_downloadable_file_permission( $download_id, $product_id, $subscription );
						}
					}
				}
			}
		}
	}

	/**
	 * When adding new downloadable content to a subscription product, check if we don't
	 * want to automatically add the new downloadable files to the subscription or initial and renewal orders.
	 *
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 *
	 * @param bool $grant_access
	 * @param string $download_id
	 * @param int $product_id
	 * @param WC_Order $order
	 * @return bool
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function maybe_revoke_immediate_access( $grant_access, $download_id, $product_id, $order ) {
		wcs_deprecated_function( __METHOD__, '4.0.0', 'WCS_Drip_Downloads_Manager::maybe_revoke_immediate_access() if available' );

		if ( class_exists( 'WCS_Drip_Downloads_Manager' ) ) {
			return WCS_Drip_Downloads_Manager::maybe_revoke_immediate_access( $grant_access, $download_id, $product_id, $order );
		}

		return $grant_access;
	}
}
