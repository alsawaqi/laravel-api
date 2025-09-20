<?php

namespace App\Http\Controllers;

use App\Models\Products;
use Illuminate\Http\Request;
use App\Models\ProductsSubSubDepartment;
use App\Models\ProductSpecificationValue;

class ProductSpecificationValueController extends Controller
{
    //

    public function index(string $subsub)
    {


         $is_active = Products::with([
                                    
                                    'specifications.specValue',
                                    'specifications.description',
                                    'specifications' => function ($q) {
                                                $q->with([
                                                    'specValue',
                                                    'description' => fn($d) => $d->where('is_active', 1),
                                                ])->whereHas('description', fn($d) => $d->where('is_active', 1));
                                            },
                                        ])->where('Slug', $subsub)
                                         ->first();

 $features = Products::with([ 'specifications.specValue',
                              'specifications.description',
                              'specifications'])->where('Slug', $subsub)
                                         ->first();


       return response()->json([
                                'is_ative' => $is_active->specifications,
                                'features' => $features->specifications
                              ]);
    }

}
