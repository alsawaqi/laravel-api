<?php

namespace App\Http\Controllers;

use App\Models\Products;
use Illuminate\Http\Request;
use App\Models\ProductsSubSubDepartment;
use App\Models\ProductSpecificationProduct;

class ProductsController extends Controller
{
    //

public function show(ProductsSubSubDepartment $subsub, Request $request)
{
   $products = Products::with(['image'])
        ->where('Product_Sub_Sub_Department_Id', $subsub->id);

    // Read inputs
    $specIds = array_map('intval', (array) $request->input('spec_ids', []));

    // `filters` might come as an array or a JSON string – accept both
    $filtersRaw = $request->input('filters', []);
    if (is_string($filtersRaw)) {
        $decoded = json_decode($filtersRaw, true);
        $filters = is_array($decoded) ? $decoded : [];
    } else {
        $filters = (array) $filtersRaw;
    }

    // Normalize: keys to int, values to int[]
    $grouped = [];
    foreach ($filters as $descId => $valueIds) {
        $dId = (int) $descId;
        $vals = array_values(array_unique(array_map('intval', (array) $valueIds)));
        if ($dId > 0 && !empty($vals)) {
            $grouped[$dId] = $vals;
        }
    }

    if (!empty($grouped)) {
        // AND across description groups; OR within group
        foreach ($grouped as $descId => $valueIds) {
            $products->whereHas('specifications', function ($q) use ($descId, $valueIds) {
                $q->where('Product_Specification_Description_Id', $descId)
                  ->whereIn('product_specification_value_id', $valueIds);
            });
        }
    } elseif (!empty($specIds)) {
        // Simple OR across all chosen value IDs
        $products->whereHas('specifications', function ($q) use ($specIds) {
            $q->whereIn('product_specification_value_id', $specIds);
        });
    }

    return response()->json($products->get());
}


public function detail(Products $product)
{
    try{

  
    $product->load('images');

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
    ]);

}



}
