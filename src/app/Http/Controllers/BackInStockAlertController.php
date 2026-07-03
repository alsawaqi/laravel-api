<?php

namespace App\Http\Controllers;

use App\Models\Products;
use App\Models\CustomerBackInStockAlert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BackInStockAlertController extends Controller
{
    public function store(Request $request, int $productId)
    {
        // SoftDeletes already 404s deleted products; deactivated ones must 404
        // too — they are hidden from the storefront and not purchasable.
        $product = Products::query()->active()->findOrFail($productId);
        $customer = Auth::user()?->customers;

        if (!$customer) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        if ((int) ($product->Product_Stock ?? 0) > 0 && strtolower((string) $product->Status) !== 'out_of_stock') {
            return response()->json([
                'message' => 'This product is currently available.',
                'already_available' => true,
            ]);
        }

        $alert = CustomerBackInStockAlert::query()->updateOrCreate(
            [
                'Products_Id' => $product->id,
                'User_Id' => Auth::id(),
                'Status' => 'active',
            ],
            [
                'Customer_Id' => $customer->id,
                'Product_Name' => $product->Product_Name,
                'Product_Slug' => $product->Slug,
                'Notified_At' => null,
            ],
        );

        return response()->json([
            'message' => $alert->wasRecentlyCreated
                ? 'Back-in-stock alert created.'
                : 'Back-in-stock alert is already active.',
            'data' => [
                'id' => $alert->id,
                'product_id' => (int) $alert->Products_Id,
                'status' => $alert->Status,
            ],
        ], $alert->wasRecentlyCreated ? 201 : 200);
    }
}
