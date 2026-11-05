<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Queue_Management;

use ActionScheduler_Store;
use Closure;

/**
 * Represents a single dedicated queue scope for scheduled actions.
 *
 * Each instance is a named registration that, on every Nth invocation of the Action Scheduler queue runner, narrows
 * that run to a designated set of Action Scheduler groups. Correspondingly, it can also be configured to try and remove
 * those same groups from 'regular' queue runs.
 *
 * One instance corresponds to one registered scope. The class is inert until {@see setup()} is called: the
 * constructor only captures dependencies and never adds hooks or otherwise reaches into WordPress / Action
 * Scheduler.
 *
 * The turn counter is the single input that decides whether a run is a focus turn, so it is read from and
 * written to the options table directly rather than through the Options API. A persistent object cache that
 * serves a stale value (or a stale `notoptions` entry) would otherwise freeze the counter below the rotation
 * threshold, and with {@see Queue_Isolator} engaged that leaves subscription actions unclaimed on every run
 * with no error anywhere. If the counter cannot be read or written at all, the scope stands down: it stops
 * acting for the rest of the process, logs an error, and notifies its owner through the optional
 * persistence-failure callback so the surrounding subsystem can switch itself off rather than starve
 * subscription work. Standing down leaves the hooks registered on purpose: removing a callback from inside
 * the hook it is running on makes WP_Hook skip the next priority bucket, which would drop third-party
 * callbacks on that run.
 *
 * See `README.md` in this directory for the subsystem's motivation and the cooperation model with the other
 * Queue_Management classes.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Dedicated_Queue {

	use Resolves_Existing_Groups;

	/**
	 * Prefix for the per-scope option key that persists the turn counter. Suffixed with `$this->name`.
	 */
	private const COUNTER_OPTION_PREFIX = 'wcs_dedicated_queue_counter_';

	/**
	 * Late hook priority so any other code with scoping intent (e.g. the WP-CLI command, another plugin) gets to
	 * populate claim filters before we look at them.
	 */
	private const HOOK_PRIORITY = 100;

	/**
	 * WC Logger source for diagnostic entries.
	 */
	private const LOG_SOURCE = 'woocommerce-subscriptions-dedicated-queue';

	/**
	 * Identifier for this scope. Used to namespace per-scope state (e.g. the turn counter option key).
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Action Scheduler group(s) this scope claims a share of runs for.
	 *
	 * @var string[]
	 */
	private array $groups;

	/**
	 * Rescope every Nth run of the queue runner to this scope. A value of 2 means "every other run".
	 *
	 * @var int
	 */
	private int $rotation;

	/**
	 * Whether the most recent before-process invocation set the `group` claim filter for this scope. Drives
	 * the after-process cleanup decision: if we did not set it, we have nothing to clean up. Reset
	 * unconditionally at the start of each cleanup so subsequent runs start from a known baseline.
	 *
	 * @var bool
	 */
	private bool $filter_applied = false;

	/**
	 * Set once the turn counter could not be read or written. From then on the hook callbacks are inert for
	 * the rest of the process; the owner decides whether the feature stays off across requests.
	 *
	 * @var bool
	 */
	private bool $stood_down = false;

	/**
	 * Invoked once, after this scope has stood down, when the turn counter can no longer be read
	 * from or written to the database. Lets the owner take the rest of the subsystem down with it.
	 *
	 * @var Closure|null
	 */
	private ?Closure $on_persistence_failure;

	/**
	 * @param string       $name                   Identifier for this scope.
	 * @param string[]     $groups                 Action Scheduler group(s) to scope rescoped runs to.
	 * @param int          $rotation               Rescope every Nth run. Defaults to 2.
	 * @param Closure|null $on_persistence_failure Called when the turn counter cannot be persisted. Optional.
	 */
	public function __construct( string $name, array $groups, int $rotation = 2, ?Closure $on_persistence_failure = null ) {
		$this->name                   = $name;
		$this->groups                 = $groups;
		$this->rotation               = $rotation;
		$this->on_persistence_failure = $on_persistence_failure;
	}

	/**
	 * Integrates this dedicated queue with Action Scheduler by registering the listener pair that decides whether
	 * to rescope each run.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'action_scheduler_before_process_queue', array( $this, 'maybe_apply_scope' ), self::HOOK_PRIORITY );
		add_action( 'action_scheduler_after_process_queue', array( $this, 'maybe_clear_scope' ), self::HOOK_PRIORITY );
	}

	/**
	 * Reverses {@see setup()} by removing the listener pair. The class becomes inert again; subsequent queue
	 * runs are unaffected by this scope until {@see setup()} is called once more.
	 *
	 * @return void
	 */
	public function teardown(): void {
		remove_action( 'action_scheduler_before_process_queue', array( $this, 'maybe_apply_scope' ), self::HOOK_PRIORITY );
		remove_action( 'action_scheduler_after_process_queue', array( $this, 'maybe_clear_scope' ), self::HOOK_PRIORITY );
	}

	/**
	 * Decide whether the current queue run should be rescoped to this dedicated queue's group(s); apply if so.
	 *
	 * Applies only when all gates pass: feature is enabled, the active store exposes the claim-filter API, no
	 * pre-existing claim filters are populated, and the turn counter has reached the configured rotation. The
	 * counter advances on every non-deferred run and resets on the run we apply. Runs deferred because of a
	 * foreign claim filter do not consume a turn. Each invocation that gets past the enable check emits
	 * exactly one debug log entry capturing the outcome.
	 *
	 * The counter is persisted before the scope is applied. A focus turn that could not be recorded would
	 * repeat on every subsequent run, so a failed read or write instead makes the scope stand down (see
	 * {@see stand_down()}) without touching the claim filter.
	 *
	 * Isolating subscription work from non-rescoped runs (by asserting an `exclude-groups` filter) is the
	 * job of {@see Queue_Isolator}, not this class. This class only ever sets the `group` filter.
	 *
	 * @return void
	 */
	public function maybe_apply_scope(): void {
		if ( $this->stood_down || ! $this->is_enabled() ) {
			return;
		}

		$store = $this->get_capable_store();
		if ( null === $store ) {
			$this->log(
				sprintf(
					'Dedicated queue runner "%1$s" could not be created: active store does not support claim filtering.',
					$this->name
				)
			);
			return;
		}

		// A `group` claim for a slug that has never been used makes Action Scheduler throw when it resolves the
		// slug at claim time, aborting the entire focus run. Skip without consuming a rotation turn (return
		// before the counter is touched), so we apply the scope on a later run once the group exists. See
		// Resolves_Existing_Groups for the full rationale.
		if ( empty( $this->existing_groups( $this->groups ) ) ) {
			$this->log( sprintf( 'Dedicated queue runner "%1$s" not applied: none of its groups exist yet.', $this->name ) );
			return;
		}

		$existing_filter = $this->find_existing_claim_filter( $store );
		$counter         = $this->read_counter();

		if ( null === $counter ) {
			$this->stand_down( 'read' );
			return;
		}

		$cycle   = $counter + 1;
		$applied = false;

		if ( null === $existing_filter ) {
			$is_focus_turn = $cycle >= $this->rotation;
			$written       = $this->write_counter( $is_focus_turn ? 0 : $cycle );

			if ( ! $written ) {
				$this->stand_down( 'written' );
				return;
			}

			if ( $is_focus_turn ) {
				// @phpstan-ignore method.notFound (see earlier safety check using $this->get_capable_store())
				$store->set_claim_filter( 'group', $this->groups );
				$this->filter_applied = true;
				$applied              = true;
			}
		}

		$this->log( $this->format_outcome( $existing_filter, $cycle, $applied ) );
	}

	/**
	 * Best-effort cleanup of the claim filter we set in the matching before-process invocation.
	 *
	 * The filter is cleared only if its current value still matches what we set, so we do not clobber a value
	 * written by something else between the two hooks. The `$applied_filter` field is reset unconditionally so
	 * a subsequent run starts from a clean slate.
	 *
	 * @return void
	 */
	public function maybe_clear_scope(): void {
		if ( ! $this->filter_applied ) {
			return;
		}
		$this->filter_applied = false;

		$store = $this->get_capable_store();

		// If $store is null, then it does not support setting/getting claim filters.
		if ( null === $store ) {
			return;
		}

		// @phpstan-ignore method.notFound (see earlier safety check using $this->get_capable_store())
		if ( $this->groups === $store->get_claim_filter( 'group' ) ) {
			// @phpstan-ignore method.notFound (see earlier safety check using $this->get_capable_store())
			$store->set_claim_filter( 'group', '' );
		}
	}

	/**
	 * Whether the feature is enabled for this scope. Default `false` (opt-in).
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		/**
		 * Filter the enabled state of dedicated Action Scheduler queues. Default `false` — opt in by returning
		 * `true`. Receives the scope name and groups so a single filter callback can make per-scope decisions.
		 *
		 * @since 8.8.0
		 *
		 * @param bool     $enabled Whether the dedicated queue mechanism is enabled. Default false.
		 * @param string   $name    Scope identifier.
		 * @param string[] $groups  Scope's Action Scheduler groups.
		 */
		return (bool) apply_filters( 'wcs_dedicated_queue_enabled', false, $this->name, $this->groups );
	}

	/**
	 * Returns the active Action Scheduler store, but only if it exposes the claim-filter API. Otherwise null.
	 *
	 * The capability gate is method-based (`is_callable`) rather than class-based: any future store that adopts
	 * the same interface engages automatically.
	 *
	 * @return ActionScheduler_Store|null
	 */
	private function get_capable_store(): ?ActionScheduler_Store {
		$store = ActionScheduler_Store::instance();

		if ( ! is_callable( array( $store, 'get_claim_filter' ) ) || ! is_callable( array( $store, 'set_claim_filter' ) ) ) {
			return null;
		}

		return $store;
	}

	/**
	 * Locate the first pre-populated claim filter on the store, if any. The three known filters (`group`,
	 * `hooks`, `exclude-groups`) are checked in turn; the first one with a non-empty value is returned.
	 *
	 * If anything comes back, someone (the WP-CLI command, another plugin, an earlier-priority listener) has
	 * already declared an intent for this run, and we must defer. Returning the filter name/value (rather than a
	 * bool) lets the caller include diagnostic detail in the outcome log.
	 *
	 * @param ActionScheduler_Store $store Capable store, as returned by {@see get_capable_store()}.
	 *
	 * @return array{name: string, value: mixed}|null
	 */
	private function find_existing_claim_filter( ActionScheduler_Store $store ): ?array {
		foreach ( array( 'group', 'hooks', 'exclude-groups' ) as $filter_name ) {
			// @phpstan-ignore method.notFound (see safety check made in the calling method using $this->get_capable_store())
			$value = $store->get_claim_filter( $filter_name );
			if ( ! empty( $value ) ) {
				return array(
					'name'  => $filter_name,
					'value' => $value,
				);
			}
		}

		return null;
	}

	/**
	 * Build the outcome line for a {@see maybe_apply_scope()} invocation that got past the enable and
	 * capable-store gates. Three shapes:
	 *
	 *  - Applied: "Dedicated queue runner "scope-name" created. Cycle: 2/2."
	 *  - Blocked: "Dedicated queue runner "scope-name" could not be created. Existing claim: group=foo. Cycle: 2/2."
	 *  - Not yet: "Dedicated queue runner "scope-name" not created this run: rotation turn 1 of 2 (subscription-focused run happens on turn 2)."
	 *
	 * The "not yet" shape fires on every non-focus run by design, so it is worded as a scheduled skip rather
	 * than a failure; only the genuinely blocked shape (a foreign claim filter) keeps "could not be created".
	 *
	 * `$cycle` reflects the in-memory turn value for the run (i.e. what would have been written had we
	 * proceeded), which keeps the log meaningful for both deferred and pre-rotation outcomes.
	 *
	 * @param array{name: string, value: mixed}|null $existing_filter Pre-set claim filter, or null if none.
	 * @param int                                    $cycle           Turn counter as observed in this run.
	 * @param bool                                   $applied         Whether the scope was applied this run.
	 *
	 * @return string
	 */
	private function format_outcome( ?array $existing_filter, int $cycle, bool $applied ): string {
		if ( $applied ) {
			return sprintf( 'Dedicated queue runner "%1$s" created. Cycle: %2$d/%3$d.', $this->name, $cycle, $this->rotation );
		}

		// If the scope was not applied, and there was no existing filter, that indicates that we simply have not reached
		// the rotation turn yet (vs bailing out because of an existing filter).
		if ( null === $existing_filter ) {
			return sprintf(
				'Dedicated queue runner "%1$s" not created this run: rotation turn %2$d of %3$d (subscription-focused run happens on turn %3$d).',
				$this->name,
				$cycle,
				$this->rotation
			);
		}

		$value = is_array( $existing_filter['value'] )
			? implode( ',', $existing_filter['value'] )
			: (string) $existing_filter['value'];
		$claim = sprintf( '%s=%s', $existing_filter['name'], $value );

		return sprintf(
			'Dedicated queue runner "%1$s" could not be created. Existing claim: %2$s. Cycle: %3$d/%4$d.',
			$this->name,
			$claim,
			$cycle,
			$this->rotation
		);
	}

	/**
	 * Emit a debug-level entry to the WooCommerce logger, prefixed with this scope's identifier (a
	 * colon-concatenated list of group names) so multiple co-resident Dedicated_Queue instances can be told
	 * apart in the log.
	 *
	 * @param string $message Pre-formatted message body.
	 * @param string $level   WC logger level. Defaults to 'debug'; stand-down entries use 'error'.
	 *
	 * @return void
	 */
	private function log( string $message, string $level = 'debug' ): void {
		wc_get_logger()->{$level}(
			sprintf( '[scope=%s] %s', implode( ':', $this->groups ), $message ),
			array( 'source' => self::LOG_SOURCE )
		);
	}

	/**
	 * Read the persisted turn counter for this scope straight from the options table.
	 *
	 * Deliberately bypasses `get_option()`: the counter is the only input to the focus-turn decision, and a
	 * persistent object cache that serves a stale value (or a stale `notoptions` entry) would freeze it. The
	 * cost is one indexed query per queue run.
	 *
	 * @return int|null The counter (0 when no row exists yet), or null if the query failed.
	 */
	private function read_counter(): ?int {
		global $wpdb;

		$table = $wpdb->options;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$table} WHERE option_name = %s",
				$this->counter_option_name()
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( '' !== $wpdb->last_error ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * Persist the turn counter for this scope straight to the options table, inserting the row on first use.
	 * Stored as a non-autoloaded option to keep the autoload payload lean.
	 *
	 * Deliberately bypasses `update_option()`, whose own cached pre-read short-circuits the write whenever the
	 * cache already holds the value being written. The object cache is not updated: nothing reads this option
	 * through the Options API.
	 *
	 * A failed write is retried once before it counts as a failure. Two queue runners can overlap (see
	 * {@see Concurrent_Batches_Booster}) and race this upsert on the same row; a deadlock or lock wait
	 * timeout between them is transient, and one retry keeps it from switching the feature off.
	 *
	 * @param int $counter The new counter value.
	 *
	 * @return bool Whether the write succeeded. A write that changes nothing (another runner persisted the same
	 *              value first) still counts as a success; only a query error is a failure.
	 */
	private function write_counter( int $counter ): bool {
		global $wpdb;

		if ( $this->upsert_counter( $counter ) ) {
			return true;
		}

		$this->log( sprintf( 'Turn counter write failed and will be retried once. Database error: %s', $wpdb->last_error ) );

		return $this->upsert_counter( $counter );
	}

	/**
	 * Single attempt at the counter upsert.
	 *
	 * @param int $counter The new counter value.
	 *
	 * @return bool False only when the query errored.
	 */
	private function upsert_counter( int $counter ): bool {
		global $wpdb;

		$table = $wpdb->options;
		$value = (string) $counter;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' ) ON DUPLICATE KEY UPDATE option_value = %s",
				$this->counter_option_name(),
				$value,
				$value
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return false !== $result;
	}

	/**
	 * Option key under which this scope's turn counter is stored.
	 *
	 * @return string
	 */
	private function counter_option_name(): string {
		return self::COUNTER_OPTION_PREFIX . $this->name;
	}

	/**
	 * Take this scope out of service after the turn counter could not be read or written.
	 *
	 * Marks the scope as stood down (so the claim filter is never set on the strength of a counter we cannot
	 * trust), records an error-level entry with the database error, and then hands control to the owner's
	 * persistence-failure callback, if one was supplied. The hooks stay registered: see the class docblock.
	 *
	 * @param string $failed_operation Past-tense verb for the log line: 'read' or 'written'.
	 *
	 * @return void
	 */
	private function stand_down( string $failed_operation ): void {
		global $wpdb;

		// Captured first: anything below (including the logger's own bootstrap) may run a query and reset it.
		$database_error = '' !== $wpdb->last_error ? $wpdb->last_error : 'none reported';

		$this->stood_down = true;

		$this->log(
			sprintf(
				'Dedicated queue runner "%1$s" stood down: its turn counter could not be %2$s. Database error: %3$s',
				$this->name,
				$failed_operation,
				$database_error
			),
			'error'
		);

		if ( null !== $this->on_persistence_failure ) {
			( $this->on_persistence_failure )();
		}
	}
}
