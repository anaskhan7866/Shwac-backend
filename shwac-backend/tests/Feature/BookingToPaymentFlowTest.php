<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\ServiceJob;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end in the default zero-cost setup: customer books → job → assigned → technician
 * bills on site → WhatsApp message prepared as a click-to-send link → customer pays by UPI
 * on the public invoice page and reports the UTR → office verifies → receipt link prepared.
 */
class BookingToPaymentFlowTest extends TestCase
{
    private function setUpJob(): array
    {
        $customer = $this->customer(phone: '9876543210');
        $service = $this->service();
        $tech = $this->technician();
        $filter = \App\Models\Product::create(['sku' => 'SED-10', 'name' => 'Sediment filter', 'hsn' => '84212190', 'sale_price' => 250, 'tax_rate' => 18]);
        $store = \App\Models\Warehouse::create(['branch_id' => $this->branch->id, 'name' => 'Store', 'code' => 'S1', 'type' => 'main']);
        $inv = app(\App\Services\Inventory\InventoryService::class);
        $inv->receive($store, $filter, 5);
        $inv->transfer($store, $inv->vanFor($tech), $filter, 2);

        return [$customer, $service, $tech, $filter];
    }

    #[Test]
    public function full_service_lifecycle_with_free_whatsapp_links_and_upi(): void
    {
        Http::fake(); // nothing external may be called in the free setup
        [$customer, $service, $tech, $filter] = $this->setUpJob();

        // 1. Customer books from the app; dynamic form is validated
        $this->actingAsUser($this->customerUser($customer));
        $this->postJson('/api/v1/customer/bookings', [
            'service_id' => $service->id, 'scheduled_date' => '2026-10-18', 'form_data' => ['brand' => 'Unknown'],
        ])->assertStatus(422)->assertJsonValidationErrors('brand');

        $bookingId = $this->postJson('/api/v1/customer/bookings', [
            'service_id' => $service->id, 'scheduled_date' => '2026-10-18', 'time_slot' => '09:00-11:00',
            'form_data' => ['brand' => 'Kent', 'injected' => 'ignored'],
        ])->assertCreated()->assertJsonPath('form_data', ['brand' => 'Kent'])->json('id');

        $booking = Booking::findOrFail($bookingId);
        $this->assertSame('confirmed', $booking->status);
        $job = ServiceJob::where('booking_id', $bookingId)->firstOrFail();
        $this->assertDatabaseHas('notification_logs', ['event' => 'booking_confirmed', 'status' => 'ready', 'to' => '919876543210', 'branch_id' => $this->branch->id]);

        // 2. Operator assigns a technician → gets a ready-to-send WhatsApp link
        $operator = $this->staff('operator');
        $this->actingAsUser($operator);
        $share = $this->postJson("/api/v1/admin/jobs/{$job->id}/assign", ['technician_id' => $tech->id])
            ->assertOk()->assertJsonPath('status', 'assigned')->json('whatsapp');
        $this->assertStringStartsWith('https://wa.me/919876543210?text=', $share['url']);
        $this->assertStringContainsString($tech->name, $share['message']);

        // office outbox lists both prepared messages; sending one marks it
        $this->getJson('/api/v1/admin/whatsapp/outbox')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/v1/admin/whatsapp/{$share['id']}/sent")->assertOk()->assertJsonPath('sent', true);
        $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('todo.whatsapp_to_send', 1);

        // 3. Technician works the job; GPS at start is saved on the address (free geocoding)
        $this->actingAsUser($tech);
        $this->postJson("/api/v1/technician/jobs/{$job->id}/status", ['status' => 'accepted'])->assertOk();
        $this->postJson("/api/v1/technician/jobs/{$job->id}/status", ['status' => 'en_route'])
            ->assertOk()->assertJsonPath('whatsapp.event', 'technician_on_the_way');
        $this->postJson("/api/v1/technician/jobs/{$job->id}/status", ['status' => 'in_progress', 'lat' => 28.9845, 'lng' => 77.7064])->assertOk();
        $this->assertEquals(28.9845, $job->address->fresh()->lat);

        // 4. On-site billing → invoice with a public link, message prepared for the technician to send
        $inv = $this->postJson("/api/v1/technician/jobs/{$job->id}/invoice", ['items' => [
            ['service_id' => $service->id, 'quantity' => 1],
            ['product_id' => $filter->id, 'quantity' => 1],
        ]])->assertCreated()
            ->assertJsonPath('number', 'SHW/2627/00001')
            ->assertJsonPath('total', '884.00')
            ->json();
        $this->assertDatabaseCount('payment_links', 0); // no paid gateway
        $this->assertStringContainsString($inv['public_url'], $inv['whatsapp']['message']);
        $this->assertStringContainsString('884.00', $inv['whatsapp']['message']);

        $show = $this->getJson("/api/v1/technician/jobs/{$job->id}")->assertOk();
        $this->assertStringStartsWith('upi://pay?pa=shwac%40okaxis', $show->json('invoice.upi_intent'));
        $invoiceMsg = collect($show->json('messages'))->firstWhere('event', 'invoice_issued');
        $this->postJson("/api/v1/technician/messages/{$invoiceMsg['id']}/sent")->assertOk()->assertJsonPath('sent', true);
        $this->postJson("/api/v1/technician/jobs/{$job->id}/status", ['status' => 'completed', 'note' => 'Serviced'])->assertOk();

        // 5. Customer opens the public invoice page, pays by UPI, reports the UTR
        $token = Invoice::find($inv['id'])->public_token;
        $this->get("/i/{$token}")->assertOk()->assertSee('SHW/2627/00001')->assertSee('upi://pay', false)->assertSee('I have paid');
        $this->get('/i/'.str_repeat('a', 24))->assertNotFound();
        $this->post("/i/{$token}/paid", ['reference' => '4298 1573 6201'])->assertRedirect();
        $this->post("/i/{$token}/paid", ['reference' => '429815736201'])->assertSessionHasErrors('reference'); // no duplicates
        $this->assertSame('issued', Invoice::find($inv['id'])->status);

        // 6. Office checks the bank app and approves → invoice paid, receipt link prepared
        $accountant = $this->staff('accountant');
        $this->actingAsUser($accountant);
        $this->getJson('/api/v1/admin/dashboard')->assertJsonPath('todo.payments_to_verify', 1);
        $payment = \App\Models\Payment::where('status', 'pending_verification')->firstOrFail();
        $this->assertSame('429815736201', $payment->reference);
        $this->postJson("/api/v1/admin/payments/{$payment->id}/verify", ['approve' => true])
            ->assertOk()->assertJsonPath('status', 'success')->assertJsonPath('invoice.status', 'paid');
        $this->assertSame('RCT/HQ/2627/00001', $payment->fresh()->number);
        $this->assertDatabaseHas('notification_logs', ['event' => 'payment_received', 'status' => 'ready']);
        $this->get("/i/{$token}")->assertOk()->assertSee('Paid');

        Http::assertNothingSent();
    }

