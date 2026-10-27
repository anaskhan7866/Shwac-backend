<?php

namespace Tests\Unit;

use App\Services\Billing\GstCalculator;
use App\Support\FinancialYear;
use App\Support\Money;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GstCalculatorTest extends TestCase
{
    private GstCalculator $gst;

    protected function setUp(): void
    {
        $this->gst = new GstCalculator;
    }

    #[Test]
    public function intra_state_splits_into_cgst_and_sgst_and_rounds_total(): void
    {
        $r = $this->gst->calculate('09', '09', [
            ['description' => 'RO service', 'quantity' => 1, 'unit_price' => 499, 'tax_rate' => 18],
        ]);

        $this->assertSame('intra', $r['supply_type']);
        $this->assertSame('9.00', $r['items'][0]['cgst_rate']);
        $this->assertSame('44.91', $r['totals']['cgst_total']);
        $this->assertSame('44.91', $r['totals']['sgst_total']);
        $this->assertSame('0.00', $r['totals']['igst_total']);
        $this->assertSame('0.18', $r['totals']['round_off']);   // 588.82 → 589
        $this->assertSame('589.00', $r['totals']['total']);
    }

    #[Test]
    public function inter_state_uses_igst(): void
    {
        $r = $this->gst->calculate('09', '07', [
            ['description' => 'RO service', 'quantity' => 1, 'unit_price' => 499, 'tax_rate' => 18],
        ]);

        $this->assertSame('inter', $r['supply_type']);
        $this->assertSame('0.00', $r['totals']['cgst_total']);
        $this->assertSame('89.82', $r['totals']['igst_total']);
        $this->assertSame('589.00', $r['totals']['total']);
    }

    #[Test]
    public function handles_multiple_rates_fractional_quantity_and_discount(): void
    {
        $r = $this->gst->calculate('09', '09', [
            ['description' => 'Service', 'quantity' => 1, 'unit_price' => 399, 'tax_rate' => 18, 'discount' => 50],
            ['description' => 'Sediment filter', 'quantity' => 2, 'unit_price' => 149.50, 'tax_rate' => 12],
            ['description' => 'Pipe (m)', 'quantity' => 1.5, 'unit_price' => 40, 'tax_rate' => 18],
        ]);

        // 349 + 299 + 60 = 708 taxable
        $this->assertSame('758.00', $r['totals']['subtotal']);
        $this->assertSame('50.00', $r['totals']['discount_total']);
        $this->assertSame('708.00', $r['totals']['taxable_value']);
        // CGST: 349×9%=31.41, 299×6%=17.94, 60×9%=5.40 → 54.75 (SGST same)
        $this->assertSame('54.75', $r['totals']['cgst_total']);
        $this->assertSame('54.75', $r['totals']['sgst_total']);
        $this->assertSame('818.00', $r['totals']['total']); // 817.50 rounds half up
        $this->assertSame('0.50', $r['totals']['round_off']);
        $this->assertSame('1.50', $r['items'][2]['quantity']);
    }

    #[Test]
    public function discount_cannot_exceed_line_value(): void
    {
        $r = $this->gst->calculate('09', '09', [
            ['description' => 'Free visit', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 18, 'discount' => 500],
        ]);
        $this->assertSame('0.00', $r['totals']['total']);
    }

    #[Test]
    public function rejects_invalid_gst_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->gst->calculate('09', '09', [['description' => 'x', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 17]]);
    }

    #[Test]
    public function money_conversions_are_exact(): void
    {
        $this->assertSame(58882, Money::toPaise('588.82'));
        $this->assertSame(1, Money::toPaise(0.005));
        $this->assertSame('-0.18', Money::fromPaise(-18));
        $this->assertSame(4491, Money::percentOf(49900, 9));
        $this->assertSame(13, Money::percentOf(10000, 0.125));
    }

    #[Test]
    public function financial_year_codes(): void
    {
        $this->assertSame('2526', FinancialYear::code(Carbon::parse('2026-03-31')));
        $this->assertSame('2627', FinancialYear::code(Carbon::parse('2026-04-01')));
        $this->assertSame('2026-27', FinancialYear::label(Carbon::parse('2026-10-17')));
        $this->assertSame('9900', FinancialYear::code(Carbon::parse('2099-12-01')));
    }
}
