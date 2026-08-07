<?php

namespace App\Http\Controllers;

use App\Models\ProductDepartment;
use App\Support\Localization\StorefrontArabicFields;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductDepartmentController extends Controller
{
    private const CACHE_TTL_SECONDS = 60;

    private const CACHE_CONTROL = 'public, max-age=15, s-maxage=30, stale-while-revalidate=15';

    public function index()
    {
        $revision = $this->displayOrderRevision();
        $departments = Cache::remember(
            "storefront:catalog:departments:v2:r{$revision}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->applyHierarchyOrder(
                ProductDepartment::select(StorefrontArabicFields::departmentSelect()),
                'Products_Departments_T',
                'Source_Main_Sequence',
                'Product_Department_Name',
            )
                ->get()
        );

        return response()->json($departments)
            ->header('Cache-Control', self::CACHE_CONTROL);
    }

    // Fetch subcategories by department
    public function getSubCategories($id)
    {
        $revision = $this->displayOrderRevision();
        $subCategories = Cache::remember(
            "storefront:catalog:departments:{$id}:subcategories:v2:r{$revision}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->applyHierarchyOrder(
                DB::table('Products_Sub_Department_T')
                    ->where('Products_Departments_Id', $id)
                    ->select(StorefrontArabicFields::subDepartmentSelect()),
                'Products_Sub_Department_T',
                'Source_Sub_Sequence',
                'Sub_Department_Name',
            )->get()
        );

        return response()->json($subCategories)
            ->header('Cache-Control', self::CACHE_CONTROL);
    }

    // Fetch sub-subcategories by subcategory
    public function getSubSubCategories($id)
    {
        $revision = $this->displayOrderRevision();
        $subSubCategories = Cache::remember(
            "storefront:catalog:subcategories:{$id}:subsubcategories:v2:r{$revision}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->applyHierarchyOrder(
                DB::table('Products_Sub_Sub_Department_T')
                    ->where('Product_Sub_Department_Id', $id)
                    ->select(StorefrontArabicFields::subSubDepartmentSelect()),
                'Products_Sub_Sub_Department_T',
                'Source_Sub_Sub_Sequence',
                'Product_Sub_Sub_Department_Name',
            )->get()
        );

        return response()->json($subSubCategories)
            ->header('Cache-Control', self::CACHE_CONTROL);
    }

    private function displayOrderRevision(): int
    {
        try {
            $revision = DB::table('Product_Hierarchy_Display_Order_State_T')
                ->where('id', 1)
                ->value('Revision');
        } catch (QueryException) {
            return 1;
        }

        if (! is_numeric($revision)) {
            return 1;
        }

        return max(1, (int) $revision);
    }

    private function applyHierarchyOrder(
        $query,
        string $table,
        string $sourceSequence,
        string $englishName,
    ) {
        if (Schema::hasColumn($table, 'Display_Order')) {
            $query
                ->orderByRaw('CASE WHEN Display_Order IS NULL THEN 1 ELSE 0 END')
                ->orderBy('Display_Order');
        }

        if (Schema::hasColumn($table, $sourceSequence)) {
            $query
                ->orderByRaw("CASE WHEN {$sourceSequence} IS NULL THEN 1 ELSE 0 END")
                ->orderBy($sourceSequence);
        }

        return $query->orderBy($englishName)->orderBy('id');
    }
}
