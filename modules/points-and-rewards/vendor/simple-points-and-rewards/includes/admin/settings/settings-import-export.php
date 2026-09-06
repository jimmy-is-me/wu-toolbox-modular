<?php
/**
 * Settings import/export (JSON).
 *
 * Export produces a versioned JSON snapshot of the full spar_options array.
 * Import converts the snapshot back into the same payload shape the settings
 * form submits and routes it through spar_process_settings_save(), so every
 * existing sanitizer, allowlist, and special-case handler applies to imported
 * data exactly as it does to a manual save.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the full current settings snapshot (defaults-merged, like the settings page).
 *
 * @return array
 */
function spar_settings_export_snapshot() {
	$defaults = spar_settings_default();
	$options  = get_option( 'spar_options', array() );
	$options  = is_array( $options ) ? $options : array();
	$merged   = array_merge( $defaults, $options );

	// Merge nested earn options so every earn method exports all of its fields.
	if ( isset( $defaults['earn'] ) && is_array( $defaults['earn'] ) ) {
		foreach ( $defaults['earn'] as $earn_key => $earn_defaults ) {
			if ( isset( $merged['earn'][ $earn_key ] ) && is_array( $merged['earn'][ $earn_key ] ) ) {
				$merged['earn'][ $earn_key ] = array_merge( $earn_defaults, $merged['earn'][ $earn_key ] );
			} else {
				$merged['earn'][ $earn_key ] = $earn_defaults;
			}
		}
	}

	return $merged;
}

/**
 * Download the current settings as a JSON file.
 */
function spar_handle_export_settings() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to export settings.', 'simple-points-and-rewards' ) );
	}

	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'spar_export_settings' ) ) {
		wp_die( esc_html__( 'Security check failed. Please go back and try the export again.', 'simple-points-and-rewards' ) );
	}

	$payload = array(
		'plugin'      => 'simple-points-and-rewards',
		'type'        => 'settings',
		'version'     => defined( 'SPAR_VERSION' ) ? SPAR_VERSION : '',
		'exported_at' => gmdate( 'c' ),
		'site'        => home_url(),
		'options'     => spar_settings_export_snapshot(),
	);

	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'simple-points-rewards-settings-' . gmdate( 'Y-m-d' ) . '.json' ) . '"' );

	echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	exit;
}
add_action( 'admin_post_spar_export_settings', 'spar_handle_export_settings' );

/**
 * Convert a stored-shape options array into the payload shape the settings
 * form posts, so spar_process_settings_save() can sanitize and persist it.
 *
 * The import is treated as a partial overlay on the site's current settings:
 * any key the file omits keeps its current value. This is required because the
 * save pipeline processes the payload as a complete form (spar_form_end), so a
 * key that is simply absent would otherwise be reset to its "off"/empty state
 * rather than preserved. A full export still replaces every setting because it
 * contains every key.
 *
 * @param array $imported Options in stored (spar_options) shape.
 * @return array
 */
