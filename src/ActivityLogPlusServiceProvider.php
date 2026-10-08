<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus;

use Asignua\FilamentActivityLogPlus\Actions\LogActivityAction;
use Asignua\FilamentActivityLogPlus\Commands\PruneCommand;
use Asignua\FilamentActivityLogPlus\Events\PivotSynced;
use Asignua\FilamentActivityLogPlus\Listeners\AuthActivitySubscriber;
use Asignua\FilamentActivityLogPlus\Listeners\LogPivotSync;
use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\Repositories\ActivityRepository;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;
use Illuminate\Support\Facades\Event;

use function Livewire\on;

use Spatie\Activitylog\Actions\LogActivityAction as SpatieLogActivityAction;
use Spatie\Activitylog\Models\Activity as SpatieActivity;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ActivityLogPlusServiceProvider extends PackageServiceProvider
{
    public const string PACKAGE = 'asignua/filament-activity-log-plus';

    public const string STYLESHEET = 'filament-activity-log-plus';

    public static string $name = 'filament-activity-log-plus';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile('activity-log-plus')
            ->hasViews()
            ->hasTranslations()
            ->hasMigrations(['create_activity_log_plus_table', 'add_activity_log_plus_columns'])
            ->hasCommand(PruneCommand::class);
    }

    public function packageRegistered(): void
    {
        // One operation per request, also under Octane (scoped, not a singleton).
        $this->app->scoped(ActivityBatch::class);

        $this->app->singleton(ActivityRepository::class);
    }

    public function packageBooted(): void
    {
        // Published by `filament:assets`, but linked by the plugin itself after the panel's
        // theme (see the plugin's register()): a custom theme must not beat our `dark:` variants.
        FilamentAsset::register([
            Css::make(self::STYLESHEET, __DIR__.'/../resources/dist/filament-activity-log-plus.css')->loadedOnRequest(),
        ], self::PACKAGE);

        $this->configureActivityLog();

        Event::listen(PivotSynced::class, [LogPivotSync::class, 'handle']);

        MediaAdapters::registerDefaults();

        // `enabled`/`log_auth` are read when an event fires, so the subscriber is always on.
        Event::subscribe(AuthActivitySubscriber::class);

        if ((bool) config('activity-log-plus.log_auth', true)) {
            // spatie/laravel-permission sends NO events unless this is on, and a role lives
            // in a pivot, so without it "promoted to administrator" would never be logged.
            config(['permission.events_enabled' => true]);
        }

        // Livewire update requests cannot be wrapped by (persistent) middleware: Livewire
        // runs that BEFORE it calls the component. The `request` hook fires at the start of
        // every update request, which is one user action. The scoped batch resets itself
        // with the next request.
        if ((bool) config('activity-log-plus.batches', true) && function_exists('Livewire\\on')) {
            on('request', static function (): void {
                app(ActivityBatch::class)->start(http: true);
            });
        }

        // The scheduler is resolved lazily; the callback also runs when it is resolved late,
        // so the registration never depends on provider order.
        $this->callAfterResolving(LaravelSchedule::class, Schedule::register(...));
    }

    /**
     * Wires the plugin into spatie's config. The model and the action are replaced ONLY when
     * the host still has spatie's own defaults: an application (or a bridge package) that set
     * its own subclass keeps it. This runs in boot, after every `mergeConfigFrom`, so that
     * the `actions` array is already complete.
     */
    private function configureActivityLog(): void
    {
        if (!(bool) config('activity-log-plus.enabled', true)) {
            config(['activitylog.enabled' => false]);
        }

        $model = config('activitylog.activity_model');

        if ($model === null || $model === SpatieActivity::class) {
            config(['activitylog.activity_model' => Activity::class]);
        }

        $action = config('activitylog.actions.log_activity');

        if ($action === null || $action === SpatieLogActivityAction::class) {
            config(['activitylog.actions.log_activity' => LogActivityAction::class]);
        }

        // A safety net on top of each model's logExcept(): spatie reads this key in the
        // LogsActivity trait only, so it guards models that use spatie's trait without ours.
        // It does NOT filter a hand-written entry: whatever goes into withProperties() is
        // stored as given, so never pass secrets there.
        config(['activitylog.default_except_attributes' => array_values(array_unique(array_merge(
            (array) config('activitylog.default_except_attributes', []),
            [
                'password', 'remember_token',
                'app_authentication_secret', 'app_authentication_recovery_codes',
                'two_factor_secret', 'two_factor_recovery_codes',
            ],
        )))]);
    }
}
