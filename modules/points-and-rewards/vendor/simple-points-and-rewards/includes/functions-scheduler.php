<?php
/**
 * Scheduled task helpers.
 *
 * All plugin background jobs run through Action Scheduler (bundled with
 * WooCommerce) when it is available, with a transparent WP-Cron fallback.
 * Hook names are identical to the legacy WP-Cron implementation, so the
 * same handlers run regardless of which scheduler fires the hook. When
 * Action Scheduler takes over a hook on an existing site, the legacy
 * WP-Cron event for that hook is cleared automatically.
 *
 * @package SimplePointsAndRewards
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'spar_scheduler_group' ) ) {
	/**
	 * Action Scheduler group used for all plugin actions.
	 *
	 * @return string
	 */
	function spar_scheduler_group() {
		return 'simple-points-and-rewards';
	}
}

if ( ! function_exists( 'spar_scheduler_is_available' ) ) {
	/**
	 * Whether Action Scheduler can be used for scheduling.
	 *
	 * @return bool
	 */
	function spar_scheduler_is_available() {
		$available = function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_next_scheduled_action' )
			&& function_exists( 'as_unschedule_all_actions' );

		/**
		 * Filter whether Action Scheduler should be used for plugin jobs.
		 *
		 * Returning false forces the WP-Cron fallback.
		 *
		 * @param bool $available Whether Action Scheduler functions are available.
		 */
		return (bool) apply_filters( 'spar_scheduler_use_action_scheduler', $available );
	}
}

if ( ! function_exists( 'spar_scheduler_get_jobs' ) ) {
	/**
	 * Get the recorded state of ensured recurring jobs.
	 *
	 * Used to avoid querying the Action Scheduler tables on every request
	 * just to confirm a recurring action is still scheduled.
	 *
	 * @return array<string, array{interval:int, checked:int}>
	 */
	function spar_scheduler_get_jobs() {
		$jobs = get_option( 'spar_scheduler_jobs', array() );

		return is_array( $jobs ) ? $jobs : array();
	}
}

if ( ! function_exists( 'spar_scheduler_remember_job' ) ) {
	/**
	 * Record that a recurring job was verified as scheduled.
	 *
	 * @param string $hook     Action hook name.
	 * @param int    $interval Interval in seconds.
	 */
	function spar_scheduler_remember_job( $hook, $interval ) {
		$jobs          = spar_scheduler_get_jobs();
		$jobs[ $hook ] = array(
			'interval' => (int) $interval,
			'checked'  => time(),
		);

		update_option( 'spar_scheduler_jobs', $jobs, true );
	}
}

if ( ! function_exists( 'spar_scheduler_forget_job' ) ) {
	/**
	 * Remove a hook from the recorded job state.
	 *
	 * @param string $hook Action hook name.
	 */
	function spar_scheduler_forget_job( $hook ) {
		$jobs = spar_scheduler_get_jobs();

		if ( isset( $jobs[ $hook ] ) ) {
			unset( $jobs[ $hook ] );
			update_option( 'spar_scheduler_jobs', $jobs, true );
		}
	}
}

if ( ! function_exists( 'spar_schedule_recurring_action' ) ) {
	/**
	 * Ensure a recurring job is scheduled, preferring Action Scheduler.
	 *
	 * Safe to call on every request (typically from `init`): once a job has
	 * been verified, the verification is skipped until the recheck window
	 * elapses, so no scheduler queries run on normal page loads.
	 *
	 * @param string   $hook       Action hook name (fired with no args).
	 * @param int      $interval   Interval in seconds.
	 * @param string   $recurrence WP-Cron recurrence name used by the fallback (e.g. 'hourly', 'daily').
	 * @param int|null $first_run  Optional first-run timestamp. Defaults to now + 1 minute.
	 * @return bool Whether the job is scheduled (via either scheduler).
	 */
	function spar_schedule_recurring_action( $hook, $interval, $recurrence = 'daily', $first_run = null ) {
		$interval  = max( MINUTE_IN_SECONDS, (int) $interval );
		$first_run = null === $first_run ? time() + MINUTE_IN_SECONDS : max( time(), (int) $first_run );

		if ( spar_scheduler_is_available() ) {
			$jobs = spar_scheduler_get_jobs();

			/**
			 * Filter how often (in seconds) a recurring job is re-verified
			 * against the Action Scheduler tables.
			 *
			 * @param int    $recheck Recheck window in seconds.
			 * @param string $hook    Action hook name.
			 */
			$recheck = (int) apply_filters( 'spar_scheduler_recheck_interval', 12 * HOUR_IN_SECONDS, $hook );

			if ( isset( $jobs[ $hook ]['interval'], $jobs[ $hook ]['checked'] )
				&& (int) $jobs[ $hook ]['interval'] === $interval
				&& ( time() - (int) $jobs[ $hook ]['checked'] ) < $recheck ) {
				return true;
			}

			try {
				if ( false === as_next_scheduled_action( $hook, null, spar_scheduler_group() ) ) {
					// The 6th (unique) argument is honoured by Action Scheduler 3.6+
					// and harmlessly ignored by older versions.
					as_schedule_recurring_action( $first_run, $interval, $hook, array(), spar_scheduler_group(), true );
				}

				// Migrate existing sites: the same hook may still have a legacy
				// WP-Cron event scheduled by previous plugin versions.
				if ( wp_next_scheduled( $hook ) ) {
					wp_clear_scheduled_hook( $hook );
				}

				spar_scheduler_remember_job( $hook, $interval );

				return true;
			} catch ( Exception $e ) {
				// Fall through to the WP-Cron fallback below.
			}
		}

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( $first_run, $recurrence, $hook );
		}

		return true;
	}
}

