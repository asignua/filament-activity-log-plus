<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | The master switch. When `false` nothing is written (the plugin also turns
    | `activitylog.enabled` off) and the History action and the log resource hide
    | themselves. Env: ACTIVITY_LOG_PLUS_ENABLED.
    */
    'enabled' => (bool) env('ACTIVITY_LOG_PLUS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Table
    |--------------------------------------------------------------------------
    |
    | The table of the activity model and of both migration stubs. Change it
    | BEFORE you publish and run the migrations. Host projects that prefix their
    | tables point this at e.g. `app_activity_log`.
    */
    'table' => env('ACTIVITY_LOG_PLUS_TABLE', 'activity_log'),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Rows older than this many days are removed by `activity-log-plus:prune`
    | (and by the schedule below, when it is on). 0 disables pruning.
    */
    'retention_days' => (int) env('ACTIVITY_LOG_PLUS_RETENTION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Maximum value length
    |--------------------------------------------------------------------------
    |
    | String values in a diff longer than this are cut (with an ellipsis) and the
    | field is listed in `attribute_changes.truncated`. Without it every edit of a
    | rich-text field stores two HTML blobs and the table becomes the biggest one
    | in the database. A truncated field cannot be used for a future rollback.
    | 0 disables truncation.
    */
    'max_value_length' => (int) env('ACTIVITY_LOG_PLUS_MAX_VALUE_LENGTH', 5000),

    /*
    |--------------------------------------------------------------------------
    | History limit
    |--------------------------------------------------------------------------
    |
    | How many entries the History action shows for one record, newest first.
    */
    'history_limit' => (int) env('ACTIVITY_LOG_PLUS_HISTORY_LIMIT', 50),

    /*
    |--------------------------------------------------------------------------
    | Authentication events
    |--------------------------------------------------------------------------
    |
    | Log login, logout, failed login and lockout, plus role grants and
    | revocations when spatie/laravel-permission is installed (this switches
    | `permission.events_enabled` on, because that package sends no events by
    | default). Env: ACTIVITY_LOG_PLUS_LOG_AUTH.
    */
    'log_auth' => (bool) env('ACTIVITY_LOG_PLUS_LOG_AUTH', true),

    /*
    |--------------------------------------------------------------------------
    | Media events
    |--------------------------------------------------------------------------
    |
    | Log file uploads and removals (media_added / media_removed) against the
    | OWNER of the file. Needs spatie/laravel-medialibrary; other libraries are
    | plugged in through `MediaAdapters::register()`.
    */
    'log_media' => (bool) env('ACTIVITY_LOG_PLUS_LOG_MEDIA', true),

    /*
    |--------------------------------------------------------------------------
    | Batches
    |--------------------------------------------------------------------------
    |
    | One operation = one batch_uuid. When on, every Livewire request starts a
    | batch and the plugin adds the batch middleware to the panel. Console and
    | queue code opens a batch explicitly with `ActivityBatch::run()`.
    */
    'batches' => (bool) env('ACTIVITY_LOG_PLUS_BATCHES', true),

    /*
    |--------------------------------------------------------------------------
    | Excluded attributes
    |--------------------------------------------------------------------------
    |
    | Never written to the log by the `LogsActivityPlus` trait. A model adds its
    | own secrets by overriding `activityExcept()` (do not replace this list).
    | `$hidden` attributes and `encrypted` casts of the model are excluded automatically.
    | The secrets are also added to `activitylog.default_except_attributes`.
    */
    'except' => [
        'id', 'password', 'remember_token', 'created_at', 'updated_at',
        'app_authentication_secret', 'app_authentication_recovery_codes',
        'two_factor_secret', 'two_factor_recovery_codes',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignore "only changed"
    |--------------------------------------------------------------------------
    |
    | An update that changed ONLY these attributes writes nothing. Typical use:
    | a derived column that is rewritten on every ancestor save (a materialised
    | path), which would otherwise log one row per child. A model adds its own
    | through `activityIgnoreOnlyChanged()`.
    */
    'ignore_only_changed' => [],

    /*
    |--------------------------------------------------------------------------
    | Field labels
    |--------------------------------------------------------------------------
    |
    | Column => translation key (or a ready string). Used by the diff view, the
    | summary column and the History modal. The key is the COLUMN: `title` also
    | covers `title.uk` and `title.en`, the locale is appended automatically.
    | Unknown columns are shown as `Str::headline()`. Example:
    |
    |     'sku' => 'Article number',
    */
    'field_labels' => [],

    /*
    |--------------------------------------------------------------------------
    | Subjects
    |--------------------------------------------------------------------------
    |
    | Model class (or morph alias) => translation key (or a ready string) for the
    | "Type" column and filter. Unlisted models show their short class name.
    | Example:
    |
    |     App\Models\Post::class => 'Posts',
    */
    'subjects' => [],

    /*
    |--------------------------------------------------------------------------
    | Schedule
    |--------------------------------------------------------------------------
    |
    | Run `activity-log-plus:prune` daily at the given time. Off by default; it
    | needs the Laravel scheduler (`schedule:run` every minute).
    */
    'schedule' => [
        'enabled' => (bool) env('ACTIVITY_LOG_PLUS_SCHEDULE', false),
        'time' => env('ACTIVITY_LOG_PLUS_SCHEDULE_TIME', '03:30'),
    ],

];
