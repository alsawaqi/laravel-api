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
        // Base query: products in this sub-sub department
        $products = Products::query()
            ->with(['image', 'specifications.specValue'])
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
        $rows = $products->get()->map(function ($p) use ($descs, $descIds) {
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

            return [
                'id'    => $p->id,
                'name'  => $p->Product_Name,
                'price' => (float) $p->Product_Price,
                'slug'  => $p->Slug,
                'specs' => $specMap,
            ];
        })->values();

        return response()->json([
            'headers'  => $descs->map(fn ($d) => [
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
