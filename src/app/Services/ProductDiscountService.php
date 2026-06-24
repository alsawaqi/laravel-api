<?php

namespace App\Services;

use App\Models\ProductDiscount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ProductDiscountService
{
    private ?Collection $activeDiscounts = null;

    public function priceForProduct(object $product): array
    {
        $original = round((float) ($product->Product_Price ?? 0), 3);
        $best = null;

        foreach ($this->activeDiscounts() as $discount) {
            if (!$this->matchesProduct($discount, $product)) {
                continue;
            }

            $unitDiscount = $this->unitDiscountAmount($discount, $original);
            if ($unitDiscount <= 0) {
                continue;
            }

            $candidate = [
                'id' => (int) $discount->id,
                'code' => $discount->Product_Discount_Code,
                'name' => $discount->Product_Discount_Name,
                'target_type' => $discount->Target_Type,
                'type' => $discount->Product_Discount_Type,
                'value' => round((float) $discount->Product_Discount_Value, 3),
                'unit_discount' => round($unitDiscount, 3),
                'specificity' => $this->specificity($discount->Target_Type),
            ];

            if (
                $best === null
                || $candidate['unit_discount'] > $best['unit_discount']
                || (
                    $candidate['unit_discount'] === $best['unit_discount']
                    && $candidate['specificity'] > $best['specificity']
                )
            ) {
                $best = $candidate;
            }
        }

        $discountAmount = $best['unit_discount'] ?? 0.0;
        $final = round(max($original - $discountAmount, 0), 3);

        return [
            'original_price' => $original,
            'final_price' => $final,
            'discount_amount' => round($discountAmount, 3),
            'has_discount' => $discountAmount > 0,
            'discount' => $best ? [
                'id' => $best['id'],
                'code' => $best['code'],
                'name' => $best['name'],
                'target_type' => $best['target_type'],
                'type' => $best['type'],
                'value' => $best['value'],
            ] : null,
        ];
    }

    public function appendPriceAttributes(object $product): object
    {
        $pricing = $this->priceForProduct($product);

        if (method_exists($product, 'setAttribute')) {
            $product->setAttribute('Original_Price', $pricing['original_price']);
            $product->setAttribute('Product_Final_Price', $pricing['final_price']);
            $product->setAttribute('Discount_Amount', $pricing['discount_amount']);
            $product->setAttribute('Has_Discount', $pricing['has_discount']);
            $product->setAttribute('Active_Discount', $pricing['discount']);
        } else {
            $product->Original_Price = $pricing['original_price'];
            $product->Product_Final_Price = $pricing['final_price'];
            $product->Discount_Amount = $pricing['discount_amount'];
            $product->Has_Discount = $pricing['has_discount'];
            $product->Active_Discount = $pricing['discount'];
        }

        return $product;
    }

    private function activeDiscounts(): Collection
    {
        if ($this->activeDiscounts !== null) {
            return $this->activeDiscounts;
        }

        if (!Schema::hasTable('Products_Discounts_T')) {
            $this->activeDiscounts = collect();
            return $this->activeDiscounts;
        }

        $now = now(config('app.business_timezone', 'Asia/Muscat'))->format('Y-m-d H:i:s');

        $this->activeDiscounts = ProductDiscount::query()
            ->where('Product_Discount_Is_Active', 1)
            ->where(function ($query) use ($now) {
                $query->whereNull('Start_Date')
                    ->orWhere('Start_Date', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('End_Date')
                    ->orWhere('End_Date', '>=', $now);
            })
            ->get();

        return $this->activeDiscounts;
    }

    private function matchesProduct(ProductDiscount $discount, object $product): bool
    {
        return match ($discount->Target_Type) {
            'product' => (int) ($discount->Products_Id ?? 0) === (int) ($product->id ?? 0),
            'department' => (int) ($discount->Product_Department_Id ?? 0) === (int) ($product->Product_Department_Id ?? 0),
            'sub_department' => (int) ($discount->Product_Sub_Department_Id ?? 0) === (int) ($product->Product_Sub_Department_Id ?? 0),
            'sub_sub_department' => (int) ($discount->Product_Sub_Sub_Department_Id ?? 0) === (int) ($product->Product_Sub_Sub_Department_Id ?? 0),
            default => false,
        };
    }

    private function unitDiscountAmount(ProductDiscount $discount, float $original): float
    {
        $value = (float) $discount->Product_Discount_Value;

        if ($original <= 0 || $value <= 0) {
            return 0;
        }

        $amount = $discount->Product_Discount_Type === 'percentage'
            ? $original * min($value, 100) / 100
            : $value;

        return min(round($amount, 3), $original);
    }

    private function specificity(?string $targetType): int
    {
        return match ($targetType) {
            'product' => 4,
            'sub_sub_department' => 3,
            'sub_department' => 2,
            'department' => 1,
            default => 0,
        };
    }
}
