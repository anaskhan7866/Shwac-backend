<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebhookAndCatalogTest extends TestCase
{
    #[Test]
    public function whatsapp_webhook_handshake_and_delivery_status(): void
    {
        $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=4242')
            ->assertOk()->assertSee('4242');
        $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=1')->assertForbidden();

        $log = NotificationLog::create(['channel' => 'whatsapp', 'event' => 'invoice_issued', 'to' => '919999999999', 'status' => 'sent', 'provider_message_id' => 'wamid.1']);
        $send = function (string $status) {
            $body = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.1', 'status' => $status]]]]]]]]);

            return $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'wa-secret'),
            ], $body);
        };

        $send('read')->assertOk();
        $send('delivered')->assertOk(); // out-of-order event must not downgrade
        $this->assertSame('read', $log->fresh()->status);

        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], '{}')
            ->assertStatus(401);
    }

    #[Test]
    public function public_catalog_shows_tax_inclusive_price_and_form(): void
    {
        $s = $this->service(['base_price' => 499]);
        $this->service(['name' => 'Hidden', 'is_active' => false]);

        $this->getJson('/api/v1/catalog')->assertOk()
            ->assertJsonCount(1, 'categories.0.services')
            ->assertJsonPath('categories.0.services.0.price_incl_tax', '588.82');
        $this->getJson("/api/v1/catalog/services/{$s->id}")->assertOk()->assertJsonPath('form_schema.0.key', 'brand');
    }

    #[Test]
    public function admin_can_define_a_dynamic_booking_form(): void
    {
        $this->actingAsUser($this->staff('super_admin'));
        $cat = $this->postJson('/api/v1/admin/catalog/categories', ['name' => 'Water Softener'])->assertCreated()->json('id');

        $this->postJson('/api/v1/admin/catalog/services', [
            'service_category_id' => $cat, 'name' => 'Softener Install', 'code' => 'SOFT-INST', 'base_price' => 999, 'tax_rate' => 17,
        ])->assertStatus(422)->assertJsonValidationErrors('tax_rate');

        $this->postJson('/api/v1/admin/catalog/services', [
            'service_category_id' => $cat, 'name' => 'Softener Install', 'code' => 'SOFT-INST', 'base_price' => 999, 'tax_rate' => 18,
            'form_schema' => [
                ['key' => 'hardness', 'label' => 'Water hardness (ppm)', 'type' => 'number', 'min' => 0, 'required' => true],
                ['key' => 'floor', 'label' => 'Floor', 'type' => 'select', 'options' => ['Ground', '1st', '2nd+']],
            ],
        ])->assertCreated()->assertJsonPath('form_schema.1.options.2', '2nd+');
    }
}
