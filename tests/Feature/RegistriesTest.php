<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Tests\Feature;

use Asignua\FilamentActivityLogPlus\ActivityEvents;
use Asignua\FilamentActivityLogPlus\FieldLabels;
use Asignua\FilamentActivityLogPlus\MediaAdapters;
use Asignua\FilamentActivityLogPlus\Models\Activity;
use Asignua\FilamentActivityLogPlus\PivotTitles;
use Asignua\FilamentActivityLogPlus\SubjectLabels;
use Asignua\FilamentActivityLogPlus\Support\ActivityPresenter;
use Asignua\FilamentActivityLogPlus\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Workbench\App\Models\Article;
use Workbench\App\Models\Attachment;
use Workbench\App\Models\Tag;
use Workbench\App\Models\TranslatableArticle;
use Workbench\App\Models\User;

class RegistriesTest extends TestCase
{
    public function test_builtin_events_have_labels_and_colours(): void
    {
        foreach (['created', 'updated', 'deleted', 'restored', 'pivot_synced', 'media_added', 'media_removed', 'login', 'logout', 'login_failed', 'lockout', 'role_attached', 'role_detached'] as $event) {
            $this->assertNotSame($event, ActivityEvents::label($event), $event);
            $this->assertStringNotContainsString('activity-log-plus::', ActivityEvents::label($event), $event);
        }

        $this->assertSame('success', ActivityEvents::color('created'));
        $this->assertSame('danger', ActivityEvents::color('deleted'));
        $this->assertSame('danger', ActivityEvents::color('login_failed'));
    }

    public function test_an_unknown_event_is_shown_as_its_raw_name_in_grey(): void
    {
        $this->assertSame('exported', ActivityEvents::label('exported'));
        $this->assertSame('gray', ActivityEvents::color('exported'));
        $this->assertSame('—', ActivityEvents::label(null));
    }

    public function test_the_eegnith_event_names_can_be_registered_as_they_are_stored(): void
    {
        foreach (['taxonomy', 'media_attached', 'media_detached'] as $name) {
            ActivityEvents::register($name, 'Label of '.$name, 'info');
        }

        $this->assertSame('Label of taxonomy', ActivityEvents::label('taxonomy'));
        $this->assertContains('media_attached', ActivityEvents::names());
        $this->assertContains('created', ActivityEvents::names());
    }

    public function test_registering_replaces_a_builtin_and_a_closure_label_is_resolved_lazily(): void
    {
        ActivityEvents::register('created', fn (): string => 'Born', 'primary');

        $this->assertSame('Born', ActivityEvents::label('created'));
        $this->assertSame('primary', ActivityEvents::color('created'));

        ActivityEvents::flush();

        $this->assertNotSame('Born', ActivityEvents::label('created'));
    }

    public function test_a_custom_summary_drives_the_row_summary(): void
    {
        ActivityEvents::register('approved', 'Approved', 'success', fn (Activity $a): string => 'by '.$a->getProperty('who'));

        $activity = new Activity;
        $activity->event = 'approved';
        $activity->properties = collect(['who' => 'Olena']);

        $this->assertSame('by Olena', ActivityPresenter::summary($activity));
    }

    public function test_the_summary_of_builtin_events(): void
    {
        $media = new Activity;
        $media->event = 'media_added';
        $media->properties = collect(['file_name' => 'cover.jpg']);
        $this->assertSame('cover.jpg', ActivityPresenter::summary($media));

        $roles = new Activity;
        $roles->event = 'role_attached';
        $roles->properties = collect(['roles' => ['admin', 'editor']]);
        $this->assertSame('admin, editor', ActivityPresenter::summary($roles));

        $pivot = new Activity;
        $pivot->event = 'taxonomy'; // an unregistered event with a pivot-shaped payload
        $pivot->properties = collect(['label' => 'Regions', 'attached' => [['id' => 1, 'title' => 'A']], 'detached' => [['id' => 2, 'title' => 'B'], ['id' => 3, 'title' => 'C']]]);
        $this->assertSame('Regions +1 −2', ActivityPresenter::summary($pivot));
    }

    public function test_a_diff_summary_lists_up_to_four_fields(): void
    {
        $activity = new Activity;
        $activity->event = 'updated';
        $activity->attribute_changes = collect(['attributes' => ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1, 'f' => 1]]);

