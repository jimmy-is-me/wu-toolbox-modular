<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Settings;

/**
 * Registry definitions for the redesigned Gifting section (originally a standalone plugin, folded
 * into core in 9.0).
 *
 * Three settings carry 1:1 (migration plan §3a): the master toggle, the gift-option text, and the
 * purchaser-downloads checkbox. The legacy default-state radio (`..._default_option`) is removed by
 * the redesign and resolved into per-product state — that materialization is a product-meta
 * migration (§3b–§3d), out of scope for this registry and tracked in clean-up.md.
 *
 * Legacy options live under the `woocommerce_subscriptions_gifting_` prefix; the derivations read
 * the full keys explicitly.
 *
 * §3a, §3b–§3d → sdd/settings-ui-refresh/context/migration.md
 *
 * @internal
 */
class Gifting_Definitions {
	private const LEGACY_ENABLE       = 'woocommerce_subscriptions_gifting_enable_gifting';
	private const LEGACY_OPTION_TEXT  = 'woocommerce_subscriptions_gifting_gifting_checkbox_text';
	private const LEGACY_DOWNLOADABLE = 'woocommerce_subscriptions_gifting_downloadable_products';

	/**
	 * Register the Gifting section's settings with the registry.
	 *
	 * @param Registry $registry Registry to populate.
	 */
	public static function register( Registry $registry ): void {
		$registry->register(
			'gifting_enable',
			static function () {
				return 'yes' === get_option( self::LEGACY_ENABLE, 'no' ) ? 'yes' : 'no';
			}
		);

		$registry->register(
			'gifting_option_text',
			static function () {
				return get_option( self::LEGACY_OPTION_TEXT, __( 'This is a gift', 'woocommerce-subscriptions' ) );
			}
		);

		$registry->register(
			'gifting_allow_purchaser_downloads',
			static function () {
				return 'yes' === get_option( self::LEGACY_DOWNLOADABLE, 'no' ) ? 'yes' : 'no';
			}
		);
	}
}