function spar_settings_convert_options_to_post_payload( $imported ) {
	$fields   = spar_get_option_fields();
	$current  = get_option( 'spar_options', array() );
	$current  = is_array( $current ) ? $current : array();
	$imported = is_array( $imported ) ? $imported : array();

	// Overlay the imported values on the current, defaults-merged settings so
	// omitted keys retain their current value instead of being wiped by the
	// complete-form save handling. Top-level keys merge shallowly; the nested
	// `earn` map is merged one level deeper so an absent earn method or an
	// absent sub-field (e.g. a key added in a newer version than the file) also
	// keeps its current value.
	$base          = spar_settings_export_snapshot();
	$imported_earn = ( isset( $imported['earn'] ) && is_array( $imported['earn'] ) ) ? $imported['earn'] : null;
	$imported      = array_merge( $base, $imported );
	if ( null !== $imported_earn ) {
		$base_earn = ( isset( $base['earn'] ) && is_array( $base['earn'] ) ) ? $base['earn'] : array();
		foreach ( $imported_earn as $earn_key => $earn_vals ) {
			if ( is_array( $earn_vals ) && isset( $base_earn[ $earn_key ] ) && is_array( $base_earn[ $earn_key ] ) ) {
				$base_earn[ $earn_key ] = array_merge( $base_earn[ $earn_key ], $earn_vals );
			} else {
				$base_earn[ $earn_key ] = $earn_vals;
			}
		}
		$imported['earn'] = $base_earn;
	}

	$payload = array(
		'spar_save_settings' => 1,
		'spar_form_end'      => 1,
		// Only checked for presence by the payload validator; the import
		// request itself is nonce-verified before this runs.
		'_wpnonce'           => 'spar-import',
	);

	// The starter badge handler in the save pipeline runs unconditionally and
	// treats missing keys as empty, so backfill absent keys from the current
	// settings (then schema defaults) to avoid wiping them on partial imports.
	$starter_badge_keys = array(
		'levels_starter_badge_icon',
		'levels_starter_badge_type',
		'levels_starter_badge_text',
		'levels_starter_badge_url',
		'levels_starter_badge_color',
		'levels_starter_badge_color_mode',
	);

	foreach ( $fields as $key => $field_data ) {
		if ( 'earn' === $key ) {
			if ( ! isset( $imported['earn'] ) || ! is_array( $imported['earn'] ) ) {
				continue;
			}
			foreach ( $field_data as $earn_key => $earn_fields ) {
				if ( ! isset( $imported['earn'][ $earn_key ] ) || ! is_array( $imported['earn'][ $earn_key ] ) ) {
					continue;
				}
				foreach ( $earn_fields as $subkey => $subfield ) {
					if ( array_key_exists( $subkey, $imported['earn'][ $earn_key ] ) ) {
						$payload[ $earn_key . '_' . $subkey ] = $imported['earn'][ $earn_key ][ $subkey ];
					}
				}
			}
		} elseif ( 'buy_products' === $key ) {
			if ( array_key_exists( 'buy_products', $imported ) ) {
				$payload['buy_products']         = $imported['buy_products'];
				$payload['buy_products_present'] = 1;
			}
		} elseif ( in_array( $key, $starter_badge_keys, true ) ) {
			if ( array_key_exists( $key, $imported ) ) {
				$payload[ $key ] = $imported[ $key ];
			} elseif ( array_key_exists( $key, $current ) ) {
				$payload[ $key ] = $current[ $key ];
			} else {
				$payload[ $key ] = isset( $field_data['default'] ) ? $field_data['default'] : '';
			}
		} elseif ( array_key_exists( $key, $imported ) ) {
			$payload[ $key ] = $imported[ $key ];
		}
	}

	// The payload validator requires these fields to be present; backfill from
	// the current settings (then schema defaults) when the file lacks them.
	foreach ( array( 'points_label', 'rewards_label', 'rewards_page_id', 'rewards_box_theme' ) as $required_key ) {
		if ( array_key_exists( $required_key, $payload ) ) {
			continue;
		}
		if ( array_key_exists( $required_key, $current ) ) {
			$payload[ $required_key ] = $current[ $required_key ];
		} elseif ( isset( $fields[ $required_key ]['default'] ) ) {
			$payload[ $required_key ] = $fields[ $required_key ]['default'];
		} else {
			$payload[ $required_key ] = '';
		}
	}

	/**
	 * Filter the converted import payload before it is passed to the save pipeline.
	 *
	 * @param array $payload  Form-shaped payload.
	 * @param array $imported Raw imported options (stored shape).
	 */
	return apply_filters( 'spar_settings_import_payload', $payload, $imported );
}

/**
 * Handle an uploaded settings JSON file.
 */
