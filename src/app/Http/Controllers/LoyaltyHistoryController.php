<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LoyaltyHistory;

class LoyaltyHistoryController extends Controller
{
    //
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user || !$user->customers) {
            return response()->json(['data' => []]);
        }

        $loyaltyHistories = LoyaltyHistory::where('Customer_Id', $user->customers->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($loyaltyHistories);
    }
}
