<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Models\Products;
use Illuminate\Support\Facades\Auth;

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
             

        return response()->json($products);
    }
}
