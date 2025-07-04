<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductBrands;

class ProductBrandsController extends Controller
{
    //

    public function index()
    {
        return response()->json(ProductBrands::orderby('id', 'DESC')->get());
    }
}
