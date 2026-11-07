#!/bin/bash
set -e

# Auto-detect PostgreSQL when DATABASE_URL is set
if [ -n "$DATABASE_URL" ] && [ -z "$DB_CONNECTION" ]; then
    export DB_CONNECTION=pgsql
fi

# Ensure SQLite file exists if using sqlite
if [ "$DB_CONNECTION" = "sqlite" ] || [ -z "$DB_CONNECTION" ]; then
    mkdir -p /var/www/html/database
    touch /var/www/html/database/database.sqlite
    chown -R www-data:www-data /var/www/html/database
fi

# Ensure storage link exists
php artisan storage:link --force || true

# Run database migrations and seed system defaults
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan db:seed --class=SystemSeeder --force

# Seed demo catalog data if categories are empty
php artisan tinker --execute '
if (\App\Models\ServiceCategory::count() === 0) {
    (new \Database\Seeders\DemoSeeder())->run();
}
' || true

# Create a first super admin if none exists. The password comes from INITIAL_ADMIN_PASSWORD;
# without it a random one is generated and printed once in the deploy log (never a fixed default).
php artisan tinker --execute '
if (\App\Models\User::where("user_type", "staff")->count() === 0) {
    $pw = getenv("INITIAL_ADMIN_PASSWORD") ?: \Illuminate\Support\Str::password(16);
    $u = \App\Models\User::create([
        "name" => "Super Admin",
        "email" => getenv("INITIAL_ADMIN_EMAIL") ?: "admin@shwac.test",
        "password" => $pw,
        "user_type" => "staff"
    ]);
    $u->assignRole("super_admin");
    if (! getenv("INITIAL_ADMIN_PASSWORD")) { echo "First Super Admin password (change it after login): ".$pw.PHP_EOL; }
}
' || true

# One-time "clean for the client" (see routes/console.php). Runs only when RESET_DEMO_DATA is set,
# and only once per value of it, so leaving the variable set never wipes data again.
if [ -n "$RESET_DEMO_DATA" ]; then
    php artisan shwac:reset-demo-data --force || echo "Demo reset did not run (see the message above). Nothing was changed."
fi

# Cache config and routes for optimal performance
php artisan config:cache || true
php artisan route:cache || true

# Configure Apache port dynamically from $PORT (Render sets PORT=10000, defaults to 80)
APACHE_PORT="${PORT:-80}"
sed -i "s/Listen .*/Listen ${APACHE_PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:.*>/<VirtualHost *:${APACHE_PORT}>/" /etc/apache2/sites-available/000-default.conf

# Scheduled tasks (routes/console.php: complaint SLA check every 5 min, token pruning daily).
# Render runs only this web container, so the scheduler runs here in the background, as the
# web user (so files it writes stay writable by Apache). Output goes to the container log.
# Set RUN_SCHEDULER=false to turn it off (e.g. if a separate Render cron job is added later).
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
if [ "${RUN_SCHEDULER:-true}" != "false" ]; then
    su -s /bin/sh www-data -c "cd /var/www/html && php artisan schedule:work" >> /proc/1/fd/1 2>&1 &
    echo "Scheduler started (php artisan schedule:work)."
fi

exec "$@"
