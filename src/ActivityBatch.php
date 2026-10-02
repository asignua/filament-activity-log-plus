<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus;

use Closure;
use Illuminate\Support\Str;

/**
 * "One operation" = one `batch_uuid`.
 *
 * Why: in a panel a single save is several independent writes: the model's `save()`, N pivot
 * syncs (which fire no Eloquent events) and separate media records that Filament writes
 * past your repositories. Without stitching them together the log would show a dozen rows
 * instead of one user action.
 *
 * activitylog 4 had `LogBatch` for this; version 5 removed it, so the plugin keeps its own.
 *
 * Bound as a SCOPED singleton (one per request, also under Octane). The batch is opened by
 * {@see Http\Middleware\ActivityBatchMiddleware} on panel requests and by a Livewire
 * `request` hook: one Livewire round trip is one user action. Outside a batch (console,
 * queue) the uuid is empty and every entry is its own root; wrap such code in {@see self::run()}.
 */
class ActivityBatch
{
    private ?string $uuid = null;

    private bool $rootClaimed = false;

    public function start(): string
    {
        $this->uuid = (string) Str::uuid();
        $this->rootClaimed = false;

        return $this->uuid;
    }

    public function end(): void
    {
        $this->uuid = null;
        $this->rootClaimed = false;
    }

    public function uuid(): ?string
    {
        return $this->uuid;
    }

    /**
     * Is the entry being written the root of the current operation?
     *
     * Filament saves the model first, then the pivots, then the media, so the save of the
     * record becomes the root. If only a picture changed (no dirty attributes, so no
     * model entry) the first media entry becomes the root. Outside a batch every entry
     * is a root.
     */
    public function claimRoot(): bool
    {
        if ($this->uuid === null) {
            return true;
        }

        if ($this->rootClaimed) {
            return false;
        }

        $this->rootClaimed = true;

        return true;
    }

    /**
     * Run a callback in its own batch (console commands, jobs, tests). The previous state is
     * restored afterwards, so batches can be nested.
     */
    public function run(Closure $callback): mixed
    {
        $previousUuid = $this->uuid;
        $previousRoot = $this->rootClaimed;

        $this->start();

        try {
            return $callback();
        } finally {
            $this->uuid = $previousUuid;
            $this->rootClaimed = $previousRoot;
        }
    }
}
