# Changelog

All notable changes to `asignua/filament-activity-log-plus` are documented here.

## Unreleased

- Dependencies: the dev constraint of `spatie/laravel-permission` now allows `^8.0` (tested on 8.3; the role events are unchanged).
- Security: `LogsActivityPlus` no longer logs `$hidden` attributes or columns with an encrypted cast (`encrypted*` strings and the `AsEncryptedCollection` / `AsEncryptedArrayObject` class casts; Filament MFA `app_authentication_secret` / recovery codes, `two_factor_*`, API tokens were stored in plain text, because spatie decrypts them before reading). The MFA column names are also in the default `except` config and in `activitylog.default_except_attributes`.
- Role grants and revocations are logged on `spatie/laravel-permission` v6 too (its events are `RoleAttached` / `RoleDetached`; v7 renamed them to `...Event`).
- The compiled stylesheet is scoped to the plugin's own markup (`:where(.activity-log-plus) .flex`), so linking it after the panel theme no longer overrides the host's responsive and `dark:` utilities. Rebuild with `npm run build`.
- The "From / Until" filter takes calendar days of the panel timezone and compares the raw `created_at` (index-friendly) instead of `whereDate()` on UTC days.
- "+N more" counts other records of the operation, not their entries.
- A host update of the user during login/logout (`last_login_at`) no longer takes the batch root from the `login` / `logout` entry.
- Pivot titles are resolved without the global scopes of the related model (soft-deleted or scoped records keep their name instead of `#id`).
- The migration indexes a pre-existing `batch_uuid` column (activitylog v4 table) and `down()` only drops indexes that exist. README and the stub docblock now say that legacy v4 rows need `UPDATE activity_log SET batch_root = 1`, and that `down()` drops `batch_uuid` even if it pre-existed.
- Entries written by artisan or a queue worker no longer get the synthetic `127.0.0.1` IP (`ActivityBatch::start(http: true)` marks real requests, also under Octane).

## v1.0.1 - 2026-10-03

- Requires PHP 8.4, as `spatie/laravel-activitylog` 5 does (the `^8.3` constraint could never be installed on PHP 8.3). CI tests PHP 8.4 and 8.5.

## v1.0.0 - 2026-10-03

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
- `ActivityLogPlusPlugin`: `authorizeResource()`, `authorizeHistory()`, `resource()`, `batchMiddleware()`, navigation group/sort/icon; gates `activity-log-plus.view` and `activity-log-plus.history`. **The log resource is denied by default**: without `authorizeResource()` and without the `activity-log-plus.view` gate nobody sees it (it holds every user's actions, IPs and failed-login addresses). The History action stays open by default.
- Two publishable migrations: create the table, or add the columns to an existing spatie table.
- `activity-log-plus:prune` and an optional daily schedule.
- Compiled stylesheet (`resources/dist`, linked via `STYLES_AFTER`) so the History modal and log views are styled without a custom theme; run `php artisan filament:assets`.
- Root of an operation: the record's own `created`/`updated`/`deleted`/`restored` entry takes the root over from an earlier pivot/media/custom entry of the same subject, whatever order Filament wrote them in.
- History modal cards show who made the change ("System" for a record without a causer).
- `created` entries leave out attributes that are null or an empty string.
- Translations: English, Ukrainian, German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish.
- An `updated` entry whose diff is empty after the trait's filtering (phantom columns, re-encoded translatable JSON) is not written.
- A failed login against an existing account is recorded with the account as subject and no causer.
- Bulk operations: the feed summary of a root shows `+N more` for the other records of its operation; the operation modal shows at most 200 entries.
- Non-translatable JSON arrays are truncated by `max_value_length` as their JSON text.
- Filter options are cached for 60 seconds; the user filter is keyed by `causer_type:causer_id`. The resource is not globally searchable.
- spatie's activity buffer is bypassed inside a batch, so the root of an operation stays single.
- Dates in the table follow the panel's format and timezone; the modal uses the locale's format in the panel's timezone.
- Laravel Boost guidelines.
