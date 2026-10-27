<?php

namespace App\Services\Billing;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Invoice;
use App\Models\ServiceJob;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Services\Messaging\Notifier;
use App\Services\NumberGenerator;
use App\Services\Payments\PaymentService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class InvoiceService
{
    public function __construct(
        private GstCalculator $gst,
        private NumberGenerator $numbers,
        private Notifier $notifier,
        private InventoryService $inventory,
    ) {}

    /**
     * Create a draft invoice. Place of supply comes from the billing address state.
     *
     * @param  array<int, array>  $lines  see GstCalculator::calculate
     */
    public function createDraft(Customer $customer, Branch $branch, array $lines, ?CustomerAddress $address = null, ?ServiceJob $job = null, ?User $by = null, ?string $notes = null, ?Warehouse $stockWarehouse = null): Invoice
    {
        $hasParts = collect($lines)->contains(fn ($l) => ($l['item_type'] ?? null) === 'part' && ! empty($l['item_id']));
        if ($hasParts) {
            $stockWarehouse ??= $this->inventory->mainWarehouseFor($branch->id);
            if (! $stockWarehouse || (int) $stockWarehouse->branch_id !== (int) $branch->id) {
                throw ValidationException::withMessages(['stock_warehouse_id' => 'Choose a warehouse of this branch for the spare parts.']);
            }
        }

        $address ??= $job?->address ?? $customer->defaultAddress;
        if (! $address) {
            throw ValidationException::withMessages(['customer_address_id' => 'Customer has no address; place of supply cannot be determined.']);
        }

        $calc = $this->calculate($branch, $address->state_code, $lines);

        return DB::transaction(function () use ($customer, $branch, $address, $job, $by, $notes, $calc, $hasParts, $stockWarehouse) {
            $invoice = Invoice::create([
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'service_job_id' => $job?->id,
                'stock_warehouse_id' => $hasParts ? $stockWarehouse->id : null,
                'supplier_state_code' => $branch->state_code,
                'place_of_supply' => $address->state_code,
                'supply_type' => $calc['supply_type'],
                'customer_gstin' => $customer->gstin,
                'billing_address' => $address->toSnapshot() + ['name' => $customer->name, 'phone' => $customer->phone],
                'status' => 'draft',
                'notes' => $notes,
                'created_by' => $by?->id,
            ] + $calc['totals'] + ['balance_due' => $calc['totals']['total']]);

            $invoice->items()->createMany($calc['items']);

            return $invoice->load('items');
        });
    }

    /** Replace lines on a draft. */
    public function updateDraft(Invoice $invoice, array $lines, ?string $notes = null): Invoice
    {
        $this->assertStatus($invoice, ['draft']);
        $calc = $this->calculate($invoice->branch, $invoice->place_of_supply, $lines);
        $hasParts = collect($lines)->contains(fn ($l) => ($l['item_type'] ?? null) === 'part' && ! empty($l['item_id']));
        $warehouseId = $invoice->stock_warehouse_id ?? ($hasParts ? $this->inventory->mainWarehouseFor($invoice->branch_id)?->id : null);
        if ($hasParts && ! $warehouseId) {
            throw ValidationException::withMessages(['stock_warehouse_id' => 'Choose a warehouse of this branch for the spare parts.']);
        }

        return DB::transaction(function () use ($invoice, $calc, $notes, $warehouseId) {
            $invoice->items()->delete();
            $invoice->items()->createMany($calc['items']);
            $invoice->update($calc['totals'] + [
                'supply_type' => $calc['supply_type'],
                'balance_due' => $calc['totals']['total'],
                'stock_warehouse_id' => $warehouseId,
                'notes' => $notes ?? $invoice->notes,
            ]);

            return $invoice->refresh()->load('items');
        });
    }

    /**
     * Issue: assigns the GST serial number, freezes the invoice, notifies the customer
     * and (optionally) attaches a payment link.
     */
    public function issue(Invoice $invoice, bool $sendPaymentLink = true, ?User $by = null): Invoice
    {
        $this->assertStatus($invoice, ['draft']);

        DB::transaction(function () use ($invoice, $by) {
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
            $this->assertStatus($locked, ['draft']);

            // Parts leave stock at the moment the invoice becomes legal; a shortage aborts the issue.
            $this->inventory->consumeForInvoice($locked, $by);

            $locked->update([
                'number' => $this->numbers->invoice($locked->branch),
                'public_token' => $locked->public_token ?? Str::lower(Str::random(24)),
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays((int) config('shwac.invoice.due_days', 7))->toDateString(),
                'status' => 'issued',
                'issued_at' => now(),
            ]);
        });

        $invoice->refresh();

        // Optional paid gateway (PAYMENT_GATEWAY=razorpay). Default: the free invoice page with UPI QR.
        $gatewayLink = null;
        if ($sendPaymentLink && $invoice->isPayable() && config('shwac.payments.gateway') === 'razorpay') {
            try {
                $gatewayLink = app(PaymentService::class)->createPaymentLink($invoice);
            } catch (Throwable $e) {
                report($e); // gateway down/unconfigured must not block issuing
            }
        }

        $log = $this->notifier->notifyCustomer($invoice->customer, 'invoice_issued', [
            'invoice_number' => $invoice->number,
            'amount' => Money::format($invoice->total),
            'due_date' => $invoice->due_date?->format('d M Y'),
            'link' => $invoice->publicUrl(),
            'payment_link' => $gatewayLink?->short_url ?? $invoice->publicUrl(),
        ], $invoice, $gatewayLink ? ltrim((string) parse_url($gatewayLink->short_url, PHP_URL_PATH), '/') : null);

        return $invoice->load('items')->setAttribute('whatsapp', $log?->share())->setAttribute('public_url', $invoice->publicUrl());
    }

    public function cancel(Invoice $invoice, string $reason, ?User $by = null): Invoice
    {
        $this->assertStatus($invoice, ['draft', 'issued']);
        if ((float) $invoice->amount_paid > 0) {
            throw ValidationException::withMessages(['invoice' => 'Invoice has payments; issue a credit note instead (Phase 3).']);
        }

        DB::transaction(function () use ($invoice, $reason, $by) {
            if ($invoice->status === 'issued') {
                $this->inventory->returnForInvoice($invoice, $by);
            }
            $invoice->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'balance_due' => 0,
                'notes' => trim(($invoice->notes ? $invoice->notes."\n" : '').'Cancelled: '.$reason),
            ]);
        });

        return $invoice;
    }

    /** Recompute paid/balance/status from successful payments. Call inside a transaction. */
    public function refreshPaymentStatus(Invoice $invoice): Invoice
    {
        $paid = Money::toPaise($invoice->payments()->where('status', 'success')->sum('amount'));
        $total = Money::toPaise($invoice->total);
        $balance = max($total - $paid, 0);

        $invoice->update([
            'amount_paid' => Money::fromPaise($paid),
            'balance_due' => Money::fromPaise($balance),
            'status' => $invoice->status === 'cancelled' ? 'cancelled' : ($balance === 0 ? 'paid' : ($paid > 0 ? 'partially_paid' : 'issued')),
        ]);

        return $invoice;
    }

    private function calculate(Branch $branch, string $placeOfSupply, array $lines): array
    {
        try {
            return $this->gst->calculate($branch->state_code, $placeOfSupply, $lines);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }
    }

    private function assertStatus(Invoice $invoice, array $allowed): void
    {
        if (! in_array($invoice->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => "Invoice is {$invoice->status}; this action needs: ".implode('/', $allowed).'.']);
        }
    }
}
