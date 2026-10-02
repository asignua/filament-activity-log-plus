<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Support;

use Illuminate\Support\Str;

/**
 * A value from the diff of the log turned into a string for display.
 *
 * `attribute_changes` holds anything: a string, a bool, null, an array (JSON columns) or
 * HTML from a rich editor. Printing an array as is would give "Array", and raw HTML would
 * mix markup into the text.
 */
final class ActivityValue
{
    public static function render(mixed $value, int $limit = 400): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? __('filament-activity-log-plus::activity-log-plus.ui.yes') : __('filament-activity-log-plus::activity-log-plus.ui.no');
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return Str::limit(is_string($encoded) ? $encoded : '', $limit);
        }

        if (!is_scalar($value)) {
            return '—';
        }

        $string = (string) $value;

        // Rich content is shown as text: markup in a diff only hides what actually changed.
        if (str_contains($string, '<') && str_contains($string, '>')) {
            $string = trim(html_entity_decode(strip_tags($string), ENT_QUOTES | ENT_HTML5));
        }

        return $string === '' ? '—' : Str::limit($string, $limit);
    }
}
