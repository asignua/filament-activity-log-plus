<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Tests\Feature;

use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\Tests\TestCase;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

use function Livewire\trigger;

use Spatie\Activitylog\Support\ActivityLogStatus;
use Workbench\App\Models\Article;
use Workbench\App\Models\Tag;
use Workbench\App\Models\TranslatableArticle;

/**
 * The write layer: per-locale diffs, phantom changes, secrets, one operation = one batch,
 * pivots and media against the owner, labels that survive deletion, truncation.
 */
class ActivityLogTest extends TestCase
{
    private function fresh(): void
    {
        Activity::query()->delete();
    }

    private function last(): Activity
    {
        return Activity::query()->latest('id')->firstOrFail();
    }

    public function test_update_logs_only_the_changed_locale_of_a_plain_json_column(): void
    {
        $article = $this->article();
        $this->fresh();

        $article->title = ['uk' => 'Заголовок', 'en' => 'Changed'];
        $article->save();

        $activity = $this->last();
        $changes = $activity->attribute_changes?->toArray() ?? [];

        $this->assertSame('updated', $activity->event);
        $this->assertSame(['title.en' => 'Changed'], $changes['attributes']);
        $this->assertSame(['title.en' => 'Title'], $changes['old']);
    }

    public function test_update_logs_only_the_changed_locale_with_spatie_translatable(): void
    {
        $article = new TranslatableArticle;
        $article->setTranslation('title', 'uk', 'Заголовок');
        $article->setTranslation('title', 'en', 'Title');
        $article->save();
        $this->fresh();

        // Another locale is current: spatie would hand over the string of THIS locale, and the
        // Ukrainian edit would vanish from the diff if the raw mode were not on.
        app()->setLocale('en');
        $article->setTranslation('title', 'uk', 'Нова назва');
        $article->save();

        $changes = $this->last()->attribute_changes?->toArray() ?? [];

        $this->assertSame(['title.uk' => 'Нова назва'], $changes['attributes']);
        $this->assertSame(['title.uk' => 'Заголовок'], $changes['old']);
    }

    public function test_created_entry_writes_every_locale(): void
    {
        $this->article();

        $changes = Activity::query()->where('event', 'created')->firstOrFail()->attribute_changes?->toArray() ?? [];

        $this->assertSame('Title', $changes['attributes']['title.en']);
        $this->assertSame('Заголовок', $changes['attributes']['title.uk']);
        $this->assertArrayNotHasKey('title', $changes['attributes']);
    }

    public function test_the_diff_is_stored_without_unicode_escapes(): void
    {
        $this->article();

        $raw = (string) $this->last()->getRawOriginal('attribute_changes');

        $this->assertStringContainsString('Заголовок', $raw);
        $this->assertStringNotContainsString(chr(92).'u0417', $raw);
    }

    public function test_create_then_update_in_one_request_logs_no_phantom_changes(): void
    {
        // `order` and `noindex` have database defaults the in-memory model never saw.
        $article = $this->article();
        $this->fresh();

        $article->slug = 'second';
        $article->save();

        $changes = $this->last()->attribute_changes?->toArray() ?? [];

        $this->assertSame(['slug' => 'second'], $changes['attributes']);
        $this->assertSame(['slug' => 'first'], $changes['old']);
    }

