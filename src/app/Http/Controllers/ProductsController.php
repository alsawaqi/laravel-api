<?php

namespace App\Http\Controllers;

use App\Models\Products;
use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\ProductsSubSubDepartment;
use App\Models\ProductSpecificationProduct;
use App\Services\ProductDiscountService;
use App\Models\ProductSpecificationDescription;
use App\Support\Reviews\ProductEngagementRules;
use App\Support\Localization\StorefrontArabicFields;

class ProductsController extends Controller
{
 
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $limit = min(max((int) $request->query('limit', 10), 1), 50);

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $contains = "%{$q}%";
        $prefix = "{$q}%";
        $perGroupLimit = max($limit, 10);

        $categories = DB::table('Products_Sub_Sub_Department_T as ssd')
            ->join('Products_Sub_Department_T as sd', 'sd.id', '=', 'ssd.Product_Sub_Department_Id')
            ->join('Products_Departments_T as d', 'd.id', '=', 'sd.Products_Departments_Id')
            ->select(StorefrontArabicFields::categorySearchSelect())
            ->whereNotNull('ssd.Slug')
            ->where(function ($query) use ($contains) {
                $query->where('ssd.Product_Sub_Sub_Department_Name', 'like', $contains)
                    ->orWhere('ssd.Product_Sub_Sub_Department_Name_Ar', 'like', $contains)
                    ->orWhere('ssd.Product_Sub_Sub_Department_Code', 'like', $contains)
                    ->orWhere('ssd.Slug', 'like', $contains)
                    ->orWhere('sd.Sub_Department_Name', 'like', $contains)
                    ->orWhere('sd.Sub_Department_Name_Ar', 'like', $contains)
                    ->orWhere('d.Product_Department_Name_Ar', 'like', $contains)
                    ->orWhere('d.Product_Department_Name', 'like', $contains);
            })
            ->orderByRaw(
                "CASE
                    WHEN ssd.Product_Sub_Sub_Department_Name = ? THEN 0
                    WHEN ssd.Product_Sub_Sub_Department_Name LIKE ? THEN 1
                    WHEN sd.Sub_Department_Name LIKE ? THEN 2
                    WHEN d.Product_Department_Name LIKE ? THEN 3
                    ELSE 4
                END",
                [$q, $prefix, $prefix, $prefix]
            )
            ->orderBy('ssd.Product_Sub_Sub_Department_Name')
            ->limit($perGroupLimit)
            ->get()
            ->map(function ($row) use ($q) {
                return [
                    'Result_Type' => 'category',
                    'id' => (int) $row->id,
                    'Slug' => $row->Slug,
                    'Search_Title' => $row->Product_Sub_Sub_Department_Name,
                    'Search_Title_Ar' => $row->Product_Sub_Sub_Department_Name_Ar,
                    'Search_Subtitle' => trim($row->Product_Department_Name . ' / ' . $row->Sub_Department_Name),
                    'Search_Subtitle_Ar' => trim(($row->Product_Department_Name_Ar ?: $row->Product_Department_Name) . ' / ' . ($row->Sub_Department_Name_Ar ?: $row->Sub_Department_Name)),
                    'Search_Score' => $this->searchScore($q, [
                        $row->Product_Sub_Sub_Department_Name,
                        $row->Product_Sub_Sub_Department_Name_Ar,
                        $row->Product_Sub_Sub_Department_Code,
                        $row->Slug,
                        $row->Sub_Department_Name,
                        $row->Sub_Department_Name_Ar,
                        $row->Product_Department_Name,
                        $row->Product_Department_Name_Ar,
                    ], 0),
                    'Image_Path' => $row->Image_Path,
                    'Product_Sub_Sub_Department_Name' => $row->Product_Sub_Sub_Department_Name,
                    'Product_Sub_Sub_Department_Name_Ar' => $row->Product_Sub_Sub_Department_Name_Ar,
                    'Sub_Department_Name' => $row->Sub_Department_Name,
                    'Sub_Department_Name_Ar' => $row->Sub_Department_Name_Ar,
                    'Product_Department_Name' => $row->Product_Department_Name,
                    'Product_Department_Name_Ar' => $row->Product_Department_Name_Ar,
                    'Product_Sub_Department_Id' => (int) $row->Product_Sub_Department_Id,
                    'Product_Department_Id' => (int) $row->Product_Department_Id,
                    'Route_Path' => "/departments/{$row->Slug}",
                    'Route_Query' => [
                        'deptId' => (string) $row->Product_Department_Id,
                        'subId' => (string) $row->Product_Sub_Department_Id,
                        'subSubId' => (string) $row->id,
                    ],
                ];
            });

