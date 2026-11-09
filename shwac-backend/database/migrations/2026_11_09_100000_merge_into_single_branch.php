<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The business runs as one company: branches are hidden in every app.
 * Moves every record of the other branches into the main branch (lowest id among
 * active ones, the same rule app sign-ups use) and switches the other branches off.
 * Nothing is deleted; the other branch rows stay, inactive.
 */
return new class extends Migration
{
    private const TABLES = [
        'users', 'customers', 'bookings', 'complaints', 'service_jobs', 'invoices', 'payments',
        'warehouses', 'attendances', 'expenses', 'notification_logs',
    ];

    public function up(): void
    {
        $main = DB::table('branches')->where('is_active', true)->orderBy('id')->value('id')
            ?? DB::table('branches')->orderBy('id')->value('id');
        if (! $main) {
            return; // fresh database: nothing to merge
        }

        DB::transaction(function () use ($main) {
            foreach (self::TABLES as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'branch_id')) {
                    DB::table($table)->whereNotNull('branch_id')->where('branch_id', '!=', $main)->update(['branch_id' => $main]);
                }
            }
            DB::table('branches')->where('id', '!=', $main)->update(['is_active' => false]);
            DB::table('branches')->where('id', $main)->update(['is_active' => true]);
        });
    }

    public function down(): void
    {
        // Data merge: the original branch of each record is not kept, so this cannot be undone.
    }
};
