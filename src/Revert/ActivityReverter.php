<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Revert;

use Asignua\FilamentActivityLogPlus\ActivityBatch;
use Asignua\FilamentActivityLogPlus\ActivityLogPlusPlugin;
use Asignua\FilamentActivityLogPlus\Concerns\LogsActivityPlus;
use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\Repositories\ActivityRepository;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Facades\Activity as ActivityFacade;

/**
 * Writes the values of a log entry back to its record.
 *
 * Two modes ({@see RevertMode}): "revert" writes the `old` side of ONE entry; "restore"
 * replays the entries recorded AFTER the chosen one and sets every field they touched to
 * what it was right after the chosen entry.
 *
 * Safety is layered, and every layer is checked again when the revert is APPLIED (a hidden
 * button is not an authorization): the feature switch (off by default), the
 * `revertActivity` gate (deny by default), the `update` policy of the record, a per-model
 * opt-out, the columns that are never written, and conflict detection (a field changed after
 * the entry is shown and skipped unless the user confirms the overwrite).
 *
 * Not revertable in v1: pivot/taxonomy and media entries (their events are not in
 * {@see self::FIELD_EVENTS}), and fields whose logged value was truncated.
 */
final class ActivityReverter
{
    /** Events that carry a field diff and can be reverted. */
    public const array FIELD_EVENTS = ['updated', 'reverted'];

    /** Events a record can be restored to (the state right after it). */
    public const array RESTORE_EVENTS = ['created', 'updated', 'reverted'];

    public function __construct(private readonly ActivityRepository $activities) {}

    /**
     * The entries of a record the History picker offers, newest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Activity>
     */
    public function entriesFor(Model $subject, int $limit): \Illuminate\Database\Eloquent\Collection
    {
        return $this->activities->changeEntries($subject->getMorphClass(), $subject->getKey(), self::RESTORE_EVENTS, $limit);
    }

    /**
     * May the current user see / use the action for this entry? Cheap checks first: the
     * table asks it for every row.
     */
    public function allows(Activity $entry, RevertMode $mode): bool
    {
        if (!$this->featureOn($entry->subject_type)) {
            return false;
        }

        if (!$this->eventFits($entry, $mode)) {
            return false;
        }

        if (!ActivityLogPlusPlugin::resolve()->canRevert($entry)) {
            return false;
        }

        $subject = $this->subject($entry);

        if ($subject === null) {
            return false;
        }

        if ($this->isTrashed($subject)) {
            return $mode === RevertMode::Revert && $entry->event === 'deleted' && $this->policyAllows($subject, 'restore');
        }

        return $entry->event !== 'deleted' && $this->policyAllows($subject, 'update');
    }

    /**
     * @throws AuthorizationException
     */
    public function authorize(Activity $entry, RevertMode $mode): void
    {
        if (!$this->allows($entry, $mode)) {
            throw new AuthorizationException;
        }
    }

    public function plan(Activity $entry, RevertMode $mode): RevertPlan
    {
        $subject = $this->subject($entry) ?? throw new RevertException(__('filament-activity-log-plus::activity-log-plus.revert.record_gone'));

        if ($entry->event === 'deleted' && $this->isTrashed($subject)) {
            return new RevertPlan($entry, $subject, $mode, [], restoresRecord: true);
        }

        $context = $this->context($subject);

        /** @var array<string, mixed> $targets */
        $targets = [];
        /** @var array<string, mixed> $expected */
        $expected = [];
        /** @var array<string, true> $truncated */
        $truncated = [];

        $collect = function (Activity $source, bool $firstWins) use (&$targets, &$expected, &$truncated): void {
            $diff = $source->attribute_changes?->toArray() ?? [];
            $old = is_array($diff['old'] ?? null) ? $diff['old'] : [];
            $new = is_array($diff['attributes'] ?? null) ? $diff['attributes'] : [];

            foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
                $key = (string) $key;

                if (!$firstWins || !array_key_exists($key, $targets)) {
                    $targets[$key] = $old[$key] ?? null;
                }

                $expected[$key] = $new[$key] ?? null;
            }

            foreach ((array) ($diff['truncated'] ?? []) as $key) {
                $truncated[(string) $key] = true;
            }
        };

        if ($mode === RevertMode::Revert) {
            $collect($entry, false);
        } else {
            foreach ($this->activities->changesAfter($entry->subject_type ?? '', $entry->subject_id ?? 0, $entry->getKey(), self::FIELD_EVENTS) as $later) {
                $collect($later, true);
            }
        }

        $rows = [];
        $skipped = [];
        $attributes = $subject->getAttributes();