        // Customer-facing search: soft-deleted and deactivated products must never
        // surface. Raw query, so the SoftDeletes trait does not apply — filter
        // explicitly, guarded because prod may not have the columns yet.
        $hasDeletedAt = Schema::hasColumn('Products_Master_T', 'deleted_at');
        $hasIsActive = Schema::hasColumn('Products_Master_T', 'Is_Active');

        $products = DB::table('Products_Master_T as p')
            ->leftJoin('Products_Sub_Sub_Department_T as ssd', 'ssd.id', '=', 'p.Product_Sub_Sub_Department_Id')
            ->leftJoin('Products_Sub_Department_T as sd', 'sd.id', '=', 'p.Product_Sub_Department_Id')
            ->leftJoin('Products_Departments_T as d', 'd.id', '=', 'p.Product_Department_Id')
            ->select(StorefrontArabicFields::productSearchSelect())
            ->when($hasDeletedAt, fn ($query) => $query->whereNull('p.deleted_at'))
            ->when($hasIsActive, fn ($query) => $query->where('p.Is_Active', 1))
            ->whereNotNull('p.Slug')
            ->where(function ($query) use ($contains) {
                $query->where('p.Product_Name', 'like', $contains)
                    ->orWhere('p.Product_Name_Ar', 'like', $contains)
                    ->orWhere('p.Product_Sku', 'like', $contains)
                    ->orWhere('p.Product_Code', 'like', $contains)
                    ->orWhere('p.Product_Description', 'like', $contains)
                    ->orWhere('ssd.Product_Sub_Sub_Department_Name_Ar', 'like', $contains)
                    ->orWhere('ssd.Product_Sub_Sub_Department_Name', 'like', $contains);
            })
            ->orderByRaw(
                "CASE
                    WHEN p.Product_Name = ? THEN 0
                    WHEN p.Product_Name LIKE ? THEN 1
                    WHEN p.Product_Sku LIKE ? THEN 2
                    WHEN p.Product_Code LIKE ? THEN 3
                    WHEN ssd.Product_Sub_Sub_Department_Name LIKE ? THEN 4
                    ELSE 5
                END",
                [$q, $prefix, $prefix, $prefix, $prefix]
            )
            ->orderBy('p.Product_Name')
            ->limit($perGroupLimit)
            ->get()
            ->map(function ($row) use ($q) {
                $subtitleParts = array_filter([
                    $row->Product_Sku ?: $row->Product_Code,
                    $row->Product_Sub_Sub_Department_Name,
                ]);

                return [
                    'Result_Type' => 'product',
                    'id' => (int) $row->id,
                    'Slug' => $row->Slug,
                    'Product_Name' => $row->Product_Name,
                    'Product_Name_Ar' => $row->Product_Name_Ar,
                    'Product_Sku' => $row->Product_Sku,
                    'Product_Code' => $row->Product_Code,
                    'Status' => $row->Status,
                    'Search_Title' => $row->Product_Name,
                    'Search_Title_Ar' => $row->Product_Name_Ar,
                    'Search_Subtitle' => implode(' / ', $subtitleParts),
                    'Search_Subtitle_Ar' => implode(' / ', array_filter([
                        $row->Product_Sku ?: $row->Product_Code,
                        $row->Product_Sub_Sub_Department_Name_Ar ?: $row->Product_Sub_Sub_Department_Name,
                    ])),
                    'Search_Score' => $this->searchScore($q, [
                        $row->Product_Name,
                        $row->Product_Name_Ar,
                        $row->Product_Sku,
                        $row->Product_Code,
                        $row->Product_Sub_Sub_Department_Name,
                        $row->Product_Sub_Sub_Department_Name_Ar,
                    ], 10),
                    'Product_Sub_Sub_Department_Name' => $row->Product_Sub_Sub_Department_Name,
                    'Product_Sub_Sub_Department_Name_Ar' => $row->Product_Sub_Sub_Department_Name_Ar,
                    'Route_Path' => "/product/{$row->Slug}",
                ];
            });

