<?php

namespace App\Http\Controllers;

use App\Models\CustomerCart;
use Illuminate\Http\Request;
use App\Models\CustomersMaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
 
 
use Illuminate\Support\Str;

class CustomerCartController extends Controller
{
  private function customerOrFail()
    {
        $user = auth()->user();

        // You already have this helper
        return $user->customerOrCreate();
    }

    public function index()
    {
        $customer = $this->customerOrFail();

        $rows = CustomerCart::query()
            ->where('Customers_Id', $customer->id)
            ->with(['product.image']) // adjust product eager-load if you want images/specs
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'data' => $rows,
        ]);
    }

    // Sync guest cart from localStorage into DB, then return DB cart
    public function sync(Request $request)
    {
        $customer = $this->customerOrFail();

        $payload = $request->validate([
            'items' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        foreach ($payload['items'] as $item) {
            $productId = (int) $item['product_id'];
            $qty       = (int) $item['quantity'];

            $existing = CustomerCart::query()
                ->where('Customers_Id', $customer->id)
                ->where('Products_Id', $productId)
                ->first();

            if ($existing) {
                // ✅ Your requirement: override DB qty with guest qty (latest)
                $existing->update(['Quantity' => $qty]);
            } else {
                CustomerCart::create([
                    'Customers_Id' => $customer->id,
                    'Products_Id'  => $productId,
                    'Quantity'     => $qty,
                ]);
            }
        }

        return $this->index();
    }


    public function setQuantity(Request $request)
    {
        $customer = $this->customerOrFail();

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity'   => ['required', 'integer', 'min:1'], // ✅ no zero here
        ]);

        $productId = (int) $data['product_id'];
        $qty       = (int) $data['quantity'];

        $row = CustomerCart::query()->firstOrCreate(
            [
                'Customers_Id' => $customer->id,
                'Products_Id'  => $productId,
            ],
            [
                'Cart_Code'    => (string) Str::uuid(),
                'Quantity'     => $qty,
            ]
        );

        if (!$row->wasRecentlyCreated) {
            $row->update(['Quantity' => $qty]);
        }

        return $this->index();
    }


    public function add(Request $request)
    {
        $customer = $this->customerOrFail();

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity'   => ['sometimes', 'integer', 'min:1'], // default 1
        ]);

        $productId = (int) $data['product_id'];
        $addQty    = (int) ($data['quantity'] ?? 1);
  
        try{

       
        DB::transaction(function () use ($customer, $productId, $addQty) {
            $row = CustomerCart::query()
                ->where('Customers_Id', $customer->id)
                ->where('Products_Id', $productId)
                ->lockForUpdate()
                ->first();

            if ($row) {
                $row->update(['Quantity' => ((int)$row->Quantity + $addQty)]);
            } else {
                CustomerCart::create([
                  
                    'Customers_Id' => $customer->id,
                    'Products_Id'  => $productId,
                    'Quantity'     => $addQty,
                ]);
            }
        });

        return $this->index();

         } catch (\Exception $e){
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // Upsert a single item (logged-in add/update)
    // public function upsert(Request $request)
    // {
    //     $customer = $this->customerOrFail();

    //     $data = $request->validate([
    //         'product_id' => ['required', 'integer'],
    //         'quantity'   => ['required', 'integer', 'min:0'],
    //     ]);

    //     $productId = (int) $data['product_id'];
    //     $qty       = (int) $data['quantity'];

    //     $row = CustomerCart::query()
    //         ->where('Customers_Id', $customer->id)
    //         ->where('Products_Id', $productId)
    //         ->first();

    //     // quantity 0 => delete
    //     if ($qty === 0) {
    //         if ($row) $row->delete();
    //         return $this->index();
    //     }

    //     if ($row) {
    //         $row->update(['Quantity' => $qty]);
    //     } else {
    //         CustomerCart::create([
               
    //             'Customers_Id' => $customer->id,
    //             'Products_Id'  => $productId,
    //             'Quantity'     => $qty,
    //         ]);
    //     }

    //     return $this->index();
    // }

    public function remove(int $productId)
    {
        $customer = $this->customerOrFail();

        CustomerCart::query()
            ->where('Customers_Id', $customer->id)
            ->where('Products_Id', $productId)
            ->delete();

        return $this->index();
    }

    public function clear()
    {
        $customer = $this->customerOrFail();

        CustomerCart::query()
            ->where('Customers_Id', $customer->id)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
