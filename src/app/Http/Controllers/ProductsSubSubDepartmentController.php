<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductsSubSubDepartment;
use App\Models\ProductSpecificationDescription;

class ProductsSubSubDepartmentController extends Controller
{
    //
    public function index(string $slug)
    {
        // If your route model binding is already by slug, you can accept ProductsSubSubDepartment $subsub instead.
    $subsub = ProductsSubSubDepartment::where('Slug', $slug)->firstOrFail();

   $filters = ProductSpecificationDescription::with(['values' => function($q) {
        $q->orderBy('value'); // order values alphabetically
    }])
    ->where('product_sub_sub_department_id', $subsub->id)
    ->where('is_active', 1)
    ->orderByRaw('CASE WHEN sort_order IS NULL THEN 1 ELSE 0 END, sort_order ASC')
    ->get();


    return response()->json([
        'data' => [
            'id'          => (int) $subsub->id,
            'name'        => $subsub->Product_Sub_Sub_Department_Name,
            'slug'        => $subsub->Slug,
            'description' => $subsub->Product_Sub_Sub_Department_Description,
            'view_options' => $subsub->View_Options,
            'image'       => $subsub->Image_Path,
        ],
        'filters' => $filters,
    ]);
       
        
    }
}
