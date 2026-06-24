<?php

namespace App\Http\Controllers;

use App\Models\Region;
use Illuminate\Http\Request;

class RegionController extends Controller
{
 
   public function index()
    {
        // Fetch all regions
        $regions = Region::all();

        return response()->json([
            'data' => $regions,
        ]);
    }


}