    #[Test]
    public function customer_app_upi_claim_can_be_rejected(): void
    {
        Http::fake();
        $customer = $this->customer();
        $this->actingAsUser($this->staff('accountant'));
        $inv = $this->postJson('/api/v1/admin/invoices', ['customer_id' => $customer->id, 'issue' => true,
            'items' => [['service_id' => $this->service()->id, 'quantity' => 1]]])->assertCreated()->json();

        $this->actingAsUser($this->customerUser($customer));
        $this->getJson("/api/v1/customer/invoices/{$inv['id']}")->assertOk()->assertJsonPath('online_payment', false);
        $this->postJson("/api/v1/customer/invoices/{$inv['id']}/upi-claim", ['reference' => 'abc'])->assertStatus(422);
        $pid = $this->postJson("/api/v1/customer/invoices/{$inv['id']}/upi-claim", ['reference' => '512233445566'])
            ->assertCreated()->assertJsonPath('status', 'pending_verification')->json('id');
        $this->postJson("/api/v1/customer/invoices/{$inv['id']}/payment-link")->assertStatus(422); // gateway off

        $this->actingAsUser($this->staff('accountant'));
        $this->postJson("/api/v1/admin/payments/{$pid}/verify", ['approve' => false])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/admin/payments/{$pid}/verify", ['approve' => false, 'note' => 'Not found in bank statement'])
            ->assertOk()->assertJsonPath('status', 'failed');
        $this->assertSame('issued', Invoice::find($inv['id'])->status);
        $this->postJson("/api/v1/admin/invoices/{$inv['id']}/reminder")->assertCreated()->assertJsonPath('event', 'payment_reminder');
    }

