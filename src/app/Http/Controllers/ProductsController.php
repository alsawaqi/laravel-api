<?php

namespace App\Http\Controllers;

use App\Models\Products;
use Illuminate\Http\Request;
use App\Models\ProductsSubSubDepartment;

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



}
