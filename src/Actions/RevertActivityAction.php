<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Actions;

use Asignua\FilamentActivityLogPlus\ActivityLogPlusPlugin;
use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\Revert\ActivityReverter;
use Asignua\FilamentActivityLogPlus\Revert\RevertException;
use Asignua\FilamentActivityLogPlus\Revert\RevertMode;
use Asignua\FilamentActivityLogPlus\Revert\RevertPlan;
use Asignua\FilamentActivityLogPlus\Support\ActivityPresenter;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Revert / Restore, OFF until `ActivityLogPlusPlugin::revertable()` (or the
 * `revert.enabled` config) turns it on, and then gated by the `revertActivity` ability.
 *
 *  - {@see self::make()}          row action of the log: write the OLD values of this entry back;
 *  - {@see self::restoreVersion()} row action of the log: set the record to its state after this entry;
 *  - {@see self::forRecord()}     header action of an Edit/View page: pick an entry of THIS record.
 *
 * Every variant opens a confirmation with the exact field-level diff that will be applied,
 * lists the fields that changed again since (skipped unless the user confirms the overwrite),
 * and re-checks the authorization when it runs. The logic is {@see ActivityReverter}.
 */
class RevertActivityAction
{
    private const string LANG = 'filament-activity-log-plus::activity-log-plus.revert.';

    /**
     * The log's row action: "Revert this change".
     */
    public static function make(string $name = 'revert_activity'): Action
    {
        return self::onEntry($name, RevertMode::Revert, __(self::LANG.'revert'), Heroicon::OutlinedArrowUturnLeft);
    }

    /**
     * The log's row action: "Restore to this version".
     */
    public static function restoreVersion(string $name = 'restore_activity'): Action
    {
        return self::onEntry($name, RevertMode::Restore, __(self::LANG.'restore'), Heroicon::OutlinedArrowPath);
    }

