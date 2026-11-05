<?php
/**
 * WC_Subscriptions_Dependency_Manager class
 *
 * @package WooCommerce Subscriptions
 * @since 5.0.0
 */

defined( 'ABSPATH' ) || exit;

class WC_Subscriptions_Dependency_Manager {

	/**
	 * Name of the transient caching the active WooCommerce version number.
	 *
	 * Public so the plugin bootstrap can refresh the same transient on 'woocommerce_init'.
	 * The value is stored on live sites and written by previous plugin versions - do not change it.
	 *
	 * @since 9.1.0
	 *
	 * @var string
	 */
	public const WC_ACTIVE_VERSION_TRANSIENT = 'wcs_woocommerce_active_version';

	/**
	 * The minimum supported WooCommerce version.
	 *
	 * @var string
	 */
	private $minimum_supported_wc_version;

	/**
	 * @var string|null The active WooCommerce version, or null if WooCommerce is not active.
	 */
	private $wc_active_version = null;

	/**
	 * @var bool Whether the active WooCommerce version has been cached.
	 */
	private $wc_version_cached = false;

	/**
	 * @var boolean Whether to skip the class_exists and WC_VERSION constant checks.
	 */
	private $skip_class_exists_and_wc_version_constant_checks = false;

	/**
	 * Constructor.
	 */
	public function __construct( $minimum_supported_wc_version ) {
		$this->minimum_supported_wc_version = $minimum_supported_wc_version;
		/**
		 * Filter allows to skip the class_exists and WC_VERSION constant checks.
		 *
		 * @since 7.8.0
		 *
		 * @param bool $use_class_exists Whether to use the class_exists and WC_VERSION constant checks.
		 *
		 * @return bool false to use the class_exists and WC_VERSION checks, true to skip them.
		 */
		if ( defined( 'WCS_ENVIRONMENT_TYPE' ) && WCS_ENVIRONMENT_TYPE === 'tests' && apply_filters( 'woocommerce_subscriptions_skip_class_exists_and_wc_version_constant_checks', false ) ) {
			$this->skip_class_exists_and_wc_version_constant_checks = true;
		}
	}

	/**
	 * Checks if the required dependencies are met.
	 *
	 * @since 5.0.0
	 * @return bool True if the required dependencies are met. Otherwise, false.
	 */
	public function has_valid_dependencies() {
		// The version check covers the active check too: get_woocommerce_active_version() only trusts the cached
		// version after a fresh active-plugins presence check, so it returns null (which fails the version
		// comparison) when WooCommerce is not going to load this request.
		return $this->is_woocommerce_version_supported();
	}

	/**
	 * Determines if the WooCommerce plugin is active.
	 *
	 * @since 5.0.0
	 * @return bool True if the plugin is active, false otherwise.
	 */
	public function is_woocommerce_active() {
		if ( class_exists( 'WooCommerce' ) && ! $this->skip_class_exists_and_wc_version_constant_checks ) {
			return true;
		}

		return $this->get_woocommerce_active_version() !== null;
	}

	/**
	 * Determines if the WooCommerce version is supported by Subscriptions.
	 *
	 * The minimum supported WooCommerce version is defined in the WC_Subscriptions::$wc_minimum_supported_version property.
	 *
	 * @return bool true if the WooCommerce version is supported, false otherwise.
	 */
	public function is_woocommerce_version_supported() {
		return version_compare(
			// In php8.2+ version_compare requires a string so ensure we always pass a string.
			// version_compare treats an empty string as less than 0.
			$this->get_woocommerce_active_version() ?? '',
			$this->minimum_supported_wc_version,
			'>='
		);
	}

