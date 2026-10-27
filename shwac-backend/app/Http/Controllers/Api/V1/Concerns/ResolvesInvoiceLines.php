<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Product;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Shared validation for invoice lines. A line is one of:
 *   - a catalog service   {service_id, quantity}
 *   - a spare part        {product_id, quantity}   → taken out of stock when the invoice is issued
 *   - free-form           {description, quantity, unit_price, tax_rate}
 *
 * Office staff may override catalog prices; technicians always bill catalog prices
 * (they can still give a discount, which is shown on the invoice).
 */
trait ResolvesInvoiceLines
{
    protected function validatedLines(Request $request, bool $allowPriceOverride = true): array
    {
        $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.service_id' => ['nullable', 'integer', 'exists:services,id'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.item_type' => ['nullable', 'in:service,part,other'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.hsn_sac' => ['nullable', 'string', 'max:8'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999'],
            'items.*.unit' => ['nullable', 'string', 'max:10'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric'],
        ]);

        $items = collect($request->input('items'));
        $services = Service::whereIn('id', $items->pluck('service_id')->filter())->get()->keyBy('id');
        $products = Product::whereIn('id', $items->pluck('product_id')->filter())->get()->keyBy('id');

        return $items->map(function (array $l, int $i) use ($services, $products, $allowPriceOverride) {
            $price = fn ($catalog) => $allowPriceOverride && isset($l['unit_price']) ? $l['unit_price'] : $catalog;

            if (! empty($l['service_id'])) {
                $s = $services[$l['service_id']];

                return [
                    'item_type' => 'service',
                    'item_id' => $s->id,
                    'description' => $l['description'] ?? $s->name,
                    'hsn_sac' => $s->sac_code,
                    'quantity' => $l['quantity'],
                    'unit' => $l['unit'] ?? 'NOS',
                    'unit_price' => $price($s->base_price),
                    'discount' => $l['discount'] ?? 0,
                    'tax_rate' => $s->tax_rate,
                ];
            }

            if (! empty($l['product_id'])) {
                $p = $products[$l['product_id']];
                if (! $p->is_active) {
                    throw ValidationException::withMessages(["items.{$i}.product_id" => "{$p->name} is no longer sold."]);
                }

                return [
                    'item_type' => 'part',
                    'item_id' => $p->id,
                    'description' => $p->name,
                    'hsn_sac' => $p->hsn,
                    'quantity' => $l['quantity'],
                    'unit' => $p->unit,
                    'unit_price' => $price($p->sale_price),
                    'discount' => $l['discount'] ?? 0,
                    'tax_rate' => $p->tax_rate,
                ];
            }

            if (! $allowPriceOverride) {
                throw ValidationException::withMessages(["items.{$i}" => 'Pick a service or a spare part from the catalog.']);
            }
            foreach (['description', 'unit_price', 'tax_rate'] as $f) {
                if (! isset($l[$f]) || $l[$f] === '') {
                    throw ValidationException::withMessages(["items.{$i}.{$f}" => "The {$f} field is required for a free-form line."]);
                }
            }

            return [
                'item_type' => $l['item_type'] ?? 'other', // no item_id → not stock-tracked
                'description' => $l['description'],
                'hsn_sac' => $l['hsn_sac'] ?? null,
                'quantity' => $l['quantity'],
                'unit' => $l['unit'] ?? 'NOS',
                'unit_price' => $l['unit_price'],
                'discount' => $l['discount'] ?? 0,
                'tax_rate' => $l['tax_rate'],
            ];
        })->all();
    }
}
