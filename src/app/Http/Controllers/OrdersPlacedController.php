<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use App\Models\OrdersPlaced;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use Illuminate\Support\Facades\DB;
use App\Models\OrdersPlacedDetails;
use Illuminate\Support\Facades\Auth;

class OrdersPlacedController extends Controller
{
 

    public function index()
    {

        $customer = Auth::user()?->customers;
     


        $orders = OrdersPlaced::orderBy('id', 'desc')
                                ->where('Customers_Id', $customer->id)
                                ->get();
           

        return response()->json($orders);
    }

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


        $customer = Auth::user()?->customers;
        // Insert into Orders_Placed_T
        $orderId = DB::table('Orders_Placed_T')->insertGetId([
            'Order_Code' => $orderCode,
            'Customers_Contacts_Id' => $request->Customers_Contacts_Id,
            'Transaction_Number' => $transactionNumber,
            'Customers_Id' => $customer->id,
            'Total_Price' => $totalPrice,
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($validated['cart_items'] as $item) {


               $orderplacecode = CodeGenerator::createCode('ORDP', 'Orders_Placed_Details_T', 'Order_Placed_Code');

            DB::table('Orders_Placed_Details_T')->insert([
                'Order_Placed_Code' => $orderplacecode,
                'Orders_Placed_Id' => $orderId,
               
                'Products_Id' => $item['product_id'],
                'Quantity' => $item['quantity'],
                'Price' => $item['price'],
                'Subtotal' => $item['subtotal'],
                'Vat' => $item['vat'] ?? 0,
                'Status' => 'pending',
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


public function getOrderDetails($id)
{
    $orderDetails = OrdersPlacedDetails::with('product')  // assuming relation is `product()`
        ->where('Orders_Placed_Id', $id)
        ->get();

    return response()->json($orderDetails);
}

}
