<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * A relation changed through `sync()`/`attach()`/`detach()`, which fire no Eloquent events.
 * The listener writes one entry AGAINST THE OWNER, so the change reads on the record's own
 * history.
 *
 *     PivotSynced::dispatch($post, 'tags', attached: [3], detached: [1]);
 *
 * For a plain BelongsToMany prefer {@see \Asignua\FilamentActivityLogPlus\Concerns\LogsPivotSync::syncAndLog()}.
 *
 * `$event` is the event name stored in the log (register its label with `ActivityEvents`).
 * `$titles` are names you already know (`[id => title]`); the rest is resolved by `PivotTitles`.
 */
final readonly class PivotSynced
{
    /**
     * @param list<int|string>         $attached
     * @param list<int|string>         $detached
     * @param array<int|string,string> $titles
     */
    public function __construct(
        public Model $owner,
        public string $relation,
        public array $attached,
        public array $detached,
        public ?string $label = null,
        public string $event = 'pivot_synced',
        public array $titles = [],
    ) {}

    /**
     * @param list<int|string>         $attached
     * @param list<int|string>         $detached
     * @param array<int|string,string> $titles
     */
    public static function dispatch(
        Model $owner,
        string $relation,
        array $attached = [],
        array $detached = [],
        ?string $label = null,
        string $event = 'pivot_synced',
        array $titles = [],
    ): void {
        event(new self($owner, $relation, $attached, $detached, $label, $event, $titles));
    }
}
