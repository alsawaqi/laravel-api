<?php

namespace Tests\Unit\Reviews;

use App\Support\Reviews\ProductEngagementRules;
use PHPUnit\Framework\TestCase;

class ProductEngagementRulesTest extends TestCase
{
    public function test_it_aggregates_only_approved_reviews_for_public_rating_summary(): void
    {
        $summary = ProductEngagementRules::ratingSummary([
            ['Status' => 'approved', 'Rating' => 5],
            ['Status' => 'approved', 'Rating' => 4],
            ['Status' => 'pending', 'Rating' => 1],
            ['Status' => 'reported', 'Rating' => 1],
        ]);

        $this->assertSame(2, $summary['review_count']);
        $this->assertSame('4.50', $summary['average_rating']);
        $this->assertSame([1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 1], $summary['distribution']);
    }

    public function test_public_visibility_hides_pending_rejected_and_reported_reviews(): void
    {
        $ids = ProductEngagementRules::publicVisibleIds([
            ['id' => 10, 'Status' => 'approved'],
            ['id' => 11, 'Status' => 'pending'],
            ['id' => 12, 'Status' => 'rejected'],
            ['id' => 13, 'Status' => 'reported'],
        ]);

        $this->assertSame([10], $ids);
    }

    public function test_it_identifies_verified_purchase_lines_from_completed_orders(): void
    {
        $purchase = ProductEngagementRules::verifiedPurchaseFromLines([
            [
                'Products_Id' => 5,
                'Customers_Id' => 9,
                'Order_Status' => 'pending',
                'Detail_Status' => 'pending',
                'Orders_Placed_Id' => 40,
                'Orders_Placed_Details_Id' => 400,
            ],
            [
                'Products_Id' => 5,
                'Customers_Id' => 9,
                'Order_Status' => 'delivered',
                'Detail_Status' => 'delivered',
                'Orders_Placed_Id' => 41,
                'Orders_Placed_Details_Id' => 401,
            ],
        ], productId: 5, customerId: 9);

        $this->assertSame([
            'verified' => true,
            'orders_placed_id' => 41,
            'orders_placed_details_id' => 401,
        ], $purchase);

        $this->assertSame([
            'verified' => false,
            'orders_placed_id' => null,
            'orders_placed_details_id' => null,
        ], ProductEngagementRules::verifiedPurchaseFromLines([], productId: 5, customerId: 9));
    }
}
