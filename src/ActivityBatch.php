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
    /** Model lifecycle events: they outrank pivot, media and custom entries of the SAME subject. */
    public const array LIFECYCLE_EVENTS = ['created', 'updated', 'deleted', 'restored'];

    private ?string $uuid = null;

    private bool $rootClaimed = false;

    /** Subject key (`type:id`) of the current root, null when it has none. */
    private ?string $rootSubject = null;

    /** Is the current root a model lifecycle event? */
    private bool $rootLifecycle = false;

    /** Is the current root an authentication entry? It is never displaced. */
    private bool $rootSealed = false;

    /** Primary key of the current root once it is saved. */
    private int|string|null $rootId = null;

    /** The root was just claimed and its id is not known yet ({@see self::confirmRoot()}). */
    private bool $awaitingId = false;

    /** The earlier root that the entry being written takes over from. */
    private int|string|null $displacedId = null;

    /** Is the batch opened by a real HTTP request (the panel middleware or the Livewire hook)? */
    private bool $http = false;

    /**
     * @param bool $http Opened by an HTTP request: artisan and queue workers bind a synthetic
     *                   request too, so {@see self::isHttp()} is the only reliable signal
     *                   (also under Octane, which runs in console mode)
     */
    public function start(bool $http = false): string
    {
        $this->http = $this->http || $http;
        $this->resetRoot();
        $this->uuid = $uuid = (string) Str::uuid();

        return $uuid;
    }

    public function end(): void
    {
        $this->uuid = null;
        $this->http = false;
        $this->resetRoot();
    }

    public function isHttp(): bool
    {
        return $this->http;
    }

    public function uuid(): ?string
    {
        return $this->uuid;
    }

    /**
     * Is the entry being written the root of the current operation?
     *
     * The first entry of a batch is the root, with one exception: a model lifecycle entry
     * (`created`/`updated`/`deleted`/`restored`) takes the root over from an EARLIER secondary
     * entry (pivot, media, custom) of the same subject. Filament does not guarantee the
     * order of a save: with a BelongsToMany relation the pivot sync can be written before
     * the record's own `updated` entry, and the operation would then read "relation changed"
     * instead of the record update. Between different subjects the first entry stays the
     * root. When the root is taken over, the caller learns the displaced row from
     * {@see self::confirmRoot()} and demotes it. Outside a batch every entry is a root.
     *
     * @param string|null $subject   Subject key (`type:id`), null when the entry has none
     * @param bool        $lifecycle Is the entry a model lifecycle event?
     * @param bool        $sealed    Is it an authentication entry? Its subject is the user, and a
     *                               host listener that updates the user on login (`last_login_at`)
     *                               must not take the sign-in's place as the root.
     */
    public function claimRoot(?string $subject = null, bool $lifecycle = true, bool $sealed = false): bool
    {
        if ($this->uuid === null) {
            return true;
        }

        if (!$this->rootClaimed) {
            $this->rootClaimed = true;
            $this->rootSubject = $subject;
            $this->rootLifecycle = $lifecycle;
            $this->rootSealed = $sealed;
            $this->awaitingId = true;

            return true;
        }

        if ($lifecycle && !$this->rootLifecycle && !$this->rootSealed && $subject !== null && $subject === $this->rootSubject) {
            $this->displacedId = $this->rootId;
            $this->rootLifecycle = true;
            $this->awaitingId = true;

            return true;
        }

        return false;
    }

    /**
     * The entry that {@see self::claimRoot()} made the root has been saved. Returns the id of
     * the row that lost the root to it (to be demoted), or null.
     */
    public function confirmRoot(int|string $id): int|string|null
    {
        if (!$this->awaitingId) {
            return null;
        }

        $this->awaitingId = false;
        $this->rootId = $id;

        $displaced = $this->displacedId;
        $this->displacedId = null;

        return $displaced;
    }

    private function resetRoot(): void
    {
        $this->rootClaimed = false;
        $this->rootSubject = null;
        $this->rootLifecycle = false;
        $this->rootSealed = false;
        $this->rootId = null;
        $this->awaitingId = false;
        $this->displacedId = null;
    }

    /**
     * Run a callback in its own batch (console commands, jobs, tests). The previous state is
     * restored afterwards, so batches can be nested.
     */
    public function run(Closure $callback): mixed
    {
        $previous = [
            $this->uuid, $this->rootClaimed, $this->rootSubject, $this->rootLifecycle, $this->rootSealed,
            $this->rootId, $this->awaitingId, $this->displacedId,
        ];

        $this->start();

        try {
            return $callback();
        } finally {
            [
                $this->uuid, $this->rootClaimed, $this->rootSubject, $this->rootLifecycle, $this->rootSealed,
                $this->rootId, $this->awaitingId, $this->displacedId,
            ] = $previous;
        }
    }
}
