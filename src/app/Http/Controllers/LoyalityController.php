<?php

namespace App\Http\Controllers;

use App\Models\Loyality;
use Illuminate\Http\Request;
use App\Models\LoyalityPoints;

class LoyalityController extends Controller
{
    //


    public function index(Request $request){


          $user = $request->user();

        if (!$user || !$user->customers) {
            return response()->json(['data' => []]);
        }


        $loyalityPoints =  Loyality::where('Customer_Id', $user->customers->id)
                                            ->first();
        return response()->json($loyalityPoints->Points_Earned ?? 0);
    }
}