        $results = $categories
            ->concat($products)
            ->sort(function ($a, $b) {
                return [$a['Search_Score'], $a['Search_Title']] <=> [$b['Search_Score'], $b['Search_Title']];
            })
            ->take($limit)
            ->values();

        return response()->json($results);
    }

    private function searchScore(string $term, array $fields, int $baseScore): int
    {
        $needle = mb_strtolower($term);

        foreach ($fields as $field) {
            if (mb_strtolower((string) $field) === $needle) {
                return $baseScore;
            }
        }

        foreach ($fields as $field) {
            if (str_starts_with(mb_strtolower((string) $field), $needle)) {
                return $baseScore + 10;
            }
        }

        foreach ($fields as $field) {
            if (str_contains(mb_strtolower((string) $field), $needle)) {
                return $baseScore + 20;
            }
        }

        return $baseScore + 99;
    }

 public function show(ProductsSubSubDepartment $subsub, Request $request)
    {
        $discountService = app(ProductDiscountService::class);

        // Base query: products in this sub-sub department.
        // SoftDeletes hides deleted rows; ->active() hides deactivated ones.
        $products = Products::query()
            ->with(['image', 'specifications.specValue'])
            ->active()
            ->where('Product_Sub_Sub_Department_Id', $subsub->id);

        // -------------------------------
        // Apply filters (value IDs)
        // -------------------------------
        $productsTable = (new Products())->getTable(); // usually "products"

        // Preferred shape: JSON map { [description_id]: number[] }
        $filtersJson = $request->input('filters');
        if ($filtersJson) {
            $map = json_decode($filtersJson, true) ?: [];

            // AND between descriptions, OR within a description
            foreach ($map as $descId => $valueIds) {
                $descId = (int) $descId;
                $vals   = array_values(array_filter(array_map('intval', (array) $valueIds)));

                if ($descId && !empty($vals)) {
                    $products->whereExists(function ($q) use ($productsTable, $descId, $vals) {
                        $q->from('Product_Specification_Product_T as psp')
                          ->whereColumn('psp.Product_Id', $productsTable . '.id')
                          ->where('psp.Product_Specification_Description_Id', $descId)
                          ->whereIn('psp.product_specification_value_id', $vals);
                    });
                }
            }
        }
        // Fallback: flat list of spec value IDs => OR across all
        elseif ($request->filled('spec_ids')) {
            $specIds = array_values(array_filter(array_map('intval', (array) $request->input('spec_ids', []))));
            if (!empty($specIds)) {
                $products->whereExists(function ($q) use ($productsTable, $specIds) {
                    $q->from('Product_Specification_Product_T as psp')
                      ->whereColumn('psp.Product_Id', $productsTable . '.id')
                      ->whereIn('psp.product_specification_value_id', $specIds);
                });
            }
        }

        $minPrice = $request->filled('min_price') && is_numeric($request->input('min_price'))
            ? (float) $request->input('min_price')
            : null;
        $maxPrice = $request->filled('max_price') && is_numeric($request->input('max_price'))
            ? (float) $request->input('max_price')
            : null;

        // -------------------------------
        // Headers (spec descriptions)
        // -------------------------------
        $descs = ProductSpecificationDescription::query()
            ->where('product_sub_sub_department_id', $subsub->id)
            ->where('is_active', 1)
            // nulls last, then sort_order, then id
            ->orderByRaw('CASE WHEN sort_order IS NULL THEN 1 ELSE 0 END ASC, sort_order ASC, id ASC')
            ->get(['id', 'Product_Specification_Description_Name']);

        $descIds = $descs->pluck('id');

        // -------------------------------
        // Rows (products with spec map)
        // -------------------------------
        $productModels = $products->get();
        $reviewsByProduct = collect();
        if (Schema::hasTable('Product_Reviews_T')) {
            $reviewsByProduct = ProductReview::query()
                ->whereIn('Products_Id', $productModels->pluck('id')->all())
                ->where('Status', ProductEngagementRules::STATUS_APPROVED)
                ->get()
                ->groupBy('Products_Id');
        }

        $rows = $productModels->map(function ($p) use ($descs, $descIds, $discountService, $reviewsByProduct) {
            // group product's specs by description id
            $byDesc = $p->specifications
                ->whereIn('Product_Specification_Description_Id', $descIds)
                ->keyBy('Product_Specification_Description_Id');

            $specMap = [];
            foreach ($descs as $d) {
                $row = $byDesc->get($d->id);

                $specMap[$d->id] = $row ? [
                    'value_id' => $row->product_specification_value_id
                        ? (int) $row->product_specification_value_id : null,
                    'label'    => optional($row->specValue)->value, // from relation
                ] : null;
            }

            $pricing = $discountService->priceForProduct($p);

            return [
                'id'    => $p->id,
                'name'  => $p->Product_Name,
                'name_ar'  => $p->Product_Name_Ar,
                'Product_Name'  => $p->Product_Name,
                'Product_Name_Ar'  => $p->Product_Name_Ar,
                'price' => $pricing['final_price'],
                'original_price' => $pricing['original_price'],
                'final_price' => $pricing['final_price'],
                'discount_amount' => $pricing['discount_amount'],
                'has_discount' => $pricing['has_discount'],
                'active_discount' => $pricing['discount'],
                'review_summary' => ProductEngagementRules::ratingSummary($reviewsByProduct->get($p->id, [])),
                'Product_Stock' => (int) ($p->Product_Stock ?? 0),
                'Product_Code' => $p->Product_Code,
                'Product_Sku' => $p->Product_Sku,
                'image' => $p->image,
                'slug'  => $p->Slug,
                'specs' => $specMap,
            ];
        })->values();

        $priceRange = [
            'min' => $rows->min('final_price') !== null ? (float) $rows->min('final_price') : 0,
            'max' => $rows->max('final_price') !== null ? (float) $rows->max('final_price') : 0,
        ];

        if ($minPrice !== null) {
            $rows = $rows->filter(fn ($row) => (float) $row['final_price'] >= $minPrice)->values();
        }

        if ($maxPrice !== null) {
            $rows = $rows->filter(fn ($row) => (float) $row['final_price'] <= $maxPrice)->values();
        }

        return response()->json([
            'headers'  => $descs->map(fn ($d) => [
                'id'   => (int) $d->id,
                'name' => $d->Product_Specification_Description_Name,
            ])->values(),
            'products' => $rows,
            'price_range' => $priceRange,
        ]);
    }



