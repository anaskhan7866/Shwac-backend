<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zero-running-cost mode:
 *  - WhatsApp messages are prepared as click-to-send links (wa.me) that staff open and send,
 *    instead of the paid WhatsApp Cloud API. Templates get an editable text body.
 *  - Invoices get a public link (no login) with a UPI QR; customers can report a UPI payment
 *    reference which staff verify, instead of a paid payment gateway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->text('body')->nullable()->after('preview');     // "Hi {customer_name}, …"
            $table->string('provider_template')->nullable()->change(); // only needed for the Cloud API
        });

        Schema::table('notification_logs', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('channel')->constrained()->nullOnDelete();
            $table->text('message')->nullable()->after('payload');
            $table->text('link')->nullable()->after('message');      // https://wa.me/91…?text=…
            $table->foreignId('created_by')->nullable()->after('error')->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable()->after('sent_by');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('public_token', 32)->nullable()->unique()->after('number');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('method');  // staff|technician|customer_app|invoice_link|gateway
            $table->foreignId('verified_by')->nullable()->after('received_by')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->string('review_note')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['source', 'verified_at', 'review_note']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('public_token');
        });
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('sent_by');
            $table->dropColumn(['message', 'link', 'sent_at']);
        });
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->dropColumn('body');
        });
    }
};
