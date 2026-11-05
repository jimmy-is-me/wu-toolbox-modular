<?php
/**
 * Creates and maintains the Health Check tool's custom tables.
 *
 * Tables:
 *  - wcs_health_check_candidates: detected candidates per scan run.
 *  - wcs_health_check_runs:       metadata for each scan run.
 *
 * @package WooCommerce Subscriptions
 */

defined( 'ABSPATH' ) || exit;

class WCS_Health_Check_Table_Maker extends WCS_Table_Maker {
	/**
	 * @inheritDoc
	 *
	 * v2: reworked the candidates table indexes to better match the access
	 * patterns of CandidateStore — see `maybe_upgrade_candidates_indexes()`
	 * below for the migration detail.
	 *
	 * v3: added `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` to
	 * the candidates table so the Status tab's "Detected on" column has a
	 * per-row timestamp to render.
	 *
	 * v4: dropped `wcs_health_check_ignored` — the Ignore action was cut
	 * during the v1 scope pivot so the table never had a write path. The
	 * Detector's always-empty ignore-list filter was pure per-scan waste.
	 * Upgrade path drops the table; fresh installs never create it. See
	 * `maybe_drop_ignored_table()`.
	 *
	 * v5: added `signal_type VARCHAR(32) NOT NULL DEFAULT 'supports_auto_renewal'`
	 * to the candidates table and widened the existing `run_subscription`
	 * UNIQUE KEY to include it. The Missing renewals tab introduced a second
	 * signal that can legitimately co-exist with Supports-auto-renewal on the
	 * same subscription in the same run — without the widened key the second
	 * write would replace the first. dbDelta never drops indexes, so the key
	 * swap runs explicitly in `maybe_upgrade_candidates_signal_type()` before
	 * the parent hand-off.
	 */
	protected $schema_version = 5;

	/**
	 * WCS_Health_Check_Table_Maker constructor.
	 */
	public function __construct() {
		$this->tables = array(
			'wcs_health_check_candidates',
			'wcs_health_check_runs',
		);
	}

	/**
	 * Register the tables, first applying any explicit migrations that
	 * dbDelta can't handle on its own.
	 *
	 * dbDelta is effective at adding new columns and indexes, but it will
	 * never drop an index OR a table that used to exist. Two explicit
	 * migrations run before the parent hand-off:
	 *
	 *  - v1 → v2 candidates indexes: drop the legacy single-column `run_id`
	 *    / `status` indexes and replace with the composite `run_id_status`
	 *    + the `(run_id, subscription_id)` unique key.
	 *  - v3 → v4 ignored table: drop the retired `wcs_health_check_ignored`
	 *    table entirely.
	 *  - v4 → v5 signal_type column + widened unique key: add the column
	 *    then drop the narrow `run_subscription` unique key so dbDelta can
	 *    recreate it with the new `(run_id, subscription_id, signal_type)`
	 *    shape.
	 */
	public function register_tables() {
		$this->maybe_upgrade_candidates_indexes();
		$this->maybe_drop_ignored_table();

		// Bail before the parent's dbDelta + schema-version bump if the
		// signal_type pre-migration didn't complete cleanly. The parent
		// would otherwise call `mark_schema_update_complete()` after
		// dbDelta — which never drops indexes — pinning a half-migrated
		// schema on disk and starving every subsequent request of the
		// retry. Without this gate a single ALTER failure (DROP INDEX
		// permissions, transient lock timeout, etc.) silently downgrades
		// the unique key to its narrow shape forever, and CandidateStore
		// REPLACE writes start clobbering rows across signals. Returning
		// here leaves the schema-version option unchanged, so
		// `schema_is_out_of_date()` keeps returning true and the next
		// request retries the whole chain.
		if ( ! $this->maybe_upgrade_candidates_signal_type() ) {
			return;
		}

		// Capture whether the schema is out of date BEFORE handing off
		// to parent::register_tables(), which bumps the stored schema
		// version at the end of a successful dbDelta run. Reading after
		// the parent would always return false.
		$schema_was_out_of_date = $this->schema_is_out_of_date();

		parent::register_tables();

		// dbDelta's return value is a descriptive array, not a success signal
		// - see wordpress-develop/wp-admin/includes/upgrade.php:3356-3357 where
		// $wpdb->query's return is discarded. Mirror WC's HPOS pattern
		// (woocommerce/src/Internal/DataStores/Orders/DataSynchronizer.php:195)
		// and verify each table exists. A missing table here means scans will
		// fail silently at every SCAN_BATCH; log loudly so support can spot it.
		//
		// Gate the verification behind the same schema-update condition
		// the parent uses for dbDelta itself — once the tables exist and
		// are on the current schema, the steady-state request doesn't
		// need two SHOW TABLES queries on every admin-side page load
		// where Bootstrap calls this.
		if ( $schema_was_out_of_date ) {
			$tables_ok = $this->verify_tables_created();

			// Defense-in-depth: even when the pre-parent migration
			// reported success, dbDelta may have left the unique key
			// in its narrow shape on weird MySQL configurations. Probe
			// the key shape after dbDelta and reset the schema option
			// if it's not what we asked for, so the next request gets
			// another go at the migration rather than serving REPLACE
			// writes against a key that silently clobbers rows across
			// signals.
			//
			// Skipped when the candidates table itself didn't make it
			// — `verify_tables_created()` has already logged that
			// failure, and `SHOW INDEX FROM` against a missing table
			// would just print a duplicate "doesn't exist" notice.
			if ( $tables_ok ) {
				$this->verify_signal_type_key_shape();
			}
		}
	}