    /**
     * The header action of a record page: choose one of the latest entries of the record and
     * whether to revert just that change or restore the record to that version.
     */
    public static function forRecord(string $name = 'restore_version'): Action
    {
        $label = __(self::LANG.'restore');
        $reverter = static fn (): ActivityReverter => app(ActivityReverter::class);

        $resolve = static function (Get $get, Model $record) use ($reverter): ?Activity {
            $id = $get('entry');
            $mode = RevertMode::tryFrom((string) $get('mode')) ?? RevertMode::Restore;

            if (blank($id)) {
                return null;
            }

            $entry = $reverter()->entriesFor($record, (int) config('activity-log-plus.history_limit', 50))->firstWhere('id', (int) $id);

            return $entry !== null && $reverter()->allows($entry, $mode) ? $entry : null;
        };

        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->modalHeading($label)
            ->modalSubmitActionLabel(__(self::LANG.'confirm'))
            ->modalWidth(Width::FiveExtraLarge)
            ->visible(fn (Model $record): bool => self::visibleForRecord($record))
            ->schema([
                Select::make('entry')
                    ->label(__(self::LANG.'entry'))
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set, Model $record) => $set('seen', self::seen($resolve($get, $record), RevertMode::tryFrom((string) $get('mode')) ?? RevertMode::Restore)))
                    ->options(fn (Model $record): array => $reverter()->entriesFor($record, (int) config('activity-log-plus.history_limit', 50))
                        ->mapWithKeys(fn (Activity $entry): array => [$entry->getKey() => self::entryLabel($entry)])
                        ->all()),
                Radio::make('mode')
                    ->label(__(self::LANG.'what'))
                    ->options([
                        RevertMode::Restore->value => __(self::LANG.'mode_restore'),
                        RevertMode::Revert->value => __(self::LANG.'mode_revert'),
                    ])
                    ->default(RevertMode::Restore->value)
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set, Model $record) => $set('seen', self::seen($resolve($get, $record), RevertMode::tryFrom((string) $get('mode')) ?? RevertMode::Restore)))
                    ->required(),
                Hidden::make('seen')->default([]),
                // @phpstan-ignore argument.type (the view namespace is registered at run time)
                View::make('filament-activity-log-plus::revert-preview')
                    ->viewData(function (Get $get, Model $record) use ($resolve): array {
                        $entry = $resolve($get, $record);

                        return self::previewData($entry, RevertMode::tryFrom((string) $get('mode')) ?? RevertMode::Restore);
                    }),
                self::overwriteToggle()->visible(fn (Get $get, Model $record): bool => self::safePlan(
                    $resolve($get, $record),
                    RevertMode::tryFrom((string) $get('mode')) ?? RevertMode::Restore,
                )?->hasConflicts() ?? false),
            ])
            ->action(function (array $data, Model $record): void {
                $entry = app(ActivityReverter::class)->entriesFor($record, (int) config('activity-log-plus.history_limit', 50))
                    ->firstWhere('id', (int) ($data['entry'] ?? 0));

                self::run($entry, RevertMode::tryFrom((string) ($data['mode'] ?? '')) ?? RevertMode::Restore, (bool) ($data['overwrite'] ?? false), self::cleanSeen($data['seen'] ?? null));
            });
    }

    private static function onEntry(string $name, RevertMode $mode, string $label, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color('warning')
            ->modalHeading(fn (Activity $record): string => $label.' — '.($record->subject_label ?? '#'.$record->getKey()))
            ->modalSubmitActionLabel(__(self::LANG.'confirm'))
            ->modalWidth(Width::FiveExtraLarge)
            ->visible(fn (Activity $record): bool => app(ActivityReverter::class)->allows($record, $mode))
            ->schema([
                // @phpstan-ignore argument.type (the view namespace is registered at run time)
                View::make('filament-activity-log-plus::revert-preview')
                    ->viewData(fn (Activity $record): array => self::previewData($record, $mode)),
                Hidden::make('seen')->default(fn (Activity $record): array => self::seen($record, $mode)),
                self::overwriteToggle()->visible(fn (Activity $record): bool => self::safePlan($record, $mode)?->hasConflicts() ?? false),
            ])
            ->action(fn (array $data, Activity $record) => self::run($record, $mode, (bool) ($data['overwrite'] ?? false), self::cleanSeen($data['seen'] ?? null)));
    }

    private static function overwriteToggle(): Toggle
    {
        return Toggle::make('overwrite')
            ->label(__(self::LANG.'overwrite'))
            ->helperText(__(self::LANG.'overwrite_help'))
            ->default(false);
    }

    /**
     * The conflicts the user is shown in the confirmation: the revert refuses to overwrite
     * any OTHER field that started to conflict after that.
     *
     * @return array<string, string|null> field => the current value shown
     */
    private static function seen(?Activity $entry, RevertMode $mode): array
    {
        return self::safePlan($entry, $mode)?->seenState() ?? [];
    }

    /**
     * The submitted `seen` is user input: keep only `field => scalar|null` pairs.
     *
     * @return array<string, string|null>
     */
    private static function cleanSeen(mixed $seen): array
    {
        $clean = [];

        foreach (is_array($seen) ? $seen : [] as $field => $value) {
            if (is_string($field) && (is_scalar($value) || $value === null)) {
                $clean[$field] = $value === null ? null : (string) $value;
            }
        }

        return $clean;
    }

    /**
     * @param array<string, string|null> $seen
     */
    private static function run(?Activity $entry, RevertMode $mode, bool $overwrite, array $seen = []): void
    {
        try {
            if ($entry === null) {
                throw new RevertException(__(self::LANG.'nothing'));
            }

            $plan = app(ActivityReverter::class)->apply($entry, $mode, $overwrite, $seen);
        } catch (AuthorizationException) {
            Notification::make()->danger()->title(__(self::LANG.'forbidden'))->send();

            return;
        } catch (RevertException $exception) {
            Notification::make()->warning()->title($exception->getMessage())->send();

            return;
        }

        $skipped = $overwrite ? 0 : count($plan->conflicts());

        Notification::make()
            ->success()
            ->title(__(self::LANG.'done'))
            ->body($skipped > 0 ? (string) __(self::LANG.'done_skipped', ['count' => $skipped]) : null)
            ->send();
    }

    private static function visibleForRecord(Model $record): bool
    {
        if (!(bool) config('activity-log-plus.enabled', true) || !ActivityLogPlusPlugin::resolve()->isRevertable()) {
            return false;
        }

        $reverter = app(ActivityReverter::class);

        // One look at the newest entry is enough to show the button (the gate and the policy
        // are about the user and the record, not the entry); the modal re-checks the one picked.
        $newest = $reverter->entriesFor($record, 1)->first();

        return $newest !== null && ($reverter->allows($newest, RevertMode::Restore) || $reverter->allows($newest, RevertMode::Revert));
    }

    private static function safePlan(?Activity $entry, RevertMode $mode): ?RevertPlan
    {
        if ($entry === null) {
            return null;
        }

        try {
            return app(ActivityReverter::class)->plan($entry, $mode);
        } catch (RevertException) {
            return null;
        }
    }

    /**
     * @return array{plan: RevertPlan|null}
     */
    private static function previewData(?Activity $entry, RevertMode $mode): array
    {
        return ['plan' => self::safePlan($entry, $mode)];
    }

    private static function entryLabel(Activity $entry): string
    {
        return '#'.$entry->getKey()
            .' · '.ActivityPresenter::dateTime($entry->created_at)
            .' · '.($entry->causer_label ?? __('filament-activity-log-plus::activity-log-plus.ui.system_record'))
            .' · '.ActivityPresenter::eventLabel($entry->event);
    }
}
