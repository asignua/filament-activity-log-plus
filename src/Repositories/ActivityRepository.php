<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Repositories;

use Asignua\FilamentActivityLogPlus\Models\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Every read of the log the UI needs, and its rotation. The model class comes from
 * `activitylog.activity_model`, so a host subclass is honoured.
 */
class ActivityRepository
{
    /**
     * @return class-string<Activity>
     */
    public function model(): string
    {
        /** @var class-string<Activity> $model */
        $model = config('activitylog.activity_model', Activity::class);

        return $model;
    }

    /**
     * @return Builder<Activity>
     */
    public function query(): Builder
    {
        $model = $this->model();

        /** @var Builder<Activity> */
        return $model::query();
    }

    /**
     * An entry that lost the root of its operation to the record's own lifecycle entry.
     */
    public function demoteRoot(int|string $id): void
    {
        $this->query()->whereKey($id)->update(['batch_root' => false]);
    }

    /**
     * One entry as a collection of one: the "outside a batch" branch of
     * {@see \Asignua\FilamentActivityLogPlus\Support\ActivityPresenter::batch()}.
     *
     * @return Collection<int, Activity>
     */
    public function withKey(int $id): Collection
    {
        return $this->query()->whereKey($id)->get();
    }

    /**
     * The whole operation: entries with one batch_uuid, oldest first.
     *
     * @return Collection<int, Activity>
     */
    public function withBatchUuid(string $batchUuid): Collection
    {
        return $this->query()->where('batch_uuid', $batchUuid)->orderBy('id')->get();
    }

    /**
     * The history of one record: its morph subject, newest first, limited by `history_limit`.
     *
     * @return Collection<int, Activity>
     */
    public function forSubject(string $subjectType, int|string $subjectId, int $limit): Collection
    {
        return $this->query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Delete the entries older than $cutoff, in chunks: one DELETE over a year of log would
     * hold its locks long enough to slow the panel down.
     */
    public function prune(Carbon $cutoff, int $batchSize = 1000): int
    {
        $deleted = 0;

        do {
            $chunk = $this->query()
                ->where('created_at', '<', $cutoff)
                ->limit($batchSize)
                ->delete();

            $deleted += $chunk;
        } while ($chunk > 0);

        return $deleted;
    }

    /**
     * The event names that actually occur in the log: the options of the table filter.
     *
     * @return list<string>
     */
    public function distinctEvents(): array
    {
        /** @var list<string> */
        return $this->query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event')->all();
    }

    /**
     * The subject types that actually occur in the log.
     *
     * @return list<string>
     */
    public function distinctSubjectTypes(): array
    {
        /** @var list<string> */
        return $this->query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type')->all();
    }

    /**
     * The causer labels by causer id, for the "User" filter.
     *
     * @return array<int|string, string>
     */
    public function causerLabelsById(): array
    {
        /** @var array<int|string, string> */
        return $this->query()
            ->whereNotNull('causer_id')
            ->whereNotNull('causer_label')
            ->distinct()
            ->orderBy('causer_label')
            ->pluck('causer_label', 'causer_id')
            ->all();
    }
}
