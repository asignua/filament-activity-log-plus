<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus;

use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;

/**
 * Registers the daily prune. Off by default (`activity-log-plus.schedule.enabled`).
 *
 * A class of its own, not the body of a service-provider callback, so that a test can pass a
 * fresh `Schedule` with the config it needs.
 */
final class Schedule
{
    public static function register(LaravelSchedule $schedule): void
    {
        if (!(bool) config('activity-log-plus.schedule.enabled', false)) {
            return;
        }

        $schedule->command('activity-log-plus:prune')
            ->dailyAt((string) config('activity-log-plus.schedule.time', '03:30'))
            ->withoutOverlapping();
    }
}
