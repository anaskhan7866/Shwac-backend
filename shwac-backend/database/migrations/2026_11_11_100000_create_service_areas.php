<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PIN codes the company serves, and which services are offered in each.
 * Customers only see (and can only book) services offered at their address's PIN code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_areas', function (Blueprint $table) {
            $table->id();
            $table->string('pincode', 6)->unique();
            $table->string('name', 120)->nullable();   // area / locality, e.g. "Shastri Nagar"
            $table->string('city', 80)->nullable();
            $table->boolean('all_services')->default(false); // also covers services added later
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('service_area_service', function (Blueprint $table) {
            $table->foreignId('service_area_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['service_area_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_area_service');
        Schema::dropIfExists('service_areas');
    }
};
