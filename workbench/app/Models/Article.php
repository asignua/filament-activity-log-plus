<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Asignua\FilamentActivityLogPlus\Concerns\LogsActivityPlus;
use Asignua\FilamentActivityLogPlus\Concerns\LogsPivotSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A record with translatable JSON fields WITHOUT spatie/laravel-translatable: `title` is a
 * plain `array`-cast `{locale: value}` map and the model declares it through the
 * `activityTranslatableAttributes()` hook.
 *
 * @property int $id
 * @property array<string, string>|null $title
 * @property string|null $slug
 * @property string|null $body
 * @property int|null $order
 * @property bool|null $noindex
 * @property string|null $secret
 * @property string|null $path
 */
class Article extends Model implements HasMedia
{
    use InteractsWithMedia;
    use LogsActivityPlus;
    use LogsPivotSync;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['title' => 'array'];
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * @return list<string>
     */
    protected function activityTranslatableAttributes(): array
    {
        return ['title'];
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['secret'];
    }

    /**
     * @return list<string>
     */
    protected function activityIgnoreOnlyChanged(): array
    {
        return ['path'];
    }
}
