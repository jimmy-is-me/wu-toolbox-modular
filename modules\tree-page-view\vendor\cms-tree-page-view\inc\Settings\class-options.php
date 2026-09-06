<?php
/**
 * Plugin options: storage, defaults, the settings screen, and its save handler.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the `cms_tpv_options` option (which post types show a tree, on the
 * dashboard and/or in the menu) and renders the Settings > CMS Tree Page View
 * screen. Consumed across the plugin via the cms_tpv_get_options() shim.
 */
class Options {

	/**
	 * Load the plugin options, normalising the dashboard/menu keys to arrays.
	 *
	 * @return array<string, mixed> Options with guaranteed 'dashboard' and 'menu' arrays.
	 */
	public static function get() {
		$arr_options = (array) get_option( 'cms_tpv_options' );

		if ( array_key_exists( 'dashboard', $arr_options ) ) {
			$arr_options['dashboard'] = (array) $arr_options['dashboard'];
		} else {
			$arr_options['dashboard'] = array();
		}

		if ( array_key_exists( 'menu', $arr_options ) ) {
			$arr_options['menu'] = (array) $arr_options['menu'];
		} else {
			$arr_options['menu'] = array();
		}

		return $arr_options;
	}

	/**
	 * On first install (no stored version), enable the tree for pages plus every
	 * hierarchical custom post type.
	 */
	public static function setup_defaults() {
		$version = get_option( 'cms_tpv_version', 0 );

		if ( $version <= 0 ) {
			$options = array();

			// Add pages to both dashboard and menu.
			$options['dashboard'] = array( 'page' );

			// Since 0.10.1 enable menu for all hierarchical custom post types.
			$post_types = get_post_types(
				array(
					'show_ui'      => true,
					'hierarchical' => true,
				),
				'objects'
			);

			foreach ( $post_types as $one_post_type ) {
				$options['menu'][] = $one_post_type->name;
			}

			$options['menu'] = array_unique( $options['menu'] );

			update_option( 'cms_tpv_options', $options );
		}
	}

	/**
	 * Post types the plugin never offers a tree for (used by other plugins).
	 *
	 * @return string[] Ignored post type slugs.
	 */
	public static function ignored_post_types() {
		return array(
			// Advanced Custom Fields.
			'acf',
		);
	}

	/**
	 * Whether a post type is on the ignore list.
	 *
	 * @param string $post_type Post type slug to check.
	 * @return bool True if the post type is ignored.
	 */
	public static function is_post_type_ignored( $post_type ) {
		return in_array( $post_type, self::ignored_post_types(), true );
	}

