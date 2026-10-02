<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Support;

use Asignua\FilamentActivityLogPlus\ActivityEvents;
use Asignua\FilamentActivityLogPlus\FieldLabels;
use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\Repositories\ActivityRepository;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Collection;

/**
 * An entry of the log in human words: the event label and colour, the short summary of a
 * table row, the field labels, and the whole operation by `batch_uuid`.
 *
 * Kept apart from the Filament table so the feed and the History action share one logic.
 */
final class ActivityPresenter
{
    public static function eventLabel(?string $event): string
    {
        return ActivityEvents::label($event);
    }

    public static function eventColor(?string $event): string
    {
        return ActivityEvents::color($event);
    }

    /**
     * The label of a field of the diff. The key may be `field.locale` after the translatable
     * expansion; the locale stays visible (see {@see FieldLabels}).
     */
    public static function fieldLabel(string $key): string
    {
        return FieldLabels::label($key);
    }

    /**
     * The short summary of a row: what happened, without opening the modal.
     */
    public static function summary(Activity $activity): string
    {
        $summary = ActivityEvents::get($activity->event)?->summary;

        if ($summary !== null) {
            $text = (string) $summary($activity);
        } elseif (self::isPivotShaped($activity->properties?->toArray() ?? [])) {
            $text = self::pivotSummary($activity);
        } else {
            $text = self::changesSummary($activity);
        }

        return self::withOtherRecords($text, $activity);
    }

    /**
     * "Title, Slug · +49 more": a root whose operation touched other records too (a bulk
     * action). `batch_others` is selected by
     * {@see ActivityRepository::withOtherSubjectsCount()}; without it nothing is added.
     */
    private static function withOtherRecords(string $text, Activity $activity): string
    {
        $others = (int) $activity->getAttribute('batch_others');

        if ($others <= 0 || !$activity->batch_root) {
            return $text;
        }

        $more = __('filament-activity-log-plus::activity-log-plus.ui.more_records', ['count' => $others]);

        return $text === '' ? $more : $text.' · '.$more;
    }

    /** How many entries of one operation the modal renders at most. */
    public const int BATCH_LIMIT = 200;

    /**
     * The whole operation: entries with a shared batch_uuid. Outside a batch (console,
     * queue) the operation is one entry. Pass a $limit to bound a bulk action over thousands
     * of records ({@see self::batchView()} does).
     *
     * @return Collection<int, Activity>
     */
    public static function batch(Activity $activity, ?int $limit = null): Collection
    {
        $repository = app(ActivityRepository::class);

        return $activity->batch_uuid === null
            ? $repository->withKey((int) $activity->getKey())
            : $repository->withBatchUuid($activity->batch_uuid, $limit);
    }

    /**
     * The data of the operation modal: at most {@see self::BATCH_LIMIT} entries and the
     * limit when the operation has more (one extra row is read to know that).
     *
     * @return array{activities: Collection<int, Activity>, limited: int|null}
     */
    public static function batchView(Activity $activity): array
    {
        $activities = self::batch($activity, self::BATCH_LIMIT + 1);
        $limited = $activities->count() > self::BATCH_LIMIT;

        return [
            'activities' => $limited ? $activities->take(self::BATCH_LIMIT) : $activities,
            'limited' => $limited ? self::BATCH_LIMIT : null,
        ];
    }

    /**
     * A moment of the log for the modal and the cards: in the panel's timezone and in the
     * format of the current locale.
     */
    public static function dateTime(?CarbonInterface $moment): string
    {
        if ($moment === null) {
            return '';
        }

        return $moment->copy()->setTimezone(FilamentTimezone::get())->isoFormat('L LTS');
    }

    /**
     * Does `properties` have the shape written by a pivot sync (`attached` and `detached` lists)?
     *
     * @param array<array-key, mixed> $properties
     */
    public static function isPivotShaped(array $properties): bool
    {
        return is_array($properties['attached'] ?? null) && is_array($properties['detached'] ?? null);
    }

    /**
     * "Tags +2 −1" for a pivot entry.
     */
    public static function pivotSummary(Activity $activity): string
    {
        $properties = $activity->properties?->toArray() ?? [];

        $label = is_string($properties['label'] ?? null) ? $properties['label'] : (string) ($properties['key'] ?? '');
        $attached = is_array($properties['attached'] ?? null) ? $properties['attached'] : [];
        $detached = is_array($properties['detached'] ?? null) ? $properties['detached'] : [];

        $parts = [];

        if ($attached !== []) {
            $parts[] = '+'.count($attached);
        }

        if ($detached !== []) {
            $parts[] = '−'.count($detached);
        }

        return trim($label.' '.implode(' ', $parts));
    }

    private static function changesSummary(Activity $activity): string
    {
        $changes = $activity->attribute_changes?->toArray() ?? [];
        $keys = ActivityDiff::changedKeys($changes);

        if ($keys === []) {
            return '';
        }

        $labels = array_map(self::fieldLabel(...), array_slice($keys, 0, 4));

        if (count($keys) > 4) {
            $labels[] = '+'.(count($keys) - 4);
        }

        return implode(', ', $labels);
    }
}
