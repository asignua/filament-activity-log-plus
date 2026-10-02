<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Listeners;

use Asignua\FilamentActivityLogPlus\Events\PivotSynced;
use Asignua\FilamentActivityLogPlus\PivotTitles;
use Illuminate\Support\Str;

/**
 * Writes the log entry of a {@see PivotSynced}.
 *
 * The subject is the OWNER (a post, a page), never the pivot: history has to read on the
 * record's own page. The names are denormalised at the moment of the action.
 *
 * Properties: `{key, label, attached: [{id, title}], detached: [{id, title}]}`.
 */
class LogPivotSync
{
    public function handle(PivotSynced $event): void
    {
        if (!(bool) config('activity-log-plus.enabled', true)) {
            return;
        }

        if ($event->attached === [] && $event->detached === []) {
            return;
        }

        activity()
            ->performedOn($event->owner)
            ->event($event->event)
            ->withProperties([
                'key' => $event->relation,
                'label' => $event->label ?? Str::headline($event->relation),
                'attached' => PivotTitles::describe($event->owner, $event->relation, $event->attached, $event->titles),
                'detached' => PivotTitles::describe($event->owner, $event->relation, $event->detached, $event->titles),
            ])
            ->log($event->event);
    }
}
