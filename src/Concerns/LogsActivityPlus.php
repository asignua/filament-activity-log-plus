<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Concerns;

use Asignua\FilamentActivityLogPlus\Support\ActivityDiff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Puts a model under audit: a wrapper over spatie's `LogsActivity` with the fixes a real
 * panel needs.
 *
 * What it adds on top of the default:
 *
 * 1. `logAll()` instead of fillable/unguarded. A model with `$guarded = ['*']` (writes only
 *    through a repository or DTO) makes `logUnguarded()` return an empty list, and the log
 *    would stay empty.
 * 2. `useAttributeRawValues()` on translatable columns. spatie/laravel-translatable
 *    overrides `getAttribute()` and would hand over the string of the CURRENT locale, so
 *    changes in other languages would vanish from the diff. The raw mode returns the JSON
 *    map that {@see ActivityDiff::expandTranslations()} then splits per locale.
 * 3. Phantom changes are filtered through `getChanges()` (see {@see ActivityDiff::onlyColumns()}).
 * 4. `dontLogIfAttributesChangedOnly()` for derived columns (config `ignore_only_changed`,
 *    or {@see self::activityIgnoreOnlyChanged()}).
 * 5. Secrets never reach the log (config `except`, plus {@see self::activityExcept()}).
 *
 * Hooks a model may override (all optional, all `protected`):
 *
 *  - `activityExcept(): list<string>`              its own secrets/tokens;
 *  - `activityTranslatableAttributes(): list<string>`  JSON columns holding `{locale: value}`;
 *    by default `getTranslatableAttributes()` when spatie/laravel-translatable is used, else `[]`;
 *  - `activityIgnoreOnlyChanged(): list<string>`   derived columns.
 *
 * @mixin Model
 */
trait LogsActivityPlus
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useAttributeRawValues($this->activityTranslatableAttributes())
            ->logExcept($this->activityExceptAll())
            ->dontLogIfAttributesChangedOnly($this->activityIgnoreOnlyChangedAll());
    }

    /**
     * The spatie hook on the subject: called before the entry is saved. It has to live here
     * because it needs the model itself (the translatable fields and the columns that were
     * actually written).
     */
    public function beforeActivityLogged(Model $activity, string $eventName): void
    {
        $changes = $activity->getAttribute('attribute_changes');

        if ($changes instanceof Collection) {
            $changes = $changes->toArray();
        }

        if (!is_array($changes) || $changes === []) {
            return;
        }

        // Cut the phantom "changes": `logOnlyDirty` compares with getRawOriginal(), which
        // does not know the database defaults, so after create+update in one request
        // order/noindex and friends landed in the diff. On the `updated` event getChanges()
        // is already synced (Model::performUpdate calls syncChanges() BEFORE it fires
        // 'updated') and holds exactly the columns of the UPDATE.
        if ($eventName === 'updated') {
            $changes = ActivityDiff::onlyColumns($changes, array_keys($this->getChanges()));
        }

        $changes = ActivityDiff::expandTranslations($changes, $this->activityTranslatableAttributes());

        // A new record: "nothing → nothing" fields (null / '') are noise.
        if ($eventName === 'created') {
            $changes = ActivityDiff::withoutEmptyValues($changes);
        }

        $activity->setAttribute('attribute_changes', $changes);
    }

    /**
     * Attributes never written to the log: the `except` config plus the model's own.
     *
     * @return list<string>
     */
    protected function activityExceptAll(): array
    {
        /** @var list<string> $base */
        $base = array_values((array) config('activity-log-plus.except', []));

        return array_values(array_unique([...$base, ...$this->activityExcept()]));
    }

    /**
     * Extension point: the model's own secrets/tokens.
     *
     * Override THIS one, not {@see self::activityExceptAll()}: the latter comes from the trait,
     * so on a model that uses the trait directly `parent::` does not exist and a full
     * override would silently drop the built-in exclusions.
     *
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return [];
    }

    /**
     * Extension point: JSON columns that hold `{locale: value}` maps.
     *
     * @return list<string>
     */
    protected function activityTranslatableAttributes(): array
    {
        if (!method_exists($this, 'getTranslatableAttributes')) {
            return [];
        }

        /** @var list<string> $attributes */
        $attributes = $this->getTranslatableAttributes();

        return $attributes;
    }

    /**
     * Extension point: derived columns whose sole change must not write an entry (a
     * materialised path that is rewritten on every ancestor save would otherwise log one row
     * per descendant).
     *
     * @return list<string>
     */
    protected function activityIgnoreOnlyChanged(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    protected function activityIgnoreOnlyChangedAll(): array
    {
        /** @var list<string> $base */
        $base = array_values((array) config('activity-log-plus.ignore_only_changed', []));

        return array_values(array_unique([...$base, ...$this->activityIgnoreOnlyChanged()]));
    }
}
