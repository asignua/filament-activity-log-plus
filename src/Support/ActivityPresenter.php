<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Support;

use Asignua\FilamentActivityLogPlus\ActivityEvents;
use Asignua\FilamentActivityLogPlus\FieldLabels;
use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\Repositories\ActivityRepository;
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
            return (string) $summary($activity);
        }

        if (self::isPivotShaped($activity->properties?->toArray() ?? [])) {
            return self::pivotSummary($activity);
        }

        return self::changesSummary($activity);
    }

    /**
     * The whole operation: entries with a shared batch_uuid. Outside a batch (console,
     * queue) the operation is one entry.
     *
     * @return Collection<int, Activity>
     */
    public static function batch(Activity $activity): Collection
    {
        $repository = app(ActivityRepository::class);

        return $activity->batch_uuid === null
            ? $repository->withKey((int) $activity->getKey())
            : $repository->withBatchUuid($activity->batch_uuid);
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
