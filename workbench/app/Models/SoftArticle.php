<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Asignua\FilamentActivityLogPlus\Concerns\LogsActivityPlus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A record with SoftDeletes: a deleted entry of it can be undone.
 *
 * @property int $id
 * @property string|null $slug
 */
class SoftArticle extends Model
{
    use LogsActivityPlus;
    use SoftDeletes;

    protected $guarded = [];
}
