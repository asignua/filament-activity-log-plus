<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Tests\Feature;

use Asignua\FilamentActivityLogPlus\Actions\RevertActivityAction;
use Asignua\FilamentActivityLogPlus\ActivityLogPlusPlugin;
use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\Resources\ActivityLog\Pages\ListActivityLog;
use Asignua\FilamentActivityLogPlus\Revert\ActivityReverter;
use Asignua\FilamentActivityLogPlus\Revert\RevertException;
use Asignua\FilamentActivityLogPlus\Revert\RevertMode;
use Asignua\FilamentActivityLogPlus\Revert\RevertPlan;
use Asignua\FilamentActivityLogPlus\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use RuntimeException;
use Workbench\App\Filament\Resources\Articles\Pages\EditArticle;
use Workbench\App\Models\Article;
use Workbench\App\Models\LegacyDateArticle;
use Workbench\App\Models\SoftArticle;
use Workbench\App\Models\Tag;
use Workbench\App\Models\TranslatableArticle;

class RevertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin());
        config(['activity-log-plus.cache.enabled' => false, 'cache.default' => 'array', 'activity-log-plus.revert.require_policy' => false]);
    }

    private function enable(): void
    {
        config(['activity-log-plus.revert.enabled' => true]);
        Gate::define(ActivityLogPlusPlugin::GATE_REVERT, fn (): bool => true);
    }

    private function reverter(): ActivityReverter
    {
        return app(ActivityReverter::class);
    }

    private function last(Model $subject, string $event): Activity
    {
        return Activity::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where('event', $event)
            ->latest('id')
            ->firstOrFail();
    }

    private function edited(): Article
    {
        $article = $this->article();
        $article->slug = 'second';
        $article->save();

        return $article;
    }

    public function test_it_is_off_by_default_even_for_a_user_with_the_gate(): void
    {
        Gate::define(ActivityLogPlusPlugin::GATE_REVERT, fn (): bool => true);

        $entry = $this->last($this->edited(), 'updated');

        $this->assertFalse($this->reverter()->allows($entry, RevertMode::Revert));
        $this->assertFalse(ActivityLogPlusPlugin::resolve()->isRevertable());
    }

    public function test_the_gate_is_deny_by_default(): void
    {
        config(['activity-log-plus.revert.enabled' => true]);

        $entry = $this->last($this->edited(), 'updated');

        $this->assertFalse($this->reverter()->allows($entry, RevertMode::Revert));

        Gate::define(ActivityLogPlusPlugin::GATE_REVERT, fn (): bool => true);

        $this->assertTrue($this->reverter()->allows($entry, RevertMode::Revert));
    }

    public function test_the_plugin_switch_and_closure_override_the_defaults(): void
    {
        ActivityLogPlusPlugin::get()->revertable()->authorizeRevert(fn (Activity $entry): bool => $entry->event === 'updated');

        $entry = $this->last($this->edited(), 'updated');

        $this->assertTrue($this->reverter()->allows($entry, RevertMode::Revert));
        $this->assertFalse($this->reverter()->allows($this->last($entry->subject, 'created'), RevertMode::Revert));
    }

    public function test_the_update_policy_of_the_record_must_pass_too(): void
    {
        $this->enable();
        Gate::policy(Article::class, DenyUpdatePolicy::class);

        $entry = $this->last($this->edited(), 'updated');

        $this->assertFalse($this->reverter()->allows($entry, RevertMode::Revert));

        $this->expectException(AuthorizationException::class);
        $this->reverter()->apply($entry, RevertMode::Revert);
    }

    public function test_a_model_can_be_opted_out(): void
    {
        $this->enable();
        $entry = $this->last($this->edited(), 'updated');

        config(['activity-log-plus.revert.except_models' => [Article::class]]);
        $this->assertFalse($this->reverter()->allows($entry, RevertMode::Revert));

        config(['activity-log-plus.revert.except_models' => []]);
        ActivityLogPlusPlugin::get()->revertableExcept([Article::class]);
        $this->assertFalse($this->reverter()->allows($entry, RevertMode::Revert));
    }

    public function test_revert_writes_the_old_values_back_and_logs_one_reverted_entry_in_one_batch(): void
    {
        $this->enable();
        $article = $this->edited();
        $entry = $this->last($article, 'updated');
        $before = Activity::query()->count();

        $this->reverter()->apply($entry, RevertMode::Revert);

        $this->assertSame('first', $article->fresh()->slug);

        // Exactly one new row: the model's own `updated` entry is suppressed.
        $this->assertSame($before + 1, Activity::query()->count());

        $reverted = $this->last($article, 'reverted');
        $this->assertSame($entry->getKey(), $reverted->getProperty('source_id'));
        $this->assertSame('first', $reverted->attribute_changes['attributes']['slug']);
        $this->assertSame('second', $reverted->attribute_changes['old']['slug']);
        $this->assertNotNull($reverted->batch_uuid);
        $this->assertTrue($reverted->batch_root);
        $this->assertSame($this->admin()::class, $reverted->causer_type);
    }

    public function test_a_field_changed_since_is_a_conflict_skipped_unless_confirmed(): void
    {
        $this->enable();
        $article = $this->article();
        $article->slug = 'second';
        $article->body = 'one';
        $article->save();
        $entry = $this->last($article, 'updated');

        $article->body = 'two';
        $article->save();

        $plan = $this->reverter()->plan($entry, RevertMode::Revert);
        $this->assertTrue($plan->hasConflicts());
        $this->assertSame(['body'], array_map(fn ($row) => $row->field, $plan->conflicts()));

        $this->reverter()->apply($entry, RevertMode::Revert);
        $fresh = $article->fresh();
        $this->assertSame('first', $fresh->slug);
        $this->assertSame('two', $fresh->body, 'the conflicting field is skipped');
        $this->assertSame(['body'], $this->last($article, 'reverted')->getProperty('skipped_conflicts'));

        $article->refresh();
        $article->slug = 'second';
        $article->save();
        $entry2 = $this->last($article, 'updated');
        $article->slug = 'third';
        $article->save();

        $this->reverter()->apply($entry2, RevertMode::Revert, overwriteConflicts: true);
        $this->assertSame('first', $article->fresh()->slug);
        $this->assertSame(['slug'], $this->last($article, 'reverted')->getProperty('overwritten'));
    }

    public function test_nothing_to_write_is_an_error_not_a_silent_success(): void
    {
        $this->enable();
        $article = $this->edited();
        $entry = $this->last($article, 'updated');
        $article->slug = 'third';
        $article->save();

        // The only field conflicts and is not confirmed.
        $this->expectException(RevertException::class);
        $this->reverter()->apply($entry, RevertMode::Revert);
    }

    public function test_translatable_columns_are_reverted_per_locale_with_a_plain_array_cast(): void
    {
        $this->enable();
        $article = $this->article();
        $article->title = ['uk' => 'Новий', 'en' => 'Title'];
        $article->save();
        $entry = $this->last($article, 'updated');
        $this->assertSame(['title.uk'], array_keys($entry->attribute_changes['old']));

        // The other language changes afterwards: it must survive the revert.
        $article->title = ['uk' => 'Новий', 'en' => 'English v2'];
        $article->save();

        $this->reverter()->apply($entry, RevertMode::Revert);

        $this->assertSame(['uk' => 'Заголовок', 'en' => 'English v2'], $article->fresh()->title);
    }

    public function test_translatable_columns_are_reverted_per_locale_with_spatie_translatable(): void
    {
        $this->enable();
        $article = new TranslatableArticle;
        $article->setTranslations('title', ['uk' => 'Один', 'en' => 'One']);
        $article->save();
        $article->setTranslation('title', 'en', 'Two');
        $article->save();
        $entry = $this->last($article, 'updated');

        $article->setTranslation('title', 'uk', 'Три');
        $article->save();

        $this->reverter()->apply($entry, RevertMode::Revert);

        $this->assertSame(['uk' => 'Три', 'en' => 'One'], $article->fresh()->getTranslations('title'));
    }

    public function test_a_locale_that_did_not_exist_is_removed_on_revert(): void
    {
        $this->enable();
        $article = $this->article();
        $article->title = ['uk' => 'Заголовок', 'en' => 'Title', 'pl' => 'Tytuł'];
        $article->save();
        $entry = $this->last($article, 'updated');

        $this->reverter()->apply($entry, RevertMode::Revert);

        $this->assertSame(['uk' => 'Заголовок', 'en' => 'Title'], $article->fresh()->title);
    }

    public function test_keys_timestamps_and_configured_columns_are_never_written(): void
    {
        $this->enable();
        config(['activity-log-plus.revert.except_attributes' => ['id', 'created_at', 'updated_at', 'body']]);
        $article = $this->article();
        $article->slug = 'second';
        $article->body = 'changed';
        $article->save();
        $entry = $this->last($article, 'updated');

        // Forge a diff that points at protected columns.
        $entry->attribute_changes = collect([
            'old' => ['id' => 999, 'created_at' => '2000-01-01T00:00:00Z', 'body' => 'x', 'slug' => 'first'],
            'attributes' => ['id' => $article->id, 'created_at' => '2001-01-01T00:00:00Z', 'body' => 'changed', 'slug' => 'second'],
        ]);
        $entry->save();

        $plan = $this->reverter()->plan($entry, RevertMode::Revert);

        $this->assertSame(['slug'], array_map(fn ($row) => $row->field, $plan->rows));
        $this->assertEqualsCanonicalizing(['id', 'created_at', 'body'], array_column($plan->skipped, 'field'));
        $this->assertSame(RevertPlan::SKIP_EXCLUDED, $plan->skipped[0]['reason']);

        $this->reverter()->apply($entry, RevertMode::Revert);
        $this->assertSame($article->id, $article->fresh()->id);
        $this->assertSame('changed', $article->fresh()->body);
    }

    public function test_a_truncated_value_is_never_written(): void
    {
        $this->enable();
        $article = $this->article();
        $article->slug = 'second';
        $article->save();
        $entry = $this->last($article, 'updated');
        $entry->attribute_changes = collect([
            'old' => ['slug' => 'fir…'],
            'attributes' => ['slug' => 'second'],
            'truncated' => ['slug'],
        ]);
        $entry->save();

        $plan = $this->reverter()->plan($entry, RevertMode::Revert);

        $this->assertSame([], $plan->rows);
        $this->assertSame(RevertPlan::SKIP_TRUNCATED, $plan->skipped[0]['reason']);
    }

    public function test_restore_sets_the_fields_changed_later_back_to_the_state_after_the_entry(): void
    {
        $this->enable();
        $article = $this->article();           // created: slug first
        $article->slug = 'two';
        $article->save();
        $target = $this->last($article, 'updated');
        $article->slug = 'three';
        $article->body = 'text';
        $article->save();
        $article->slug = 'four';
        $article->save();

        $plan = $this->reverter()->plan($target, RevertMode::Restore);
        $this->assertSame(['body', 'slug'], array_map(fn ($row) => $row->field, $plan->rows));
        $this->assertFalse($plan->hasConflicts());

        $this->reverter()->apply($target, RevertMode::Restore);

        $fresh = $article->fresh();
        $this->assertSame('two', $fresh->slug);
        $this->assertNull($fresh->body);
        $this->assertSame('restore', $this->last($article, 'reverted')->getProperty('mode'));
    }

    public function test_restoring_to_the_latest_entry_has_nothing_to_do(): void
    {
        $this->enable();
        $article = $this->edited();
        $entry = $this->last($article, 'updated');

        $this->assertTrue($this->reverter()->plan($entry, RevertMode::Restore)->isEmpty());
        $this->expectException(RevertException::class);
        $this->reverter()->apply($entry, RevertMode::Restore);
    }

    public function test_a_reverted_entry_can_itself_be_reverted(): void
    {
        $this->enable();
        $article = $this->edited();
        $this->reverter()->apply($this->last($article, 'updated'), RevertMode::Revert);
        $reverted = $this->last($article, 'reverted');

        $this->assertTrue($this->reverter()->allows($reverted, RevertMode::Revert));
        $this->reverter()->apply($reverted, RevertMode::Revert);

        $this->assertSame('second', $article->fresh()->slug);
    }

    public function test_pivot_media_and_auth_entries_are_not_revertable(): void
    {
        $this->enable();
        $article = $this->article();
        $tag = Tag::query()->create(['name' => 'x']);
        $article->syncAndLog('tags', [$tag->id]);
        $pivot = $this->last($article, 'pivot_synced');

        $this->assertFalse($this->reverter()->allows($pivot, RevertMode::Revert));
        $this->assertFalse($this->reverter()->allows($pivot, RevertMode::Restore));

        $created = $this->last($article, 'created');
        $this->assertFalse($this->reverter()->allows($created, RevertMode::Revert), 'a creation has no old values');
        $this->assertTrue($this->reverter()->allows($created, RevertMode::Restore));
    }

    public function test_a_soft_deleted_record_can_be_restored_from_its_deleted_entry(): void
    {
        $this->enable();
        $record = SoftArticle::query()->create(['slug' => 'a']);
        $record->delete();
        $entry = $this->last($record, 'deleted');

        $this->assertTrue($this->reverter()->allows($entry, RevertMode::Revert));
        $this->assertFalse($this->reverter()->allows($this->last($record, 'created'), RevertMode::Restore), 'fields of a trashed record are not edited');

        $this->reverter()->apply($entry, RevertMode::Revert);

        $this->assertNull($record->fresh()->deleted_at);
        $this->assertTrue($this->last($record, 'reverted')->getProperty('record_restored'));
    }

    public function test_a_hard_deleted_record_offers_nothing(): void
    {
        $this->enable();
        $article = $this->edited();
        $entry = $this->last($article, 'updated');
        $article->delete();

        $this->assertFalse($this->reverter()->allows($entry, RevertMode::Revert));
        $this->assertFalse($this->reverter()->allows($this->last($article, 'deleted'), RevertMode::Revert));
    }

    public function test_revert_using_replaces_the_write_and_is_still_logged(): void
    {
        $this->enable();
        $article = $this->edited();
        $entry = $this->last($article, 'updated');
        $seen = null;

        ActivityLogPlusPlugin::get()->revertUsing(function (Model $record, array $values, Activity $source) use (&$seen): void {
            $seen = [$record->getKey(), $values, $source->getKey()];
        });

        $this->reverter()->apply($entry, RevertMode::Revert);

        $this->assertSame([$article->id, ['slug' => 'first'], $entry->getKey()], $seen);
        $this->assertSame('second', $article->fresh()->slug, 'the callback owns the write');
        $this->assertSame(1, Activity::query()->where('event', 'reverted')->count());
    }

    public function test_a_failing_write_rolls_everything_back_and_logs_nothing(): void
    {
        $this->enable();
        $article = $this->edited();
        $entry = $this->last($article, 'updated');

        ActivityLogPlusPlugin::get()->revertUsing(function (): void {
            throw new RuntimeException('boom');
        });

        try {
            $this->reverter()->apply($entry, RevertMode::Revert);
            $this->fail('expected exception');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Activity::query()->where('event', 'reverted')->count());
    }

    public function test_the_log_row_action_is_hidden_until_enabled_and_reverts_through_livewire(): void
    {
        Gate::define(ActivityLogPlusPlugin::GATE_RESOURCE, fn (): bool => true);
        $article = $this->edited();
        $entry = $this->last($article, 'updated');

        Livewire::test(ListActivityLog::class)
            ->assertTableActionHidden('revert_activity', $entry);

        $this->enable();

        Livewire::test(ListActivityLog::class)
            ->assertTableActionVisible('revert_activity', $entry)
            ->assertTableActionVisible('restore_activity', $entry)
            ->callTableAction('revert_activity', $entry)
            ->assertNotified(__('filament-activity-log-plus::activity-log-plus.revert.done'));

        $this->assertSame('first', $article->fresh()->slug);
    }

    public function test_a_hidden_row_action_cannot_be_called_directly(): void
    {
        Gate::define(ActivityLogPlusPlugin::GATE_RESOURCE, fn (): bool => true);
        config(['activity-log-plus.revert.enabled' => true]);   // enabled, but the gate is missing
        $article = $this->edited();
        $entry = $this->last($article, 'updated');

        Livewire::test(ListActivityLog::class)
            ->assertTableActionHidden('revert_activity', $entry);

        $this->expectException(AuthorizationException::class);
        $this->reverter()->apply($entry, RevertMode::Revert);
    }

    public function test_the_record_header_action_restores_to_a_chosen_version(): void
    {
        $this->enable();
        $article = $this->article();
        $article->slug = 'two';
        $article->save();
        $target = $this->last($article, 'updated');
        $article->slug = 'three';
        $article->save();

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertActionVisible('restore_version')
            ->callAction('restore_version', ['entry' => $target->getKey(), 'mode' => 'restore'])
            ->assertNotified(__('filament-activity-log-plus::activity-log-plus.revert.done'));

        $this->assertSame('two', $article->fresh()->slug);
    }

    public function test_the_record_header_action_is_hidden_when_off(): void
    {
        $article = $this->edited();

        Livewire::test(EditArticle::class, ['record' => $article->getKey()])
            ->assertActionHidden('restore_version');
    }

    public function test_the_reverted_entry_renders_in_the_operation_modal(): void
    {
        $this->enable();
        $article = $this->edited();
        $this->reverter()->apply($this->last($article, 'updated'), RevertMode::Revert);

        $html = view('filament-activity-log-plus::batch', [
            'activities' => Activity::query()->where('event', 'reverted')->get(),
        ])->render();

        $this->assertStringContainsString(__('filament-activity-log-plus::activity-log-plus.events.reverted'), $html);
        $this->assertStringContainsString('#'.$this->last($article, 'updated')->getKey(), $html);
    }

    public function test_a_datetime_is_reverted_to_the_same_instant_in_a_non_utc_app_timezone(): void
    {
        $this->enable();
        $previous = date_default_timezone_get();
        config(['app.timezone' => 'Europe/Kyiv']);
        date_default_timezone_set('Europe/Kyiv');

        try {
            $article = $this->article();
            $article->published_at = Carbon::parse('2026-06-01 12:00:00', 'Europe/Kyiv');
            $article->save();
            $article->published_at = Carbon::parse('2026-06-02 15:30:00', 'Europe/Kyiv');
            $article->save();
            $entry = $this->last($article, 'updated');

            $this->reverter()->apply($entry, RevertMode::Revert);

            $this->assertSame('2026-06-01 12:00:00', $article->fresh()->getRawOriginal('published_at'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function test_a_model_with_its_own_serialize_date_gets_no_false_date_conflicts(): void
    {
        $this->enable();
        $article = new LegacyDateArticle;
        $article->slug = 'legacy';
        $article->published_at = Carbon::parse('2026-06-01 12:00:00');
        $article->save();
        $article->published_at = Carbon::parse('2026-06-02 15:30:00');
        $article->save();
        $entry = $this->last($article, 'updated');

        $plan = $this->reverter()->plan($entry, RevertMode::Revert);

        $this->assertSame(['published_at'], array_map(fn ($row) => $row->field, $plan->rows));
        $this->assertFalse($plan->hasConflicts());

        $this->reverter()->apply($entry, RevertMode::Revert);
        $this->assertSame('2026-06-01 12:00:00', $article->fresh()->getRawOriginal('published_at'));
    }

    public function test_a_locale_added_later_is_really_removed_on_a_spatie_translatable_model(): void
    {
        $this->enable();
        $article = new TranslatableArticle;
        $article->setTranslations('title', ['uk' => 'Один', 'en' => 'One']);
        $article->save();
        $article->setTranslation('title', 'pl', 'Jeden');
        $article->save();
        $entry = $this->last($article, 'updated');
        $this->assertArrayHasKey('title.pl', $entry->attribute_changes['old']);

        $this->reverter()->apply($entry, RevertMode::Revert);

        $this->assertSame(['uk' => 'Один', 'en' => 'One'], $article->fresh()->getTranslations('title'));
    }

    public function test_new_conflicts_since_the_preview_abort_an_overwrite(): void
    {
        $this->enable();
        $article = $this->article();
        $article->slug = 'second';
        $article->body = 'one';
        $article->save();
        $entry = $this->last($article, 'updated');

        $seen = $this->reverter()->plan($entry, RevertMode::Revert)->seenState();
        $this->assertSame([], $seen);

        $article->body = 'edited meanwhile';
        $article->save();

        try {
            $this->reverter()->apply($entry, RevertMode::Revert, overwriteConflicts: true, seenConflicts: $seen);
            $this->fail('expected the revert to abort');
        } catch (RevertException $exception) {
            $this->assertSame(__('filament-activity-log-plus::activity-log-plus.revert.conflicts_changed'), $exception->getMessage());
        }

        $this->assertSame('edited meanwhile', $article->fresh()->body);
        $this->assertSame('second', $article->fresh()->slug);
        $this->assertSame(0, Activity::query()->where('event', 'reverted')->count());
    }

    public function test_a_record_without_a_policy_is_denied_unless_allowed(): void
    {
        $this->enable();
        config(['activity-log-plus.revert.require_policy' => true]);
        $entry = $this->last($this->edited(), 'updated');

        $this->assertFalse($this->reverter()->allows($entry, RevertMode::Revert));

        ActivityLogPlusPlugin::get()->revertWithoutPolicy();
        $this->assertTrue($this->reverter()->allows($entry, RevertMode::Revert));
    }

    public function test_a_record_with_a_policy_does_not_need_the_without_policy_switch(): void
    {
        $this->enable();
        config(['activity-log-plus.revert.require_policy' => true]);
        Gate::policy(Article::class, AllowUpdatePolicy::class);
        $entry = $this->last($this->edited(), 'updated');

        $this->assertTrue($this->reverter()->allows($entry, RevertMode::Revert));
    }

    public function test_a_page_of_rows_costs_one_subject_query_per_record(): void
    {
        $this->enable();
        $article = $this->edited();

        foreach (['third', 'fourth', 'fifth'] as $slug) {
            $article->slug = $slug;
            $article->save();
        }
        $entries = Activity::query()->where('event', 'updated')->get();
        $this->assertGreaterThanOrEqual(4, $entries->count());

        $reverter = $this->reverter();
        DB::enableQueryLog();

        foreach ($entries as $entry) {
            $reverter->allows($entry, RevertMode::Revert);
            $reverter->allows($entry, RevertMode::Restore);
        }

        $articleQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "articles"'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $articleQueries);
    }

    public function test_a_conflict_that_changed_again_after_the_preview_aborts_the_overwrite(): void
    {
        $this->enable();
        $article = $this->article();
        $article->slug = 'second';
        $article->body = 'one';
        $article->save();
        $entry = $this->last($article, 'updated');
        $article->body = 'two';
        $article->save();

        $seen = $this->reverter()->plan($entry, RevertMode::Revert)->seenState();
        $this->assertSame(['body' => 'two'], $seen);

        $article->body = 'three';   // a third party, after the user saw "two"
        $article->save();

        try {
            $this->reverter()->apply($entry, RevertMode::Revert, overwriteConflicts: true, seenConflicts: $seen);
            $this->fail('expected the revert to abort');
        } catch (RevertException) {
        }

        $this->assertSame('three', $article->fresh()->body);

        // The same value the user saw is fine.
        $article->body = 'two';
        $article->save();
        $this->reverter()->apply($entry, RevertMode::Revert, overwriteConflicts: true, seenConflicts: $seen);
        $this->assertNull($article->fresh()->body);
    }

    public function test_a_malformed_seen_payload_is_cleaned_not_a_server_error(): void
    {
        $clean = new \ReflectionMethod(RevertActivityAction::class, 'cleanSeen');

        $this->assertSame(
            ['title.en' => 'x', 'slug' => null, 'n' => '3'],
            $clean->invoke(null, [['x'], 'a' => ['y'], 'title.en' => 'x', 'slug' => null, 'n' => 3, 'o' => new \stdClass]),
        );
        $this->assertSame([], $clean->invoke(null, 'not an array'));
    }

    public function test_the_subject_memo_is_cleared_after_an_apply(): void
    {
        $this->enable();
        $article = $this->edited();
        $entry = $this->last($article, 'updated');
        $reverter = $this->reverter();
        $reverter->allows($entry, RevertMode::Revert);
        $reverter->apply($entry, RevertMode::Revert);

        DB::enableQueryLog();
        $reverter->allows($entry, RevertMode::Revert);
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "articles"'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $queries, 'the record is looked up again after the revert');
    }

    public function test_the_action_class_is_exposed(): void
    {
        $this->assertSame('restore_version', RevertActivityAction::forRecord()->getName());
    }
}

class AllowUpdatePolicy
{
    public function update(): bool
    {
        return true;
    }
}

class DenyUpdatePolicy
{
    public function update(): bool
    {
        return false;
    }
}
