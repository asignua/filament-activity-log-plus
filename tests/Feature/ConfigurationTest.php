<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Tests\Feature;

use Asignua\FilamentActivityLogPlus\Actions\LogActivityAction;
use Asignua\FilamentActivityLogPlus\ActivityLogPlusPlugin;
use Asignua\FilamentActivityLogPlus\Http\Middleware\ActivityBatchMiddleware;
use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class ConfigurationTest extends TestCase
{
    public function test_the_plugin_binds_its_model_and_action_by_default(): void
    {
        $this->assertSame(Activity::class, config('activitylog.activity_model'));
        $this->assertSame(LogActivityAction::class, config('activitylog.actions.log_activity'));
        // Replacing the action must not lose the sibling entry of the same array.
        $this->assertNotNull(config('activitylog.actions.clean_log'));
    }

    public function test_the_secrets_are_added_to_the_global_exclusions(): void
    {
        $this->assertContains('password', config('activitylog.default_except_attributes'));
        $this->assertContains('remember_token', config('activitylog.default_except_attributes'));
    }

    public function test_the_table_comes_from_the_config(): void
    {
        $this->assertSame('activity_log', (new Activity)->getTable());

        config(['activity-log-plus.table' => 'app_activity_log']);

        $this->assertSame('app_activity_log', (new Activity)->getTable());
    }

    public function test_the_create_migration_builds_the_full_table_under_a_configured_name(): void
    {
        config(['activity-log-plus.table' => 'custom_log']);

        (include __DIR__.'/../../database/migrations/create_activity_log_plus_table.php.stub')->up();

        foreach (['log_name', 'description', 'subject_type', 'subject_id', 'event', 'causer_type', 'causer_id', 'attribute_changes', 'properties', 'subject_label', 'causer_label', 'batch_uuid', 'batch_root', 'ip', 'created_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('custom_log', $column), $column);
        }

        $this->assertTrue(Schema::hasIndex('custom_log', ['batch_root', 'created_at']));
    }

    public function test_the_add_columns_migration_upgrades_a_plain_spatie_table_and_can_run_twice(): void
    {
        Schema::drop('activity_log');
        Schema::create('activity_log', function ($table): void {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });

        $migration = include __DIR__.'/../../database/migrations/add_activity_log_plus_columns.php.stub';
        $migration->up();
        $migration->up();

        foreach (['subject_label', 'causer_label', 'batch_uuid', 'batch_root', 'ip'] as $column) {
            $this->assertTrue(Schema::hasColumn('activity_log', $column), $column);
        }

        // The plugin writes into the upgraded table.
        $this->article();
        $this->assertSame('Title', Activity::query()->firstOrFail()->subject_label);

        $migration->down();

        $this->assertFalse(Schema::hasColumn('activity_log', 'batch_uuid'));
    }

    public function test_the_add_columns_migration_indexes_an_existing_batch_uuid_and_rolls_back_cleanly(): void
    {
        Schema::drop('activity_log');
        Schema::create('activity_log', function ($table): void {
            $table->id();
            $table->text('description');
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });

        $migration = include __DIR__.'/../../database/migrations/add_activity_log_plus_columns.php.stub';
        $migration->up();

        $this->assertTrue(Schema::hasIndex('activity_log', ['batch_uuid']));

        $migration->down();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('activity_log', 'batch_uuid'));
    }

    public function test_both_migrations_are_publishable(): void
    {
        $stubs = array_keys(ServiceProvider::pathsToPublish(null, 'filament-activity-log-plus-migrations'));

        $this->assertCount(2, $stubs);
    }

    public function test_the_plugin_adds_the_batch_middleware_to_the_panel(): void
    {
        $this->assertContains(ActivityBatchMiddleware::class, Filament::getPanel('admin')->getMiddleware());
    }

    public function test_the_batch_middleware_wraps_exactly_one_request(): void
    {
        $seen = null;

        (new ActivityBatchMiddleware)->handle(Request::create('/'), function () use (&$seen): Response {
            $seen = $this->batch()->uuid();

            return new Response;
        });

        $this->assertNotNull($seen);
        $this->assertNull($this->batch()->uuid());
    }

    public function test_the_plugin_can_drop_the_middleware_and_the_resource(): void
    {
        $panel = Filament::getPanel('admin');
        $before = count($panel->getMiddleware());

        ActivityLogPlusPlugin::make()->batchMiddleware(false)->resource(false)->register($panel);

        $this->assertCount($before, $panel->getMiddleware());
    }
}
