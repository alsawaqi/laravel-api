<?php

namespace App\Http\Controllers;

use App\Models\Products;
use Illuminate\Http\Request;
use App\Models\ProductSpecificationProduct;
 

class ProductsController extends Controller
{
    //

public function show(Products $product)
{
    
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

    return response()->json([
        'product' => $product,
        'specifications' => $formatted,
    ]);

}



}
