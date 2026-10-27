<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Inventory: products/spare parts, warehouses (main + one van per technician),
 * stock levels, an append-only movement ledger and low-stock alerts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 30)->unique();
            $table->string('name');
            $table->string('category', 60)->nullable();       // filter, membrane, pump, valve, purifier…
            $table->string('brand', 60)->nullable();
            $table->string('hsn', 8)->nullable();
            $table->string('unit', 10)->default('NOS');
            $table->decimal('tax_rate', 5, 2)->default(18);
            $table->decimal('mrp', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2);             // exclusive of GST
            $table->decimal('purchase_price', 12, 2)->nullable();
            $table->string('barcode', 64)->nullable()->unique();
            $table->decimal('reorder_level', 10, 2)->default(0);   // main warehouse threshold
            $table->decimal('van_min_level', 10, 2)->default(0);   // technician van threshold
            $table->unsignedSmallInteger('warranty_months')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('type', 10)->default('main'); // main|van
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete(); // van owner
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'warehouse_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('from_warehouse_id')->nullable()->constrained('warehouses');
            $table->foreignId('to_warehouse_id')->nullable()->constrained('warehouses');
            $table->decimal('quantity', 12, 2);
            $table->string('reason', 20); // purchase|transfer|consumed|returned|adjustment
            $table->nullableMorphs('reference'); // invoice, purchase order (Phase 3) …
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->decimal('threshold', 12, 2);
            $table->string('status', 10)->default('open')->index(); // open|resolved
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('stock_warehouse_id')->nullable()->after('service_job_id')->constrained('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_warehouse_id');
        });
        foreach (['stock_alerts', 'stock_movements', 'stock_levels', 'warehouses', 'products'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
