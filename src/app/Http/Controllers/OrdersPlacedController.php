<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Products;
use App\Events\OrderPlaced;
use App\Models\OrdersPlacedVendors;
use App\Mail\NewOrderEmail;
use Illuminate\Support\Str;
use App\Events\OrderCreated;
use App\Models\OrdersPlaced;
use App\Models\CustomerCart;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use App\Models\LoyalityPoints;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Models\OrdersPlacedDetails;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Models\ConxDatabaseNotification;
use App\Notifications\NewOrderNotification;

class OrdersPlacedController extends Controller
{


    public function index(Request $request)
    {
        $customer = Auth::user()?->customers;
        if (!$customer) return response()->json(['data' => [], 'pagination' => null]);

        $request->validate([
            'from'     => ['nullable', 'date'],
            'to'       => ['nullable', 'date', 'after_or_equal:from'],
            'status'   => ['nullable', 'string', 'max:50'],
            'q'        => ['nullable', 'string', 'max:50'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $perPage = (int) ($request->input('per_page', 10));
        $perPage = max(1, min($perPage, 50));

        $query = OrdersPlaced::query()
            ->where('Customers_Id', $customer->id)
            ->orderBy('id', 'desc');

        // date range
        if ($request->filled('from') || $request->filled('to')) {
            $from = $request->filled('from')
                ? Carbon::parse($request->from)->startOfDay()
                : Carbon::parse('1970-01-01')->startOfDay();

            $to = $request->filled('to')
                ? Carbon::parse($request->to)->endOfDay()
                : Carbon::now()->endOfDay();

            $query->whereBetween('created_at', [$from, $to]);
        }

        // status
        if ($request->filled('status')) {
            $query->where('Status', $request->status);
        }

        // search
        if ($request->filled('q')) {
            $q = trim($request->q);
            $query->where(function ($sub) use ($q) {
                $sub->where('Transaction_Number', 'like', "%{$q}%")
                    ->orWhere('Order_Code', 'like', "%{$q}%");
            });
        }

        $p = $query->paginate($perPage);

        return response()->json([
            'data' => $p->items(),
            'pagination' => [
                'current_page' => $p->currentPage(),
                'last_page'    => $p->lastPage(),
                'per_page'     => $p->perPage(),
                'total'        => $p->total(),
                'from'         => $p->firstItem(),
                'to'           => $p->lastItem(),
            ],
        ]);
    }

    public function place(Request $request)
    {
        $validated = $request->validate([
            'delivery_method' => ['required', Rule::in(['ship', 'pickup'])],
            'location_id'     => ['nullable', 'integer', Rule::requiredIf($request->delivery_method === 'pickup')],

            // keep them if you still send shipping info from UI
            'shipping_cost'   => 'required|numeric',

            'Customers_Contacts_Id' => 'nullable|integer',

            // shipping option (optional)
            'shipping_option' => 'nullable|array',
            'shipping_option.shipper_id'     => 'nullable|integer',
            'shipping_option.destination_id' => 'nullable|integer',
            'shipping_option.basis'          => 'nullable|in:weight,volume,heavy',
            'shipping_option.price'          => 'nullable|numeric',
            'shipping_option.currency'       => 'nullable|string|size:3',
            'shipping_option.weight_kg'      => 'nullable|numeric',
            'shipping_option.volume_cbm'     => 'nullable|numeric',

            // payment
            'payment'         => 'nullable|array',
            'payment.method'  => 'nullable|in:card,cod,transfer',

            // totals from UI (OPTIONAL) - we will compute our own totals from DB cart
            'total'           => 'nullable|array',
            'total.subtotal'  => 'nullable|numeric',
            'total.vat'       => 'nullable|numeric',
            'total.grand'     => 'nullable|numeric',
        ]);

        $pay = $request->input('payment', []);
        $method = $pay['method'] ?? 'cod';

        DB::beginTransaction();

        try {
            $customer = Auth::user()?->customers;
            if (!$customer) {
                throw new \Exception("Customer not found for this user.");
            }

            // ✅ Load cart rows from DB
            $cartRows = CustomerCart::query()
                ->where('Customers_Id', $customer->id)
                ->get();

            if ($cartRows->isEmpty()) {
                throw new \Exception("Cart is empty.");
            }

            // ✅ Preload all products
            $productIds = $cartRows->pluck('Products_Id')->unique()->values();
            $productsMap = Products::query()
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            // ✅ Compute totals from DB (secure)
            $itemsSubtotal = 0.0;
            $itemsVat = 0.0;

            // For vendor grouping
            $lines = [];          // per cart line
            $vendorTotals = [];   // vendor_id => ['subtotal'=>, 'vat'=>]
            $vendorSubTotalSum = 0.0;

            foreach ($cartRows as $cart) {
                $product = $productsMap->get($cart->Products_Id);
                if (!$product) {
                    throw new \Exception("Product not found: ID {$cart->Products_Id}");
                }

                $qty = (int) $cart->Quantity;

                // stock validation
                if ((int)$product->Product_Stock < $qty) {
                    throw new \Exception("Insufficient stock for {$product->Product_Name} (ID {$product->id})");
                }

                $price = (float) ($product->Product_Price ?? 0);
                $subtotal = $price * $qty;
                $vat = $subtotal * 0.05;

                $itemsSubtotal += $subtotal;
                $itemsVat += $vat;

                $vendorId = $product->Vendor_Id ?? null;
                if (empty($vendorId) || (int)$vendorId === 0) {
                    $vendorId = null;
                }

                if ($vendorId) {
                    if (!isset($vendorTotals[$vendorId])) {
                        $vendorTotals[$vendorId] = ['subtotal' => 0.0, 'vat' => 0.0];
                    }
                    $vendorTotals[$vendorId]['subtotal'] += $subtotal;
                    $vendorTotals[$vendorId]['vat'] += $vat;
                    $vendorSubTotalSum += $subtotal;
                }

                $lines[] = [
                    'cart_id'    => $cart->id,
                    'product_id' => $product->id,
                    'vendor_id'  => $vendorId,
                    'qty'        => $qty,
                    'price'      => $price,
                    'subtotal'   => $subtotal,
                    'vat'        => $vat,
                ];
            }

            // ✅ Shipping
            $shipping = $request->input('shipping_option', []);
            $shippingCost = (float) ($shipping['price'] ?? ($validated['shipping_cost'] ?? 0));
            $shippingCurrency = $shipping['currency'] ?? 'OMR';

            // ✅ Final totals for main order
            $grandTotal = $itemsSubtotal + $itemsVat + $shippingCost;

            // ✅ Create Orders_Placed_T header
            $orderCode = CodeGenerator::createCode('ORD', 'Orders_Placed_T', 'order_code');
            $transactionNumber = strtoupper(Str::random(10));

            $orderId = DB::table('Orders_Placed_T')->insertGetId([
                'Order_Code'              => $orderCode,
                'Customers_Contacts_Id'   => $request->Customers_Contacts_Id,
                'Transaction_Number'      => $transactionNumber,
                'Customers_Id'            => $customer->id,

                'Delivery_Type'           => $validated['delivery_method'],
                'Location_Id'             => $validated['location_id'] ?? null,

                'Total_Price'             => $grandTotal,
                'Status'                  => 'pending',
                'VAT'                     => $itemsVat,
                'Sub_Total_Price'         => $itemsSubtotal,

                'Shippers_Id'             => $shipping['shipper_id'] ?? null,
                'Shippers_Destination_Id' => $shipping['destination_id'] ?? null,
                'Shipping_Basis'          => $shipping['basis'] ?? null,
                'Shipping_Price'          => $shippingCost,
                'Shipping_Currency'       => $shippingCurrency,
                'Shipping_Weight_Kg'      => $shipping['weight_kg'] ?? null,
                'Shipping_Volume_Cbm'     => $shipping['volume_cbm'] ?? null,

                'created_at'              => now(),
                'updated_at'              => now(),
            ]);

            // ✅ Admin notification (keep your logic)
            try {
                ConxDatabaseNotification::create([
                    'type'            => 'App\\Notifications\\NewOrder',
                    'notifiable_type' => 'App\\Models\\Admin',
                    'notifiable_id'   => 1,
                    'data'            => [
                        'title'    => 'New Order Has Been Created',
                        'message'  => 'Order ' . $orderCode . ' has been created.',
                        'order_id' => $orderId,
                        'url'      => '/orders/' . $orderId,
                    ],
                ]);
            } catch (\Throwable $notifyException) {
                Log::error('Failed to create admin notification', [
                    'order_id'  => $orderId,
                    'error'     => $notifyException->getMessage(),
                    'exception' => $notifyException,
                ]);
            }

            // ✅ Loyalty (same logic but use $grandTotal)
            $loyalitypoints = LoyalityPoints::first();
            if ($loyalitypoints) {
                $pointsEarned = (int) round($loyalitypoints->Point * $grandTotal);

                $loyalty = DB::table('Customers_Loyalty_T')->where('Customer_Id', $customer->id)->first();
                $Customers_Loyalty_Code = CodeGenerator::createCode('LOYCODE', 'Customers_Loyalty_T', 'Customers_Loyalty_Code');

                if ($loyalty) {
                    DB::table('Customers_Loyalty_T')->where('Customer_Id', $customer->id)->update([
                        'Points_Earned' => DB::raw('Points_Earned + ' . $pointsEarned),
                        'updated_at'    => now(),
                    ]);
                } else {
                    DB::table('Customers_Loyalty_T')->insert([
                        'Customers_Loyalty_Code' => $Customers_Loyalty_Code,
                        'Customer_Id'            => $customer->id,
                        'Points_Earned'           => $pointsEarned,
                        'created_at'             => now(),
                        'updated_at'             => now(),
                    ]);
                }

                DB::table('Customers_Loyalty_Transactions_T')->insert([
                    'Loyalty_Transaction_Code' => CodeGenerator::createCode('LOYTRANS', 'Customers_Loyalty_Transactions_T', 'Loyalty_Transaction_Code'),
                    'Customer_Id'              => $customer->id,
                    'Orders_Placed_Id'         => $orderId,
                    'Points_Earned'            => $pointsEarned,
                    'Points_Redeemed'          => 0,
                    'created_at'               => now(),
                    'updated_at'               => now(),
                ]);
            }

            // ✅ Sales transaction header
            $transactionHeaderCode = CodeGenerator::createCode('TRANS', 'Sales_Transaction_Header_T', 'Sales_Transaction_Header_code');

            $sthId = DB::table('Sales_Transaction_Header_T')->insertGetId([
                'Sales_Transaction_Header_code' => $transactionHeaderCode,
                'Bill_No'                       => $transactionNumber,
                'created_at'                    => now(),
                'updated_at'                    => now(),
                'Orders_Placed_Id'              => $orderId,
            ]);

            // ✅ Sales transactions details (keep your logic)
            $Merchant_Id = strtoupper(Str::random(10));
            $billNumber = $transactionNumber;

            $detailsCode = CodeGenerator::createCode('ORDP', 'Sales_Transactions_Details_T', 'Sales_Transactions_Details_code');

            $base = [
                'Sales_Transactions_Details_code' => $detailsCode,
                'Sales_Transaction_Header_Id'     => $sthId,
                'Transaction_No'                  => $transactionNumber,
                'Merchant_Id'                     => $Merchant_Id,
                'Bill_No'                         => $billNumber,
                'Discount_Amount'                 => 0,
                'VAT_Tax_Amount'                  => $itemsVat,
                'Transaction_Date'                => now(),
                'created_at'                      => now(),
                'updated_at'                      => now(),
            ];

            $payCols = [
                'Payment_Method'   => $method,
                'Payment_Status'   => match ($method) {
                    'card'     => 'pending_authorization',
                    'transfer' => 'pending_verification',
                    default    => 'pending_cod',
                },
                'Payment_Currency' => $pay['currency'] ?? 'OMR',
            ];

            $specific = [];
            if ($method === 'card') {
                $card = $pay['card'] ?? [];
                $specific = [
                    'Card_Brand'     => $card['brand'] ?? null,
                    'Card_Last4'     => $card['last4'] ?? null,
                    'Card_Exp_Month' => $card['exp_month'] ?? null,
                    'Card_Exp_Year'  => $card['exp_year'] ?? null,
                ];
            } elseif ($method === 'transfer') {
                $tr = $pay['transfer'] ?? [];
                $specific = [
                    'Transfer_Reference'  => $tr['reference'] ?? null,
                    'Transfer_Payer_Name' => $tr['payer_name'] ?? null,
                ];
            } else {
                $specific = [
                    'COD_Collected'    => null,
                    'COD_Collected_At' => null,
                    'COD_Note'         => null,
                ];
            }

            DB::table('Sales_Transactions_Details_T')->insert(array_merge($base, $payCols, $specific));

            // ✅ Create vendor headers (ONLY for vendor products)
            $vendorOrderIds = []; // vendor_id => vendor_order_id

            foreach ($vendorTotals as $vendorId => $totals) {

                // shipping allocation across vendors by subtotal proportion
                $vendorShipping = 0.0;
                if ($vendorSubTotalSum > 0) {
                    $vendorShipping = $shippingCost * ($totals['subtotal'] / $vendorSubTotalSum);
                }

                $vendorOrder = OrdersPlacedVendors::create([
                    'Orders_Placed_Id'   => $orderId,
                    'Vendor_Id'          => $vendorId,
                    'Vendor_Order_Code'  => CodeGenerator::createCode('VORD', 'Orders_Placed_Vendors_T', 'Vendor_Order_Code'),

                    'Sub_Total'          => $totals['subtotal'],
                    'VAT'                => $totals['vat'],
                 
                    'Total'              => $totals['subtotal'] + $totals['vat'],

                    'Status'             => 'pending',
                    'Commission_Type'    => null,
                    'Commission_Value'   => null,
                    'Commission_Amount'  => null,
                    'Payout_Status'      => 'unpaid',
                ]);

                $vendorOrderIds[$vendorId] = $vendorOrder->id;
            }

            // ✅ Insert order details & update stock
            foreach ($lines as $ln) {
                $orderplacecode = CodeGenerator::createCode('ORDP', 'Orders_Placed_Details_T', 'Order_Placed_Code');

                $vendorId = $ln['vendor_id'];
                $vendorOrderId = $vendorId ? ($vendorOrderIds[$vendorId] ?? null) : null;

                DB::table('Orders_Placed_Details_T')->insert([
                    'Order_Placed_Code'       => $orderplacecode,
                    'Orders_Placed_Id'        => $orderId,
                    'Cart_Id'                 => $ln['cart_id'],

                    'Products_Id'             => $ln['product_id'],
                    'Quantity'                => $ln['qty'],
                    'Price'                   => $ln['price'],
                    'Subtotal'                => $ln['subtotal'],
                    'Vat'                     => $ln['vat'],

                    'Vendor_Id'               => $vendorId,
                    'Orders_Placed_Vendor_Id' => $vendorOrderId,

                    'Status'                  => 'pending',
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ]);

                // Atomic stock update
                Products::where('id', $ln['product_id'])
                    ->update([
                        'Product_Stock' => DB::raw("Product_Stock - {$ln['qty']}")
                    ]);
            }

            // ✅ Clear cart
            CustomerCart::where('Customers_Id', $customer->id)->delete();

            DB::commit();

            // ✅ Events / Mail (keep your logic)
            try {
                event(new OrderPlaced($orderId, $orderCode, (float) $grandTotal));

                event(new OrderCreated($orderId, [
                    'title'    => 'New Order Placed',
                    'message'  => 'Order ' . $orderCode . ' has been placed.',
                    'order_id' => $orderId,
                ]));

                $to = 'buzz644@yahoo.com';
                Mail::to($to)->queue(new NewOrderEmail($orderCode));
            } catch (\Throwable $e) {
                Log::error('Pusher/Mail failed', [
                    'order_id' => $orderId,
                    'error'    => $e->getMessage(),
                ]);
            }

            return response()->json([
                'message'    => 'Order placed successfully',
                'order_code' => $orderCode,
                'order_id'   => $orderId,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Order failed',
                'error'   => $e->getMessage()
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
