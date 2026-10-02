<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Support;

/**
 * Pure logic that turns a diff into something fit to show. No config, no models and no
 * database on purpose, so it is covered by plain unit tests.
 *
 * The `attribute_changes` format of spatie/laravel-activitylog 5 is
 * `['attributes' => [...], 'old' => [...]]` (a created entry has no `old`, a deleted one
 * has no `attributes`). This plugin adds a third key, `truncated`: the fields whose
 * values were cut.
 */
final class ActivityDiff
{
    /**
     * Expand translatable columns into `field.locale` pairs and drop the locales that did
     * not change.
     *
     * Why: translatable fields live in JSON columns such as `{"uk":"...","en":"..."}`.
     * Without this, editing only the Ukrainian title would show as a change of the whole
     * blob in both languages. The values arrive here as RAW JSON strings (or arrays, for an
     * `array`-cast column): the trait switches `useAttributeRawValues()` on for these fields,
     * because spatie/laravel-translatable overrides `getAttribute()` and would hand over the
     * string of the CURRENT locale instead of the map.
     *
     * @param array<string, mixed> $changes
     * @param list<string>         $translatable
     *
     * @return array<string, mixed>
     */
    public static function expandTranslations(array $changes, array $translatable): array
    {
        if ($translatable === []) {
            return $changes;
        }

        $new = is_array($changes['attributes'] ?? null) ? $changes['attributes'] : [];
        $old = is_array($changes['old'] ?? null) ? $changes['old'] : [];

        $hasNew = array_key_exists('attributes', $changes);
        $hasOld = array_key_exists('old', $changes);

        foreach ($translatable as $field) {
            if (!array_key_exists($field, $new) && !array_key_exists($field, $old)) {
                continue;
            }

            $newMap = self::decodeMap($new[$field] ?? null);
            $oldMap = self::decodeMap($old[$field] ?? null);

            unset($new[$field], $old[$field]);

            $locales = array_unique(array_merge(array_keys($newMap), array_keys($oldMap)));
            sort($locales);

            foreach ($locales as $locale) {
                $newValue = $newMap[$locale] ?? null;
                $oldValue = $oldMap[$locale] ?? null;

                // A created/deleted entry has nothing to compare with: write whatever exists.
                if ($hasNew && $hasOld && $newValue === $oldValue) {
                    continue;
                }

                if ($newValue === null && $oldValue === null) {
                    continue;
                }

                $key = $field.'.'.$locale;

                if ($hasNew) {
                    $new[$key] = $newValue;
                }

                if ($hasOld) {
                    $old[$key] = $oldValue;
                }
            }
        }

        if ($hasNew) {
            $changes['attributes'] = $new;
        }

        if ($hasOld) {
            $changes['old'] = $old;
        }

        return $changes;
    }

    /**
     * Keep only the listed columns in the diff.
     *
     * Why: spatie's `logOnlyDirty` compares a fresh model with `getRawOriginal()`, which
     * does not know the DEFAULTS of the database. Create a record and update it in the same
     * request and `order`/`noindex` stay null in memory while the database already holds
     * 0/false, so a "change" of fields nobody touched lands in the diff. The source of truth
     * for an update is `Model::getChanges()`: exactly the columns that went into the UPDATE.
     *
     * Call it BEFORE {@see self::expandTranslations()}, which turns the keys into `field.locale`.
     *
     * @param array<string, mixed> $changes
     * @param list<string>         $columns
     *
     * @return array<string, mixed>
     */
    public static function onlyColumns(array $changes, array $columns): array
    {
        $allowed = array_flip($columns);

        foreach (['attributes', 'old'] as $side) {
            if (!is_array($changes[$side] ?? null)) {
                continue;
            }

            $changes[$side] = array_intersect_key($changes[$side], $allowed);
        }

        return $changes;
    }

    /**
     * Drop the new values that are null or an empty string: on `created` that is "nothing →
     * nothing" and only buries the fields the editor actually filled in. 0 and false are
     * real values and stay. Call it AFTER {@see self::expandTranslations()}.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public static function withoutEmptyValues(array $changes): array
    {
        if (is_array($changes['attributes'] ?? null)) {
            $changes['attributes'] = array_filter(
                $changes['attributes'],
                static fn (mixed $value): bool => $value !== null && $value !== '',
            );
        }

        return $changes;
    }

    /**
     * Cut values that are too long (rich content) and list the cut fields.
     *
     * Why: without it every edit of an article stores two HTML blobs (old and new) and
     * within half a year the log is the largest table in the database. The cut fields are
     * marked in `truncated` and must never be used for a rollback.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public static function truncate(array $changes, int $maxLength): array
    {
        if ($maxLength <= 0) {
            return $changes;
        }

        $truncated = [];

        foreach (['attributes', 'old'] as $side) {
            if (!is_array($changes[$side] ?? null)) {
                continue;
            }

            foreach ($changes[$side] as $key => $value) {
                if (!is_string($value) || mb_strlen($value) <= $maxLength) {
                    continue;
                }

                $changes[$side][$key] = mb_substr($value, 0, $maxLength).'…';
                $truncated[(string) $key] = true;
            }
        }

        if ($truncated !== []) {
            $changes['truncated'] = array_keys($truncated);
        }

        return $changes;
    }

    /**
     * Is there at least one changed value?
     *
     * @param array<string, mixed> $changes
     */
    public static function isEmpty(array $changes): bool
    {
        $new = is_array($changes['attributes'] ?? null) ? $changes['attributes'] : [];
        $old = is_array($changes['old'] ?? null) ? $changes['old'] : [];

        return $new === [] && $old === [];
    }

    /**
     * The changed fields (the union of both sides), for the short summary in the table.
     *
     * @param array<string, mixed> $changes
     *
     * @return list<string>
     */
    public static function changedKeys(array $changes): array
    {
        $new = is_array($changes['attributes'] ?? null) ? $changes['attributes'] : [];
        $old = is_array($changes['old'] ?? null) ? $changes['old'] : [];

        $keys = array_unique(array_merge(array_keys($new), array_keys($old)));
        sort($keys);

        return array_map(static fn (mixed $key): string => (string) $key, $keys);
    }

    /**
     * The raw content of a translatable column into a locale => value map.
     *
     * @return array<string, mixed>
     */
    private static function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
