<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Products;
use App\Events\OrderPlaced;
use Illuminate\Support\Str;
use App\Models\OrdersPlaced;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\LoyalityPoints;
use Illuminate\Support\Facades\DB;
use App\Models\OrdersPlacedDetails;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\ConxDatabaseNotification;
use App\Notifications\NewOrderNotification;

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
            'delivery_method' => 'required|in:ship,pickup',
            'shipping_cost' => 'required|numeric',
            'Customers_Contacts_Id' => 'nullable|integer',
            'cart_items' => 'required|array|min:1',
            'cart_items.*.product_id' => 'required|integer',
            'cart_items.*.quantity' => 'required|integer|min:1',
            'cart_items.*.price' => 'required|numeric',
            'cart_items.*.subtotal' => 'required|numeric',
            'VAT' => 'nullable|numeric',
            'shipping_option' => 'nullable|array',
            'shipping_option.shipper_id' => 'nullable|integer',
            'shipping_option.destination_id' => 'nullable|integer',
            'shipping_option.basis' => 'nullable|in:weight,volume,heavy',
            'shipping_option.price' => 'nullable|numeric',
            'shipping_option.currency' => 'nullable|string|size:3',
            'shipping_option.weight_kg' => 'nullable|numeric',
            'shipping_option.volume_cbm' => 'nullable|numeric',
        ]);


        $pay = $request->input('payment', []);
        $method = $pay['method'] ?? 'cod';

        DB::beginTransaction();
        try {
            $orderCode = CodeGenerator::createCode('ORD', 'Orders_Placed_T', 'order_code');
            $transactionNumber = strtoupper(Str::random(10));
            $itemsTotal = collect($validated['cart_items'])->sum('subtotal');
            $totalPrice = $itemsTotal + $validated['shipping_cost'];

            $customer = Auth::user()?->customers;

            $shipping = $request->input('shipping_option', []);

            $total = $request->input('total', []);

            $orderId = DB::table('Orders_Placed_T')->insertGetId([
                'Order_Code'             => $orderCode,
                'Customers_Contacts_Id'  => $request->Customers_Contacts_Id,
                'Transaction_Number'     => $transactionNumber,
                'Customers_Id'           => $customer->id,
                'Total_Price'            => $total['grand'],
                'Status'                 => 'pending',
                'VAT'                    => $total['vat'] ?? 0,
                'Sub_Total_Price'        => $total['subtotal'] ?? 0,
                'Shippers_Id'            => $shipping['shipper_id'] ?? null,
                'Shippers_Destination_Id' => $shipping['destination_id'] ?? null,
                'Shipping_Basis'         => $shipping['basis'] ?? null,
                'Shipping_Price'         => $shipping['price'] ?? ($validated['shipping_cost'] ?? 0),
                'Shipping_Currency'      => $shipping['currency'] ?? 'OMR',
                'Shipping_Weight_Kg'     => $shipping['weight_kg'] ?? null,
                'Shipping_Volume_Cbm'    => $shipping['volume_cbm'] ?? null,

                'created_at'             => now(),
                'updated_at'             => now(),
            ]);



            try {
                ConxDatabaseNotification::create([
                    'type'            => 'App\\Notifications\\NewOrder',
                    'notifiable_type' => 'App\\Models\\Admin',
                    'notifiable_id'   => 1, // admin ID (or change to proper admin)
                    'data'            => [
                        'title'    => 'New Order Has Been Created',
                        'message'  => 'Order ' . $orderCode . ' has been created.',
                        'order_id' => $orderId,
                        'url'      => '/orders/' . $orderId,
                    ],
                ]);
            } catch (\Throwable $notifyException) {
                // IMPORTANT: Do NOT throw – we don't want order placement to fail
                Log::error('Failed to create admin notification or send Beams push', [
                    'order_id'  => $orderId,
                    'error'     => $notifyException->getMessage(),
                    'exception' => $notifyException,
                ]);
            }



            $loyalitypoints = LoyalityPoints::first();

            $loyalty = DB::table('Customers_Loyalty_T')->where('Customer_Id', $customer->id)->first();

            $pointsEarned = $loyalitypoints->Point * $totalPrice;


            $Customers_Loyalty_Code = CodeGenerator::createCode('LOYCODE', 'Customers_Loyalty_T', 'Customers_Loyalty_Code');

            if ($loyalty) {
                // Update existing loyalty record
                DB::table('Customers_Loyalty_T')->where('Customer_Id', $customer->id)->update([
                    'Points_Earned' => DB::raw('Points_Earned + ' . $pointsEarned),
                    'updated_at' => now(),
                ]);
            } else {
                // Create new loyalty record
                DB::table('Customers_Loyalty_T')->insert([
                    'Customers_Loyalty_Code' => $Customers_Loyalty_Code,
                    'Customer_Id' => $customer->id,
                    'Points_Earned' => $pointsEarned,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('Customers_Loyalty_Transactions_T')->insert([
                'Loyalty_Transaction_Code' => CodeGenerator::createCode('LOYTRANS', 'Customers_Loyalty_Transactions_T', 'Loyalty_Transaction_Code'),
                'Customer_Id' => $customer->id,
                'Orders_Placed_Id' => $orderId,
                'Points_Earned' => $pointsEarned,
                'Points_Redeemed' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $transactionHeaderCode = CodeGenerator::createCode('TRANS', 'Sales_Transaction_Header_T', 'Sales_Transaction_Header_code');


            $sthId = DB::table('Sales_Transaction_Header_T')->insertGetId([
                'Sales_Transaction_Header_code' => $transactionHeaderCode,
                'Bill_No'   => $transactionNumber,
                'created_at' => now(),
                'updated_at' => now(),
                'Orders_Placed_Id'  => $orderId,
            ]);



            $Merchant_Id = strtoupper(Str::random(10));
            $billNumber = strtoupper(Str::random(10));

            $detailsCode = CodeGenerator::createCode('ORDP', 'Sales_Transactions_Details_T', 'Sales_Transactions_Details_code');


            $base = [
                'Sales_Transactions_Details_code' => $detailsCode,
                'Sales_Transaction_Header_Id' => $sthId,
                'Transaction_No'              => $transactionNumber,
                'Merchant_Id'                 => $Merchant_Id,                   // fill if applicable
                'Bill_No'                     => $billNumber,

                'Discount_Amount'             => 0,                      // adjust if you have discounts
                'VAT_Tax_Amount'              => $item['vat'] ?? 0,
                // same as subtotal if no discount
                'Transaction_Date'            => now(),
                'created_at'                  => now(),
                'updated_at'                  => now(),
            ];

            // Payment columns common to all methods (line-level)
            $payCols = [
                'Payment_Method'   => $method, // 'card' | 'cod' | 'transfer'
                'Payment_Status'   => match ($method) {
                    'card'     => 'pending_authorization',
                    'transfer' => 'pending_verification',
                    default    => 'pending_cod',
                },
                // line-level amount
                'Payment_Currency' => $pay['currency'] ?? 'OMR',
            ];

            // Method-specific columns
            $specific = [];
            if ($method === 'card') {
                $card = $pay['card'] ?? [];
                $specific = [
                    'Card_Brand'       => $card['brand'] ?? null,
                    'Card_Last4'       => $card['last4'] ?? null,
                    'Card_Exp_Month'   => $card['exp_month'] ?? null,
                    'Card_Exp_Year'    => $card['exp_year'] ?? null,
                    // leave gateway/txn ids null for now – fill after actual charge
                ];
            } elseif ($method === 'transfer') {
                $tr = $pay['transfer'] ?? [];
                $specific = [
                    'Transfer_Reference'  => $tr['reference'] ?? null,
                    'Transfer_Payer_Name' => $tr['payer_name'] ?? null,
                    // optionally: 'Transfer_Bank_Name' / 'Transfer_IBAN' if you collect them
                ];
            } else { // COD
                $specific = [
                    'COD_Collected'    => null,
                    'COD_Collected_At' => null,
                    'COD_Note'         => null,
                ];
            }

            DB::table('Sales_Transactions_Details_T')->insert(array_merge($base, $payCols, $specific));


            foreach ($validated['cart_items'] as $item) {
                $orderplacecode = CodeGenerator::createCode('ORDP', 'Orders_Placed_Details_T', 'Order_Placed_Code');

                //get 5 percent of price as vat
                $vat = $item['subtotal'] * 0.05;

                DB::table('Orders_Placed_Details_T')->insert([
                    'Order_Placed_Code' => $orderplacecode,
                    'Orders_Placed_Id'  => $orderId,
                    'Products_Id'       => $item['product_id'],
                    'Quantity'          => $item['quantity'],
                    'Price'             => $item['price'],
                    'Subtotal'          => $item['subtotal'],
                    'Vat'               =>  $vat ?? 0,
                    'Status'            => 'pending',
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);


                $product = Products::find($item['product_id']);
                $product->Product_Stock -= $item['quantity'];
                $product->save();
            }

            DB::commit();



            try {
                event(new OrderPlaced($orderId, $orderCode, (float) ($total['grand'] ?? 0)));
            } catch (\Throwable $e) {
                Log::error('Pusher broadcast failed', [
                    'order_id' => $orderId,
                    'error'    => $e->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Order placed successfully',
                'order_code' => $orderCode,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Order failed', 'error' => $e->getMessage()], 500);
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