	/**
	 * This method detects the active version of WooCommerce.
	 *
	 * If the WC_VERSION constant is already defined, use that as a first preference.
	 * Otherwise, first confirm WooCommerce is going to load this request (a fresh check of the
	 * active plugin options plus the plugin file existing on disk), then resolve the version from
	 * the cached transient or, on a cache miss, from the plugin file header. The cached version is
	 * never trusted as proof that WooCommerce is active - only as the version number.
	 *
	 * The WooCommerce plugin is determined by this logic:
	 * 1. Installed at 'woocommerce/woocommerce.php'
	 * 2. Installed at any '{x}/woocommerce.php' where the plugin name is 'WooCommerce'
	 *
	 * @return string|null The active WooCommerce version, or null if WooCommerce is not active.
	 */
	private function get_woocommerce_active_version() {
		if ( defined( 'WC_VERSION' ) && ! $this->skip_class_exists_and_wc_version_constant_checks ) {
			return WC_VERSION;
		}

		// Use a cached value to avoid resolving the version multiple times per request.
		if ( true === $this->wc_version_cached ) {
			return $this->wc_active_version;
		}

		$this->wc_version_cached = true;

		// Presence gate: no candidate means WooCommerce won't load this request, regardless of any
		// cached version. Don't touch the transient here - invalidation is the option-update
		// listener's job and the TTL is the backstop.
		$candidates = $this->get_active_woocommerce_plugin_files();

		if ( empty( $candidates ) ) {
			$this->wc_active_version = null;
			return null;
		}

		// Warm path: trust the cached version number only after a candidate is confirmed to actually
		// be WooCommerce - an impostor '{x}/woocommerce.php' entry must not resurrect a stale cache.
		$cached_version = get_transient( self::WC_ACTIVE_VERSION_TRANSIENT );

		if ( false !== $cached_version && $this->contains_woocommerce_plugin_file( $candidates ) ) {
			$this->wc_active_version = $cached_version;
			return $this->wc_active_version;
		}

		// Cache miss: read the version from the candidate plugin file headers.
		foreach ( $candidates as $plugin_file ) {
			if ( ! $this->is_woocommerce_plugin_file( $plugin_file ) ) {
				continue;
			}

			$plugin_data = get_file_data( WP_PLUGIN_DIR . '/' . $plugin_file, array( 'Version' => 'Version' ) );

			// A file without a Version header (e.g. truncated mid-update) is not a resolved
			// version: keep looking so the version stays null rather than an empty string.
			if ( '' === $plugin_data['Version'] ) {
				continue;
			}

			$this->wc_active_version = $plugin_data['Version'];
			break; // Found it, no need to continue looping
		}

		// Cache the result in a transient for 1 hour
		if ( ! empty( $this->wc_active_version ) ) {
			set_transient( self::WC_ACTIVE_VERSION_TRANSIENT, $this->wc_active_version, HOUR_IN_SECONDS );
		}

		return $this->wc_active_version;
	}

	/**
	 * Gets the active plugin entries that could be WooCommerce and exist on disk.
	 *
	 * Reads the active plugin options directly (avoiding wp-admin/includes/plugin.php on the hot
	 * path), discards invalid entries via validate_file() and requires the plugin file to exist,
	 * mirroring wp_get_active_and_valid_plugins() semantics. This also covers the case where the
	 * plugin directory was renamed or removed while the option entry remained. Presence only -
	 * plugin headers are not read here.
	 *
	 * @since 9.1.0
	 *
	 * @return string[] Plugin file paths relative to WP_PLUGIN_DIR. Empty if WooCommerce is not active.
	 */
	private function get_active_woocommerce_plugin_files() {
		$active_plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		// Discard corrupted option values (non-string entries, traversal/absolute paths) before any
		// use - the same validate_file() check wp_get_active_and_valid_plugins() applies. Filtering
		// first also keeps non-string entries away from array_unique()'s string casts.
		$active_plugins = array_filter(
			$active_plugins,
			static function ( $plugin_file ) {
				return is_string( $plugin_file ) && 0 === validate_file( $plugin_file );
			}
		);

		$candidates = array();

		foreach ( array_unique( $active_plugins ) as $plugin_file ) {
			if ( 'woocommerce.php' !== basename( $plugin_file ) ) {
				continue;
			}

			if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
				$candidates[] = $plugin_file;
			}
		}

