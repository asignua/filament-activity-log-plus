<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus;

use Illuminate\Support\Str;

/**
 * Labels of the columns shown in a diff.
 *
 * Deliberately NOT generated from the column name for known fields: `public` or `substance`
 * explain nothing on their own. Unknown columns fall back to `Str::headline()`, which is more
 * honest than an invented translation.
 *
 * The key is the COLUMN, not the diff key: `title` covers `title.uk` and `title.en`, and the
 * locale is appended here (a bare "Title" on a two-language site does not answer "which one
 * changed").
 *
 * Sources, strongest first: the `field_labels` config of the host application, then maps
 * added in code by a package or the host through {@see self::register()}.
 */
final class FieldLabels
{
    /** @var array<string, string> */
    private static array $registered = [];

    /**
     * @param array<string, string> $labels column => translation key or a ready string
     */
    public static function register(array $labels): void
    {
        self::$registered = $labels + self::$registered;
    }

    public static function flush(): void
    {
        self::$registered = [];
    }

    public static function label(string $key): string
    {
        $locale = null;
        $column = $key;

        if (str_contains($key, '.')) {
            [$column, $locale] = explode('.', $key, 2);
        }

        $label = self::map()[$column] ?? Str::headline($column);

        return $locale === null ? $label : $label.' ('.$locale.')';
    }

    /**
     * @return array<string, string>
     */
    private static function map(): array
    {
        $labels = [];

        /** @var mixed $configured */
        $configured = config('activity-log-plus.field_labels', []);

        foreach ((is_array($configured) ? $configured : []) + self::$registered as $column => $label) {
            if (!is_string($column) || !is_string($label) || $label === '') {
                continue;
            }

            // A literal that is not a translation key passes through __() unchanged.
            $labels[$column] = __($label);
        }

        return $labels;
    }
}
