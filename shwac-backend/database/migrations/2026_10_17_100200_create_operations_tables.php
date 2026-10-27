<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookings, service jobs, complaints, SLA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('customer_address_id')->constrained();
            $table->foreignId('service_id')->constrained();
            $table->foreignId('customer_product_id')->nullable()->constrained()->nullOnDelete();
            $table->date('scheduled_date');
            $table->string('time_slot', 20)->nullable(); // "10:00-12:00"
            $table->string('status', 20)->default('pending')->index(); // pending|confirmed|scheduled|completed|cancelled
            $table->string('source', 20)->default('web');             // web|app|admin|whatsapp|phone
            $table->json('form_data')->nullable();
            $table->decimal('quoted_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('priority', 10)->unique(); // low|normal|high|critical
            $table->unsignedInteger('response_minutes');
            $table->unsignedInteger('resolution_minutes');
            $table->timestamps();
        });

        Schema::create('complaints', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('customer_address_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 40)->default('general'); // leakage|no_water|taste|noise|electrical|general
            $table->string('priority', 10)->default('normal');
            $table->string('status', 20)->default('open')->index(); // open|assigned|in_progress|resolved|closed|reopened
            $table->string('channel', 20)->default('web');
            $table->string('subject');
            $table->text('description')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('sla_response_due_at')->nullable()->index();
            $table->timestamp('sla_resolution_due_at')->nullable()->index();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->boolean('sla_breached')->default(false)->index();
            $table->timestamp('sla_breach_notified_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('complaint_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('complaint_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('comment'); // comment|status|assignment|system
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('service_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('customer_address_id')->constrained();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('complaint_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20)->default('service'); // installation|service|repair|amc_visit|inspection
            $table->string('status', 20)->default('pending')->index(); // pending|assigned|accepted|en_route|in_progress|completed|cancelled
            $table->date('scheduled_date')->nullable();
            $table->string('time_slot', 20)->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('work_summary')->nullable();
            $table->timestamps();
            $table->index(['technician_id', 'status']);
        });

        Schema::create('job_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['job_status_logs', 'service_jobs', 'complaint_updates', 'complaints', 'sla_policies', 'bookings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
