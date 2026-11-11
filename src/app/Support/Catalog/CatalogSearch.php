<?php

namespace App\Support\Catalog;

use Illuminate\Database\Query\Builder;

final class CatalogSearch
{
    private const TITLES = ['p.Product_Name', 'p.Product_Name_Ar'];

    private const IDENTIFIERS = ['p.Product_Sku', 'p.Product_Code'];

    public static function escapeLike(string $term): string
    {
        return strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_', '[' => '![']);
    }

    /**
     * Require every pattern to match at least one of the supplied catalog fields.
     * Columns come from the application; all user input is bound as parameters.
     *
     * @return array{string, list<string>}
     */
    public static function matchAll(array $columns, array $patterns): array
    {
        $anyColumn = '('.implode(' OR ', array_map(
            fn (string $column) => "$column LIKE ? ESCAPE '!'",
            $columns
        )).')';

        $conditions = [];
        $bindings = [];
        foreach ($patterns as $pattern) {
            $conditions[] = $anyColumn;
            array_push($bindings, ...array_fill(0, count($columns), $pattern));
        }

        return [implode(' AND ', $conditions), $bindings];
    }

    public static function rankProducts(Builder $products, string $term): Builder
    {
        $literal = self::escapeLike($term);
        $phrase = "%{$literal}%";
        $prefix = "{$literal}%";

        $words = array_values(array_unique(explode(' ', $term)));
        // Bound the amount of work per request. Long pasted queries still get
        // a literal phrase search instead of silently dropping words.
        $patterns = count($words) > 8
            ? [$phrase]
            : array_map(fn (string $word) => '%'.self::escapeLike($word).'%', $words);

        $textMatch = self::matchAll([...self::TITLES, 'p.Product_Description'], $patterns);
        $legacyMatch = self::matchAll([
            ...self::IDENTIFIERS,
            'ssd.Product_Sub_Sub_Department_Name',
            'ssd.Product_Sub_Sub_Department_Name_Ar',
        ], [$phrase]);

        $products->where(function (Builder $query) use ($textMatch, $legacyMatch) {
            $query->whereRaw(...$textMatch)->orWhereRaw(...$legacyMatch);
        });

        // Rank before applying the result limit, so description and category
        // hits cannot push better product matches out of the candidate set.
        $exactFields = [...self::TITLES, ...self::IDENTIFIERS];
        $rules = [
            1 => [implode(' OR ', array_map(fn ($field) => "$field = ?", $exactFields)), array_fill(0, count($exactFields), $term)],
            2 => self::matchAll(self::TITLES, [$prefix]),
            3 => self::matchAll(self::IDENTIFIERS, [$prefix]),
            4 => self::matchAll(self::TITLES, [$phrase]),
        ];

        if (count($patterns) > 1) {
            $rules[5] = self::matchAll(self::TITLES, $patterns);
        }

        $rules[6] = self::matchAll(self::IDENTIFIERS, [$phrase]);
        $rules[7] = $textMatch;

        $score = 'CASE';
        $bindings = [];
        foreach ($rules as $rank => [$condition, $values]) {
            $score .= " WHEN $condition THEN $rank";
            array_push($bindings, ...$values);
        }

        return $products->selectRaw("$score ELSE 30 END AS Search_Score", $bindings)
            ->orderBy('Search_Score');
    }
}
