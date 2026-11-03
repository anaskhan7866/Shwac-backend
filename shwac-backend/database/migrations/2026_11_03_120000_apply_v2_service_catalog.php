<?php

use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V2: move an existing (live) catalog to the two categories Home Appliances + Solar.
 * Runs once. An empty database (fresh install / tests) is left alone: the demo seeder
 * builds the same catalog there. Services already used by bookings, jobs or invoices
 * are kept (inactive) so history stays intact; see ServiceCatalogSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('service_categories')->exists()) {
            return;
        }
        (new ServiceCatalogSeeder)->run();
    }

    public function down(): void
    {
        // Data migration: the old catalog is not restored automatically.
    }
};
