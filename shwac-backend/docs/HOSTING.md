# Hosting SHWAC on your Linux server (no paid services)

Tested layout: Ubuntu 22.04/24.04 VPS with Nginx, PHP 8.3, MySQL 8. cPanel hosting works too (skip the Nginx part; point the domain's document root to `public/`).

## 1. Server packages
```bash
sudo apt update
sudo apt install -y nginx mysql-server unzip git \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
```

## 2. Database
```sql
CREATE DATABASE shwac CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'shwac'@'localhost' IDENTIFIED BY 'a-strong-password';
GRANT ALL ON shwac.* TO 'shwac'@'localhost';
```

## 3. App
```bash
sudo mkdir -p /var/www/shwac && sudo chown $USER /var/www/shwac
cd /var/www/shwac && unzip ~/shwac-backend.zip && mv shwac-backend/* shwac-backend/.[!.]* . && rmdir shwac-backend
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
```
Edit `.env`:
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.shwac.in          # used in WhatsApp invoice links
DB_CONNECTION=mysql
DB_DATABASE=shwac
DB_USERNAME=shwac
DB_PASSWORD=a-strong-password
QUEUE_CONNECTION=database
OTP_EXPOSE=false
WHATSAPP_MODE=link
PAYMENT_GATEWAY=none
UPI_VPA=yourbusiness@bank
UPI_PAYEE_NAME="Shree Hargangeshwar Water Care"
SMS_DRIVER=http
SMS_HTTP_URL="https://<your-provider>/...{phone}...{message}..."
```
Then:
```bash
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan db:seed --class=SystemSeeder --force
php artisan storage:link
php artisan config:cache && php artisan route:cache
sudo chown -R www-data:www-data storage bootstrap/cache
```
Create the first Super Admin:
```bash
php artisan tinker --execute '$u = App\Models\User::create(["name"=>"Owner","email"=>"you@shwac.in","phone"=>"9xxxxxxxxx","password"=>"ChangeMe123","user_type"=>"staff"]); $u->assignRole("super_admin");'
```
Then open **https://api.shwac.in/admin** and log in with that email and password. Add the other staff and technician logins from *Staff & roles* inside the panel (no more tinker needed).

## 4. Nginx + free SSL
`/etc/nginx/sites-available/shwac`:
```nginx
server {
    server_name api.shwac.in;
    root /var/www/shwac/public;
    index index.php;
    client_max_body_size 10M;            # photos & receipts
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
}
# Web apps (unzipped Flutter web builds)
server { server_name tech.shwac.in; root /var/www/web-technician; location / { try_files $uri /index.html; } }
server { server_name app.shwac.in;  root /var/www/web-customer;   location / { try_files $uri /index.html; } }
```
```bash
sudo ln -s /etc/nginx/sites-available/shwac /etc/nginx/sites-enabled/ && sudo nginx -t && sudo systemctl reload nginx
sudo apt install -y certbot python3-certbot-nginx && sudo certbot --nginx -d api.shwac.in -d tech.shwac.in -d app.shwac.in
```
HTTPS is required: browsers only allow GPS and the camera on https pages.

## 5. Cron (one line does everything)
`crontab -e` for the `www-data` user (or your cPanel cron):
```
* * * * * cd /var/www/shwac && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /var/www/shwac && php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
```
The first runs the SLA breach check every 5 minutes. The second processes background jobs without needing Redis or Supervisor.

## 6. Apps
- Build the APKs with GitHub Actions (see `shwac-apps/README.md`) using `API_BASE=https://api.shwac.in/api/v1`.
- Put the APKs in `/var/www/shwac/public/downloads/` and share **https://api.shwac.in/apps** with technicians and customers.
- Unzip the web builds into `/var/www/web-technician` and `/var/www/web-customer`.
- In `config/cors.php`, set `allowed_origins` to `['https://tech.shwac.in', 'https://app.shwac.in']`.

## 7. Backups (free)
```
30 2 * * * mysqldump shwac | gzip > /var/backups/shwac-$(date +\%a).sql.gz
45 2 * * * tar czf /var/backups/shwac-files-$(date +\%a).tgz -C /var/www/shwac storage/app/public
```
This keeps 7 rolling days on the server. Copy them off the server weekly, for example to Google Drive with `rclone` (free).

## 8. Updating
```bash
cd /var/www/shwac && php artisan down
# copy new files over (keep .env and storage/)
composer install --no-dev --optimize-autoloader && php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan up
```
