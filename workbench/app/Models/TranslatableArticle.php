<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Asignua\FilamentActivityLogPlus\Concerns\LogsActivityPlus;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * A record WITH spatie/laravel-translatable: nothing to declare, the trait reads
 * `getTranslatableAttributes()`.
 *
 * @property int $id
 */
class TranslatableArticle extends Model
{
    use HasTranslations;
    use LogsActivityPlus;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['title'];
}
