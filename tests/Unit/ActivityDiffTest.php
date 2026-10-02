<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Tests\Unit;

use Asignua\FilamentActivityLogPlus\Support\ActivityDiff;
use PHPUnit\Framework\TestCase;

/**
 * Diff shaping: per-locale expansion of translatable JSON and truncation of long values.
 * Pure logic: no database and no application.
 */
class ActivityDiffTest extends TestCase
{
    public function test_it_expands_translatable_columns_per_locale(): void
    {
        $changes = ActivityDiff::expandTranslations([
            'attributes' => ['title' => '{"uk":"Старий","en":"NEW"}'],
            'old' => ['title' => '{"uk":"Старий","en":"OLD"}'],
        ], ['title']);

        // An unchanged locale drops out completely, otherwise every edit would look like a
        // change in both languages.
        self::assertSame(['title.en' => 'NEW'], $changes['attributes']);
        self::assertSame(['title.en' => 'OLD'], $changes['old']);
    }

    public function test_it_keeps_locale_added_from_scratch(): void
    {
        $changes = ActivityDiff::expandTranslations([
            'attributes' => ['title' => '{"uk":"Заголовок","en":"Title"}'],
            'old' => ['title' => '{"uk":"Заголовок"}'],
        ], ['title']);

        self::assertSame(['title.en' => 'Title'], $changes['attributes']);
        self::assertSame(['title.en' => null], $changes['old']);
    }

    public function test_it_writes_every_locale_on_create_where_there_is_no_old_side(): void
    {
        $changes = ActivityDiff::expandTranslations([
            'attributes' => ['title' => '{"uk":"Заголовок","en":"Title"}'],
        ], ['title']);

        self::assertSame(['title.en' => 'Title', 'title.uk' => 'Заголовок'], $changes['attributes']);
        self::assertArrayNotHasKey('old', $changes);
    }

    public function test_it_leaves_non_translatable_columns_untouched(): void
    {
        $changes = ActivityDiff::expandTranslations([
            'attributes' => ['order' => 5, 'public' => ['uk' => true]],
            'old' => ['order' => 3, 'public' => ['uk' => false]],
        ], ['title']);

        self::assertSame(['order' => 5, 'public' => ['uk' => true]], $changes['attributes']);
    }

    public function test_it_truncates_long_values_and_lists_them(): void
    {
        $long = str_repeat('я', 50);

        $changes = ActivityDiff::truncate([
            'attributes' => ['body.uk' => $long, 'title.uk' => 'коротко'],
            'old' => ['body.uk' => $long, 'title.uk' => 'було'],
        ], 10);

        self::assertSame(str_repeat('я', 10).'…', $changes['attributes']['body.uk']);
        self::assertSame('коротко', $changes['attributes']['title.uk']);
        self::assertSame(['body.uk'], $changes['truncated']);
    }

    public function test_it_truncates_large_array_values_as_json_and_keeps_small_ones(): void
    {
        $blocks = [['type' => 'paragraph', 'text' => str_repeat('я', 50)]];

        $changes = ActivityDiff::truncate([
            'attributes' => ['blocks' => $blocks, 'settings' => ['a' => 1]],
            'old' => ['blocks' => null, 'settings' => ['a' => 0]],
        ], 20);

        self::assertSame('[{"type":"paragraph"…', $changes['attributes']['blocks']);
        self::assertSame(['a' => 1], $changes['attributes']['settings']);
        self::assertSame(['blocks'], $changes['truncated']);
    }

    public function test_zero_max_length_disables_truncation(): void
    {
        $long = str_repeat('a', 100);

        $changes = ActivityDiff::truncate(['attributes' => ['body' => $long]], 0);

        self::assertSame($long, $changes['attributes']['body']);
        self::assertArrayNotHasKey('truncated', $changes);
    }

    public function test_changed_keys_merges_both_sides(): void
    {
        $keys = ActivityDiff::changedKeys([
            'attributes' => ['title.uk' => 'a', 'order' => 1],
            'old' => ['title.uk' => 'b', 'slug.en' => 'c'],
        ]);

        self::assertSame(['order', 'slug.en', 'title.uk'], $keys);
    }

    public function test_is_empty_detects_no_changes(): void
    {
        self::assertTrue(ActivityDiff::isEmpty(['attributes' => [], 'old' => []]));
        self::assertFalse(ActivityDiff::isEmpty(['attributes' => ['a' => 1]]));
    }

    public function test_it_accepts_an_array_cast_column_as_well_as_a_raw_json_string(): void
    {
        $changes = ActivityDiff::expandTranslations([
            'attributes' => ['title' => ['uk' => 'Той самий', 'en' => 'NEW']],
            'old' => ['title' => ['uk' => 'Той самий', 'en' => 'OLD']],
        ], ['title']);

        self::assertSame(['title.en' => 'NEW'], $changes['attributes']);
    }

    public function test_only_columns_keeps_the_columns_that_went_into_the_update(): void
    {
        $changes = ActivityDiff::onlyColumns([
            'attributes' => ['slug' => 'b', 'order' => 0, 'noindex' => false],
            'old' => ['slug' => 'a', 'order' => null, 'noindex' => null],
        ], ['slug']);

        self::assertSame(['slug' => 'b'], $changes['attributes']);
        self::assertSame(['slug' => 'a'], $changes['old']);
    }

    public function test_only_columns_leaves_a_missing_side_alone(): void
    {
        $changes = ActivityDiff::onlyColumns(['attributes' => ['slug' => 'b', 'order' => 0]], ['slug']);

        self::assertSame(['slug' => 'b'], $changes['attributes']);
        self::assertArrayNotHasKey('old', $changes);
    }
}
