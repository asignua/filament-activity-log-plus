<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Models;

use Asignua\FilamentActivityLogPlus\Casts\UnescapedJsonCollection;
use Spatie\Activitylog\Models\Activity as SpatieActivity;
use Stringable;

/**
 * An entry of the log: the spatie model with a configurable table and the columns of this
 * plugin. Bound through `activitylog.activity_model` (only when the host did not set its
 * own; a host model should extend this one).
 *
 * @property int $id
 * @property string|null $log_name
 * @property string $description
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property string|null $event
 * @property string|null $causer_type
 * @property int|string|null $causer_id
 * @property \Illuminate\Support\Collection<array-key, mixed>|null $attribute_changes
 * @property \Illuminate\Support\Collection<array-key, mixed>|null $properties
 * @property string|null $subject_label
 * @property string|null $causer_label
 * @property string|null $batch_uuid
 * @property bool $batch_root
 * @property string|null $ip
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Model|null $causer
 * @property-read \Illuminate\Database\Eloquent\Model|null $subject
 */
class Activity extends SpatieActivity
{
    public function getTable(): string
    {
        return (string) config('activity-log-plus.table', 'activity_log');
    }

    /**
     * @return array<string, string|Stringable>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'batch_root' => 'boolean',
            // Overrides the vendor `collection` cast, which escapes non-ASCII to `\uXXXX`.
            'attribute_changes' => UnescapedJsonCollection::class,
            'properties' => UnescapedJsonCollection::class,
        ]);
    }
}
