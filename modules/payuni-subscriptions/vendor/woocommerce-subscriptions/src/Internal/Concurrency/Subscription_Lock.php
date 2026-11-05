<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Concurrency;

/**
 * A short-lived, per-subscription lock, stored as a row in the options table.
 *
 * Acquiring is a single `INSERT IGNORE`, which the unique index on
 * `option_name` makes atomic: of two concurrent requests exactly one inserts
 * the row, and the other is told the lock is already held.
 *
 * `add_option()` is not a substitute. It checks for the option and then runs
 * `INSERT ... ON DUPLICATE KEY UPDATE`, which overwrites an existing row
 * instead of failing, so two callers which both find no option can both be
 * told they created it. Transients are not a substitute either: without a
 * persistent object cache they are written with `update_option()`, and with
 * one they can be evicted at any time.
 *
 * The row holds the lock's expiry and a random token. The expiry means a
 * request which dies before releasing its lock doesn't hold it forever. The
 * token means a request can only release the lock it took, and not one which
 * another request took over after it expired. Taking over an expired lock is a
 * compare-and-set on the value read, so two requests finding the same expired
 * lock can't both take it.
 *
 * A database error is logged and the write retried once. If it still fails,
 * the lock is treated as not acquired.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Subscription_Lock {

	/**
	 * Prefix for the option name the lock is stored under.
	 *
	 * @var string
	 */
	private $key_prefix;

	/**
	 * How long a lock is honoured for, in seconds.
	 *
	 * @var int
	 */
	private $ttl_seconds;

	/**
	 * The logger source lock recovery and database errors are recorded against.
	 *
	 * @var string
	 */
	private $log_source;

	/**
	 * The values this instance wrote for the locks it holds, keyed by subscription ID.
	 *
	 * @var array<int, string>
	 */
	private $held = array();

	/**
	 * Constructor.
	 *
	 * @param string $key_prefix  Prefix for the option name the lock is stored under. The subscription ID is appended.
	 * @param int    $ttl_seconds How long the lock is honoured for.
	 * @param string $log_source  The logger source lock recovery and database errors are recorded against.
	 */
	public function __construct( string $key_prefix, int $ttl_seconds, string $log_source ) {
		$this->key_prefix  = $key_prefix;
		$this->ttl_seconds = $ttl_seconds;
		$this->log_source  = $log_source;
	}

	/**
	 * Try to take the lock for a subscription.
	 *
	 * @param int $subscription_id The subscription to lock.
	 *
	 * @return bool True if the caller now holds the lock, false if someone else does or the database failed.
	 */
	public function acquire( int $subscription_id ): bool {
		$key      = $this->lock_key( $subscription_id );
		$now      = time();
		$value    = ( $now + $this->ttl_seconds ) . ':' . wp_generate_uuid4();
		$acquired = $this->insert( $key, $value );

		if ( ! $acquired ) {
			$current = $this->read_value( $key );

			if ( null === $current ) {
				// The row has gone since the insert failed, or it couldn't be read. Whoever deleted it may already be
				// holding a new lock, and a database which is failing will fail this insert too.
				$acquired = $this->insert( $key, $value );
			} elseif ( $this->parse_expiry( $current ) <= $now ) {
				// Recovering an expired lock is invisible without this, and a request which repeatedly dies mid-flight
				// would otherwise show up only as intermittent, unexplained contention.
				wc_get_logger()->debug(
					sprintf(
						'Taking over an expired lock — lock=%1$s subscription=%2$d expired=%3$d age=%4$ds',
						$key,
						$subscription_id,
						$this->parse_expiry( $current ),
						$now - $this->parse_expiry( $current )
					),
					array(
						'source'          => $this->log_source,
						'subscription_id' => $subscription_id,
					)
				);

				$acquired = $this->take_over( $key, $current, $value );
			}
		}

		if ( $acquired ) {
			$this->held[ $subscription_id ] = $value;
		}

		return $acquired;
	}

	/**
	 * Release a lock this instance holds on a subscription.
	 *
	 * Only the row this instance wrote is deleted. If the lock expired and another request has taken it over, that
	 * request's lock is left in place. Calling this again once the lock is released does nothing.
	 *
	 * @param int $subscription_id The subscription to unlock.
	 *
	 * @return void
	 */
	public function release( int $subscription_id ): void {
		global $wpdb;

		if ( ! isset( $this->held[ $subscription_id ] ) ) {
			return;
		}

		$key = $this->lock_key( $subscription_id );

		$this->write(
			$key,
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				$this->held[ $subscription_id ]
			)
		);

		unset( $this->held[ $subscription_id ] );
	}

	/**
	 * Insert the lock row, unless it already exists.
	 *
	 * @param string $key   The option name.
	 * @param string $value The lock's expiry and token.
	 *
	 * @return bool True if this caller inserted the row.
	 */
	private function insert( string $key, string $value ): bool {
		global $wpdb;

		return (bool) $this->write(
			$key,
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
				$key,
				$value
			)
		);
	}

	/**
	 * Read the value of the lock currently held, straight from the database.
	 *
	 * @param string $key The option name.
	 *
	 * @return string|null The lock's value, or null if no lock is held or it couldn't be read.
	 */
	private function read_value( string $key ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberate: a cached read of a lock is worthless.
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key )
		);

		if ( '' !== $wpdb->last_error ) {
			$this->log_database_error( $key, 'read' );
		}

		return null === $value ? null : (string) $value;
	}

	/**
	 * Take over an expired lock, provided nobody else has already done so.
	 *
	 * @param string $key       The option name.
	 * @param string $current   The value this caller read.
	 * @param string $new_value The value to set.
	 *
	 * @return bool True if this caller took the lock over.
	 */
	private function take_over( string $key, string $current, string $new_value ): bool {
		global $wpdb;

		return (bool) $this->write(
			$key,
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_value,
				$key,
				$current
			)
		);
	}

	/**
	 * Run a prepared write, retrying it once if the database reports an error.
	 *
	 * @param string $key   The option name, for logging.
	 * @param string $query The prepared query.
	 *
	 * @return int|false The number of rows affected, or false if the query failed twice.
	 */
	private function write( string $key, string $query ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Deliberate: the options API can't express these writes, and the query is prepared by the caller.
		$result = $wpdb->query( $query );

		if ( false === $result ) {
			// A blip which the retry absorbs is worth a record, but not an error: only a write which fails twice has
			// cost the caller its lock.
			$this->log_database_error( $key, 'write, retrying once', 'debug' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- As above.
			$result = $wpdb->query( $query );

			if ( false === $result ) {
				$this->log_database_error( $key, 'write retry' );
			}
		}

		return $result;
	}

	/**
	 * Log the database's most recent error.
	 *
	 * @param string $key       The option name.
	 * @param string $operation What was being attempted.
	 * @param string $level     The level to log at.
	 *
	 * @return void
	 */
	private function log_database_error( string $key, string $operation, string $level = 'error' ): void {
		global $wpdb;

		wc_get_logger()->log(
			$level,
			sprintf( 'Lock query failed — lock=%1$s operation=%2$s error=%3$s', $key, $operation, $wpdb->last_error ),
			array( 'source' => $this->log_source )
		);
	}

	/**
	 * The expiry recorded in a lock's value. A value which can't be read is treated as expired.
	 *
	 * @param string $value The lock's value.
	 *
	 * @return int The expiry as a Unix timestamp.
	 */
	private function parse_expiry( string $value ): int {
		return (int) explode( ':', $value, 2 )[0];
	}

	/**
	 * The option name a subscription's lock is stored under.
	 *
	 * @param int $subscription_id The subscription.
	 *
	 * @return string
	 */
	private function lock_key( int $subscription_id ): string {
		return $this->key_prefix . $subscription_id;
	}
}
