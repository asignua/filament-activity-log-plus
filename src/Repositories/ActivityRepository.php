<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Repositories;

use Asignua\FilamentActivityLogPlus\Models\Activity;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Every read of the log the UI needs, and its rotation. The model class comes from
 * `activitylog.activity_model`, so a host subclass is honoured.
 */
class ActivityRepository
{
    /** Seconds the filter options stay cached. */
    public const int OPTIONS_TTL = 60;

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
     * The whole operation: entries with one batch_uuid, oldest first. A bulk action over
     * thousands of records is one operation too, so the modal asks for a $limit.
     *
     * @return Collection<int, Activity>
     */
    public function withBatchUuid(string $batchUuid, ?int $limit = null): Collection
    {
        return $this->query()
            ->where('batch_uuid', $batchUuid)
            ->orderBy('id')
            ->when($limit !== null, fn (Builder $query): Builder => $query->limit((int) $limit))
            ->get();
    }

    /**
     * Add `batch_others` to every row of the feed: how many OTHER records (distinct subjects)
     * the same operation touched. A bulk delete of 50 records is one operation with one root,
     * and without the count the feed would read as if only the first record was deleted.
     * One correlated COUNT per row of the page, over the `batch_uuid` index.
     *
     * @param Builder<Activity> $query
     *
     * @return Builder<Activity>
     */
    public function withOtherSubjectsCount(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        $others = $this->query()
            ->from($table, 'others')
            ->selectRaw('count(*)')
            // One row per other RECORD: only the first entry of each subject within the batch.
            ->whereNotExists(fn ($earlier) => $earlier
                ->from($table, 'earlier')
                ->whereColumn('earlier.batch_uuid', 'others.batch_uuid')
                ->whereColumn('earlier.subject_type', 'others.subject_type')
                ->whereColumn('earlier.subject_id', 'others.subject_id')
                ->whereColumn('earlier.id', '<', 'others.id'))
            ->whereNotNull("{$table}.batch_uuid")
            ->whereColumn('others.batch_uuid', "{$table}.batch_uuid")
            ->whereNotNull('others.subject_id')
            ->where(fn (Builder $q): Builder => $q
                ->whereNull("{$table}.subject_id")
                ->orWhereColumn('others.subject_type', '<>', "{$table}.subject_type")
                ->orWhereColumn('others.subject_id', '<>', "{$table}.subject_id"));

        if ($query->getQuery()->columns === null) {
            $query->select("{$table}.*");
        }

        return $query->addSelect(['batch_others' => $others]);
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
     * Each filter-option list is a DISTINCT over the whole table, and the list page renders
     * it on every Livewire update, so it is cached for {@see self::OPTIONS_TTL} seconds: a
     * new event shows up in the filter a minute late, the feed itself is never cached.
     *
     * @return list<string>
     */
    public function distinctEvents(): array
    {
        /** @var list<string> */
        return $this->rememberOptions('events', fn (): array => $this->query()
            ->whereNotNull('event')->distinct()->orderBy('event')->pluck('event')->all());
    }

    /**
     * The subject types that actually occur in the log.
     *
     * @return list<string>
     */
    public function distinctSubjectTypes(): array
    {
        /** @var list<string> */
        return $this->rememberOptions('subject_types', fn (): array => $this->query()
            ->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type')->all());
    }

    /**
     * The causer labels by causer id, for the "User" filter.
     *
     * Kept for backward compatibility: ids of two causer models (User, Admin) collide here.
     * The table uses {@see self::causerLabelsByKey()}.
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

    /**
     * The causer labels by `causer_type:causer_id`, for the "User" filter: two causer models
     * may share an id, so the key carries the type ({@see self::whereCauserKeys()}).
     *
     * @return array<string, string>
     */
    public function causerLabelsByKey(): array
    {
        /** @var array<string, string> */
        return $this->rememberOptions('causers', function (): array {
            $options = [];

            $rows = $this->query()
                ->whereNotNull('causer_id')
                ->whereNotNull('causer_label')
                ->select(['causer_type', 'causer_id', 'causer_label'])
                ->distinct()
                ->orderBy('causer_label')
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $options[$row->causer_type.':'.$row->causer_id] = (string) $row->causer_label;
            }

            return $options;
        });
    }

    /**
     * Restrict a query to the causers picked in the "User" filter (`type:id` keys).
     *
     * @param Builder<Activity> $query
     * @param list<string>      $keys
     *
     * @return Builder<Activity>
     */
    public function whereCauserKeys(Builder $query, array $keys): Builder
    {
        return $query->where(function (Builder $query) use ($keys): void {
            foreach ($keys as $key) {
                $type = Str::beforeLast($key, ':');
                $id = Str::afterLast($key, ':');

                $query->orWhere(fn (Builder $q): Builder => $q->where('causer_type', $type)->where('causer_id', $id));
            }
        });
    }

    /**
     * @template T of array
     *
     * @param Closure(): T $callback
     *
     * @return T
     */
    protected function rememberOptions(string $name, Closure $callback): array
    {
        return Cache::remember(
            'activity-log-plus:options:'.$this->query()->getModel()->getTable().':'.$name,
            self::OPTIONS_TTL,
            $callback,
        );
    }
}
