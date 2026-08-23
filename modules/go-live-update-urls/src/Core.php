<?php

namespace WUTM\GoLive;

use WUTM\GoLive\Traits\Singleton;

/**
 * Core functionality for the 網站網址更新 plugin.
 *
 * @author OnPoint Plugins
 * @since  6.0.0
 */
class Core {
	use Singleton;

	public const MEMORY_LIMIT = '256M';
	public const PLUGIN_FILE  = 'wutm-go-live-update-urls/wutm-go-live-update-urls.php';


	/**
	 * Actions and filters.
	 */
	protected function hook(): void {
		add_action( 'wutm-go-live-update-urls/database/before-update', [ $this, 'raise_resource_limits' ], 0, 0 );
		add_action( 'wutm-go-live-update-urls/database/before-counting', [ $this, 'raise_resource_limits' ], 0, 0 );
		add_action( 'wutm-go-live-update-urls/database/after-update', [ $this, 'flush_caches' ] );
		add_filter( 'wutm-go-live-update-urls/database/memory-limit_memory_limit', [ $this, 'raise_memory_limit' ], 0, 0 );
		add_filter( 'plugin_action_links_' . static::PLUGIN_FILE, [ $this, 'plugin_action_link' ] );
	}


	/**
	 * Set the following:
	 * 1. Time limit to unlimited.
	 * 2. Input time to unlimited.
	 * 3. Memory limit to context which will use our filter.
	 *
	 * @see Core::raise_memory_limit();
	 *
	 * @return void
	 */
	public function raise_resource_limits() {
		set_time_limit( 0 );
		ini_set( 'max_input_time', '-1' ); //phpcs:ignore

		wp_raise_memory_limit( 'wutm-go-live-update-urls/database/memory-limit' );
	}


	/**
	 * Flush any known caches, which are affected by updating the database.
	 *
	 * 1. WP core object cache.
	 * 2. Elementor CSS cache.
	 *
	 * @ticket #7751
	 *
	 * @since 6.2.1
	 *
	 * @see   \Elementor\設定::update_css_print_method
	 */
	public function flush_caches() {
		// Special flushing of CSS cache for Elementor #7751.
		$method = get_option( 'elementor_css_print_method' );
		//phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action( 'update_option_elementor_css_print_method', $method, $method, 'elementor_css_print_method' );

		wp_cache_flush();
	}


	/**
	 * Raise the memory limit while the Database runs.
	 * If the memory limit is higher than self::MEMORY_LIMIT
	 * this will do nothing.
	 *
	 * @uses wp_raise_memory_limit();
	 *
	 * @return string
	 */
	public function raise_memory_limit() {
		return self::MEMORY_LIMIT;
	}


	/**
	 * Quick and dirty update of the entire blog
	 *
	 * Mostly used for unit testing and future WP-CLI command
	 *
	 * @since 5.0.1
	 *
	 * @param string $old_url - The old URL.
	 * @param string $new_url - The new URL.
	 *
	 * @return int[]
	 */
	public function update( $old_url, $new_url ) {
		$db = Database::instance();
		$tables = $db->get_all_table_names();

		do_action( 'wutm-go-live-update-urls/core/before-update', $old_url, $new_url, $tables );

		return $db->update_the_database( $old_url, $new_url, $tables );
	}


	/**
	 * Display custom action links in the plugin list.
	 *
	 * 1. 設定.
	 * 2. 使用說明.
	 * 3. 進階版.
	 *
	 * @param array<string, string> $actions - Array of actions and their link.
	 *
	 * @return array<string, string>
	 */
	public function plugin_action_link( array $actions ) {
		$actions['documentation'] = \sprintf( '<a href="%s" target="_blank">%s</a>',
			'https://onpointplugins.com/wutm-go-live-update-urls/wutm-go-live-update-urls-usage/?utm_source=wp-plugins&utm_campaign=documentation&utm_medium=wp-dash', __( '使用說明', 'wutm-go-live-update-urls' ) );
		$actions['settings'] = \sprintf( '<a href="%1$s">%2$s</a>', Admin::instance()->get_url(), __( '設定', 'wutm-go-live-update-urls' ) );
		if ( ! \defined( 'WUTM_GO_LIVE_URLS_PRO_VERSION' ) ) {
			$actions['go-pro'] = \sprintf( '<a href="%1$s" target="_blank" style="color:#3db634;font-weight:700;">%2$s</a>', 'https://onpointplugins.com/product/wutm-go-live-update-urls-pro/?utm_source=wp-plugins&utm_campaign=gopro&utm_medium=wp-dash', __( '進階版', 'wutm-go-live-update-urls' ) );
		}
		return $actions;
	}
}
