<?php

namespace Tests\Unit\Vendors;

use App\Support\Vendors\VendorCommissionCalculator;
use PHPUnit\Framework\TestCase;

class VendorCommissionCalculatorTest extends TestCase
{
    public function test_percent_commission_is_computed_from_line_subtotal(): void
    {
        $this->assertSame(10.0, VendorCommissionCalculator::lineCommission('percent', 10, 100.0, 1));
        $this->assertSame(5.0, VendorCommissionCalculator::lineCommission('percent', 2.5, 200.0, 4));
    }

    public function test_percent_commission_rounds_to_three_decimals(): void
    {
        // 7.5% of 19.990 = 1.49925 -> 1.499
        $this->assertSame(1.499, VendorCommissionCalculator::lineCommission('percent', 7.5, 19.99, 1));

        // 3% of 0.111 = 0.00333 -> 0.003
        $this->assertSame(0.003, VendorCommissionCalculator::lineCommission('percent', 3, 0.111, 1));
    }

    public function test_percent_of_exactly_one_hundred_is_valid(): void
    {
        $this->assertSame(50.0, VendorCommissionCalculator::lineCommission('percent', 100, 50.0, 1));
    }

    public function test_percent_above_one_hundred_is_invalid(): void
    {
        $this->assertNull(VendorCommissionCalculator::lineCommission('percent', 100.001, 50.0, 1));
        $this->assertNull(VendorCommissionCalculator::lineCommission('percent', 150, 50.0, 1));
    }

    public function test_fixed_commission_is_per_unit_times_quantity(): void
    {
        $this->assertSame(7.5, VendorCommissionCalculator::lineCommission('fixed', 2.5, 999.0, 3));
        $this->assertSame(2.469, VendorCommissionCalculator::lineCommission('fixed', 1.2345, 10.0, 2));

        // fixed is NOT capped at 100 (it's an amount, not a rate)
        $this->assertSame(250.0, VendorCommissionCalculator::lineCommission('fixed', 125, 500.0, 2));
    }

    public function test_numeric_strings_are_accepted_as_values(): void
    {
        // DECIMAL columns commonly hydrate as strings
        $this->assertSame(1.0, VendorCommissionCalculator::lineCommission('percent', '10', 10.0, 1));
        $this->assertSame(5.0, VendorCommissionCalculator::lineCommission('fixed', '2.500', 10.0, 2));
    }

    public function test_type_is_normalized_for_case_and_whitespace(): void
    {
        $this->assertSame(1.0, VendorCommissionCalculator::lineCommission(' Percent ', 10, 10.0, 1));
        $this->assertSame(3.0, VendorCommissionCalculator::lineCommission('FIXED', 1.5, 10.0, 2));
    }

    public function test_missing_or_unknown_type_yields_null(): void
    {
        $this->assertNull(VendorCommissionCalculator::lineCommission(null, 10, 100.0, 1));
        $this->assertNull(VendorCommissionCalculator::lineCommission('', 10, 100.0, 1));
        $this->assertNull(VendorCommissionCalculator::lineCommission('flat', 10, 100.0, 1));
    }

    public function test_zero_or_negative_value_yields_null(): void
    {
        $this->assertNull(VendorCommissionCalculator::lineCommission('percent', 0, 100.0, 1));
        $this->assertNull(VendorCommissionCalculator::lineCommission('percent', -5, 100.0, 1));
        $this->assertNull(VendorCommissionCalculator::lineCommission('fixed', 0, 100.0, 1));
        $this->assertNull(VendorCommissionCalculator::lineCommission('fixed', '-1.5', 100.0, 1));
        $this->assertNull(VendorCommissionCalculator::lineCommission('fixed', '0.000', 100.0, 1));
    }

    public function test_non_numeric_value_yields_null(): void
    {
        $this->assertNull(VendorCommissionCalculator::lineCommission('percent', null, 100.0, 1));
        $this->assertNull(VendorCommissionCalculator::lineCommission('percent', 'abc', 100.0, 1));
        $this->assertNull(VendorCommissionCalculator::lineCommission('fixed', '', 100.0, 1));
    }

    public function test_plan_covers_vendor_when_every_line_has_valid_commission(): void
    {
        $plan = VendorCommissionCalculator::planForVendorLines([
            ['subtotal' => 100.0, 'quantity' => 2, 'commission_type' => 'percent', 'commission_value' => 10],
            ['subtotal' => 30.0, 'quantity' => 3, 'commission_type' => 'fixed', 'commission_value' => '1.500'],
        ]);

        $this->assertTrue($plan['covered']);
        $this->assertSame(14.5, $plan['total']); // 10.000 + 4.500
        $this->assertSame([10.0, 4.5], $plan['lines']);
    }

    public function test_plan_is_uncovered_when_any_line_lacks_commission(): void
    {
        $plan = VendorCommissionCalculator::planForVendorLines([
            ['subtotal' => 100.0, 'quantity' => 1, 'commission_type' => 'percent', 'commission_value' => 10],
            ['subtotal' => 50.0, 'quantity' => 1, 'commission_type' => null, 'commission_value' => null],
        ]);

        $this->assertFalse($plan['covered']);
        $this->assertSame([10.0, null], $plan['lines']);
        // Total still reflects the valid lines only (callers must check covered).
        $this->assertSame(10.0, $plan['total']);
    }

    public function test_plan_treats_invalid_commission_as_missing(): void
    {
        $planPercentTooHigh = VendorCommissionCalculator::planForVendorLines([
            ['subtotal' => 100.0, 'quantity' => 1, 'commission_type' => 'percent', 'commission_value' => 101],
        ]);
        $this->assertFalse($planPercentTooHigh['covered']);
        $this->assertSame([null], $planPercentTooHigh['lines']);

        $planZeroValue = VendorCommissionCalculator::planForVendorLines([
            ['subtotal' => 100.0, 'quantity' => 1, 'commission_type' => 'fixed', 'commission_value' => 0],
        ]);
        $this->assertFalse($planZeroValue['covered']);

        $planBadType = VendorCommissionCalculator::planForVendorLines([
            ['subtotal' => 100.0, 'quantity' => 1, 'commission_type' => 'flat', 'commission_value' => 5],
        ]);
        $this->assertFalse($planBadType['covered']);
    }

    public function test_plan_with_no_lines_is_not_covered(): void
    {
        $plan = VendorCommissionCalculator::planForVendorLines([]);

        $this->assertFalse($plan['covered']);
        $this->assertSame(0.0, $plan['total']);
        $this->assertSame([], $plan['lines']);
    }

    public function test_plan_total_is_rounded_to_three_decimals(): void
    {
        // 0.1 + 0.2 in floats is 0.30000000000000004 without rounding
        $plan = VendorCommissionCalculator::planForVendorLines([
            ['subtotal' => 1.0, 'quantity' => 1, 'commission_type' => 'percent', 'commission_value' => 10],
            ['subtotal' => 2.0, 'quantity' => 1, 'commission_type' => 'percent', 'commission_value' => 10],
        ]);

        $this->assertTrue($plan['covered']);
        $this->assertSame(0.3, $plan['total']);
    }

    public function test_plan_handles_missing_line_keys_defensively(): void
    {
        $plan = VendorCommissionCalculator::planForVendorLines([
            [], // no keys at all -> treated as missing commission
        ]);

        $this->assertFalse($plan['covered']);
        $this->assertSame([null], $plan['lines']);
    }
}
