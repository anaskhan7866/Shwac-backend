<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Field operations: attendance, live location, expenses, job photos/signatures,
 * and idempotency keys so offline actions replayed by the apps never apply twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->timestamp('check_in_at');
            $table->decimal('check_in_lat', 10, 7)->nullable();
            $table->decimal('check_in_lng', 10, 7)->nullable();
            $table->string('check_in_address')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->decimal('check_out_lat', 10, 7)->nullable();
            $table->decimal('check_out_lng', 10, 7)->nullable();
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });

        Schema::create('location_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->unsignedTinyInteger('battery')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'recorded_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('location_consent_at')->nullable();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_job_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('category', 30); // fuel|travel|food|parking|parts_local|other
            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('status', 10)->default('pending')->index(); // pending|approved|rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();
        });

        Schema::create('job_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // before|after|other|signature
            $table->string('path');
            $table->string('caption')->nullable();
            $table->timestamps();
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('route', 120);
            $table->unsignedSmallInteger('status_code');
            $table->longText('response');
            $table->timestamp('created_at')->nullable()->index();
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        foreach (['idempotency_keys', 'job_attachments', 'expenses', 'location_pings', 'attendances'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_lat', 'last_lng', 'last_seen_at', 'location_consent_at']);
        });
    }
};
