<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "file attached from a library" model, to exercise `MediaAdapters::register()` with a
 * library other than spatie/laravel-medialibrary.
 *
 * @property int $id
 * @property int $article_id
 * @property string $name
 */
class Attachment extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
