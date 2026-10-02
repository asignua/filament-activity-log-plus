<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Tests\Feature;

use Asignua\FilamentActivityLogPlus\Actions\ActivityHistoryAction;
use Asignua\FilamentActivityLogPlus\ActivityEvents;
use Asignua\FilamentActivityLogPlus\ActivityLogPlusPlugin;
use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\Resources\ActivityLog\ActivityLogResource;
use Asignua\FilamentActivityLogPlus\Resources\ActivityLog\Pages\ListActivityLog;
use Asignua\FilamentActivityLogPlus\Resources\ActivityLog\Tables\ActivityLogTable;
use Asignua\FilamentActivityLogPlus\Tests\TestCase;
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

    public function test_everyone_in_the_panel_is_allowed_by_default(): void
    {
        $this->assertTrue(ActivityLogResource::canAccess());
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

        Livewire::test(ListActivityLog::class)
            ->filterTable('event', 'created')
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$new])
            ->resetTableFilters()
            ->filterTable('causer_id', 2)
            ->assertCanSeeTableRecords([$new])
            ->assertCanNotSeeTableRecords([$old])
            ->resetTableFilters()
            ->filterTable('subject_type', $article->getMorphClass())
            ->assertCanSeeTableRecords([$old, $new])
            ->resetTableFilters()
            ->filterTable('period', ['from' => now()->subDays(2)->toDateString(), 'until' => null])
            ->assertCanSeeTableRecords([$new])
            ->assertCanNotSeeTableRecords([$old]);
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