	/**
	 * Whether the stored schema version is behind this class's
	 * declared `$schema_version`. Parallels the parent's private
	 * `schema_update_required()` so the child can short-circuit
	 * steady-state verification without changing the parent API.
	 *
	 * @return bool
	 */
	private function schema_is_out_of_date(): bool {
		$stored = (string) get_option( 'wcs-schema-' . get_class( $this ), '0' );
		return version_compare( $stored, (string) $this->schema_version, '<' );
	}

	/**
	 * Verifies that every table expected to be created exists.
	 *
	 * Logs via WC's logger for any missing tables. Does not attempt repair.
	 *
	 * @return bool True if all tables exist.
	 */
	private function verify_tables_created(): bool {
		global $wpdb;

		$missing = array();
		foreach ( $this->tables as $table_key ) {
			$table_name = $wpdb->prefix . $table_key;
			$found      = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

			if ( $found !== $table_name ) {
				$missing[] = $table_name;
			}
		}

		if ( ! empty( $missing ) ) {
			wc_get_logger()->error(
				sprintf(
					'Health Check: required tables are missing after dbDelta - scans will fail. Missing: %s',
					implode( ', ', $missing )
				),
				array( 'source' => 'wcs-health-check' )
			);

			return false;
		}

		return true;
	}

	/**
	 * Drop legacy single-column indexes on the candidates table when
	 * upgrading from schema v1. No-op if the table hasn't been created yet
	 * (first-time install) or has already been upgraded.
	 */
	private function maybe_upgrade_candidates_indexes() {
		global $wpdb;

		$stored_version = (int) get_option( 'wcs-schema-' . get_class( $this ), 0 );
		if ( $stored_version < 1 || $stored_version >= $this->schema_version ) {
			return;
		}

		$table = $wpdb->prefix . 'wcs_health_check_candidates';

		// Only act if the table actually exists — on fresh installs dbDelta
		// will create it with the v2 layout directly.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $found !== $table ) {
			return;
		}

