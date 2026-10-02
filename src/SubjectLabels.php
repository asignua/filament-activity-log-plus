<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * Human labels for the records and the types the log talks about.
 *
 * A subject (and a causer) label is stored AT THE MOMENT OF THE ACTION in the columns
 * `subject_label` / `causer_label`, never resolved when the log is shown: otherwise a
 * deleted record would read "Post #57" and a renamed one would show its new name instead of
 * the one it had.
 *
 * Resolution order for a record:
 *  1. your closure from {@see self::using()} (return `null` to fall through);
 *  2. `getFilamentName()`;
 *  3. the first filled attribute of title, name, label, key, email, path, old_path (a
 *     translatable one is read in the current locale, then the fallback locale, then any);
 *  4. `Basename #id`.
 * The result is limited to 190 characters.
 */
final class SubjectLabels
{
    public const array ATTRIBUTES = ['title', 'name', 'label', 'key', 'email', 'path', 'old_path'];

    private static ?Closure $resolver = null;

    /**
     * @param (Closure(Model): ?string)|null $resolver
     */
    public static function using(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function flush(): void
    {
        self::$resolver = null;
    }

    public static function label(?Model $model): ?string
    {
        if (!$model instanceof Model) {
            return null;
        }

        return Str::limit(trim(self::resolve($model)), 190, '…');
    }

    /**
     * The label of a model type from the `subjects` config map (class or morph alias =>
     * translation key). An unlisted type shows its short class name.
     */
    public static function typeLabel(?string $morph): string
    {
        if ($morph === null || $morph === '') {
            return '—';
        }

        $class = Model::getActualClassNameForMorph($morph);

        /** @var array<string, mixed> $registry */
        $registry = (array) config('activity-log-plus.subjects', []);

        foreach ([$class, $morph] as $key) {
            if (isset($registry[$key]) && is_string($registry[$key])) {
                return __($registry[$key]);
            }
        }

        return class_basename($class);
    }

    private static function resolve(Model $model): string
    {
        if (self::$resolver instanceof Closure) {
            $custom = (self::$resolver)($model);

            if (is_string($custom) && $custom !== '') {
                return $custom;
            }
        }

        if (method_exists($model, 'getFilamentName')) {
            $name = $model->getFilamentName();

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        foreach (self::ATTRIBUTES as $attribute) {
            $value = self::stringAttribute($model, $attribute);

            if ($value !== null) {
                return $value;
            }
        }

        return class_basename($model).' #'.$model->getKey();
    }

    private static function stringAttribute(Model $model, string $attribute): ?string
    {
        if (!array_key_exists($attribute, $model->getAttributes())) {
            return null;
        }

        $value = $model->getAttribute($attribute);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        // A translatable record may be filled in one language only, and "Post #57" helps
        // nobody. spatie/laravel-translatable returns '' for a missing current locale;
        // a plain `array`-cast JSON column returns the whole map.
        $map = is_array($value) ? $value : self::translations($model, $attribute);

        foreach ([app()->getLocale(), (string) config('app.fallback_locale')] as $locale) {
            if (is_string($map[$locale] ?? null) && $map[$locale] !== '') {
                return $map[$locale];
            }
        }

        foreach ($map as $item) {
            if (is_string($item) && $item !== '') {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function translations(Model $model, string $attribute): array
    {
        if (!method_exists($model, 'getTranslations')) {
            return [];
        }

        try {
            $translations = $model->getTranslations($attribute);
        } catch (Throwable) {
            return [];
        }

        return is_array($translations) ? $translations : [];
    }
}
