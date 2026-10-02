# Changelog

All notable changes to `asignua/filament-activity-log-plus` are documented here.

## v1.0.0 - 2026-10-02

- `LogsActivityPlus` trait for `spatie/laravel-activitylog` 5: `logAll()` (works with `$guarded = ['*']`), dirty-only diffs, secrets excluded, model hooks `activityExcept()`, `activityTranslatableAttributes()` and `activityIgnoreOnlyChanged()`.
- Per-language diffs of translatable JSON fields (`title.uk`, `title.en`; unchanged languages dropped), with or without `spatie/laravel-translatable`.
- No phantom changes: a diff is cut down to the columns of the actual `UPDATE` (`getChanges()`), so database defaults never show up as edits.
- One operation = one batch: `ActivityBatch` (scoped), `ActivityBatchMiddleware` added to the panel by the plugin, a Livewire `request` hook, `ActivityBatch::run()` for console and queue; the feed shows batch roots.
- Pivot changes recorded against the owner: `PivotSynced` event, `LogsPivotSync::syncAndLog()`, names stored at the moment of the action, replaceable with `PivotTitles::using()`.
- Media changes recorded against the owner: built-in `spatie/laravel-medialibrary` adapter and `MediaAdapters::register()` for any other library.
- Authentication events (`login`, `logout`, `login_failed`, `lockout`) and role grants/revocations (`spatie/laravel-permission`).
- Subject and causer labels, the IP and truncation of long values stored with every entry; `SubjectLabels::using()`, `FieldLabels`, the `subjects` and `field_labels` config maps.
- `ActivityEvents` registry: built-in events plus `register(name, label, color, summary:, view:)` for your own.
- `ActivityHistoryAction` (modal with the history of a record) and a read-only `Activity log` resource with filters (operations only, event, type, user, period).
- `ActivityLogPlusPlugin`: `authorizeResource()`, `authorizeHistory()`, `resource()`, `batchMiddleware()`, navigation group/sort/icon; gates `activity-log-plus.view` and `activity-log-plus.history`.
- Two publishable migrations: create the table, or add the columns to an existing spatie table.
- `activity-log-plus:prune` and an optional daily schedule.
- Compiled stylesheet (`resources/dist`, linked via `STYLES_AFTER`) so the History modal and log views are styled without a custom theme; run `php artisan filament:assets`.
- Root of an operation: the record's own `created`/`updated`/`deleted`/`restored` entry takes the root over from an earlier pivot/media/custom entry of the same subject, whatever order Filament wrote them in.
- History modal cards show who made the change ("System" for a record without a causer).
- `created` entries leave out attributes that are null or an empty string.
- Translations: English, Ukrainian, German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish.
- Laravel Boost guidelines.
