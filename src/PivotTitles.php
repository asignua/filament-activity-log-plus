<?php

declare(strict_types=1);

namespace Asignua\FilamentActivityLogPlus;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Resolves the display names of the related records in a pivot entry, AT THE MOMENT OF THE
 * ACTION. Storing ids only would leave the log blank on the day someone renames or deletes
 * the related record, which is exactly when you need it.
 *
 * Default: the related model of the relation, labelled through {@see SubjectLabels}. Replace
 * it for a relation whose names live elsewhere:
 *
 *     PivotTitles::using(fn (string $relation, array $ids, Model $owner): array => [...]);
 *
 * The closure returns `[id => title]`; ids it does not return fall back to `#id`.
 */
final class PivotTitles
{
    private static ?Closure $resolver = null;

    /**
     * @param (Closure(string, list<int|string>, Model): array<int|string, string>)|null $resolver
     */
    public static function using(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function flush(): void
    {
        self::$resolver = null;
    }

    /**
     * @param list<int|string>         $ids
     * @param array<int|string,string> $known titles the caller already has; they win over the resolver
     *
     * @return list<array{id: int|string, title: string}>
     */
    public static function describe(Model $owner, string $relation, array $ids, array $known = []): array
    {
        if ($ids === []) {
            return [];
        }

        $missing = array_values(array_filter($ids, static fn (int|string $id): bool => !isset($known[$id])));
        $titles = $known + ($missing === [] ? [] : self::titles($owner, $relation, $missing));
        $described = [];

        foreach ($ids as $id) {
            $described[] = ['id' => $id, 'title' => $titles[$id] ?? '#'.$id];
        }

        return $described;
    }

    /**
     * @param list<int|string> $ids
     *
     * @return array<int|string, string>
     */
    private static function titles(Model $owner, string $relation, array $ids): array
    {
        if (self::$resolver instanceof Closure) {
            return (self::$resolver)($relation, $ids, $owner);
        }

        if (!method_exists($owner, $relation)) {
            return [];
        }

        $relationObject = $owner->{$relation}();

        if (!$relationObject instanceof Relation) {
            return [];
        }

        $titles = [];

        foreach ($relationObject->getRelated()->newQueryWithoutScopes()->findMany($ids) as $related) {
            $label = SubjectLabels::label($related);

            if ($label !== null) {
                $titles[$related->getKey()] = $label;
            }
        }

        return $titles;
    }
}
