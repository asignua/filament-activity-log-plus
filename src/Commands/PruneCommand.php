<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Commands;

use Asignua\FilamentActivityLogPlus\Repositories\ActivityRepository;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'activity-log-plus:prune {--days= : Override the retention_days config}';

    protected $description = 'Delete activity log entries older than the retention period';

    public function handle(ActivityRepository $repository): int
    {
        $days = (int) ($this->option('days') ?? config('activity-log-plus.retention_days', 365));

        if ($days <= 0) {
            $this->info('Pruning is disabled (retention_days = 0).');

            return self::SUCCESS;
        }

        $deleted = $repository->prune(now()->subDays($days));

        $this->info("Deleted {$deleted} activity log entries older than {$days} days.");

        return self::SUCCESS;
    }
}
