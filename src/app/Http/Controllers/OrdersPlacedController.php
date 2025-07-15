<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use Illuminate\Support\Facades\DB;

class OrdersPlacedController extends Controller
{
    //

    public function place(Request $request)
{
    $validated = $request->validate([
        'customer_id' => 'required|integer',
        'delivery_method' => 'required|in:ship,pickup',
        'shipping_cost' => 'required|numeric',
        'cart_items' => 'required|array|min:1',
        'cart_items.*.product_id' => 'required|integer',
        'cart_items.*.quantity' => 'required|integer|min:1',
        'cart_items.*.price' => 'required|numeric',
        'cart_items.*.subtotal' => 'required|numeric',
    ]);

    DB::beginTransaction();

    try {
        $orderCode = CodeGenerator::createCode('ORD', 'Orders_Placed_T', 'order_code');
        $transactionNumber = strtoupper(Str::random(10));
        $totalPrice = collect($validated['cart_items'])->sum('subtotal') + $validated['shipping_cost'];


        $customer = auth()->user()?->customers;
        // Insert into Orders_Placed_T
        $orderId = DB::table('Orders_Placed_T')->insertGetId([
            'order_code' => $orderCode,
            'transaction_number' => $transactionNumber,
            'customer_id' => $customer->id,
            'total_price' => $totalPrice,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($validated['cart_items'] as $item) {


               $orderplacecode = CodeGenerator::createCode('ORDP', 'Orders_Placed_Details_T', 'order_Placed_code');

            DB::table('Orders_Placed_Details_T')->insert([
                'order_Placed_code' => $orderplacecode,
                'order_id' => $orderId,
               
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'subtotal' => $item['subtotal'],
                'vat' => $item['vat'] ?? 0,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::commit();

        return response()->json([
            'message' => 'Order placed successfully',
            'order_code' => $orderCode,
        ]);
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'message' => 'Order failed',
            'error' => $e->getMessage(),
        ], 500);
    }
}
}
