<?php

namespace Tests\Unit\Pricing;

use App\Support\Pricing\BulkPriceResolver;
use PHPUnit\Framework\TestCase;

class BulkPriceResolverTest extends TestCase
{
    /**
     * The contract's example tier set: 5-10 @ 6.000, 20-50 @ 5.500,
     * 51+ (open-ended) @ 5.000. Gaps: 1-4 and 11-19 pay the normal price.
     */
    private function exampleTiers(): array
    {
        return [
            ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 6.000],
            ['min_qty' => 20, 'max_qty' => 50, 'unit_price' => 5.500],
            ['min_qty' => 51, 'max_qty' => null, 'unit_price' => 5.000],
        ];
    }

    public function test_empty_tier_set_resolves_to_null(): void
    {
        $this->assertNull(BulkPriceResolver::unitPriceFor([], 5));
        $this->assertNull(BulkPriceResolver::tierFor([], 5));
    }

    public function test_quantity_below_all_tiers_resolves_to_null(): void
    {
        $this->assertNull(BulkPriceResolver::unitPriceFor($this->exampleTiers(), 1));
        $this->assertNull(BulkPriceResolver::unitPriceFor($this->exampleTiers(), 4));
    }

    public function test_quantity_equal_to_min_qty_matches_the_tier(): void
    {
        $this->assertSame(6.0, BulkPriceResolver::unitPriceFor($this->exampleTiers(), 5));
        $this->assertSame(5.5, BulkPriceResolver::unitPriceFor($this->exampleTiers(), 20));
        $this->assertSame(5.0, BulkPriceResolver::unitPriceFor($this->exampleTiers(), 51));
    }

    public function test_quantity_equal_to_max_qty_matches_the_tier(): void
    {
        $this->assertSame(6.0, BulkPriceResolver::unitPriceFor($this->exampleTiers(), 10));
        $this->assertSame(5.5, BulkPriceResolver::unitPriceFor($this->exampleTiers(), 50));
    }

    public function test_quantity_inside_a_range_matches_the_tier(): void
    {
        $this->assertSame(6.0, BulkPriceResolver::unitPriceFor($this->exampleTiers(), 7));
        $this->assertSame(5.5, BulkPriceResolver::unitPriceFor($this->exampleTiers(), 35));
    }

    public function test_quantity_in_a_gap_between_tiers_resolves_to_null(): void
    {
        // 11-19 is uncovered: pays the normal (possibly discounted) price.
        $this->assertNull(BulkPriceResolver::unitPriceFor($this->exampleTiers(), 11));
        $this->assertNull(BulkPriceResolver::unitPriceFor($this->exampleTiers(), 15));
        $this->assertNull(BulkPriceResolver::unitPriceFor($this->exampleTiers(), 19));
    }

    public function test_open_ended_tier_matches_any_quantity_at_or_above_min(): void
    {
        $this->assertSame(5.0, BulkPriceResolver::unitPriceFor($this->exampleTiers(), 52));
        $this->assertSame(5.0, BulkPriceResolver::unitPriceFor($this->exampleTiers(), 100000));
    }

    public function test_single_quantity_tier_matches_only_that_quantity(): void
    {
        $tiers = [['min_qty' => 5, 'max_qty' => 5, 'unit_price' => 4.5]];

        $this->assertNull(BulkPriceResolver::unitPriceFor($tiers, 4));
        $this->assertSame(4.5, BulkPriceResolver::unitPriceFor($tiers, 5));
        $this->assertNull(BulkPriceResolver::unitPriceFor($tiers, 6));
    }

    public function test_zero_or_negative_quantity_resolves_to_null(): void
    {
        $tiers = [['min_qty' => 1, 'max_qty' => null, 'unit_price' => 2.0]];

        $this->assertNull(BulkPriceResolver::unitPriceFor($tiers, 0));
        $this->assertNull(BulkPriceResolver::unitPriceFor($tiers, -3));
    }

    public function test_unordered_tier_sets_still_resolve_correctly(): void
    {
        $tiers = [
            ['min_qty' => 51, 'max_qty' => null, 'unit_price' => 5.0],
            ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 6.0],
            ['min_qty' => 20, 'max_qty' => 50, 'unit_price' => 5.5],
        ];

        $this->assertSame(6.0, BulkPriceResolver::unitPriceFor($tiers, 8));
        $this->assertSame(5.0, BulkPriceResolver::unitPriceFor($tiers, 60));
        $this->assertNull(BulkPriceResolver::unitPriceFor($tiers, 12));
    }

    public function test_accepts_house_style_object_rows_like_eloquent_models(): void
    {
        // DB rows come back with house-style columns; open-ended Max_Qty is a
        // null property (isset() false on models), which must mean infinity.
        $bounded = (object) ['Min_Qty' => 5, 'Max_Qty' => 10, 'Unit_Price' => '6.000'];
        $openEnded = (object) ['Min_Qty' => 51, 'Max_Qty' => null, 'Unit_Price' => '5.000'];

        $this->assertSame(6.0, BulkPriceResolver::unitPriceFor([$bounded, $openEnded], 10));
        $this->assertSame(5.0, BulkPriceResolver::unitPriceFor([$bounded, $openEnded], 999));
        $this->assertNull(BulkPriceResolver::unitPriceFor([$bounded, $openEnded], 11));
    }

    public function test_accepts_string_numerics_from_the_database_driver(): void
    {
        $tiers = [['min_qty' => '5', 'max_qty' => '10', 'unit_price' => '6.250']];

        $this->assertSame(6.25, BulkPriceResolver::unitPriceFor($tiers, 5));
        $this->assertNull(BulkPriceResolver::unitPriceFor($tiers, 11));
    }

    public function test_tier_for_returns_the_normalized_tier(): void
    {
        $tier = BulkPriceResolver::tierFor($this->exampleTiers(), 25);

        $this->assertSame(
            ['min_qty' => 20, 'max_qty' => 50, 'unit_price' => 5.5],
            $tier
        );
    }

    public function test_tier_for_normalizes_open_ended_max_to_null(): void
    {
        $tier = BulkPriceResolver::tierFor($this->exampleTiers(), 60);

        $this->assertNotNull($tier);
        $this->assertSame(51, $tier['min_qty']);
        $this->assertNull($tier['max_qty']);
        $this->assertSame(5.0, $tier['unit_price']);
    }

    public function test_unit_price_is_rounded_to_three_decimals(): void
    {
        $tiers = [['min_qty' => 2, 'max_qty' => null, 'unit_price' => 4.12345]];

        $this->assertSame(4.123, BulkPriceResolver::unitPriceFor($tiers, 2));
    }

    public function test_malformed_rows_are_skipped_defensively(): void
    {
        $tiers = [
            ['min_qty' => null, 'max_qty' => 10, 'unit_price' => 6.0], // no min
            ['min_qty' => 5, 'max_qty' => 10],                          // no price
            ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 0],       // zero price
            ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => -1],      // negative
            ['min_qty' => 'x', 'max_qty' => 10, 'unit_price' => 6.0],   // non-numeric
            ['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 6.0],     // valid
        ];

        $this->assertSame(6.0, BulkPriceResolver::unitPriceFor($tiers, 7));

        // Only malformed rows in range -> no match at all.
        $this->assertNull(BulkPriceResolver::unitPriceFor(
            [['min_qty' => 5, 'max_qty' => 10, 'unit_price' => 0]],
            7
        ));
    }
}