if ( ! function_exists( 'spar_schedule_followup_action' ) ) {
	/**
	 * Schedule a one-off follow-up action, used to chain batched jobs.
	 *
	 * @param string $hook          Action hook name.
	 * @param array  $args          Positional arguments passed to the hook.
	 * @param int    $delay_seconds Delay before the action may run.
	 * @return bool Whether the follow-up was scheduled (or already pending).
	 */
	function spar_schedule_followup_action( $hook, $args = array(), $delay_seconds = 30 ) {
		$args      = array_values( (array) $args );
		$timestamp = time() + max( 0, (int) $delay_seconds );

		if ( spar_scheduler_is_available() ) {
			try {
				if ( false === as_next_scheduled_action( $hook, $args, spar_scheduler_group() ) ) {
					as_schedule_single_action( $timestamp, $hook, $args, spar_scheduler_group() );
				}

				return true;
			} catch ( Exception $e ) {
				// Fall through to the WP-Cron fallback below.
			}
		}

		// WP-Cron ignores single events scheduled within 10 minutes of an
		// identical pending event, which provides equivalent deduplication.
		wp_schedule_single_event( max( $timestamp, time() + MINUTE_IN_SECONDS ), $hook, $args );

		return true;
	}
}

if ( ! function_exists( 'spar_unschedule_action' ) ) {
	/**
	 * Unschedule all pending occurrences of a hook from both schedulers.
	 *
	 * @param string $hook  Action hook name.
	 * @param bool   $force Query Action Scheduler even when the job was never
	 *                      recorded as scheduled (used on deactivation).
	 */
	function spar_unschedule_action( $hook, $force = false ) {
		$jobs = spar_scheduler_get_jobs();

		if ( ( $force || isset( $jobs[ $hook ] ) ) && function_exists( 'as_unschedule_all_actions' ) ) {
			try {
				// Hook-only form: cancels every pending action with this hook
				// in a single store call, regardless of args or group.
				as_unschedule_all_actions( $hook );
			} catch ( Exception $e ) {
				// Nothing else to do; the WP-Cron clear below still runs.
			}
		}

		spar_scheduler_forget_job( $hook );
		wp_clear_scheduled_hook( $hook );
	}
}

if ( ! function_exists( 'spar_unschedule_job' ) ) {
	/**
	 * Unschedule a recurring job and its chained batch hooks, doing no work
	 * when the job is not known to be scheduled.
	 *
	 * Feature-disable paths call this on every request. The known-scheduled
	 * check keeps the steady disabled state free of scheduler queries, while
	 * the disable transition force-cancels the recurring action AND any
	 * in-flight chained batch actions (which are never recorded in the jobs
	 * option and would otherwise survive).
	 *
	 * @param string   $hook        Recurring action hook name.
	 * @param string[] $chain_hooks Follow-up hooks chained by the job's batches.
	 */
	function spar_unschedule_job( $hook, $chain_hooks = array() ) {
		$jobs = spar_scheduler_get_jobs();

		if ( ! isset( $jobs[ $hook ] ) && ! wp_next_scheduled( $hook ) ) {
			return;
		}

		spar_unschedule_action( $hook, true );

		foreach ( (array) $chain_hooks as $chain_hook ) {
			spar_unschedule_action( $chain_hook, true );
		}
	}
}