		foreach ( array( 'run_id', 'status' ) as $legacy_index ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SHOW INDEX FROM {$table} WHERE Key_name = %s",
					$legacy_index
				)
			);
			if ( $exists ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
				$result = $wpdb->query( "ALTER TABLE {$table} DROP INDEX {$legacy_index}" );
				if ( false === $result ) {
					wc_get_logger()->error(
						sprintf( 'Health Check: failed to drop legacy index %s on %s — %s', $legacy_index, $table, $wpdb->last_error ),
						array( 'source' => 'wcs-health-check' )
					);
				}
			}
		}
	}

	/**
	 * Add the `signal_type` column and drop the narrow `run_subscription`
	 * UNIQUE KEY when upgrading from any pre-v5 schema, so dbDelta can
	 * recreate the key with the widened `(run_id, subscription_id,
	 * signal_type)` shape. No-op on fresh installs — the table is created
	 * with the v5 layout directly.
	 *
	 * Same shape as `maybe_upgrade_candidates_indexes()`: dbDelta can add
	 * columns and indexes, but never drops an existing index, so the swap
	 * has to be explicit before the parent hand-off.
	 *
	 * Returns false when any step actually attempted (ADD COLUMN, DROP
	 * INDEX) reports a SQL failure — the caller skips the parent's
	 * dbDelta + schema-version bump on a false return so the next
	 * request retries. Returns true on the no-op paths (already on v5,
	 * fresh install where the table doesn't yet exist).
	 *
	 * @return bool True when the migration completed (or was a no-op).
	 */
	private function maybe_upgrade_candidates_signal_type(): bool {
		global $wpdb;

		$stored_version = (int) get_option( 'wcs-schema-' . get_class( $this ), 0 );
		if ( $stored_version < 1 || $stored_version >= $this->schema_version ) {
			return true;
		}

		$table = $wpdb->prefix . 'wcs_health_check_candidates';

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $found !== $table ) {
			return true;
		}

		// Add the column before swapping the key. dbDelta will handle both on
		// a fresh schema, but the legacy-row backfill default baked into the
		// column definition means existing rows are attributed to the
		// Supports-auto-renewal signal automatically.
		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SHOW COLUMNS FROM {$table} LIKE %s",
				'signal_type'
			)
		);

		if ( ! $column_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN signal_type VARCHAR(32) NOT NULL DEFAULT 'supports_auto_renewal' AFTER subscription_id" );
			if ( false === $result ) {
				wc_get_logger()->error(
					sprintf( 'Health Check: failed to add signal_type column to %s — %s', $table, $wpdb->last_error ),
					array( 'source' => 'wcs-health-check' )
				);
				return false;
			}
		}

		// Drop the narrow (run_id, subscription_id) unique key so dbDelta can
		// recreate it with the widened column list. The key name stays stable
		// — `run_subscription` — so `SHOW INDEX` queries in support tools keep
		// resolving.
		$key_exists = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SHOW INDEX FROM {$table} WHERE Key_name = %s",
				'run_subscription'
			)
		);

		if ( $key_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( "ALTER TABLE {$table} DROP INDEX run_subscription" );
			if ( false === $result ) {
				wc_get_logger()->error(
					sprintf( 'Health Check: failed to drop run_subscription index on %s — %s', $table, $wpdb->last_error ),
					array( 'source' => 'wcs-health-check' )
				);
				return false;
			}
		}

		return true;
	}

	/**
	 * Confirm the post-dbDelta `run_subscription` unique key includes
	 * `signal_type`. If the column is missing from the key — typically
	 * because dbDelta couldn't add the wider key while the narrower
	 * one still existed — log loudly and reset the schema option so
	 * `schema_is_out_of_date()` flags the next request for retry.
	 *
	 * Resetting the option is a stronger response than logging alone:
	 * the alternative is shipping CandidateStore writes against a key
	 * that silently REPLACE-clobbers rows across signals. The retry
	 * loop will eventually clear the wedged state once the underlying
	 * cause (locks, permissions, transient errors) clears.
	 *
	 * @return bool True when the unique key includes `signal_type`.
	 */
	private function verify_signal_type_key_shape(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'wcs_health_check_candidates';

		// SHOW INDEX … WHERE Key_name returns one row per key column.
		// We collect the Column_name values and check whether
		// signal_type made it in. ARRAY_A keeps the field names readable
		// across MySQL / MariaDB versions that occasionally rename
		// columns in SHOW output.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SHOW INDEX FROM {$table} WHERE Key_name = %s",
				'run_subscription'
			),
			ARRAY_A
		);

		$columns_in_key = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( isset( $row['Column_name'] ) ) {
					$columns_in_key[] = (string) $row['Column_name'];
				}
			}
		}

		if ( in_array( 'signal_type', $columns_in_key, true ) ) {
			return true;
		}

		wc_get_logger()->error(
			sprintf(
				'Health Check: run_subscription unique key on %s does not include signal_type after dbDelta — migration incomplete; resetting schema option to retry on the next request. Current key columns: %s',
				$table,
				empty( $columns_in_key ) ? '(none)' : implode( ', ', $columns_in_key )
			),
			array( 'source' => 'wcs-health-check' )
		);

		// Roll the stored schema version back to 0 so the next request
		// re-enters the migration chain. delete_option() is the
		// canonical "treat as fresh" path the parent's
		// `recreate_tables()` uses for the same purpose.
		delete_option( 'wcs-schema-' . get_class( $this ) );

		return false;
	}

	/**
	 * Drop the retired `wcs_health_check_ignored` table when upgrading
	 * from any pre-v4 schema. Fresh installs never had it, so the SHOW
	 * TABLES probe short-circuits for them.
	 *
	 * Same shape as `maybe_upgrade_candidates_indexes()` — dbDelta can
	 * add tables but never removes them, so we issue the DROP TABLE
	 * explicitly before handing control to the parent.
	 */
	private function maybe_drop_ignored_table() {
		global $wpdb;

		$stored_version = (int) get_option( 'wcs-schema-' . get_class( $this ), 0 );
		if ( $stored_version < 1 || $stored_version >= $this->schema_version ) {
			return;
		}

		$table = $wpdb->prefix . 'wcs_health_check_ignored';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $found !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		if ( false === $result ) {
			wc_get_logger()->error(
				sprintf( 'Health Check: failed to drop retired table %s — %s', $table, $wpdb->last_error ),
				array( 'source' => 'wcs-health-check' )
			);
		}
	}

	/**
	 * Gets the CREATE TABLE statement for a given Health Check table.
	 *
	 * @param string $table Table identifier (one of the values from $this->tables).
	 *
	 * @return string
	 */
	protected function get_table_definition( $table ) {
		global $wpdb;
		// phpcs:disable QITStandard.DB.DynamicWpdbMethodCall.DynamicMethod
		$table_name      = $wpdb->$table;
		$charset_collate = $wpdb->get_charset_collate();

		switch ( $table ) {
			case 'wcs_health_check_candidates':
				return "
				CREATE TABLE {$table_name} (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					run_id BIGINT UNSIGNED NOT NULL,
					subscription_id BIGINT UNSIGNED NOT NULL,
					signal_type VARCHAR(32) NOT NULL DEFAULT 'supports_auto_renewal',
					signal_summary LONGTEXT NULL,
					status VARCHAR(20) NOT NULL DEFAULT 'pending',
					fixed_at DATETIME NULL,
					errored_at DATETIME NULL,
					error_message TEXT NULL,
					snapshot_key VARCHAR(64) NULL,
					created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY  (id),
					UNIQUE KEY run_subscription (run_id, subscription_id, signal_type),
					KEY run_id_status (run_id, status),
					KEY run_id_signal_type (run_id, signal_type),
					KEY subscription_id (subscription_id)
				) $charset_collate;";

			case 'wcs_health_check_runs':
				return "
				CREATE TABLE {$table_name} (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					type VARCHAR(10) NOT NULL,
					started_at DATETIME NOT NULL,
					completed_at DATETIME NULL,
					status VARCHAR(20) NOT NULL DEFAULT 'running',
					triggered_by VARCHAR(32) NOT NULL,
					stats_json LONGTEXT NULL,
					error_message TEXT NULL,
					PRIMARY KEY  (id),
					KEY type_status (type, status)
				) $charset_collate;";

			default:
				return '';
		}
	}
}
