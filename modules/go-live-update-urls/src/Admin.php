<?php

namespace WUTM\GoLive;

use WUTM\GoLive\Traits\Singleton;

/**
 * Tools page in WordPress admin.
 *
 * @author OnPoint Plugins
 * @since  6.0.0
 */
class Admin {
	use Singleton;

	public const NAME = 'wutm-go-live-update-urls-settings';

	public const PARENT_MENU      = 'tools.php';
	public const OLD_URL          = 'old_url';
	public const NEW_URL          = 'new_url';
	public const NONCE            = 'wutm-go-live-update-urls/nonce/update-tables';
	public const TABLE_INPUT_NAME = 'wutm-go-live-update-urls/input/database-table';
	public const SUBMIT           = 'wutm-go-live-update-urls/input/submit';

	protected const CAPABILITY = 'manage_options';


	/**
	 * Add actions.
	 */
	protected function hook(): void {
		if ( isset( $_POST[ static::SUBMIT ] ) ) { //phpcs:ignore WordPress.Security.NonceVerification
			add_action( 'init', [ $this, 'validate_update_submission' ] );
		}

		add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
	}


	/**
	 * Validate and trigger an update submission
	 *
	 * @since 5.0.0
	 *
	 * @return void
	 */
	public function validate_update_submission(): void {
		if ( ! isset( $_POST[ static::NONCE ] ) || false === wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ static::NONCE ] ) ), static::NONCE ) ) {
			wp_die( esc_html__( '驗證失敗，請重新整理頁面後再試。', 'wutm-go-live-update-urls' ) );
		}

		if ( ! isset( $_POST[ static::OLD_URL ], $_POST[ static::NEW_URL ] ) || '' === $_POST[ static::OLD_URL ] || '' === $_POST[ static::NEW_URL ] ) {
			$this->failure_message();
			return;
		}

		$old_url = wutm_go_live_update_urls_sanitize_field( (string) $_POST[ static::OLD_URL ] );
		$new_url = wutm_go_live_update_urls_sanitize_field( (string) $_POST[ static::NEW_URL ] );
		if ( '' === $old_url || '' === $new_url || ! isset( $_POST[ static::TABLE_INPUT_NAME ] ) ) {
			$this->failure_message();
			return;
		}

		$tables = \array_filter( \array_map( 'wutm_go_live_update_urls_sanitize_field', (array) $_POST[ static::TABLE_INPUT_NAME ] ), fn( $value ) => '' !== $value );

		do_action( 'wutm-go-live-update-urls/admin-page/before-update', $old_url, $new_url, $tables );

		if ( \count( Database::instance()->update_the_database( $old_url, $new_url, $tables ) ) > 0 ) {
			add_action( 'admin_notices', [ $this, 'success' ] );
			add_filter( 'wutm-go-live-update-urls/views/admin-tools-page/disable-description', '__return_true' );
		}
	}


	/**
	 * Render a success message as admin banner.
	 */
	public function success(): void {
		?>
		<div id="message" class="updated fade">
			<p>
				<strong>
					<?php echo esc_html( apply_filters( 'wutm-go-live-update-urls/admin/success', __( '已完成勾選資料表中的網址更新。', 'wutm-go-live-update-urls' ) ) ); ?>
				</strong>
			</p>
		</div>
		<?php
	}


	/**
	 * Display a message if any fields were not filed out.
	 *
	 * @return void
	 */
	public function failure_message(): void {
		add_action( 'admin_notices', function() {
			?>
			<div id="message" class="error fade">
				<p>
					<strong>
						<?php esc_html_e( '請選擇資料表，並填寫舊網址與新網址。', 'wutm-go-live-update-urls' ); ?>
					</strong>
				</p>
			</div>
			<?php
		} );
	}


	/**
	 * Menu Under Tools Menu
	 *
	 * @since 5.0.0
	 */
	public function register_admin_page(): void {
		add_submenu_page( self::PARENT_MENU, '網站網址更新', '網址更新', $this->get_admin_capability(), self::NAME, [ $this, 'admin_page' ] );
	}


	/**
	 * Get the filtered capability required to use the tools page.
	 *
	 * @since 6.9.0
	 *
	 * @return string
	 */
	public function get_admin_capability(): string {
		return (string) apply_filters( 'wutm-go-live-update-urls/admin/admin-capability', self::CAPABILITY, $this );
	}


	/**
	 * Render the tools page header.
	 *
	 * This is the same header used by the PRO version.
	 *
	 * @param string $url - URL to link the plugin name to.
	 *
	 * @return void
	 */
	public function render_admin_header( string $url ): void {
		?>
		<div class="go-live-header-wrap">
			<div>
				<h1 class="dashicons-before dashicons-update">
					<a
						href="<?php echo esc_url( $url ); ?>"
						target="_blank"
						rel="noopener noreferrer">
						<?php esc_html_e( '網站網址更新', 'wutm-go-live-update-urls' ); ?>
					</a>
				</h1>
			</div>
			<div class="go-live-header-message">
				<?php esc_html_e( '將網站資料庫中的舊網址全部更新為新網址。', 'wutm-go-live-update-urls' ); ?>
			</div>
		</div>
		<?php
	}


	/**
	 * Output the Admin Page for using this plugin
	 *
	 * @since 5.0.0
	 */
	public function admin_page(): void {
		wp_enqueue_script( 'wutm-go-live-update-urls/admin/admin-page/js', WUTM_GO_LIVE_URLS_URL . 'assets/go-live-update-urls.js', [ 'jquery' ], WUTM_GO_LIVE_URLS_VERSION, true );
		wp_enqueue_style( 'wutm-go-live-update-urls/admin/admin-page/css', WUTM_GO_LIVE_URLS_URL . 'assets/go-live-update-urls.css', [], WUTM_GO_LIVE_URLS_VERSION );

		?>
		<div id="wutm-go-live-update-urls/admin-page">
			<?php
			$this->render_admin_header( 'https://wordpress.org/plugins/wutm-go-live-update-urls/' );
			?>
			<form
				method="post"
				class="go-live-checkbox-form">
				<?php
				wp_nonce_field( static::NONCE, static::NONCE );

				do_action( 'wutm-go-live-update-urls-pro/admin/before-checkboxes', Database::instance() );

				if ( apply_filters( 'wutm-go-live-update-urls-pro/admin/use-default-checkboxes', true ) ) {
					?>
					<h3>
						<?php esc_html_e( 'WordPress 核心資料表', 'wutm-go-live-update-urls' ); ?>
					</h3>
					<div class="go-live-section">
						<p class="description" style="color:green;">
							<strong>
								<?php esc_attr_e( '這些資料表可安全更新。', 'wutm-go-live-update-urls' ); ?>
							</strong>
						</p>
						<p>
							<input
								type="checkbox"
								data-list="wp-core"
								data-js="wutm-go-live-update-urls/checkboxes/check-all"
								checked
							/>
							<span class="go-live-only-checked"><?php esc_html_e( '只會更新勾選的資料表。', 'wutm-go-live-update-urls' ); ?></span>
						</p>
						<hr />

						<?php
						$this->render_check_boxes( Database::instance()->get_core_tables(), 'wp-core' );
						?>
					</div>
					<?php

					$custom_tables = Database::instance()->get_custom_plugin_tables();
					if ( \count( $custom_tables ) > 0 ) {
						?>
						<h3>
							<?php esc_html_e( '外掛建立的資料表', 'wutm-go-live-update-urls' ); ?>
						</h3>
						<div class="go-live-section">
							<p class="description" style="color:red;">
								<strong>
									<?php
									/* translators: <br /> <a> </a> */
									printf( esc_html_x( '基本版本不建議更新這些資料表！%1$s如需更新外掛建立的資料表，請使用%2$s進階版本%3$s。', '{<br />}{<a>}{</a>}', 'wutm-go-live-update-urls' ), '<br />', '<a href="https://onpointplugins.com/product/wutm-go-live-update-urls-pro/?utm_source=plugin-tables&utm_campaign=gopro&utm_medium=wp-dash" target="_blank">', '</a>' );
									?>
								</strong>
							</p>
							<p>
								<input
									type="checkbox"
									data-list="custom-plugins"
									data-js="wutm-go-live-update-urls/checkboxes/check-all" />
								<span class="go-live-only-checked"><?php esc_html_e( '只會更新勾選的資料表。', 'wutm-go-live-update-urls' ); ?></span>
							</p>
							<hr />

							<?php
							$this->render_check_boxes( $custom_tables, 'custom-plugins', false );
							?>
						</div>
						<?php
					}
				}

				do_action( 'wutm-go-live-update-urls-pro/admin/after-checkboxes', Database::instance() );

				if ( apply_filters( 'wutm-go-live-update-urls-pro/admin/use-default-inputs', true ) ) {
					?>
					<table class="form-table go-live-inputs">
						<tr class="go-live-inputs-old-url">
							<th scope="row">
								<label for="old_url">
									<?php esc_html_e( '舊網址', 'wutm-go-live-update-urls' ); ?>
								</label>
							</th>
							<td>
								<input
									name="<?php echo esc_attr( static::OLD_URL ); ?>"
									type="text"
									id="old_url"
									value=""
									class="regular-text"
									title="<?php esc_attr_e( '舊網址', 'wutm-go-live-update-urls' ); ?>" />
							</td>
						</tr>
						<tr class="go-live-inputs-new-url">
							<th scope="row">
								<label for="new_url">
									<?php esc_attr_e( '新網址', 'wutm-go-live-update-urls' ); ?>
								</label>
							</th>
							<td>
								<input
									name="<?php echo esc_attr( static::NEW_URL ); ?>"
									type="text"
									id="new_url"
									value=""
									class="regular-text"
									title="<?php esc_attr_e( '新網址', 'wutm-go-live-update-urls' ); ?>" />
							</td>
						</tr>
					</table>


					<?php
				}
				if ( apply_filters( 'wutm-go-live-update-urls-pro/admin/use-default-checkboxes', true ) ) {
					?>
					<p class="description">
						<strong>

							<?php
							/* translators: <a></a> */
							printf( esc_html_x( '如需在套用前測試網址更新，請使用%1$s進階版本%2$s。', '{<a>}{</a>}', 'wutm-go-live-update-urls' ), '<a href="https://onpointplugins.com/wutm-go-live-update-urls/wutm-go-live-update-urls-pro-usage/wutm-go-live-update-urls-pro-url-testing/?utm_source=url-test&utm_campaign=gopro&utm_medium=wp-dash" target="_blank">', '</a>' );
							?>

						</strong>
					</p>
					<?php
				}
				?>
				<?php submit_button( __( '更新網址', 'wutm-go-live-update-urls' ), 'primary', static::SUBMIT ); ?>
			</form>
		</div>
		<?php
	}


	/**
	 * Creates a list of checkboxes for each table
	 *
	 * @since  5.0.0
	 *
	 * @param string[] $tables  - List of all tables.
	 * @param string   $list_id - Used by JS to separate lists.
	 * @param bool     $checked - Should all checkboxes be checked.
	 *
	 * @return void
	 */
	public function render_check_boxes( array $tables, string $list_id, bool $checked = true ): void {
		?>
		<ul data-list="<?php echo esc_attr( $list_id ); ?>">
			<?php
			foreach ( $tables as $_table ) {
				?>
				<li>
					<?php
					\printf( '<input name="%s[]" type="checkbox" value="%s" %s/> %s', esc_attr( static::TABLE_INPUT_NAME ), esc_attr( $_table ), checked( $checked, true, false ), esc_html( $_table ) );
					?>
				</li>
				<?php
			}
			?>
		</ul>
		<?php
	}


	/**
	 * Get the URL of the tools page.
	 *
	 * @return string
	 */
	public function get_url(): string {
		return admin_url( 'tools.php?page=' . static::NAME );
	}
}
