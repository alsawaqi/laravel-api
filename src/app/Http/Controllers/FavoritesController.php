<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Models\Products;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class FavoritesController extends Controller
{
    public function toggle(Products $product)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $customer = $user->customerOrCreate();

        $favorite = Favorite::query()
            ->where('Customers_Id', $customer->id)
            ->where('Products_Id', $product->getKey())
            ->first();

        if ($favorite) {
            $favorite->delete();
            return response()->json(['favorited' => false]);
        }

        Favorite::create([
            'Customers_Id' => $customer->id,
            'Products_Id' => $product->getKey(),
        ]);

        return response()->json(['favorited' => true]);
    }

    // Add an index endpoint since you registered the route
    public function index()
    {
        $user = Auth::user();
        if (!$user || !$user->customers) {
            return response()->json(['data' => []]);
        }

        $products = Favorite::query()
            ->where('Customers_Id', $user->customers->id)
            ->with('product', 'product.images') // Eager load the related product
            ->get();

        // Hide favorites whose product was soft-deleted (relation is null via
        // SoftDeletes) or deactivated — they are no longer visible on the storefront.
        $hasIsActive = Schema::hasColumn('Products_Master_T', 'Is_Active');
        $products = $products->filter(function ($favorite) use ($hasIsActive) {
            if (!$favorite->product) {
                return false;
            }

            return !$hasIsActive || (int) ($favorite->product->Is_Active ?? 1) === 1;
        })->values();

        return response()->json($products);
    }
}