		return $candidates;
	}

	/**
	 * Determines whether any of the given presence candidates is the WooCommerce plugin.
	 *
	 * The default install path is confirmed via the candidate path alone, keeping the common
	 * (default-folder) warm path free of any file reads; only non-default-folder candidates
	 * fall through to the plugin header check.
	 *
	 * @since 9.1.0
	 *
	 * @param string[] $candidates Plugin file paths relative to WP_PLUGIN_DIR.
	 * @return bool True if at least one candidate is the WooCommerce plugin.
	 */
	private function contains_woocommerce_plugin_file( $candidates ) {
		if ( in_array( 'woocommerce/woocommerce.php', $candidates, true ) ) {
			return true;
		}

		foreach ( $candidates as $plugin_file ) {
			if ( $this->is_woocommerce_plugin_file( $plugin_file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determines whether an active plugin entry is the WooCommerce plugin.
	 *
	 * The default install path ('woocommerce/woocommerce.php') is accepted without reading the
	 * file; any other '{x}/woocommerce.php' entry must declare 'Plugin Name: WooCommerce' in its
	 * header. Shared by the warm (transient) and cold (header read) paths so the matching rule
	 * cannot drift between them.
	 *
	 * @since 9.1.0
	 *
	 * @param string $plugin_file Plugin file path relative to WP_PLUGIN_DIR.
	 * @return bool True if the entry is the WooCommerce plugin.
	 */
	private function is_woocommerce_plugin_file( $plugin_file ) {
		if ( 'woocommerce/woocommerce.php' === $plugin_file ) {
			return true;
		}

		$plugin_data = get_file_data( WP_PLUGIN_DIR . '/' . $plugin_file, array( 'Name' => 'Plugin Name' ) );

		return 'WooCommerce' === $plugin_data['Name'];
	}

	/**
	 * Clears the cached WooCommerce version.
	 *
	 * Deletes the version transient and resets the per-request memoization so the next lookup
	 * re-resolves from the active plugin state. Registered on the 'update_option_active_plugins'
	 * and 'update_site_option_active_sitewide_plugins' hooks, but safe to call at any time.
	 *
	 * @since 9.1.0
	 */
	public function delete_woocommerce_active_version_cache() {
		delete_transient( self::WC_ACTIVE_VERSION_TRANSIENT );

		$this->wc_version_cached = false;
		$this->wc_active_version = null;
	}

	/**
	 * Displays an admin notice if the required dependencies are not met.
	 *
	 * @since 5.0.0
	 */
	public function display_dependency_admin_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$admin_notice_content = '';

		if ( ! $this->is_woocommerce_active() ) {
			$install_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'install-plugin',
						'plugin' => 'woocommerce',
					),
					admin_url( 'update.php' )
				),
				'install-plugin_woocommerce'
			);

			// translators: 1$-2$: opening and closing <strong> tags, 3$-4$: link tags, takes to woocommerce plugin on wp.org, 5$-6$: opening and closing link tags, leads to plugins.php in admin
			$admin_notice_content = sprintf( esc_html__( '%1$sWooCommerce Subscriptions is inactive.%2$s The %3$sWooCommerce plugin%4$s must be active for WooCommerce Subscriptions to work. Please %5$sinstall & activate WooCommerce &raquo;%6$s', 'woocommerce-subscriptions' ), '<strong>', '</strong>', '<a href="http://wordpress.org/extend/plugins/woocommerce/">', '</a>', '<a href="' . esc_url( $install_url ) . '">', '</a>' );
		} elseif ( ! $this->is_woocommerce_version_supported() ) {
			// translators: 1$-2$: opening and closing <strong> tags, 3$: minimum supported WooCommerce version, 4$-5$: opening and closing link tags, leads to plugin admin
			$admin_notice_content = sprintf( esc_html__( '%1$sWooCommerce Subscriptions is inactive.%2$s This version of Subscriptions requires WooCommerce %3$s or newer. Please %4$supdate WooCommerce to version %3$s or newer &raquo;%5$s', 'woocommerce-subscriptions' ), '<strong>', '</strong>', $this->minimum_supported_wc_version, '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">', '</a>' );
		}

		if ( $admin_notice_content ) {
			echo '<div class="error">';
			echo '<p>' . wp_kses_post( $admin_notice_content ) . '</p>';
			echo '</div>';
		}
	}
}
