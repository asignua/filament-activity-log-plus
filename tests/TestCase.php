<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Tests;

use Asignua\FilamentActivityLogPlus\ActivityBatch;
use Asignua\FilamentActivityLogPlus\ActivityEvents;
use Asignua\FilamentActivityLogPlus\ActivityLogPlusServiceProvider;
use Asignua\FilamentActivityLogPlus\FieldLabels;
use Asignua\FilamentActivityLogPlus\PivotTitles;
use Asignua\FilamentActivityLogPlus\SubjectLabels;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use Workbench\App\Models\Article;
use Workbench\App\Models\User;
use Workbench\App\Providers\AdminPanelProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flushRegistries();

        Filament::setCurrentPanel('admin');
    }

    protected function tearDown(): void
    {
        $this->flushRegistries();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ActionsServiceProvider::class,
            ActivitylogServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            MediaLibraryServiceProvider::class,
            NotificationsServiceProvider::class,
            PermissionServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            ActivityLogPlusServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.url', 'https://site.test');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('permission.testing', true);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');

        foreach ([
            __DIR__.'/../database/migrations/create_activity_log_plus_table.php.stub',
            __DIR__.'/../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub',
            __DIR__.'/../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub',
        ] as $stub) {
            (include $stub)->up();
        }
    }

    protected function admin(): User
    {
        return User::factory()->create(['name' => 'Audit Tester']);
    }

    protected function article(string $slug = 'first'): Article
    {
        $article = new Article;
        $article->title = ['uk' => 'Заголовок', 'en' => 'Title'];
        $article->slug = $slug;
        $article->save();

        return $article;
    }

    /**
     * Forget the run-time registrations so no test leaks into the next one.
     */
    private function flushRegistries(): void
    {
        ActivityEvents::flush();
        FieldLabels::flush();
        PivotTitles::flush();
        SubjectLabels::flush();
    }

    protected function batch(): ActivityBatch
    {
        return app(ActivityBatch::class);
    }
}
