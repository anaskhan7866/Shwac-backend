<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2: a customer's appliance remembers what it is (the catalog service, e.g. AC),
 * its warranty status (In-Warranty / Out of Warranty / AMC) and its details
 * (brand, model & serial, tonnage, gas type...) so the next call fills them in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('customer_address_id')->constrained()->nullOnDelete();
            $table->string('warranty_status', 20)->nullable()->after('warranty_until');
            $table->json('details')->nullable()->after('warranty_status');
        });
    }

    public function down(): void
    {
        Schema::table('customer_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
            $table->dropColumn(['warranty_status', 'details']);
        });
    }
};
