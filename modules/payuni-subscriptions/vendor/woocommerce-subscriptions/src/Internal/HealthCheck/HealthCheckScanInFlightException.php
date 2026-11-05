<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

use RuntimeException;

/**
 * Thrown by `ScheduleManager::start_scan()` when the atomic scan-start
 * guard in `RunStore::start()` rejects the insert because another scan
 * of the same type is already in flight — i.e. a concurrent caller won
 * the race between the pre-check `get_in_flight_scan()` and the
 * guarded INSERT.
 *
 * Having a dedicated type lets `StatusTab::run_scan()` catch the race
 * branch with its own clause (mapped to the `scan_already_running`
 * merchant notice) without matching on the exception message string.
 * The string-match form would silently stop routing correctly if the
 * message in `start_scan()` is ever reworded — same fragility the
 * DB-failure branch hit.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class HealthCheckScanInFlightException extends RuntimeException {
}
