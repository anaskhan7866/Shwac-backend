<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Delete" for staff & technicians. The row is kept so past jobs, invoices,
 * attendance and expenses still show who did them; the person is hidden from
 * the Staff list and can no longer log in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['removed_at']);
            $table->dropColumn('removed_at');
        });
    }
};
