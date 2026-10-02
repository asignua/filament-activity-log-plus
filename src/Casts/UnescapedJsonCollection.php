<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JsonException;

/**
 * A JSON column read as a Collection and written WITHOUT escaping Unicode.
 *
 * The stock `collection` cast calls `json_encode` without flags, so Cyrillic (or any
 * non-ASCII text) is stored as `До...`. MariaDB keeps the literal string PHP
 * sent (its `json` type is a `longtext` with a `json_valid()` check), which makes the table,
 * dumps and backup diffs unreadable. Reading accepts both formats, so rows written by
 * the stock cast keep working and normalise themselves on the next save.
 *
 * @implements CastsAttributes<Collection<array-key, mixed>, array<array-key, mixed>|Collection<array-key, mixed>>
 */
final class UnescapedJsonCollection implements CastsAttributes
{
    /**
     * @param array<string, mixed> $attributes
     *
     * @return Collection<array-key, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Collection
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Collection) {
            return $value;
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        return is_array($decoded) ? new Collection($decoded) : null;
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @throws JsonException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
