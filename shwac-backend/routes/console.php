<?php

use App\Services\Operations\ComplaintService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('complaints:check-sla', function (ComplaintService $complaints) {
    $breached = $complaints->scanSlaBreaches();
    $this->info("Flagged {$breached->count()} complaint(s) as SLA breached.");
})->purpose('Flag open complaints that have missed their response or resolution SLA');

// Server cron:  * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('complaints:check-sla')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
