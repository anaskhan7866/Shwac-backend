<?php

namespace App\Services\Billing;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Computes GST for an invoice.
 *
 *  - Supplier state == place of supply → intra-state: CGST + SGST, each half the rate.
 *  - Otherwise → inter-state: IGST at the full rate.
 *  - Tax is computed per line on the taxable value and rounded to the paisa.
 *  - The grand total is rounded to the nearest rupee; the difference is shown as round-off.
 *
 * All values are returned as 2-decimal strings.
 */
class GstCalculator
{
    public const ALLOWED_RATES = [0, 0.25, 3, 5, 12, 18, 28, 40];

    /**
     * @param  array<int, array{description:string, quantity:numeric, unit_price:numeric, tax_rate:numeric, discount?:numeric, hsn_sac?:string, item_type?:string, item_id?:int, unit?:string}>  $lines
     * @return array{supply_type:string, items:array<int,array>, totals:array<string,string>}
     */
    public function calculate(string $supplierStateCode, string $placeOfSupply, array $lines): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException('An invoice needs at least one line.');
        }

        $intra = $supplierStateCode === $placeOfSupply;
        $sum = ['subtotal' => 0, 'discount' => 0, 'taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
        $items = [];

        foreach ($lines as $i => $line) {
            $rate = (float) $line['tax_rate'];
            if (! in_array($rate, array_map('floatval', self::ALLOWED_RATES), true)) {
                throw new InvalidArgumentException("Line {$i}: {$rate}% is not a valid GST rate.");
            }

            $qtyHundredths = Money::toPaise($line['quantity']);      // 1.50 → 150
            if ($qtyHundredths <= 0) {
                throw new InvalidArgumentException("Line {$i}: quantity must be positive.");
            }
            $unit = Money::toPaise($line['unit_price']);
            $gross = intdiv($unit * $qtyHundredths + 50, 100);
            $discount = min(Money::toPaise($line['discount'] ?? 0), $gross);
            $taxable = $gross - $discount;

            if ($intra) {
                $half = $rate / 2;
                $cgst = Money::percentOf($taxable, $half);
                $sgst = Money::percentOf($taxable, $half);
                $igst = 0;
            } else {
                $half = 0;
                $cgst = $sgst = 0;
                $igst = Money::percentOf($taxable, $rate);
            }

            $sum['subtotal'] += $gross;
            $sum['discount'] += $discount;
            $sum['taxable'] += $taxable;
            $sum['cgst'] += $cgst;
            $sum['sgst'] += $sgst;
            $sum['igst'] += $igst;

            $items[] = [
                'item_type' => $line['item_type'] ?? 'service',
                'item_id' => $line['item_id'] ?? null,
                'description' => $line['description'],
                'hsn_sac' => $line['hsn_sac'] ?? null,
                'quantity' => Money::fromPaise($qtyHundredths),
                'unit' => $line['unit'] ?? 'NOS',
                'unit_price' => Money::fromPaise($unit),
                'discount' => Money::fromPaise($discount),
                'taxable_value' => Money::fromPaise($taxable),
                'tax_rate' => number_format($rate, 2, '.', ''),
                'cgst_rate' => number_format($intra ? $half : 0, 2, '.', ''),
                'cgst_amount' => Money::fromPaise($cgst),
                'sgst_rate' => number_format($intra ? $half : 0, 2, '.', ''),
                'sgst_amount' => Money::fromPaise($sgst),
                'igst_rate' => number_format($intra ? 0 : $rate, 2, '.', ''),
                'igst_amount' => Money::fromPaise($igst),
                'total' => Money::fromPaise($taxable + $cgst + $sgst + $igst),
            ];
        }

        $exact = $sum['taxable'] + $sum['cgst'] + $sum['sgst'] + $sum['igst'];
        $rounded = (int) (round($exact / 100, 0, PHP_ROUND_HALF_UP) * 100);

        return [
            'supply_type' => $intra ? 'intra' : 'inter',
            'items' => $items,
            'totals' => [
                'subtotal' => Money::fromPaise($sum['subtotal']),
                'discount_total' => Money::fromPaise($sum['discount']),
                'taxable_value' => Money::fromPaise($sum['taxable']),
                'cgst_total' => Money::fromPaise($sum['cgst']),
                'sgst_total' => Money::fromPaise($sum['sgst']),
                'igst_total' => Money::fromPaise($sum['igst']),
                'round_off' => Money::fromPaise($rounded - $exact),
                'total' => Money::fromPaise($rounded),
            ],
        ];
    }
}
