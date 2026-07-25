<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SeoSitemapController extends Controller
{
    private const CACHE_CONTROL = 'public, max-age=300, s-maxage=3600, stale-while-revalidate=86400';

    public function __invoke(): JsonResponse
    {
        $categories = DB::table('Products_Sub_Sub_Department_T as categories')
            ->select([
                'categories.Slug as slug',
                'categories.updated_at',
            ])
            ->whereNotNull('categories.Slug')
            ->where('categories.Slug', '<>', '')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('Products_Master_T as category_products')
                    ->whereColumn(
                        'category_products.Product_Sub_Sub_Department_Id',
                        'categories.id'
                    )
                    ->whereNotNull('category_products.Slug')
                    ->where('category_products.Slug', '<>', '')
                    ->where('category_products.Is_Active', 1)
                    ->whereNull('category_products.deleted_at');
            })
            ->orderBy('categories.Slug')
            ->get();

        $products = DB::table('Products_Master_T as products')
            ->select([
                'products.Slug as slug',
                'products.updated_at',
            ])
            ->whereNotNull('products.Slug')
            ->where('products.Slug', '<>', '')
            ->where('products.Is_Active', 1)
            ->whereNull('products.deleted_at')
            ->orderBy('products.Slug')
            ->get();

        return response()
            ->json([
                'categories' => $this->records($categories),
                'products' => $this->records($products),
            ])
            ->header('Cache-Control', self::CACHE_CONTROL);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, array{slug: string, updated_at: string|null}>
     */
    private function records(Collection $rows): array
    {
        return $rows
            ->map(function (object $row): ?array {
                $slug = trim((string) $row->slug);

                if ($slug === '') {
                    return null;
                }

                return [
                    'slug' => $slug,
                    'updated_at' => $this->timestamp($row->updated_at),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC')
            ->utc()
            ->toIso8601String();
    }
}