    #[Test]
    public function paid_integrations_still_work_when_switched_on_later(): void
    {
        // WHATSAPP_MODE=cloud and PAYMENT_GATEWAY=razorpay
        config(['services.whatsapp.mode' => 'cloud', 'shwac.payments.gateway' => 'razorpay']);
        $this->fakeExternal();
        [$customer, $service, $tech, $filter] = $this->setUpJob();
        $c = $customer;
        $job = ServiceJob::create(['number' => 'JOB-PAID', 'branch_id' => $this->branch->id, 'customer_id' => $c->id,
            'customer_address_id' => $c->addresses()->first()->id, 'technician_id' => $tech->id, 'status' => 'in_progress', 'service_id' => $service->id]);

        $this->actingAsUser($tech);
        $inv = $this->postJson("/api/v1/technician/jobs/{$job->id}/invoice", ['items' => [
            ['service_id' => $service->id, 'quantity' => 1], ['product_id' => $filter->id, 'quantity' => 1],
        ]])->assertCreated()->json();

        $this->assertDatabaseHas('payment_links', ['invoice_id' => $inv['id'], 'gateway_link_id' => 'plink_TEST1', 'amount' => '884.00']);
        $log = NotificationLog::where('event', 'invoice_issued')->firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertSame('rzp/abc123', $log->payload['button_url_suffix']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com/v21.0/123456/messages')
            && $r['template']['name'] === 'shwac_invoice_issued'
            && $r->hasHeader('Authorization', 'Bearer wa-token'));

        // Razorpay webhook: bad signature rejected, good one marks paid, replay is idempotent
        $body = json_encode([
            'event' => 'payment_link.paid',
            'payload' => [
                'payment_link' => ['entity' => ['id' => 'plink_TEST1', 'status' => 'paid']],
                'payment' => ['entity' => ['id' => 'pay_ABC', 'amount' => 88400, 'order_id' => 'order_X', 'method' => 'upi', 'vpa' => 'sunita@okicici']],
            ],
        ]);
        $this->call('POST', '/api/v1/webhooks/razorpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => 'bad'], $body)->assertStatus(400);
        $sig = hash_hmac('sha256', $body, 'rzp_webhook_secret');
        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', '/api/v1/webhooks/razorpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $sig], $body)->assertOk();
        }
        $invoice = Invoice::findOrFail($inv['id']);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(1, $invoice->payments()->count());
        $this->assertDatabaseHas('notification_logs', ['event' => 'payment_received', 'status' => 'sent']);
    }

    #[Test]
    public function inter_state_customer_is_billed_igst_and_manual_payments_settle_the_balance(): void
    {
        $this->fakeExternal();
        $customer = $this->customer(stateCode: '07'); // Delhi address, UP branch
        $service = $this->service();
        $accountant = $this->staff('accountant');
        $this->actingAsUser($accountant);

        $inv = $this->postJson('/api/v1/admin/invoices', [
            'customer_id' => $customer->id, 'issue' => true,
            'items' => [['service_id' => $service->id, 'quantity' => 2]],
        ])->assertCreated()->assertJsonPath('supply_type', 'inter')->assertJsonPath('igst_total', '179.64')
            ->assertJsonPath('total', '1178.00')->json();

        $this->postJson('/api/v1/admin/payments', ['invoice_id' => $inv['id'], 'amount' => 2000, 'method' => 'cash'])->assertStatus(422);
        $this->postJson('/api/v1/admin/payments', ['invoice_id' => $inv['id'], 'amount' => 500, 'method' => 'upi'])
            ->assertStatus(422)->assertJsonValidationErrors('reference');

        $this->postJson('/api/v1/admin/payments', ['invoice_id' => $inv['id'], 'amount' => 500, 'method' => 'cash'])
            ->assertCreated()->assertJsonPath('invoice.status', 'partially_paid')->assertJsonPath('invoice.balance_due', '678.00');
        $this->postJson('/api/v1/admin/payments', ['invoice_id' => $inv['id'], 'amount' => 678, 'method' => 'upi', 'reference' => 'UTR123'])
            ->assertCreated()->assertJsonPath('invoice.status', 'paid');

        // Paid invoices can't be cancelled; the show endpoint has no UPI link once paid
        $this->postJson("/api/v1/admin/invoices/{$inv['id']}/cancel", ['reason' => 'test'])->assertStatus(422);
        $this->getJson("/api/v1/admin/invoices/{$inv['id']}")->assertOk()->assertJsonPath('upi_intent', null);
    }

    #[Test]
    public function draft_invoices_can_be_edited_and_numbering_is_per_branch(): void
    {
        $this->fakeExternal();
        $delhi = $this->makeBranch('DEL', '07', 'Delhi');
        $this->actingAsUser($this->staff('super_admin'));

        $draft = $this->postJson('/api/v1/admin/invoices', [
            'customer_id' => $this->customer()->id,
            'items' => [['description' => 'Visit', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 18]],
        ])->assertCreated()->assertJsonPath('number', null)->assertJsonPath('status', 'draft')->json();

        $this->putJson("/api/v1/admin/invoices/{$draft['id']}", ['items' => [['description' => 'Visit', 'quantity' => 1, 'unit_price' => 200, 'tax_rate' => 18]]])
            ->assertOk()->assertJsonPath('total', '236.00');

        $this->postJson("/api/v1/admin/invoices/{$draft['id']}/issue", ['send_payment_link' => false])->assertOk()->assertJsonPath('number', 'SHW/2627/00001');
        $this->putJson("/api/v1/admin/invoices/{$draft['id']}", ['items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 18]]])->assertStatus(422);

        $this->postJson('/api/v1/admin/invoices', [
            'customer_id' => $this->customer($delhi, '07')->id, 'issue' => true,
            'items' => [['description' => 'Visit', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 18]],
        ])->assertCreated()->assertJsonPath('number', 'DEL/2627/00001')->assertJsonPath('supply_type', 'intra');

        $this->get("/api/v1/admin/invoices/{$draft['id']}/print")->assertOk()->assertSee('TAX INVOICE')->assertSee('SHW/2627/00001');
    }
}
