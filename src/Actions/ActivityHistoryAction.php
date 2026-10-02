<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Actions;

use Asignua\FilamentActivityLogPlus\ActivityLogPlusPlugin;
use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\Repositories\ActivityRepository;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The "History" header action of a record page: the log of exactly this record.
 *
 *     protected function getHeaderActions(): array
 *     {
 *         return [ActivityHistoryAction::make(), DeleteAction::make()];
 *     }
 *
 * Filament has no global hook for header actions, so it is added with one explicit line per
 * Edit/View page, which also keeps it greppable.
 *
 * Its visibility is NOT tied to the log resource: whoever may edit a record may see who
 * changed it before (see `ActivityLogPlusPlugin::authorizeHistory()`).
 */
class ActivityHistoryAction
{
    public static function make(string $name = 'activity_history'): Action
    {
        $label = __('filament-activity-log-plus::activity-log-plus.ui.history');

        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->modalHeading($label)
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('filament-activity-log-plus::activity-log-plus.ui.close'))
            ->visible(fn (): bool => (bool) config('activity-log-plus.enabled', true) && ActivityLogPlusPlugin::allowsHistory())
            // @phpstan-ignore argument.type (the view namespace is registered at run time)
            ->modalContent(fn (Model $record) => view('filament-activity-log-plus::batch', [
                'activities' => self::activitiesFor($record),
            ]));
    }

    /**
     * The history of a record is not only its own Eloquent events: pivot and media changes
     * are written with the same subject, so one morph filter is enough.
     *
     * @return Collection<int, Activity>
     */
    private static function activitiesFor(Model $record): Collection
    {
        return app(ActivityRepository::class)->forSubject(
            $record->getMorphClass(),
            $record->getKey(),
            (int) config('activity-log-plus.history_limit', 50),
        );
    }
}