        $this->assertSame('A, B, C, D, +2', ActivityPresenter::summary($activity));
    }

    public function test_field_labels_come_from_config_then_code_then_headline(): void
    {
        $this->assertSame('Sku User', FieldLabels::label('sku_user'));

        FieldLabels::register(['sku_user' => 'Registered label']);
        $this->assertSame('Registered label', FieldLabels::label('sku_user'));

        config(['activity-log-plus.field_labels' => ['sku_user' => 'Article number']]);
        $this->assertSame('Article number', FieldLabels::label('sku_user'));
        // The key is the COLUMN: the locale suffix is appended automatically.
        $this->assertSame('Article number (uk)', FieldLabels::label('sku_user.uk'));
    }

    public function test_field_labels_are_translated_and_malformed_config_is_ignored(): void
    {
        app('translator')->addLines(['custom.sku' => 'Артикул'], 'en');
        config(['activity-log-plus.field_labels' => ['sku' => 'custom.sku', 5 => 'x', 'bad' => 12, 'empty' => '']]);

        $this->assertSame('Артикул', FieldLabels::label('sku'));
        $this->assertSame('Bad', FieldLabels::label('bad'));
        $this->assertSame('Empty', FieldLabels::label('empty'));
    }

    public function test_subject_label_chain(): void
    {
        $article = $this->article();
        $this->assertSame('Title', SubjectLabels::label($article), 'array-cast title, current locale');

        app()->setLocale('de');
        config(['app.fallback_locale' => 'uk']);
        $this->assertSame('Заголовок', SubjectLabels::label($article), 'fallback locale');

        $onlyEnglish = new Article;
        $onlyEnglish->title = ['pl' => 'Tylko po polsku'];
        $this->assertSame('Tylko po polsku', SubjectLabels::label($onlyEnglish), 'any filled locale');

        $bare = new Article;
        $bare->id = 57;
        $this->assertSame('Article #57', SubjectLabels::label($bare));

        $user = new User;
        $user->email = 'a@b.test';
        $this->assertSame('a@b.test', SubjectLabels::label($user));
        $this->assertNull(SubjectLabels::label(null));
    }

    public function test_subject_label_with_spatie_translatable_falls_back_to_a_filled_locale(): void
    {
        $article = new TranslatableArticle;
        $article->setTranslation('title', 'uk', 'Лише українською');
        $article->save();

        app()->setLocale('en');

        $this->assertSame('Лише українською', SubjectLabels::label($article));
    }

    public function test_subject_label_closure_wins_and_may_decline(): void
    {
        $article = $this->article();

        SubjectLabels::using(fn (Model $model): ?string => $model instanceof Article ? 'Custom '.$model->slug : null);
        $this->assertSame('Custom first', SubjectLabels::label($article));

        $user = new User;
        $user->name = 'Olena';
        $this->assertSame('Olena', SubjectLabels::label($user), 'declined: falls through to the chain');
    }

    public function test_subject_label_is_cut_at_190_characters_plus_an_ellipsis(): void
    {
        $article = new Article;
        $article->slug = 'x';
        SubjectLabels::using(fn (): string => str_repeat('я', 300));

        $this->assertSame(str_repeat('я', 190).'…', SubjectLabels::label($article));
    }

    public function test_subject_label_uses_get_filament_name(): void
    {
        $model = new class extends Model
        {
            public function getFilamentName(): string
            {
                return 'Filament name';
            }
        };

        $this->assertSame('Filament name', SubjectLabels::label($model));
    }

    public function test_type_labels_come_from_the_subjects_map(): void
    {
        config(['activity-log-plus.subjects' => [Article::class => 'Articles']]);

        $this->assertSame('Articles', SubjectLabels::typeLabel(Article::class));
        $this->assertSame('Tag', SubjectLabels::typeLabel(Tag::class));
        $this->assertSame('—', SubjectLabels::typeLabel(null));
    }

    public function test_pivot_titles_can_be_replaced(): void
    {
        PivotTitles::using(fn (string $relation, array $ids): array => array_combine($ids, array_map(fn ($id): string => strtoupper($relation).$id, $ids)));

        $this->assertSame(
            [['id' => 4, 'title' => 'TAGS4']],
            PivotTitles::describe($this->article(), 'tags', [4]),
        );
    }

    public function test_known_pivot_titles_win_and_missing_ones_fall_back_to_the_id(): void
    {
        $article = $this->article();

        $this->assertSame(
            [['id' => 1, 'title' => 'Known'], ['id' => 99, 'title' => '#99']],
            PivotTitles::describe($article, 'tags', [1, 99], [1 => 'Known']),
        );
    }

    public function test_a_custom_media_adapter_writes_events_against_the_owner(): void
    {
        MediaAdapters::register(
            Attachment::class,
            owner: fn (Model $a): ?Model => $a instanceof Attachment ? $a->article : null,
            properties: fn (Model $a): array => ['name' => $a instanceof Attachment ? $a->name : null],
            events: ['created' => 'media_attached', 'deleted' => 'media_detached'],
        );
        // Registering again must not double the entries.
        MediaAdapters::register(
            Attachment::class,
            owner: fn (Model $a): ?Model => null,
            properties: fn (Model $a): array => [],
            events: ['created' => 'media_attached', 'deleted' => 'media_detached'],
        );

        $article = $this->article();
        Activity::query()->delete();

        $attachment = Attachment::create(['article_id' => $article->id, 'name' => 'report.pdf']);

        $this->assertSame(1, Activity::query()->count());
        $entry = Activity::query()->firstOrFail();
        $this->assertSame('media_attached', $entry->event);
        $this->assertSame($article->getKey(), $entry->subject_id);
        $this->assertSame(['name' => 'report.pdf'], $entry->properties?->toArray());

        $attachment->delete();

        $this->assertSame('media_detached', Activity::query()->latest('id')->firstOrFail()->event);
    }
}
