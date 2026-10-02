# Filament Activity Log Plus

[![Stand With Ukraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://stand-with-ukraine.pp.ua)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/asignua/filament-activity-log-plus.svg?style=flat-square)](https://packagist.org/packages/asignua/filament-activity-log-plus)
[![Tests](https://img.shields.io/github/actions/workflow/status/asignua/filament-activity-log-plus/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/asignua/filament-activity-log-plus/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/asignua/filament-activity-log-plus.svg?style=flat-square)](https://packagist.org/packages/asignua/filament-activity-log-plus)
[![License](https://img.shields.io/packagist/l/asignua/filament-activity-log-plus.svg?style=flat-square)](https://github.com/asignua/filament-activity-log-plus/blob/main/LICENSE.md)
[![Plumb score](https://plumbphp.dev/badges/asignua/filament-activity-log-plus/composite.svg)](https://plumbphp.dev/asignua/filament-activity-log-plus)

<img class="filament-hidden" src="https://raw.githubusercontent.com/asignua/filament-activity-log-plus/v1.0.0/art/cover.jpg" alt="Filament Activity Log Plus">

An audit trail for [Filament](https://filamentphp.com) 5 that finishes the job [spatie/laravel-activitylog](https://github.com/spatie/laravel-activitylog)
5 starts: the engine stores rows, this plugin makes them **true** and **readable** on a real, multilingual panel.

Viewers for the activity log already exist; they show what the engine wrote. The trouble is what the engine writes
when a save in a panel is not one `save()`:

- A translatable JSON column shows up as one blob (`title`) instead of `title.uk` / `title.en`.
- `logOnlyDirty` reports columns nobody touched whenever the database has defaults (the *phantom changes*).
- One click on **Save** is a model `save()`, several `sync()`s on pivots and a few media writes: a dozen rows, not one action.
- `sync()` and media uploads fire no model events on the record at all, so "removed the tag" leaves no trace.
- Labels are resolved when the log is read, so a deleted record is "Post #57" and a renamed user changes history.

This plugin fixes each of those in the write layer, and ships the History action and a read-only log resource on top.

- [Screenshots](#screenshots)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Translatable fields](#translatable-fields)
- [Phantom changes](#phantom-changes)
- [Batches](#batches)
- [Pivot sync](#pivot-sync)
- [Media](#media)
- [Auth events](#auth-events)
- [Custom events](#custom-events)
- [Labels](#labels)
- [History action](#history-action)
- [Activity log resource](#activity-log-resource)
- [Authorization](#authorization)
- [Pruning](#pruning)
- [Configuration](#configuration)
- [Translations](#translations)
- [AI agents](#ai-agents)
- [Testing](#testing)

## Screenshots

The History button on a record: a field / old / new table per entry, one row per language (`Title (uk)`, `Title (en)`), and attached / detached tag badges.

![The History modal](https://raw.githubusercontent.com/asignua/filament-activity-log-plus/v1.0.0/art/history-modal.jpg)

![The History modal, dark mode](https://raw.githubusercontent.com/asignua/filament-activity-log-plus/v1.0.0/art/history-modal-dark.jpg)

The read-only Activity log, "Operations only" filter on: who did what to which record, and which fields changed.

![The Activity log resource](https://raw.githubusercontent.com/asignua/filament-activity-log-plus/v1.0.0/art/activity-log.jpg)

One operation, opened from the log: the record save and the tag sync of the same click, together.

![One operation in the log](https://raw.githubusercontent.com/asignua/filament-activity-log-plus/v1.0.0/art/activity-batch.jpg)

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- Filament 5
- `spatie/laravel-activitylog` 5 (installed as a dependency)

Optional: `spatie/laravel-medialibrary` (file events), `spatie/laravel-permission` (role events),
`spatie/laravel-translatable` (picked up automatically; plain JSON columns work too).

## Installation

```bash
composer require asignua/filament-activity-log-plus
```

Publish **one** of the two migrations and run it. The table name comes from `activity-log-plus.table`
(default `activity_log`); change it *before* you publish.

```bash
php artisan vendor:publish --tag=filament-activity-log-plus-migrations
```

| You are... | Run |
| --- | --- |
| starting fresh | `create_activity_log_plus_table` (the full table; delete the other file) |
| already using spatie's `activity_log` | `add_activity_log_plus_columns` (adds `subject_label`, `causer_label`, `batch_uuid`, `batch_root`, `ip` and an index; delete the other file) |

```bash
php artisan migrate
```

Existing rows keep `batch_root = false`, so the default "Operations only" filter hides them; switch it off in the
log, or mark them once with `UPDATE activity_log SET batch_root = 1 WHERE batch_uuid IS NULL`.

Optionally publish the config:

```bash
php artisan vendor:publish --tag=filament-activity-log-plus-config
```

Register the plugin in your panel provider:

```php
use Asignua\FilamentActivityLogPlus\ActivityLogPlusPlugin;

$panel->plugin(ActivityLogPlusPlugin::make());
```

**What the plugin sets in spatie's config.** Recording works without the panel. On boot the plugin sets
`activitylog.activity_model` and `activitylog.actions.log_activity` to its own classes, **but only while they are still
spatie's defaults**. If your application (or another package) bound its own, it is left alone: extend
`Asignua\FilamentActivityLogPlus\Models\Activity` and `Asignua\FilamentActivityLogPlus\Actions\LogActivityAction`
there to keep the batch, the labels and the IP. It also turns `activitylog.enabled` off when the plugin is disabled and
adds `password` / `remember_token` to `activitylog.default_except_attributes` as a safety net.

## Styling

The views use a few Tailwind utilities that Filament's own stylesheet does not contain. The plugin ships them as a small compiled file (`resources/dist/filament-activity-log-plus.css`, no preflight) and links it after the panel's styles, so **no custom theme or `@source` line is needed**. Publish the file after installing or upgrading:

```bash
php artisan filament:assets
```

The stylesheet is linked by the plugin registered in the panel (the History action rendered in a panel without the plugin stays unstyled). Editing the views? Rebuild with `npm install && npm run build`.

## Quick start

```php
use Asignua\FilamentActivityLogPlus\Concerns\LogsActivityPlus;

class Post extends Model
{
    use LogsActivityPlus; // instead of spatie's LogsActivity, which it includes
}
```

That is all for a model. It logs created / updated / deleted / restored, with `logAll()` (so a model with
`$guarded = ['*']`, written only through repositories, is logged too), only the dirty attributes, no empty entries and
no secrets (`except` config: `id`, `password`, `remember_token`, `created_at`, `updated_at`).

Three optional `protected` hooks:

```php
protected function activityExcept(): array { return ['api_secret']; }          // your own secrets
protected function activityTranslatableAttributes(): array { return ['title']; } // see below
protected function activityIgnoreOnlyChanged(): array { return ['path']; }     // derived columns
```

Override `activityExcept()`, never `activityExceptAll()`: the latter comes from the trait, and a full override would
silently drop the built-in exclusions.

## Translatable fields

A translatable column is a JSON map such as `{"uk":"Привіт","en":"Hello"}`. The log stores the diff per locale and
drops the locales that did not change:

```json
{"attributes": {"title.en": "Hello!"}, "old": {"title.en": "Hello"}}
```

- With **spatie/laravel-translatable** nothing is needed: the trait reads `getTranslatableAttributes()`. The trait also
  switches `useAttributeRawValues()` on for those columns, because the package overrides `getAttribute()` and would hand
  over the string of the *current* locale, so an edit in another language would vanish from the diff.
- With **plain JSON columns** (an `array` cast or a raw string) declare them:

```php
protected function activityTranslatableAttributes(): array
{
    return ['title', 'summary'];
}
```

The expansion is plain static logic in `Support\ActivityDiff` (`expandTranslations`, `onlyColumns`, `truncate`, `isEmpty`,
`changedKeys`), usable on its own.

## Phantom changes

spatie's `logOnlyDirty` compares the model with `getRawOriginal()`, which does not know the **defaults of the
database**. Create a record and update it in the same request and every column with a default (`order`, `noindex`...)
is `null` in memory but `0` / `false` in the table, so a "change" of fields nobody touched lands in the diff.

On `updated` the trait therefore cuts the diff down to `array_keys($model->getChanges())`: exactly the columns that went
into the `UPDATE` (Eloquent syncs them before the `updated` event fires).

Also bounded: values longer than `max_value_length` (5000) are cut with `…` and listed in
`attribute_changes.truncated`, otherwise every edit of a rich-text field stores two HTML blobs; and an update that
changed **only** columns from `ignore_only_changed` / `activityIgnoreOnlyChanged()` writes nothing (typical: a
materialised `path` rewritten on every ancestor save, which would log one row per descendant).

Hooks that save *other* records on save must be silenced, or one edit becomes dozens of rows:

```php
activity()->withoutLogging(fn () => $this->rebuildLinks());
```

Do the same in bulk imports and seeders.

## Batches

One operation = one `batch_uuid`. The entry that opens the operation (normally the record's own save, otherwise the
first pivot/media entry) is the **root** (`batch_root = true`); the log shows roots by default and the modal lists
every part.

- **Panel requests**: the plugin adds `ActivityBatchMiddleware` to the panel, and the service provider hooks Livewire's
  `request` event (persistent middleware cannot wrap a Livewire update: Livewire runs it *before* it calls the component).
  Turn the middleware off with `ActivityLogPlusPlugin::make()->batchMiddleware(false)`, or both with the `batches` config.
- **Console, queue, tests**: there is no request, so every entry is its own root. Wrap the work yourself:

```php
app(ActivityBatch::class)->run(function () use ($post): void {
    $post->update([...]);
    $post->syncAndLog('tags', $ids);
});
```

`ActivityBatch` is a scoped binding (one per request, Octane-safe) and `run()` restores the previous state, so it nests.

## Pivot sync

`sync()` fires no Eloquent events, so a changed relation is invisible to the engine. Two ways in, both writing **one
entry against the owner** (so it reads on the record's own history):

```php
use Asignua\FilamentActivityLogPlus\Concerns\LogsPivotSync;

class Post extends Model
{
    use LogsActivityPlus;
    use LogsPivotSync;
}

$post->syncAndLog('tags', [1, 2, 3]);        // returns sync()'s result

// or, for anything else (attach/detach, a custom pivot):
PivotSynced::dispatch($post, 'tags', attached: [3], detached: [1]);
```

Properties: `{key, label, attached: [{id, title}], detached: [{id, title}]}`, event `pivot_synced` (pass `event:` to use
your own name). The titles are stored **at the moment of the action**: ids alone would leave the log blank on the day
someone renames or deletes the related record. By default they come from the related model through `SubjectLabels`;
pass `titles: [id => title]` to the event, or replace the resolver globally:

```php
PivotTitles::using(fn (string $relation, array $ids, Model $owner): array => [...]); // [id => title]
```

A raw `$post->tags()->sync()` stays invisible. If nothing was attached or detached, nothing is written.

## Media

With `spatie/laravel-medialibrary` installed, uploads and removals are logged as `media_added` / `media_removed`
(properties `collection`, `file_name`, `name`, `mime_type`, `size`) **against the owner of the file**. Filament writes
media directly (`SpatieMediaLibraryFileUpload`), past your DTOs and repositories, so "removed the picture" would
otherwise leave no trace; the batch stitches it to the form save. Switch it off with `log_media`.

Another library, or an "attach a file from a shared library" model, plugs in with an adapter:

```php
MediaAdapters::register(
    MediaAttachment::class,
    owner: fn (MediaAttachment $a): ?Model => $a->attachable,
    properties: fn (MediaAttachment $a): array => ['name' => $a->file?->name],
    events: ['created' => 'media_attached', 'deleted' => 'media_detached'],
);
```

## Auth events

`log_auth` (on by default) records `login`, `logout`, `login_failed` (with the attempted address, never the password)
and `lockout` in the `auth` log, with the IP. With `spatie/laravel-permission` it also records `role_attached` /
`role_detached` by role **name**; that package sends no events unless `permission.events_enabled` is on, so the plugin
switches it on when `log_auth` is. A role lives in a pivot, so without this "promoted to administrator" would never be
logged. The volume is bounded by [pruning](#pruning).

## Custom events

Write the entry against the **owner**, with the names denormalised at write time:

```php
activity()
    ->performedOn($application)
    ->event('approved')
    ->withProperties(['note' => 'Looks fine'])
    ->log('approved');
```

Then teach the log how to present it (an unregistered event is shown as its raw name, in grey):

```php
ActivityEvents::register(
    'approved',
    'Approved',                                           // a translation key or a ready string, or a closure
    color: 'success',                                     // a Filament colour
    summary: fn (Activity $a): string => (string) $a->getProperty('note'),
    view: 'my-app::activity.approved',                    // optional: replaces the body of the card
);
```

Built in: `created`, `updated`, `deleted`, `restored`, `pivot_synced`, `media_added`, `media_removed`, `login`,
`logout`, `login_failed`, `lockout`, `role_attached`, `role_detached`. Registering a built-in name replaces it. An entry
whose properties have the pivot shape (`attached` and `detached` lists) is rendered as badges without registering
anything; any other properties are shown as a definition list. A custom view receives `$entry` and `$properties`.

## Labels

Labels are **stored with the entry**, in the current locale, at the moment of the action: `subject_label`,
`causer_label`. The log survives the deletion of a record or a user.

- **Records**: `SubjectLabels::using(fn (Model $model): ?string => ...)` (return `null` to fall through), then
  `getFilamentName()`, then the first filled of `title`, `name`, `label`, `key`, `email`, `path`, `old_path` (a
  translatable one in the current locale, then the fallback locale, then any filled one), then `Post #57`. Limited to
  190 characters.
- **Types**: the `subjects` config, `Post::class => 'Posts'` (a translation key or a ready string); unlisted models show
  their short class name.
- **Fields**: the `field_labels` config or `FieldLabels::register([...])`; the key is the **column**, so `title` also
  labels `title.uk` and `title.en` (the locale is appended: a bare "Title" on a two-language site does not say which
  changed). Unknown columns fall back to `Str::headline()`. The config wins over code.

## History action

```php
use Asignua\FilamentActivityLogPlus\Actions\ActivityHistoryAction;

protected function getHeaderActions(): array
{
    return [ActivityHistoryAction::make(), DeleteAction::make()];
}
```

A modal with the latest `history_limit` (50) entries of **this record**, newest first: event badge, field / old / new
value (rich text shown as plain text, `(Truncated)` where a value was cut), attached / detached badges, and a
definition list for other properties. Filament has no global hook for header actions, so it is one explicit line per
Edit/View page. It works without the log resource.

## Activity log resource

A read-only resource (create, edit and delete are closed at the resource level, not just hidden): time, user
(searchable; "System" when there is none), event badge, type (toggleable), record and a one-line summary. Filters:
**Operations only** (on by default), event, type and user (taken from the values that really occur in the table) and a
period. The row action opens the whole operation. Sorted by id descending, 25/50/100 per page.

```php
ActivityLogPlusPlugin::make()
    ->navigationGroup('System')
    ->navigationSort(8)
    ->navigationIcon('heroicon-o-clipboard-document-list')
    ->resource(false); // History action only
```

The views use Filament components and a few Tailwind utilities. With a custom panel theme add the package to its
sources: `@source '../../../../vendor/asignua/filament-activity-log-plus/resources/views';`.

## Authorization

The feed shows other people's actions and the addresses of failed logins, so restrict it:

```php
ActivityLogPlusPlugin::make()
    ->authorizeResource(fn (): bool => auth()->user()?->isAdmin())  // the log resource
    ->authorizeHistory(fn (): bool => true);                        // the History action
```

Without a closure the plugin consults the gates `activity-log-plus.view` and `activity-log-plus.history` when they are
defined, and otherwise allows everyone who can enter the panel. The two are separate on purpose: whoever may edit a
record should see who changed it before, even without access to the global log.

## Pruning

```bash
php artisan activity-log-plus:prune            # retention_days (365)
php artisan activity-log-plus:prune --days=90
```

`retention_days = 0` disables it. Deletion runs in chunks of 1000, so it never holds a long lock. A daily run is a
config switch (needs the Laravel scheduler):

```php
'schedule' => ['enabled' => true, 'time' => '03:30'],
```

## Configuration

Every key of `config/activity-log-plus.php` is commented. In short: `enabled`, `table`, `retention_days`,
`max_value_length`, `history_limit`, `log_auth`, `log_media`, `batches`, `except`, `ignore_only_changed`,
`field_labels`, `subjects` and `schedule`. The log columns are cast with `Casts\UnescapedJsonCollection`, not the stock
`collection` cast: that one writes `До...` for non-ASCII text, which makes the table, dumps and backup diffs
unreadable. Reading accepts both formats.

## Translations

The interface ships in English, Ukrainian, German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and
Turkish under the `filament-activity-log-plus::activity-log-plus` namespace. A test keeps every language in step with
the English keys. Override a string by publishing the translations and editing the copy in
`lang/vendor/filament-activity-log-plus`.

## AI agents

The package ships [Laravel Boost](https://laravel.com/docs/boost) guidelines
(`resources/boost/guidelines/core.blade.php`) that describe the trait, batches, pivots, media and the registries, so a
coding agent wires an audit trail up correctly.

## Testing

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
```

The suite runs on [Orchestra Testbench](https://packages.tools/testbench) with a `workbench/` panel: a model with
translatable JSON fields **without** spatie/laravel-translatable, one **with** it, a `BelongsToMany` pivot, media and
roles.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