function spar_handle_import_settings() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to import settings.', 'simple-points-and-rewards' ) );
	}

	check_admin_referer( 'spar_import_settings', 'spar_import_settings_nonce' );

	$redirect = admin_url( 'admin.php?page=spar-settings' );

	$fail = function( $code, $message = '' ) use ( $redirect ) {
		if ( '' !== $message ) {
			set_transient( 'spar_settings_import_message_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( add_query_arg( 'spar_ie_notice', $code, $redirect ) );
		exit;
	};

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File array is validated below; contents are sanitized by the save pipeline.
	$file = isset( $_FILES['spar_settings_file'] ) ? $_FILES['spar_settings_file'] : null;
	if ( ! is_array( $file ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || empty( $file['tmp_name'] ) ) {
		$fail( 'no_file' );
	}

	if ( ! is_uploaded_file( $file['tmp_name'] ) || (int) $file['size'] > 2 * MB_IN_BYTES ) {
		$fail( 'invalid_file' );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading an uploaded temp file.
	$contents = file_get_contents( $file['tmp_name'] );
	$data     = json_decode( (string) $contents, true, 64 );

	if ( ! is_array( $data ) ) {
		$fail( 'invalid_json' );
	}

	if ( isset( $data['options'] ) && is_array( $data['options'] ) ) {
		// Enveloped export file — verify it belongs to this plugin when identified.
		if ( isset( $data['plugin'] ) && 'simple-points-and-rewards' !== $data['plugin'] ) {
			$fail( 'wrong_plugin' );
		}
		$imported = $data['options'];
	} else {
		// Raw options array — require at least one recognised settings key.
		$known = array_intersect( array_keys( spar_get_option_fields() ), array_keys( $data ) );
		if ( empty( $known ) ) {
			$fail( 'invalid_file' );
		}
		$imported = $data;
	}

	$payload = spar_settings_convert_options_to_post_payload( $imported );
	// The save pipeline unslashes values like it does for real form posts.
	$result = spar_process_settings_save( wp_slash( $payload ) );

	if ( is_wp_error( $result ) ) {
		$fail( 'save_failed', $result->get_error_message() );
	}

	wp_safe_redirect( add_query_arg( 'spar_ie_notice', 'imported', $redirect ) );
	exit;
}
add_action( 'admin_post_spar_import_settings', 'spar_handle_import_settings' );

/**
 * Show the result notice after an import redirect.
 */
function spar_settings_import_export_notices() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$notice = isset( $_GET['spar_ie_notice'] ) ? sanitize_key( wp_unslash( $_GET['spar_ie_notice'] ) ) : '';

	if ( 'spar-settings' !== $page || '' === $notice ) {
		return;
	}

	$messages = array(
		'imported'     => array( 'success', esc_html__( 'Settings imported successfully.', 'simple-points-and-rewards' ) ),
		'no_file'      => array( 'error', esc_html__( 'Settings import failed: no file was uploaded.', 'simple-points-and-rewards' ) ),
		'invalid_file' => array( 'error', esc_html__( 'Settings import failed: the uploaded file is not a valid settings export.', 'simple-points-and-rewards' ) ),
		'invalid_json' => array( 'error', esc_html__( 'Settings import failed: the file does not contain valid JSON.', 'simple-points-and-rewards' ) ),
		'wrong_plugin' => array( 'error', esc_html__( 'Settings import failed: the file was exported by a different plugin.', 'simple-points-and-rewards' ) ),
		'save_failed'  => array( 'error', esc_html__( 'Settings import failed while saving.', 'simple-points-and-rewards' ) ),
	);

	if ( ! isset( $messages[ $notice ] ) ) {
		return;
	}

	list( $type, $message ) = $messages[ $notice ];

	$detail_key = 'spar_settings_import_message_' . get_current_user_id();
	$detail     = get_transient( $detail_key );
	if ( is_string( $detail ) && '' !== $detail ) {
		$message .= ' ' . $detail;
		delete_transient( $detail_key );
	}

	printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $message ) );
}
add_action( 'admin_notices', 'spar_settings_import_export_notices' );

/**
 * Render the Import / Export panel shown below the settings form.
 */
function spar_settings_render_import_export_panel() {
	$export_url = wp_nonce_url( add_query_arg( 'action', 'spar_export_settings', admin_url( 'admin-post.php' ) ), 'spar_export_settings' );
	?>
	<div class="spar-settings-import-export" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-top: 24px;">
		<h3 style="margin-top: 0;"><?php esc_html_e( 'Import / Export Settings', 'simple-points-and-rewards' ); ?></h3>
		<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px;">
			<div>
				<h4 style="margin-bottom: 6px;"><?php esc_html_e( 'Export', 'simple-points-and-rewards' ); ?></h4>
				<p style="margin-top: 0;"><?php esc_html_e( 'Download all plugin settings as a JSON file. Use it as a backup, or to copy your configuration to another site.', 'simple-points-and-rewards' ); ?></p>
				<a href="<?php echo esc_url( $export_url ); ?>" class="button">
					<span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span>
					<?php esc_html_e( 'Export Settings', 'simple-points-and-rewards' ); ?>
				</a>
			</div>
			<div>
				<h4 style="margin-bottom: 6px;"><?php esc_html_e( 'Import', 'simple-points-and-rewards' ); ?></h4>
				<p style="margin-top: 0;">
					<?php esc_html_e( 'Upload a settings export file. Imported values overwrite your current settings, so export a backup first.', 'simple-points-and-rewards' ); ?>
					<em><?php esc_html_e( 'Note: settings that reference site content (rewards page, products, coupons) expect that content to exist on this site.', 'simple-points-and-rewards' ); ?></em>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data"
					onsubmit="return window.confirm( '<?php echo esc_js( __( 'Importing will overwrite your current settings. Continue?', 'simple-points-and-rewards' ) ); ?>' );">
					<?php wp_nonce_field( 'spar_import_settings', 'spar_import_settings_nonce' ); ?>
					<input type="hidden" name="action" value="spar_import_settings" />
					<input type="file" name="spar_settings_file" accept=".json,application/json" required style="margin-bottom: 8px;" />
					<br/>
					<button type="submit" class="button">
						<span class="dashicons dashicons-upload" style="vertical-align: text-bottom;"></span>
						<?php esc_html_e( 'Import Settings', 'simple-points-and-rewards' ); ?>
					</button>
				</form>
			</div>
		</div>
	</div>
	<?php
}