    public function test_an_update_whose_diff_is_empty_after_filtering_writes_nothing(): void
    {
        $article = $this->article();
        $this->fresh();

        // The same values in another key order: the raw JSON differs (so Eloquent runs the
        // UPDATE and spatie sees a change), but no locale changed.
        $article->title = ['en' => 'Title', 'uk' => 'Заголовок'];
        $article->save();

        $this->assertTrue($article->wasChanged('title'));
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_an_empty_update_does_not_take_the_root_from_a_pivot_entry(): void
    {
        $article = $this->article();
        $tag = Tag::create(['name' => 'News']);
        $this->fresh();

        $this->batch()->run(function () use ($article, $tag): void {
            $article->syncAndLog('tags', [$tag->id]);
            $article->title = ['en' => 'Title', 'uk' => 'Заголовок'];
            $article->save();
        });

        $activities = Activity::query()->get();

        $this->assertCount(1, $activities);
        $this->assertSame('pivot_synced', $activities->first()?->event);
        $this->assertTrue((bool) $activities->first()?->batch_root);
    }

    public function test_the_buffer_is_bypassed_inside_a_batch_so_the_root_stays_single(): void
    {
        config(['activitylog.buffer.enabled' => true]);

        $article = $this->article();
        $tag = Tag::create(['name' => 'News']);
        $this->fresh();

        $this->batch()->run(function () use ($article, $tag): void {
            $article->syncAndLog('tags', [$tag->id]);
            $article->slug = 'buffered';
            $article->save();
        });

        $this->assertSame(2, Activity::query()->count());
        $this->assertSame(1, Activity::query()->where('batch_root', true)->count());
        $this->assertSame('updated', Activity::query()->where('batch_root', true)->firstOrFail()->event);
    }

    public function test_the_hook_drops_columns_that_did_not_go_into_the_update(): void
    {
        $article = $this->article();
        $article->slug = 'second';
        $article->save();

        $activity = new Activity;
        $activity->setAttribute('attribute_changes', [
            'attributes' => ['slug' => 'second', 'order' => 0, 'noindex' => false],
            'old' => ['slug' => 'first', 'order' => null, 'noindex' => null],
        ]);

        $article->beforeActivityLogged($activity, 'updated');

        $changes = $activity->attribute_changes?->toArray() ?? [];

        $this->assertSame(['slug' => 'second'], $changes['attributes']);
        $this->assertSame(['slug' => 'first'], $changes['old']);
    }

    public function test_it_records_who_did_it_and_labels_that_survive_deletion(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $article = $this->article();
        $this->fresh();

        $article->title = ['uk' => 'Нова назва', 'en' => 'Title'];
        $article->save();

        $activity = $this->last();

        $this->assertSame($user->getKey(), $activity->causer_id);
        $this->assertSame('Audit Tester', $activity->causer_label);
        // Stored at the moment of the action, so it outlives the record itself.
        $this->assertSame('Title', $activity->subject_label);

        $article->delete();
        $user->update(['name' => 'Renamed']);

        $this->assertSame('Title', $activity->fresh()?->subject_label);
        $this->assertSame('Audit Tester', $activity->fresh()?->causer_label);
    }

    public function test_the_ip_is_stamped_from_a_real_http_request(): void
    {
        $this->batch()->start(http: true);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
        $this->app->instance('request', Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']));

        $this->article();

        $this->assertSame('203.0.113.9', Activity::query()->firstOrFail()->ip);
    }

    public function test_the_synthetic_console_request_does_not_stamp_an_ip(): void
    {
        $this->app->instance('request', Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']));

        $this->batch()->run(fn () => $this->article());

        $this->assertNull(Activity::query()->firstOrFail()->ip);
    }

    public function test_hidden_and_encrypted_attributes_never_reach_the_log(): void
    {
        $article = new class extends Article
        {
            protected $table = 'articles';

            protected $hidden = ['path'];

            // Article lists `secret` itself: only the encrypted cast may keep it out of the log here.
            protected function activityExcept(): array
            {
                return [];
            }

            protected function casts(): array
            {
                return ['title' => 'array', 'secret' => 'encrypted'];
            }

            public function getMorphClass(): string
            {
                return Article::class;
            }
        };
        $article->slug = 'hidden-test';
        $article->title = ['en' => 'T'];
        $article->path = 'hidden-path-value';
        $article->secret = 'totp-secret-value';
        $article->save();

        $raw = (string) $this->last()->getRawOriginal('attribute_changes');

        $this->assertStringContainsString('hidden-test', $raw);
        $this->assertStringNotContainsString('hidden-path-value', $raw);
        $this->assertStringNotContainsString('totp-secret-value', $raw);
    }

    public function test_encrypted_class_casts_never_reach_the_log(): void
    {
        $article = new class extends Article
        {
            protected $table = 'articles';

            protected function activityExcept(): array
            {
                return [];
            }

            protected function casts(): array
            {
                return [
                    'title' => 'array',
                    'secret' => AsEncryptedCollection::class,
                    'path' => AsEncryptedCollection::using(Collection::class),
                ];
            }

            public function getMorphClass(): string
            {
                return Article::class;
            }
        };
        $article->slug = 'class-cast-test';
        $article->title = ['en' => 'T'];
        $article->secret = ['k' => 'collection-secret-value'];
        $article->path = ['k' => 'array-object-secret-value'];
        $article->save();

        $raw = (string) $this->last()->getRawOriginal('attribute_changes');

        $this->assertStringContainsString('class-cast-test', $raw);
        $this->assertStringNotContainsString('collection-secret-value', $raw);
        $this->assertStringNotContainsString('array-object-secret-value', $raw);
    }

    public function test_the_mfa_secrets_are_in_the_default_exclusions(): void
    {
        foreach (['app_authentication_secret', 'app_authentication_recovery_codes', 'two_factor_secret'] as $column) {
            $this->assertContains($column, config('activity-log-plus.except'));
            $this->assertContains($column, config('activitylog.default_except_attributes'));
        }
    }

    public function test_a_pivot_title_is_resolved_past_the_global_scopes_of_the_related_model(): void
    {
        $article = $this->article();
        $tag = Tag::create(['name' => 'Hidden']);
        $this->fresh();

        Tag::addGlobalScope('visible', fn ($query) => $query->where('name', '!=', 'Hidden'));

        try {
            $article->syncAndLog('tags', [$tag->id]);
        } finally {
            Tag::clearBootedModels();
        }

        $this->assertSame([['id' => $tag->id, 'title' => 'Hidden']], $this->last()->properties?->toArray()['attached']);
    }

    public function test_secrets_never_reach_the_log(): void
    {
        $user = $this->admin();
        $this->fresh();

        // With the email, or nothing is written at all: the password alone is excluded and an
        // empty diff is not stored.
        $user->password = 'a-brand-new-password';
        $user->email = 'moved-'.uniqid().'@test.local';
        $user->save();

        $raw = (string) $this->last()->getRawOriginal('attribute_changes');

        $this->assertStringContainsString('email', $raw);
        $this->assertStringNotContainsString('password', $raw);
        $this->assertStringNotContainsString('a-brand-new-password', $raw);
    }

    public function test_changing_only_a_secret_writes_nothing(): void
    {
        $user = $this->admin();
        $this->fresh();

        $user->password = 'another-password';
        $user->save();

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_a_model_excludes_its_own_fields_without_losing_the_defaults(): void
    {
        $article = $this->article();
        $this->fresh();

        $article->secret = 'api-key';
        $article->slug = 'changed';
        $article->save();

        $changes = $this->last()->attribute_changes?->toArray() ?? [];

        $this->assertArrayNotHasKey('secret', $changes['attributes']);
        $this->assertArrayHasKey('slug', $changes['attributes']);
        $this->assertArrayNotHasKey('updated_at', $changes['attributes']);
    }

    public function test_an_update_that_changes_only_a_derived_column_writes_nothing(): void
    {
        $article = $this->article();
        $this->fresh();

        $article->path = '/a/b';
        $article->save();

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_config_can_extend_the_ignore_only_changed_list(): void
    {
        config(['activity-log-plus.ignore_only_changed' => ['slug']]);

        $article = $this->article();
        $this->fresh();

        $article->slug = 'moved';
        $article->save();

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_long_values_are_truncated_and_flagged(): void
    {
        config(['activity-log-plus.max_value_length' => 20]);

        $article = $this->article();
        $this->fresh();

        $article->body = str_repeat('я', 100);
        $article->save();

        $changes = $this->last()->attribute_changes?->toArray() ?? [];

        $this->assertSame(str_repeat('я', 20).'…', $changes['attributes']['body']);
        $this->assertContains('body', $changes['truncated']);
    }

    public function test_one_operation_is_one_batch_with_a_single_root(): void
    {
        $article = $this->article();
        $tag = Tag::create(['name' => 'News']);
        $this->fresh();

        $this->batch()->run(function () use ($article, $tag): void {
            $article->slug = 'batched';
            $article->save();
            $article->syncAndLog('tags', [$tag->id]);
        });

        $activities = Activity::query()->orderBy('id')->get();

        $this->assertCount(2, $activities);
        $this->assertCount(1, $activities->pluck('batch_uuid')->unique()->filter());
        $this->assertSame(1, $activities->where('batch_root', true)->count());
        $this->assertSame('updated', $activities->firstWhere('batch_root', true)?->event);
    }

    public function test_the_record_update_takes_the_root_over_from_an_earlier_pivot_entry(): void
    {
        $article = $this->article();
        $tag = Tag::create(['name' => 'News']);
        $this->fresh();

        $this->batch()->run(function () use ($article, $tag): void {
            $article->syncAndLog('tags', [$tag->id]);
            $article->slug = 'after-pivot';
            $article->save();
        });

        $roots = Activity::query()->where('batch_root', true)->get();

        $this->assertCount(1, $roots);
        $this->assertSame('updated', $roots->first()?->event);
        $this->assertFalse((bool) Activity::query()->where('event', '!=', 'updated')->firstOrFail()->batch_root);
    }

    public function test_the_pivot_after_the_update_does_not_change_the_root(): void
    {
        $article = $this->article();
        $tag = Tag::create(['name' => 'News']);
        $this->fresh();

        $this->batch()->run(function () use ($article, $tag): void {
            $article->slug = 'before-pivot';
            $article->save();
            $article->syncAndLog('tags', [$tag->id]);
        });

        $roots = Activity::query()->where('batch_root', true)->get();

        $this->assertCount(1, $roots);
        $this->assertSame('updated', $roots->first()?->event);
    }

    public function test_the_first_entry_stays_the_root_between_different_subjects(): void
    {
        $article = $this->article();
        $other = $this->article('other');
        $tag = Tag::create(['name' => 'News']);
        $this->fresh();

        $this->batch()->run(function () use ($article, $other, $tag): void {
            $article->syncAndLog('tags', [$tag->id]);
            $other->slug = 'other-changed';
            $other->save();
        });

        $roots = Activity::query()->where('batch_root', true)->get();

        $this->assertCount(1, $roots);
        $this->assertSame($article->getKey(), (int) $roots->first()?->subject_id);
        $this->assertNotSame('updated', $roots->first()?->event);
    }

    public function test_created_drops_empty_values_but_keeps_zero_and_false(): void
    {
        $article = new Article;
        $article->title = ['uk' => 'Заголовок'];
        $article->slug = 'filled';
        $article->body = '';
        $article->order = 0;
        $article->noindex = false;
        $article->save();

        $attributes = Activity::query()->where('event', 'created')->firstOrFail()->attribute_changes?->toArray()['attributes'] ?? [];

        $this->assertArrayNotHasKey('body', $attributes);
        $this->assertArrayNotHasKey('secret', $attributes);
        $this->assertArrayNotHasKey('title.en', $attributes);
        $this->assertSame('filled', $attributes['slug']);
        $this->assertSame(0, $attributes['order']);
        $this->assertArrayHasKey('noindex', $attributes);
        $this->assertEmpty($attributes['noindex']);
    }

    public function test_the_first_entry_is_the_root_even_when_it_is_not_the_model_save(): void
    {
        $article = $this->article();
        $tag = Tag::create(['name' => 'News']);
        $this->fresh();

        $this->batch()->run(fn () => $article->syncAndLog('tags', [$tag->id]));

        $this->assertSame(1, Activity::query()->where('batch_root', true)->count());
    }

    public function test_outside_a_batch_every_entry_is_its_own_root(): void
    {
        $article = $this->article();
        $this->fresh();

        $article->slug = 'one';
        $article->save();
        $article->slug = 'two';
        $article->save();

        $this->assertSame(2, Activity::query()->where('batch_root', true)->whereNull('batch_uuid')->count());
    }

    public function test_a_livewire_request_starts_a_batch(): void
    {
        $this->assertNull($this->batch()->uuid());

        trigger('request', []);

        $this->assertNotNull($this->batch()->uuid());
    }

    public function test_pivot_sync_is_logged_against_the_owner_with_titles(): void
    {
        $article = $this->article();
        $news = Tag::create(['name' => 'News']);
        $sport = Tag::create(['name' => 'Sport']);
        $article->syncAndLog('tags', [$news->id, $sport->id]);
        $this->fresh();

        // Drop one: the very scenario the log exists for.
        $article->syncAndLog('tags', [$news->id]);

        $activity = $this->last();
        $properties = $activity->properties?->toArray() ?? [];

        $this->assertSame('pivot_synced', $activity->event);
        $this->assertSame($article->getKey(), $activity->subject_id);
        $this->assertSame('tags', $properties['key']);
        $this->assertSame('Tags', $properties['label']);
        $this->assertSame([], $properties['attached']);
        $this->assertSame([['id' => $sport->id, 'title' => 'Sport']], $properties['detached']);
    }

    public function test_the_names_in_a_pivot_entry_survive_the_deletion_of_the_related_record(): void
    {
        $article = $this->article();
        $tag = Tag::create(['name' => 'Gone soon']);
        $this->fresh();

        $article->syncAndLog('tags', [$tag->id]);
        $tag->delete();

        $this->assertSame(
            [['id' => $tag->id, 'title' => 'Gone soon']],
            $this->last()->properties?->toArray()['attached'],
        );
    }

    public function test_a_sync_that_changes_nothing_writes_nothing(): void
    {
        $article = $this->article();
        $tag = Tag::create(['name' => 'News']);
        $article->syncAndLog('tags', [$tag->id]);

        $this->fresh();
        $article->syncAndLog('tags', [$tag->id]);

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_a_raw_sync_is_invisible(): void
    {
        $article = $this->article();
        $tag = Tag::create(['name' => 'News']);
        $this->fresh();

        $article->tags()->sync([$tag->id]);

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_media_changes_are_recorded_against_the_owner(): void
    {
        Storage::fake('public');

        $article = $this->article();
        $this->fresh();

        $media = $article->addMedia(UploadedFile::fake()->create('cover.pdf', 10, 'application/pdf'))
            ->toMediaCollection('files');

        $added = Activity::query()->where('event', 'media_added')->latest('id')->firstOrFail();

        // The subject is the owner of the file, not the `media` row.
        $this->assertSame($article->getMorphClass(), $added->subject_type);
        $this->assertSame($article->getKey(), $added->subject_id);
        $this->assertSame('cover.pdf', $added->properties?->toArray()['file_name']);
        $this->assertSame('files', $added->properties?->toArray()['collection']);

        $media->delete();

        $removed = Activity::query()->where('event', 'media_removed')->latest('id')->firstOrFail();
        $this->assertSame($article->getKey(), $removed->subject_id);
    }

    public function test_media_logging_can_be_switched_off(): void
    {
        Storage::fake('public');
        config(['activity-log-plus.log_media' => false]);

        $article = $this->article();
        $this->fresh();

        $article->addMedia(UploadedFile::fake()->create('cover.pdf', 10, 'application/pdf'))->toMediaCollection('files');

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_a_disabled_plugin_writes_nothing(): void
    {
        config(['activitylog.enabled' => false]);
        app()->forgetInstance(ActivityLogStatus::class);

        $article = $this->article();
        $this->assertSame(0, Activity::query()->count());

        $article->syncAndLog('tags', []);
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_the_pivot_listener_respects_the_plugin_switch(): void
    {
        $article = $this->article();
        $tag = Tag::create(['name' => 'News']);
        $this->fresh();

        config(['activity-log-plus.enabled' => false]);
        $article->syncAndLog('tags', [$tag->id]);

        $this->assertSame(0, Activity::query()->count());
    }
}
