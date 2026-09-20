<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductBrands;
use Illuminate\Support\Facades\Cache;

class ProductBrandsController extends Controller
{
    //

    public function index()
    {
        return response()->json(Cache::remember('storefront:brands:v1', 60,
            fn () => ProductBrands::orderby('id', 'DESC')->get()))
            ->header('Cache-Control', 'public, max-age=15, s-maxage=30');
    }
}
