<?php

namespace App\Http\Controllers;

use App\Models\Loyality;
use Illuminate\Http\Request;
use App\Models\LoyalityPoints;
use Illuminate\Support\Facades\DB;

class LoyalityController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user || !$user->customers) {
            return response()->json(['data' => []]);
        }

        return response()->json($this->availablePoints((int) $user->customers->id));
    }

    public function summary(Request $request)
    {
        $user = $request->user();

        if (!$user || !$user->customers) {
            return response()->json([
                'available_points' => 0,
                'points_earned' => 0,
                'points_redeemed' => 0,
                'redeem_points' => 0,
                'redeem_amount' => 0,
                'redemption_value_per_point' => 0,
            ]);
        }

        $customerId = (int) $user->customers->id;
        $loyalty = Loyality::where('Customer_Id', $customerId)->first();
        $settings = LoyalityPoints::first();
        $redeemPoints = (float) ($settings->Redeem_Points ?? 0);
        $redeemAmount = (float) ($settings->Redeem_Amount ?? 0);
        $valuePerPoint = $redeemPoints > 0 ? round($redeemAmount / $redeemPoints, 6) : 0;

        return response()->json([
            'available_points' => $this->availablePoints($customerId),
            'points_earned' => (int) ($loyalty->Points_Earned ?? 0),
            'points_redeemed' => (int) ($loyalty->Points_Redeemed ?? 0),
            'redeem_points' => $redeemPoints,
            'redeem_amount' => $redeemAmount,
            'redemption_value_per_point' => $valuePerPoint,
        ]);
    }

    private function availablePoints(int $customerId): int
    {
        $row = DB::table('Customers_Loyalty_T')
            ->where('Customer_Id', $customerId)
            ->selectRaw('COALESCE(Points_Earned, 0) - COALESCE(Points_Redeemed, 0) as balance')
            ->first();

        return max(0, (int) ($row->balance ?? 0));
    }
}
