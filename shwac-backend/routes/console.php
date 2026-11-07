<?php

use App\Services\Operations\ComplaintService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('complaints:check-sla', function (ComplaintService $complaints) {
    $breached = $complaints->scanSlaBreaches();
    $this->info("Flagged {$breached->count()} complaint(s) as SLA breached.");
})->purpose('Flag open complaints that have missed their response or resolution SLA');

// One-time "clean for the client": removes test data, keeps catalog/settings/stock, loads dummy demo data.
// Token + passwords come from the environment (RESET_DEMO_DATA, DEMO_ADMIN_PASSWORD, DEMO_TECH_PASSWORD,
// optional DEMO_ADMIN_EMAIL). The same token never runs twice. See App\Services\DemoDataReset.
Artisan::command('shwac:reset-demo-data {--token= : overrides RESET_DEMO_DATA} {--force : required on production}', function (\App\Services\DemoDataReset $reset) {
    if (app()->isProduction() && ! $this->option('force')) {
        $this->error('This deletes live data. Re-run with --force.');

        return 1;
    }
    $env = fn (string $k, string $d = '') => (string) (getenv($k) !== false ? getenv($k) : $d);
    try {
        $result = $reset->run((string) ($this->option('token') ?: $env('RESET_DEMO_DATA')), $env('DEMO_ADMIN_EMAIL', 'admin@shwac.test'),
            $env('DEMO_ADMIN_PASSWORD'), $env('DEMO_TECH_PASSWORD'));
    } catch (\InvalidArgumentException $e) {
        $this->error('Demo reset NOT run: '.$e->getMessage());

        return 1;
    }
    $this->info($result['message']);

    return 0;
})->purpose('One-time: remove test data and load dummy demo data for the client');

// Server cron:  * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('complaints:check-sla')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
