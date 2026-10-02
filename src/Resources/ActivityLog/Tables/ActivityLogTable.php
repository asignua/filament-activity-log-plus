<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Resources\ActivityLog\Tables;

use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\Repositories\ActivityRepository;
use Asignua\FilamentActivityLogPlus\SubjectLabels;
use Asignua\FilamentActivityLogPlus\Support\ActivityPresenter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ActivityLogTable
{
    private const string LANG = 'filament-activity-log-plus::activity-log-plus.ui.';

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label(__(self::LANG.'date'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('causer_label')
                    ->label(__(self::LANG.'user'))
                    ->placeholder(__(self::LANG.'system_record'))
                    ->searchable(),

                TextColumn::make('event')
                    ->label(__(self::LANG.'action'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ActivityPresenter::eventLabel($state))
                    ->color(fn (?string $state): string => ActivityPresenter::eventColor($state)),

                TextColumn::make('subject_type')
                    ->label(__(self::LANG.'type'))
                    ->formatStateUsing(fn (?string $state): string => SubjectLabels::typeLabel($state))
                    ->toggleable(),

                TextColumn::make('subject_label')
                    ->label(__(self::LANG.'record'))
                    ->placeholder('—')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('summary')
                    ->label(__(self::LANG.'changes'))
                    ->state(fn (Activity $record): string => ActivityPresenter::summary($record))
                    ->wrap()
                    ->color('gray'),
            ])
            ->filters([
                // By default the feed shows only the roots of operations: one save in the
                // panel is one row. Switching the filter off expands every part.
                Filter::make('batch_root')
                    ->label(__(self::LANG.'operations_only'))
                    ->query(fn (Builder $query): Builder => $query->where('batch_root', true))
                    ->default(),

                SelectFilter::make('event')
                    ->label(__(self::LANG.'action'))
                    ->options(fn (): array => self::eventOptions()),

                SelectFilter::make('subject_type')
                    ->label(__(self::LANG.'type'))
                    ->options(fn (): array => self::subjectTypeOptions()),

                SelectFilter::make('causer_id')
                    ->label(__(self::LANG.'user'))
                    ->options(fn (): array => app(ActivityRepository::class)->causerLabelsById()),

                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label(__(self::LANG.'from')),
                        DatePicker::make('until')->label(__(self::LANG.'until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', Carbon::parse($date)))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', Carbon::parse($date)))),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::viewBatchAction(),
                ]),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    /**
     * The operation modal: ALL the entries of a shared batch_uuid, the record save, the
     * pivot changes and the files of the same form submit together.
     */
    public static function viewBatchAction(): Action
    {
        return Action::make('view_batch')
            ->label(__(self::LANG.'details'))
            ->icon(Heroicon::OutlinedEye)
            ->iconButton()
            ->modalHeading(fn (Activity $record): string => ActivityPresenter::eventLabel($record->event)
                .($record->subject_label === null ? '' : ' — '.$record->subject_label))
            ->modalDescription(fn (Activity $record): string => trim(
                ($record->causer_label ?? __(self::LANG.'system_record'))
                .' · '.($record->created_at?->format('d.m.Y H:i:s') ?? '')
                .($record->ip === null ? '' : ' · '.$record->ip),
            ))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__(self::LANG.'close'))
            // @phpstan-ignore argument.type (the view namespace is registered at run time)
            ->modalContent(fn (Activity $record) => view('filament-activity-log-plus::batch', [
                'activities' => ActivityPresenter::batch($record),
            ]));
    }

    /**
     * The options come from the values present in the table, not from a static list: events
     * are added over time and the filter must not lag behind the log.
     *
     * @return array<string, string>
     */
    private static function eventOptions(): array
    {
        $events = [];

        foreach (app(ActivityRepository::class)->distinctEvents() as $event) {
            $events[$event] = ActivityPresenter::eventLabel($event);
        }

        return $events;
    }

    /**
     * @return array<string, string>
     */
    private static function subjectTypeOptions(): array
    {
        $types = [];

        foreach (app(ActivityRepository::class)->distinctSubjectTypes() as $type) {
            $types[$type] = SubjectLabels::typeLabel($type);
        }

        return $types;
    }
}
