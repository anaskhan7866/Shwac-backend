<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone', 15)->unique();
            $table->string('whatsapp', 15)->nullable();
            $table->string('email')->nullable();
            $table->string('gstin', 15)->nullable();
            $table->boolean('whatsapp_opt_in')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label')->default('Home');
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('landmark')->nullable();
            $table->string('city');
            $table->string('district')->nullable();
            $table->string('state');
            $table->char('state_code', 2);
            $table->string('pincode', 6)->index();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('customer_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_address_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category')->nullable(); // RO purifier, water softener, solar heater...
            $table->string('product_name');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_no')->nullable()->index();
            $table->date('purchase_date')->nullable();
            $table->date('installed_on')->nullable();
            $table->date('warranty_until')->nullable();
            $table->timestamps();
        });

        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_category_id')->constrained();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->text('description')->nullable();
            $table->decimal('base_price', 12, 2)->default(0);   // exclusive of GST
            $table->decimal('tax_rate', 5, 2)->default(18);     // GST %
            $table->string('sac_code', 8)->default('998719');
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->json('form_schema')->nullable();            // dynamic booking form definition
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['services', 'service_categories', 'customer_products', 'customer_addresses', 'customers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
