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

# Create default super admin if none exists
php artisan tinker --execute '
if (\App\Models\User::where("user_type", "staff")->count() === 0) {
    $u = \App\Models\User::create([
        "name" => "Super Admin",
        "email" => "admin@shwac.test",
        "phone" => "9000000001",
        "password" => "Password@123",
        "user_type" => "staff"
    ]);
    $u->assignRole("super_admin");
}
' || true

# Cache config and routes for optimal performance
php artisan config:cache || true
php artisan route:cache || true

exec "$@"