	/**
	 * Whether the plugin can manage a tree for this post type at all: a UI-visible
	 * post type that isn't the media library or on the ignore list. This is the
	 * single source of truth for that set — both the settings screen (which offers
	 * it as toggleable) and the REST allowlist run through here, so the two can't
	 * drift.
	 *
	 * WordPress's internal post types — `revision`, `nav_menu_item` — register with
	 * show_ui=false and so are excluded, and `attachment` is skipped explicitly.
	 * So the tree, detail, and write endpoints never describe or mutate an object
	 * the tree itself would never list (a revision, an attachment), no matter what
	 * id or post_type a crafted request supplies. It is deliberately the structural
	 * "could show" set rather than the currently-toggled dashboard/menu subset: the
	 * only ids the UI ever sends already come from the tree, and gating on the
	 * toggled subset would 404 a perfectly editable type a user simply hasn't
	 * enabled yet, without closing any disclosure the caps don't already gate.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function is_manageable_post_type( $post_type ) {
		$post_type = (string) $post_type;

		if ( 'attachment' === $post_type ) {
			return false;
		}

		if ( self::is_post_type_ignored( $post_type ) ) {
			return false;
		}

		$object = get_post_type_object( $post_type );

		return ! empty( $object ) && ! empty( $object->show_ui );
	}

	/**
	 * Persist the settings form on admin_init (self-handled Post/Redirect/Get).
	 */
	public static function save() {
		$action = isset( $_POST['cms_tpv_action'] ) ? sanitize_text_field( wp_unslash( $_POST['cms_tpv_action'] ) ) : '';

		if ( 'save_settings' === $action && current_user_can( 'manage_options' ) && check_admin_referer( 'update-options' ) ) {

			$options              = array();
			$options['dashboard'] = isset( $_POST['post-type-dashboard'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post-type-dashboard'] ) ) : array();
			$options['menu']      = isset( $_POST['post-type-menu'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post-type-menu'] ) ) : array();

			update_option( 'cms_tpv_options', $options );

			// Self-handled save (Post/Redirect/Get): the form posts to this settings
			// page rather than core options.php, so only this handler runs. Redirect
			// back with a flag so a refresh doesn't resubmit and we can show a notice.
			// (Posting to options.php with action=update + page_options caused
			// "unregistered/deprecated setting" and "headers already sent" notices on
			// modern WordPress).
			wp_safe_redirect( add_query_arg( 'settings-updated', 'true', \CMS_Tree_Page_View\Admin\Menu::get_settings_url() ) );
			exit;
		}
	}

	/**
	 * Render the Settings > CMS Tree Page View screen (add_submenu_page callback).
	 */
	public static function render_settings_page() {
		?>
		<div class="wrap">

			<?php \CMS_Tree_Page_View\Admin\Promo::help_card(); ?>

			<h2><?php echo esc_html( CMS_TPV_NAME ); ?> <?php esc_html_e( 'settings', 'cms-tree-page-view' ); ?></h2>

			<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set by WordPress core after a nonce-verified save; nothing is mutated here. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'cms-tree-page-view' ); ?></p></div>
			<?php endif; ?>

			<form method="post" class="cmtpv_options_form">

				<?php wp_nonce_field( 'update-options' ); ?>

				<h3><?php esc_html_e( 'Select where to show a tree for pages and custom post types', 'cms-tree-page-view' ); ?></h3>

				<table class="form-table">

					<tbody>

						<?php

						$options = self::get();

						$post_types = get_post_types(
							array(
								'show_ui' => true,
							),
							'objects'
						);

						foreach ( $post_types as $one_post_type ) {

							$name = $one_post_type->name;

							// Same rule the REST allowlist uses (posts are intentionally
							// supported since 2011; only media/attachments and ignored
							// types are skipped) — one predicate so the two never drift.
							if ( ! self::is_manageable_post_type( $name ) ) {
								continue;
							}

							$on_dashboard = in_array( $name, $options['dashboard'], true );
							$in_menu      = in_array( $name, $options['menu'], true );

							printf(
								'<tr><th scope="row"><p>%1$s</p></th><td><p>',
								esc_html( $one_post_type->label )
							);

							printf(
								'<input %1$s type="checkbox" name="post-type-dashboard[]" value="%2$s" id="post-type-dashboard-%2$s" /> <label for="post-type-dashboard-%2$s">%3$s</label>',
								checked( $on_dashboard, true, false ),
								esc_attr( $name ),
								esc_html__( 'On dashboard', 'cms-tree-page-view' )
							);

							echo '<br />';

							printf(
								'<input %1$s type="checkbox" name="post-type-menu[]" value="%2$s" id="post-type-menu-%2$s" /> <label for="post-type-menu-%2$s">%3$s</label>',
								checked( $in_menu, true, false ),
								esc_attr( $name ),
								esc_html__( 'In menu', 'cms-tree-page-view' )
							);

							echo '</p></td></tr>';

						}

						?>
					</tbody>
				</table>

				<input type="hidden" name="cms_tpv_action" value="save_settings" />
				<p class="submit">
					<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Save Changes', 'cms-tree-page-view' ); ?>" />
				</p>

			</form>

		</div>

		<?php
	}
}
