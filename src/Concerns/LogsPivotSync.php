<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Concerns;

use Asignua\FilamentActivityLogPlus\Events\PivotSynced;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use InvalidArgumentException;

/**
 * `sync()` that is recorded: use `$post->syncAndLog('tags', [1, 2])` instead of
 * `$post->tags()->sync([1, 2])`. A raw `sync()` fires no Eloquent events and is invisible
 * to the log.
 *
 * @mixin Model
 */
trait LogsPivotSync
{
    /**
     * @param array<int|string, mixed>|\Illuminate\Support\Collection<array-key, mixed>|int|string $ids
     *
     * @return array{attached: array<int|string>, detached: array<int|string>, updated: array<int|string>}
     */
    public function syncAndLog(string $relation, mixed $ids, bool $detaching = true, ?string $label = null, string $event = 'pivot_synced'): array
    {
        $relationObject = $this->{$relation}();

        if (!$relationObject instanceof BelongsToMany) {
            throw new InvalidArgumentException(sprintf('%s::%s() is not a BelongsToMany relation.', static::class, $relation));
        }

        /** @var array{attached: array<int|string>, detached: array<int|string>, updated: array<int|string>} $changes */
        $changes = $relationObject->sync($ids, $detaching);

        PivotSynced::dispatch(
            $this,
            $relation,
            array_values($changes['attached']),
            array_values($changes['detached']),
            $label,
            $event,
        );

        return $changes;
    }
}
