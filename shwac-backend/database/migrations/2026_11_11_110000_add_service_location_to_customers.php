<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a customer signed up (step after OTP): PIN code (or picked from a city), plus GPS if they allowed it.
 * Decides which services they see until they save a full address (asked when booking).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('pincode', 6)->nullable()->index();
            $table->string('city', 80)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['pincode']);
            $table->dropColumn(['pincode', 'city', 'lat', 'lng']);
        });
    }
};
