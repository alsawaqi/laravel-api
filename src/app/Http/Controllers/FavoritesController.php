<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Models\User;
use App\Models\Products;
use Illuminate\Http\Request;
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

        if ($customer->hasFavorited($product->getKey())) {
            $customer->favorites()->detach($product->getKey());
            return response()->json(['favorited' => false]);
        }

        $customer->favorites()->syncWithoutDetaching([$product->getKey()]);
        return response()->json(['favorited' => true]);
    }

    // Add an index endpoint since you registered the route
    public function index()
    {
        $user = Auth::user();
        if (!$user || !$user->customers) {
            return response()->json(['data' => []]);
        }

        $products = Favorite::where('Customers_Id', $user->customers->id)
            ->with('product','product.images') // Eager load the related product
            ->get();
             

        return response()->json($products);
    }
}
