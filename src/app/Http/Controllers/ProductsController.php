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
   $products = Products::with(['image', 'specifications'])
        ->where('product_sub_sub_department_id', $subsub->id);

    if ($request->has('spec_ids')) {
        $specIds = $request->input('spec_ids', []);

        $products->whereHas('specifications', function ($query) use ($specIds) {
            $query->whereIn('id', $specIds);
        });
    }

    return response()->json($products->get());
}


public function detail(Products $product)
{
    try{

  
 $product->load('images');

    $specs = ProductSpecificationProduct::with('description')
        ->where('product_id', $product->id)
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