        foreach ($targets as $field => $target) {
            [$column, $locale] = $this->split($field, $context['translatable']);

            if (isset($truncated[$field]) || isset($truncated[$column])) {
                $skipped[] = ['field' => $field, 'reason' => RevertPlan::SKIP_TRUNCATED];

                continue;
            }

            if ($this->isExcluded($column, $subject, $context['except'])) {
                $skipped[] = ['field' => $field, 'reason' => RevertPlan::SKIP_EXCLUDED];

                continue;
            }

            if (!array_key_exists($column, $attributes)) {
                $skipped[] = ['field' => $field, 'reason' => RevertPlan::SKIP_MISSING];

                continue;
            }

            $current = $locale === null
                ? $subject->getAttribute($column)
                : ($this->translations($subject, $column)[$locale] ?? null);

            if ($this->normalize($current) === $this->normalize($target)) {
                continue;
            }

            $rows[] = new RevertRow(
                $field,
                $current,
                $target,
                $expected[$field] ?? null,
                $this->normalize($current) !== $this->normalize($expected[$field] ?? null),
            );
        }

        usort($rows, static fn (RevertRow $a, RevertRow $b): int => $a->field <=> $b->field);

        return new RevertPlan($entry, $subject, $mode, $rows, $skipped);
    }

    /**
     * Apply the revert: authorize, write through Eloquent (or `revertUsing()`) inside a
     * transaction, log ONE `reverted` entry in the current batch (or a new one).
     *
     * @throws AuthorizationException
     * @throws RevertException
     */
    public function apply(Activity $entry, RevertMode $mode, bool $overwriteConflicts = false): RevertPlan
    {
        $this->authorize($entry, $mode);

        $plan = $this->plan($entry, $mode);
        $rows = $plan->applicable($overwriteConflicts);

        if ($rows === [] && !$plan->restoresRecord) {
            throw new RevertException(__('filament-activity-log-plus::activity-log-plus.revert.nothing'));
        }

        $subject = $plan->subject;

        $write = function () use ($plan, $rows, $subject, $entry, $mode, $overwriteConflicts): void {
            $subject->getConnection()->transaction(function () use ($plan, $rows, $subject, $entry, $mode, $overwriteConflicts): void {
                // The revert is ONE entry of its own: the `updated` entry the model would
                // write for the same save is suppressed, or the operation would list the
                // change twice.
                ActivityFacade::withoutLogging(function () use ($plan, $rows, $subject, $entry): void {
                    if ($plan->restoresRecord) {
                        /** @phpstan-ignore method.notFound */
                        $subject->restore();

                        return;
                    }

                    $this->write($subject, $rows, $entry);
                });

                $this->log($plan, $rows, $mode, $overwriteConflicts);
            });
        };

        $batch = app(ActivityBatch::class);
        $batch->uuid() === null ? $batch->run($write) : $write();

        return $plan;
    }

    /**
     * @param list<RevertRow> $rows
     */
    private function write(Model $subject, array $rows, Activity $entry): void
    {
        $context = $this->context($subject);

        /** @var array<string, mixed> $values */
        $values = [];

        foreach ($rows as $row) {
            [$column, $locale] = $this->split($row->field, $context['translatable']);

            if ($locale === null) {
                $values[$column] = $row->target;

                continue;
            }

            $map = $values[$column] ?? $this->translations($subject, $column);
            $map = is_array($map) ? $map : [];

            if ($row->target === null) {
                unset($map[$locale]);
            } else {
                $map[$locale] = $row->target;
            }

            $values[$column] = $map;
        }

        $callback = ActivityLogPlusPlugin::resolve()->getRevertUsing();

        if ($callback !== null) {
            $callback($subject, $values, $entry);

            return;
        }

        $subject->forceFill($values)->save();
    }

    /**
     * @param list<RevertRow> $rows
     */
    private function log(RevertPlan $plan, array $rows, RevertMode $mode, bool $overwriteConflicts): void
    {
        $logger = ActivityFacade::performedOn($plan->subject)
            ->event('reverted')
            ->withProperties([
                'source_id' => $plan->entry->getKey(),
                'source_event' => $plan->entry->event,
                'source_at' => $plan->entry->created_at?->toJSON(),
                'source_causer' => $plan->entry->causer_label,
                'mode' => $mode->value,
                'record_restored' => $plan->restoresRecord,
                'overwritten' => $overwriteConflicts ? array_map(
                    static fn (RevertRow $row): string => $row->field,
                    $plan->conflicts(),
                ) : [],
                'skipped' => $plan->skipped,
                'skipped_conflicts' => $overwriteConflicts ? [] : array_map(
                    static fn (RevertRow $row): string => $row->field,
                    $plan->conflicts(),
                ),
            ]);

        if ($rows !== []) {
            $logger->withChanges([
                'attributes' => Arr::mapWithKeys($rows, fn (RevertRow $row): array => [$row->field => $row->target]),
                'old' => Arr::mapWithKeys($rows, fn (RevertRow $row): array => [$row->field => $row->current]),
            ]);
        }

        $logger->log('reverted');
    }

    private function featureOn(?string $subjectType): bool
    {
        if (!(bool) config('activity-log-plus.enabled', true) || !ActivityLogPlusPlugin::resolve()->isRevertable()) {
            return false;
        }

        $class = $subjectType === null ? null : (Relation::getMorphedModel($subjectType) ?? $subjectType);

        foreach ([...(array) config('activity-log-plus.revert.except_models', []), ...ActivityLogPlusPlugin::resolve()->getRevertExcept()] as $excluded) {
            $excludedClass = Relation::getMorphedModel((string) $excluded) ?? (string) $excluded;

            if ($subjectType === $excluded || $class === $excludedClass) {
                return false;
            }
        }

        return true;
    }

    private function eventFits(Activity $entry, RevertMode $mode): bool
    {
        if ($entry->event === 'deleted') {
            return $mode === RevertMode::Revert;
        }

        if ($mode === RevertMode::Revert) {
            return in_array($entry->event, self::FIELD_EVENTS, true) && $this->hasOld($entry);
        }

        return in_array($entry->event, self::RESTORE_EVENTS, true);
    }

    private function hasOld(Activity $entry): bool
    {
        $changes = $entry->attribute_changes?->toArray() ?? [];

        return is_array($changes['old'] ?? null) && $changes['old'] !== [];
    }

    private function subject(Activity $entry): ?Model
    {
        if ($entry->subject_type === null || $entry->subject_id === null) {
            return null;
        }

        /** @var Model|null */
        return $entry->subject()->withTrashed()->first();
    }

    private function isTrashed(Model $subject): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($subject), true)
            && method_exists($subject, 'trashed') && $subject->trashed();
    }

    /**
     * Like Filament: a model without a policy is not blocked by one. With a policy, the
     * `update` (or `restore`) ability must pass.
     */
    private function policyAllows(Model $subject, string $ability): bool
    {
        $policy = Gate::getPolicyFor($subject);

        if ($policy === null) {
            return true;
        }

        if ($ability === 'restore' && !method_exists($policy, 'restore')) {
            $ability = 'update';
        }

        return Gate::allows($ability, $subject);
    }

    /**
     * @return array{translatable: list<string>, except: list<string>}
     */
    private function context(Model $subject): array
    {
        if (in_array(LogsActivityPlus::class, class_uses_recursive($subject), true) && method_exists($subject, 'activityRevertContext')) {
            /** @var array{translatable: list<string>, except: list<string>} */
            return $subject->activityRevertContext();
        }

        $translatable = method_exists($subject, 'getTranslatableAttributes') ? (array) $subject->getTranslatableAttributes() : [];

        return [
            'translatable' => array_values(array_map('strval', $translatable)),
            'except' => array_values(array_map('strval', (array) config('activity-log-plus.except', []))),
        ];
    }

    /**
     * @param list<string> $except
     */
    private function isExcluded(string $column, Model $subject, array $except): bool
    {
        $fixed = [
            $subject->getKeyName(),
            $subject->getCreatedAtColumn(),
            $subject->getUpdatedAtColumn(),
            ...$subject->getHidden(),
            ...(array) config('activity-log-plus.revert.except_attributes', []),
            ...$except,
        ];

        if (method_exists($subject, 'getDeletedAtColumn')) {
            $fixed[] = $subject->getDeletedAtColumn();
        }

        return in_array($column, $fixed, true);
    }

    /**
     * `title.uk` of a translatable column becomes [`title`, `uk`]; anything else is [field, null].
     *
     * @param list<string> $translatable
     *
     * @return array{0: string, 1: string|null}
     */
    private function split(string $field, array $translatable): array
    {
        if (str_contains($field, '.')) {
            [$column, $locale] = explode('.', $field, 2);

            if (in_array($column, $translatable, true)) {
                return [$column, $locale];
            }
        }

        return [$field, null];
    }

    /**
     * The locale => value map of a translatable column, whether it comes from
     * spatie/laravel-translatable or from a plain `array` cast.
     *
     * @return array<string, mixed>
     */
    private function translations(Model $subject, string $column): array
    {
        if (method_exists($subject, 'getTranslations') && method_exists($subject, 'isTranslatableAttribute') && $subject->isTranslatableAttribute($column)) {
            /** @var array<string, mixed> */
            return $subject->getTranslations($column);
        }

        $value = $subject->getAttributes()[$column] ?? null;

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * One comparable form of a logged value and a live attribute (bool vs 1, enum vs its
     * value, Carbon vs the ISO string the log kept, an array vs its JSON).
     */
    private function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toJSON();
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if (is_array($value)) {
            return (string) json_encode($this->sorted($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function sorted(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sorted($item);
            }
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
