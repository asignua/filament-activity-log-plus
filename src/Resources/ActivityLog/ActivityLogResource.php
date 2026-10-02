<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Resources\ActivityLog;

use Asignua\FilamentActivityLogPlus\ActivityLogPlusPlugin;
use Asignua\FilamentActivityLogPlus\Resources\ActivityLog\Pages\ListActivityLog;
use Asignua\FilamentActivityLogPlus\Resources\ActivityLog\Tables\ActivityLogTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The activity log: who / what / when.
 *
 * Read-only: entries are written by the trait, the listeners and the adapters, never by the
 * panel. An editable log is useless as an audit, so create, edit and delete are closed at
 * the resource level, not merely hidden in the UI.
 *
 * Access: `ActivityLogPlusPlugin::authorizeResource()` or the `activity-log-plus.view` gate.
 * The feed shows other people's actions and the addresses of failed logins.
 */
class ActivityLogResource extends Resource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'subject_label';

    /**
     * The resource has no view/edit page, so every global-search hit would be dropped
     * after a `LIKE '%term%'` scan over the whole log on each keystroke.
     */
    protected static bool $isGloballySearchable = false;

    /**
     * @return class-string<Model>
     */
    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return (string) config('activitylog.activity_model');
    }

    public static function canAccess(): bool
    {
        if (!(bool) config('activity-log-plus.enabled', true)) {
            return false;
        }

        return ActivityLogPlusPlugin::allowsResource();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-activity-log-plus::activity-log-plus.ui.activity_log');
    }

    public static function getModelLabel(): string
    {
        return __('filament-activity-log-plus::activity-log-plus.ui.activity_record');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-activity-log-plus::activity-log-plus.ui.activity_log');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return ActivityLogPlusPlugin::resolve()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return ActivityLogPlusPlugin::resolve()->getNavigationSort();
    }

    public static function getNavigationIcon(): string|BackedEnum|null
    {
        return ActivityLogPlusPlugin::resolve()->getNavigationIcon() ?? static::$navigationIcon;
    }

    public static function table(Table $table): Table
    {
        return ActivityLogTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLog::route('/'),
        ];
    }
}
