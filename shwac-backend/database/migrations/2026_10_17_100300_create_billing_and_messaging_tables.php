<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GST invoices, payments, payment links, notification templates and logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 16)->nullable()->unique(); // assigned on issue (GST: max 16 chars)
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('service_job_id')->nullable()->constrained()->nullOnDelete();
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();
            $table->char('supplier_state_code', 2);
            $table->char('place_of_supply', 2);           // state code
            $table->string('supply_type', 5);             // intra|inter
            $table->string('customer_gstin', 15)->nullable();
            $table->json('billing_address')->nullable();  // snapshot
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('taxable_value', 12, 2)->default(0);
            $table->decimal('cgst_total', 12, 2)->default(0);
            $table->decimal('sgst_total', 12, 2)->default(0);
            $table->decimal('igst_total', 12, 2)->default(0);
            $table->decimal('round_off', 6, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('balance_due', 12, 2)->default(0);
            $table->string('status', 20)->default('draft')->index(); // draft|issued|partially_paid|paid|cancelled
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 10)->default('service'); // service|part|other
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('description');
            $table->string('hsn_sac', 8)->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 10)->default('NOS');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('taxable_value', 12, 2);
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('cgst_rate', 5, 2)->default(0);
            $table->decimal('cgst_amount', 12, 2)->default(0);
            $table->decimal('sgst_rate', 5, 2)->default(0);
            $table->decimal('sgst_amount', 12, 2)->default(0);
            $table->decimal('igst_rate', 5, 2)->default(0);
            $table->decimal('igst_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique(); // receipt number, set on success
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('method', 20);                 // cash|upi|card|razorpay|bank_transfer|cheque
            $table->string('status', 20)->default('pending')->index(); // pending|success|failed|refunded
            $table->string('gateway', 20)->nullable();    // razorpay
            $table->string('gateway_order_id')->nullable()->index();
            $table->string('gateway_payment_id')->nullable()->unique();
            $table->string('reference')->nullable();      // UTR / cheque no
            $table->json('gateway_payload')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 20)->default('razorpay');
            $table->string('gateway_link_id')->unique();
            $table->string('short_url');
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('created'); // created|paid|expired|cancelled
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event', 50);                  // booking_confirmed, invoice_issued...
            $table->string('channel', 20)->default('whatsapp'); // whatsapp|sms
            $table->string('provider_template');          // template name approved on Meta / DLT id
            $table->string('language', 10)->default('en');
            $table->json('variables');                    // ordered list of variable keys for {{1}}, {{2}}...
            $table->text('preview')->nullable();          // human-readable body for the admin
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['event', 'channel']);
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20);
            $table->string('event', 50)->nullable();
            $table->string('to', 20);
            $table->nullableMorphs('notifiable');
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('queued')->index(); // queued|sent|delivered|read|failed|skipped
            $table->string('provider_message_id')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['notification_logs', 'notification_templates', 'payment_links', 'payments', 'invoice_items', 'invoices'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
