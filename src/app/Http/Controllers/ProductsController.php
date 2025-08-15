<?php

namespace App\Http\Controllers;

use App\Models\Products;
use Illuminate\Http\Request;
use App\Models\ProductsSubSubDepartment;
use App\Models\ProductSpecificationProduct;
use App\Models\ProductSpecificationDescription;

class ProductsController extends Controller
{
    //

 public function show(ProductsSubSubDepartment $subsub, Request $request)
{
    // Eager load nested: specifications.specValue
    $products = Products::with(['image', 'specifications.specValue'])
        ->where('Product_Sub_Sub_Department_Id', $subsub->id);

    // ... your existing filter logic stays the same ...

    $descs = ProductSpecificationDescription::where('product_sub_sub_department_id', $subsub->id)
        ->where('is_active', 1)
        ->orderByRaw('CASE WHEN sort_order IS NULL THEN 1 ELSE 0 END ASC, sort_order ASC, id ASC')
        ->get(['id', 'Product_Specification_Description_Name']);

    $descIds = $descs->pluck('id');

    $rows = $products->get()->map(function ($p) use ($descs, $descIds) {
        $byDesc = $p->specifications
                    ->whereIn('Product_Specification_Description_Id', $descIds)
                    ->keyBy('Product_Specification_Description_Id');

        $specMap = [];
        foreach ($descs as $d) {
            $row = $byDesc->get($d->id);
            $specMap[$d->id] = $row ? [
                'value_id' => $row->product_specification_value_id
                    ? (int) $row->product_specification_value_id : null,
                // now this works because specValue is on ProductSpecificationProduct
                'label'    => $row->specValue?->value,
            ] : null;
        }

        return [
            'id'    => $p->id,
            'name'  => $p->Product_Name,
            'price' => $p->Product_Price,
            'slug'  => $p->Slug,
            'specs' => $specMap,
        ];
    })->values();

    return response()->json([
        'headers'  => $descs->map(fn($d) => [
            'id'   => (int) $d->id,
            'name' => $d->Product_Specification_Description_Name,
        ])->values(),
        'products' => $rows,
    ]);
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
