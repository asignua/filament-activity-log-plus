<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Tests\Feature;

use Asignua\FilamentActivityLogPlus\Actions\ActivityHistoryAction;
use Asignua\FilamentActivityLogPlus\ActivityEvents;
use Asignua\FilamentActivityLogPlus\ActivityLogPlusPlugin;
use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\Repositories\ActivityRepository;
use Asignua\FilamentActivityLogPlus\Resources\ActivityLog\ActivityLogResource;
use Asignua\FilamentActivityLogPlus\Resources\ActivityLog\Pages\ListActivityLog;
use Asignua\FilamentActivityLogPlus\Resources\ActivityLog\Tables\ActivityLogTable;
use Asignua\FilamentActivityLogPlus\Support\ActivityPresenter;
use Asignua\FilamentActivityLogPlus\Tests\TestCase;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Workbench\App\Filament\Resources\Articles\Pages\EditArticle;
use Workbench\App\Models\Article;

class ActivityLogResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin());

        // The resource is closed by default; these tests exercise it as an authorised user.
        Gate::define(ActivityLogPlusPlugin::GATE_RESOURCE, fn (): bool => true);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function entry(Article $article, string $event, array $attributes = []): Activity
    {
        $entry = new Activity;
        $entry->log_name = 'default';
        $entry->description = $event;
        $entry->event = $event;
        $entry->subject()->associate($article);
        $entry->subject_label = $article->slug;

        foreach ($attributes as $key => $value) {
            $entry->setAttribute($key, $value);
        }

        $entry->save();

        return $entry;
    }

    public function test_the_panel_pages_respond(): void
    {
        $this->article();

        $this->get(ActivityLogResource::getUrl('index'))->assertOk();
    }

    public function test_the_log_is_read_only(): void
    {
        $activity = Activity::query()->firstOrCreate(['description' => 'x'], ['log_name' => 'default']);

        $this->assertFalse(ActivityLogResource::canCreate());
        $this->assertFalse(ActivityLogResource::canEdit($activity));
        $this->assertFalse(ActivityLogResource::canDelete($activity));
    }

    public function test_the_resource_is_closed_by_default_and_history_is_open(): void
    {
        // A fresh gate without the ability defined in setUp().
        $this->app->instance(GateContract::class, new AccessGate($this->app, fn () => auth()->user()));
        Gate::clearResolvedInstance(GateContract::class);

        $this->assertFalse(ActivityLogResource::canAccess());
        $this->get(ActivityLogResource::getUrl('index'))->assertForbidden();
        $this->assertTrue(ActivityLogPlusPlugin::allowsHistory());
    }

    public function test_the_gate_decides_when_it_is_defined(): void
    {
        Gate::define(ActivityLogPlusPlugin::GATE_RESOURCE, fn (): bool => false);

        $this->assertFalse(ActivityLogResource::canAccess());
        $this->get(ActivityLogResource::getUrl('index'))->assertForbidden();

        Gate::define(ActivityLogPlusPlugin::GATE_RESOURCE, fn (): bool => true);
        $this->assertTrue(ActivityLogResource::canAccess());
    }

    public function test_an_authorize_closure_wins_over_the_gate(): void
    {
        Gate::define(ActivityLogPlusPlugin::GATE_RESOURCE, fn (): bool => true);
        ActivityLogPlusPlugin::get()->authorizeResource(fn (): bool => false);

        $this->assertFalse(ActivityLogResource::canAccess());
        // The History action has its own switch.
        $this->assertTrue(ActivityLogPlusPlugin::allowsHistory());
    }

    public function test_history_has_its_own_authorization(): void
    {
        ActivityLogPlusPlugin::get()->authorizeHistory(fn (): bool => false);

        $this->assertFalse(ActivityLogPlusPlugin::allowsHistory());
        $this->assertTrue(ActivityLogResource::canAccess());

        Gate::define(ActivityLogPlusPlugin::GATE_HISTORY, fn (): bool => true);
        ActivityLogPlusPlugin::get()->authorizeHistory(fn (): bool => (bool) Gate::allows(ActivityLogPlusPlugin::GATE_HISTORY));
        $this->assertTrue(ActivityLogPlusPlugin::allowsHistory());
    }

    public function test_a_disabled_plugin_hides_the_resource(): void
    {
        config(['activity-log-plus.enabled' => false]);

        $this->assertFalse(ActivityLogResource::canAccess());
    }

    public function test_the_navigation_comes_from_the_plugin(): void
    {
        ActivityLogPlusPlugin::get()->navigationGroup('System')->navigationSort(8);

        $this->assertSame('System', ActivityLogResource::getNavigationGroup());
        $this->assertSame(8, ActivityLogResource::getNavigationSort());
        $this->assertNotNull(ActivityLogResource::getNavigationIcon());
    }

    public function test_the_feed_shows_operation_roots_only_by_default(): void
    {
        $article = $this->article();
        Activity::query()->delete();

        $root = $this->entry($article, 'updated', ['batch_uuid' => '11111111-1111-1111-1111-111111111111', 'batch_root' => true, 'subject_label' => 'Root of the operation']);
        $part = $this->entry($article, 'pivot_synced', ['batch_uuid' => $root->batch_uuid, 'batch_root' => false, 'subject_label' => 'Part of the operation']);

        Livewire::test(ListActivityLog::class)
            ->assertCanSeeTableRecords([$root])
            ->assertCanNotSeeTableRecords([$part])
            // Switching the filter off expands the operation into its parts.
            ->removeTableFilter('batch_root')
            ->assertCanSeeTableRecords([$root, $part]);
    }

    public function test_the_filters_narrow_the_feed(): void
    {
        $article = $this->article();
        Activity::query()->delete();

        $old = $this->entry($article, 'created', ['batch_root' => true, 'created_at' => now()->subDays(10), 'causer_label' => 'Olena', 'causer_id' => 1, 'causer_type' => 'user']);
        $new = $this->entry($article, 'deleted', ['batch_root' => true, 'created_at' => now(), 'causer_label' => 'Ivan', 'causer_id' => 2, 'causer_type' => 'user']);
        // Another causer model with the same id must not leak into the "User" filter.
        $bot = $this->entry($article, 'updated', ['batch_root' => true, 'created_at' => now(), 'causer_label' => 'Bot', 'causer_id' => 2, 'causer_type' => 'bot']);

        Livewire::test(ListActivityLog::class)
            ->filterTable('event', 'created')
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$new])
            ->resetTableFilters()
            ->filterTable('causer_id', 'user:2')
            ->assertCanSeeTableRecords([$new])
            ->assertCanNotSeeTableRecords([$old, $bot])
            ->resetTableFilters()
            ->filterTable('subject_type', $article->getMorphClass())
            ->assertCanSeeTableRecords([$old, $new, $bot])
            ->resetTableFilters()
            ->filterTable('period', ['from' => now()->subDays(2)->toDateString(), 'until' => null])
            ->assertCanSeeTableRecords([$new])
            ->assertCanNotSeeTableRecords([$old]);
    }

    public function test_the_period_filter_uses_calendar_days_of_the_panel_timezone(): void
    {
        config(['app.timezone' => 'UTC']);
        FilamentTimezone::set('Europe/Kyiv');

        $article = $this->article();
        Activity::query()->delete();

        // 01:30 on 7 October in Kyiv (UTC+3), still the 6th in UTC.
        $inside = $this->entry($article, 'updated', ['created_at' => '2026-10-06 22:30:00']);
        $before = $this->entry($article, 'updated', ['created_at' => '2026-10-06 20:30:00']);
        $after = $this->entry($article, 'updated', ['created_at' => '2026-10-07 21:30:00']);

        $ids = ActivityLogTable::wherePeriod(Activity::query(), '2026-10-07', '2026-10-07')->pluck('id')->all();

        $this->assertSame([$inside->id], $ids);
        $this->assertNotContains($before->id, $ids);
        $this->assertNotContains($after->id, $ids);
    }

    public function test_the_view_batch_action_mounts_and_lists_the_whole_operation(): void
    {
        $article = $this->article();
        Activity::query()->delete();

        $root = $this->entry($article, 'updated', ['batch_uuid' => '22222222-2222-2222-2222-222222222222', 'batch_root' => true]);
        $this->entry($article, 'media_added', ['batch_uuid' => $root->batch_uuid, 'properties' => ['file_name' => 'cover.jpg']]);

        Livewire::test(ListActivityLog::class)
            ->mountTableAction('view_batch', $root)
            ->assertHasNoErrors();

        $action = ActivityLogTable::viewBatchAction()->record($root);

        $this->assertStringContainsString('cover.jpg', (string) $action->getModalContent()?->render());
        $this->assertStringContainsString(__('filament-activity-log-plus::activity-log-plus.events.updated'), (string) $action->getModalHeading());
    }

    public function test_the_modal_renders_every_entry_shape(): void
    {
        app()->setLocale('uk');
        $article = $this->article();
        Activity::query()->delete();

        View::addNamespace('workbench', __DIR__.'/../../workbench/resources/views');
        ActivityEvents::register('approved', 'Approved', 'success', view: 'workbench::approved');

        $this->entry($article, 'updated', ['attribute_changes' => [
            'attributes' => ['title.en' => 'Новий', 'body' => 'short…', 'noindex' => true, 'public' => ['uk' => true]],
            'old' => ['title.en' => 'Старий', 'body' => 'long', 'noindex' => false, 'public' => ['uk' => false]],
            'truncated' => ['body'],
        ]]);
        $this->entry($article, 'pivot_synced', ['properties' => [
            'key' => 'tags', 'label' => 'Теги',
            'attached' => [['id' => 1, 'title' => 'Київ']],
            'detached' => [['id' => 2, 'title' => 'Львів']],
        ]]);
        $this->entry($article, 'media_removed', ['properties' => ['collection' => 'main', 'file_name' => 'cover.jpg', 'size' => 1024]]);
        $this->entry($article, 'approved', ['properties' => ['note' => 'looks fine']]);
        $this->entry($article, 'exported');

        $html = view('filament-activity-log-plus::batch', [
            'activities' => Activity::query()->orderBy('id')->get(),
        ])->render();

        $this->assertStringContainsString(__('filament-activity-log-plus::activity-log-plus.ui.old_value'), $html);
        $this->assertStringContainsString('Новий', $html);
        $this->assertStringContainsString('(uk)', $html.'(uk)');
        $this->assertStringContainsString(__('filament-activity-log-plus::activity-log-plus.ui.truncated'), $html);
        $this->assertStringContainsString(__('filament-activity-log-plus::activity-log-plus.ui.yes'), $html);
        $this->assertStringContainsString('Теги', $html);
        $this->assertStringContainsString('Київ', $html);
        $this->assertStringContainsString('Львів', $html);
        $this->assertStringContainsString(__('filament-activity-log-plus::activity-log-plus.ui.attached'), $html);
        $this->assertStringContainsString('cover.jpg', $html);
        // The custom view of a registered event replaces the default body of its card.
        $this->assertStringContainsString('custom-approved: looks fine', $html);
        $this->assertStringContainsString('exported', $html);
    }

    public function test_the_user_filter_options_are_keyed_by_type_and_id(): void
    {
        $article = $this->article();
        Activity::query()->delete();

        $this->entry($article, 'created', ['causer_label' => 'Ivan', 'causer_id' => 2, 'causer_type' => 'user']);
        $this->entry($article, 'created', ['causer_label' => 'Bot', 'causer_id' => 2, 'causer_type' => 'bot']);

        $this->assertSame(
            ['bot:2' => 'Bot', 'user:2' => 'Ivan'],
            app(ActivityRepository::class)->causerLabelsByKey(),
        );
    }

    public function test_the_resource_is_not_globally_searchable(): void
    {
        $this->assertFalse(ActivityLogResource::canGloballySearch());
    }

    public function test_a_bulk_root_counts_the_other_records_of_its_operation(): void
    {
        $first = $this->article('first');
        $second = $this->article('second');
        $third = $this->article('third');
        Activity::query()->delete();

        $uuid = '33333333-3333-3333-3333-333333333333';
        $root = $this->entry($first, 'deleted', ['batch_uuid' => $uuid, 'batch_root' => true]);
        $this->entry($first, 'media_removed', ['batch_uuid' => $uuid]);
        $this->entry($second, 'deleted', ['batch_uuid' => $uuid]);
        // Several entries of one record count as ONE other record.
        $this->entry($second, 'media_removed', ['batch_uuid' => $uuid]);
        $this->entry($second, 'media_removed', ['batch_uuid' => $uuid]);
        $this->entry($third, 'deleted', ['batch_uuid' => $uuid]);
        $single = $this->entry($first, 'updated', ['batch_uuid' => '44444444-4444-4444-4444-444444444444', 'batch_root' => true]);

        $rows = app(ActivityRepository::class)->withOtherSubjectsCount(Activity::query())->get()->keyBy('id');

        $this->assertSame(2, (int) $rows[$root->id]->getAttribute('batch_others'));
        $this->assertSame(0, (int) $rows[$single->id]->getAttribute('batch_others'));

        $more = __('filament-activity-log-plus::activity-log-plus.ui.more_records', ['count' => 2]);
        $this->assertStringContainsString($more, ActivityPresenter::summary($rows[$root->id]));
        $this->assertStringNotContainsString('+', ActivityPresenter::summary($rows[$single->id]));

        Livewire::test(ListActivityLog::class)
            ->assertCanSeeTableRecords([$root, $single])
            ->assertSee($more);
    }

    public function test_the_operation_modal_is_capped(): void
    {
        $article = $this->article();
        Activity::query()->delete();

        $uuid = '55555555-5555-5555-5555-555555555555';
        $root = $this->entry($article, 'updated', ['batch_uuid' => $uuid, 'batch_root' => true]);

        for ($i = 0; $i < ActivityPresenter::BATCH_LIMIT; $i++) {
            $this->entry($article, 'media_added', ['batch_uuid' => $uuid]);
        }

        $view = ActivityPresenter::batchView($root);

        $this->assertCount(ActivityPresenter::BATCH_LIMIT, $view['activities']);
        $this->assertSame(ActivityPresenter::BATCH_LIMIT, $view['limited']);

        $html = (string) ActivityLogTable::viewBatchAction()->record($root)->getModalContent()?->render();
        $this->assertStringContainsString(
            e(__('filament-activity-log-plus::activity-log-plus.ui.batch_limited', ['count' => ActivityPresenter::BATCH_LIMIT])),
            $html,
        );

        Activity::query()->where('event', 'media_added')->limit(1)->delete();
        $this->assertNull(ActivityPresenter::batchView($root)['limited']);
    }

    public function test_stored_labels_and_values_are_escaped(): void
    {
        $article = $this->article();
        Activity::query()->delete();

        $this->entry($article, 'approved', [
            'batch_root' => true,
            'subject_label' => '<script>alert(1)</script>',
            'causer_label' => '<img src=x onerror=alert(2)>',
            'properties' => ['note' => '<b>bold</b>'],
            'attribute_changes' => ['attributes' => ['slug' => '<i>new</i>'], 'old' => ['slug' => 'old']],
        ]);

        $html = view('filament-activity-log-plus::batch', ['activities' => Activity::query()->get()])->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<i>new</i>', $html);
        $this->assertStringContainsString(e('<script>alert(1)</script>'), $html);

        $root = Activity::query()->firstOrFail();

        // The table, and the mounted modal with its heading and description.
        Livewire::test(ListActivityLog::class)
            ->assertSeeHtml(e('<script>alert(1)</script>'))
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertDontSeeHtml('<img src=x')
            ->mountTableAction('view_batch', $root)
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertDontSeeHtml('<img src=x');
    }

    public function test_the_history_action_mounts_on_an_edit_page(): void
    {
        $article = $this->article();
        $article->slug = 'after-edit';
        $article->save();

        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
            ->assertActionExists('activity_history')
            ->assertActionVisible('activity_history')
            ->mountAction('activity_history')
            ->assertHasNoErrors();

        $html = ActivityHistoryAction::make()->record($article)->getModalContent()?->render();

        $this->assertStringContainsString('after-edit', (string) $html);
    }

    public function test_the_history_cards_show_the_author_or_the_system_placeholder(): void
    {
        $article = $this->article();
        $article->slug = 'by-admin';
        $article->save();

        Activity::query()->create([
            'log_name' => 'default',
            'description' => 'system',
            'event' => 'updated',
            'subject_type' => $article->getMorphClass(),
            'subject_id' => $article->getKey(),
            'causer_label' => null,
        ]);

        $html = (string) ActivityHistoryAction::make()->record($article)->getModalContent()?->render();

        $this->assertStringContainsString('Audit Tester', $html);
        $this->assertStringContainsString(__('filament-activity-log-plus::activity-log-plus.ui.system_record'), $html);
    }

    public function test_the_history_is_limited_and_belongs_to_the_record(): void
    {
        config(['activity-log-plus.history_limit' => 2]);

        $article = $this->article();
        $other = $this->article('other');

        foreach (['a', 'b', 'c'] as $slug) {
            $article->slug = $slug;
            $article->save();
        }

        $other->slug = 'zzz-other';
        $other->save();

        $html = (string) ActivityHistoryAction::make()->record($article)->getModalContent()?->render();

        // Newest first, two entries, none of the other record.
        $this->assertStringContainsString('>c<', preg_replace('/\s+/', '', $html) ?? '');
        $this->assertSame(2, substr_count($html, 'rounded-lg border'));
        $this->assertStringNotContainsString('zzz-other', $html);
    }

    public function test_the_history_action_hides_when_not_authorized_or_disabled(): void
    {
        $article = $this->article();

        ActivityLogPlusPlugin::get()->authorizeHistory(fn (): bool => false);
        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
            ->assertActionHidden('activity_history');

        ActivityLogPlusPlugin::get()->authorizeHistory(true);
        config(['activity-log-plus.enabled' => false]);
        Livewire::test(EditArticle::class, ['record' => $article->getRouteKey()])
            ->assertActionHidden('activity_history');
    }
}