public function detail(Products $product)
{
    try{
                $product->load(['images','department','subdepartment','subSubDepartment']);
                app(ProductDiscountService::class)->appendPriceAttributes($product);

                // Quantity-tier bulk prices (public pricing — table ships in
                // an isc-admin-api migration, so guard for a lagging prod DB).
                if (Schema::hasTable('Products_Bulk_Prices_T')) {
                    $product->setAttribute(
                        'Bulk_Prices',
                        $product->bulkPrices()->get()->map(fn ($tier) => [
                            'min_qty'    => (int) $tier->Min_Qty,
                            'max_qty'    => $tier->Max_Qty !== null ? (int) $tier->Max_Qty : null,
                            'unit_price' => round((float) $tier->Unit_Price, 3),
                        ])->values()
                    );
                }

                $specs = ProductSpecificationProduct::with('description')
                                                     ->where('Product_Id', $product->id)
                                                     ->get()
                                                     ->groupBy(fn ($item) => $item->description->name);

                $formatted = $specs->map(function ($items, $key) {
                    return [
                        'category' => $key,
                        'values' => $items->pluck('value')->unique()->values()
                    ];
                })->values();

      }catch(\Exception $e){

             return response()->json(['error' => $e->getMessage()], 404);

       }

    return response()->json([
        'product' => $product,
        'specifications' => $formatted,
        'review_summary' => Schema::hasTable('Product_Reviews_T')
            ? ProductEngagementRules::ratingSummary(
                ProductReview::query()
                    ->where('Products_Id', $product->id)
                    ->where('Status', ProductEngagementRules::STATUS_APPROVED)
                    ->get()
            )
            : ProductEngagementRules::ratingSummary([]),
    ]);

}



}
