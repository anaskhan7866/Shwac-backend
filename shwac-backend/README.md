# SHWAC Service Platform — Backend API (Phase 1 + Phase 2)

> **Zero running cost by default:** only your domain, Linux hosting and SMS (for login OTP) cost money.
> - **WhatsApp:** click-to-send links that staff open in their own WhatsApp.
> - **Payments:** UPI direct to your UPI ID, with a QR and a reference check by the office.
> - **Location:** phone GPS, Google Maps links and OpenStreetMap, all free.
> - **Optional later:** paid automation (WhatsApp Cloud API, Razorpay) stays in the code, switched off.
>
> → `docs/RUNNING-COSTS.md` (full review) · `docs/HOSTING.md` (server setup)

Laravel 13 API for **Shree Hargangeshwar Water Care**: admin panel, customer website/app and technician app all talk to this one backend.

- Architecture, full database schema, scope gaps and the phased roadmap: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- Endpoint reference: [`docs/API.md`](docs/API.md)

## What Phase 1 includes

| Area | Built |
|---|---|
| Auth | Staff/technician password login, customer **WhatsApp OTP** login, Sanctum tokens, login & OTP rate limits |
| RBAC | Roles: Super Admin, Branch Manager, Accountant, Operator, Technician, Customer. Permission strings editable per role. Branch scoping (staff only see their branch unless `branches.all`). Managers can't escalate privileges. |
| Customers | Customers, multiple addresses (with GST state code + lat/lng), registered products with warranty date |
| Catalog | Service categories & services with price, GST rate, SAC, and a **dynamic booking form** (`form_schema`) validated server-side |
| Bookings → Jobs | Booking auto-creates a service job; assign technician; job state machine with GPS-stamped status log |
| Complaints | Auto-assign to least-loaded technician in branch, response + resolution **SLA timers**, breach scanner (scheduled), internal vs public notes, reopen, job completion auto-resolves |
| GST invoicing | CGST+SGST vs IGST by place of supply, paise-exact math, round-off, gap-free numbering per branch per FY (`SHW/2627/00001`), drafts editable, issued invoices immutable, printable tax invoice |
| Payments | Cash/UPI/bank/cheque recording, partial payments, **Razorpay Orders + Payment Links**, checkout signature verification, idempotent **webhook**, UPI intent link, receipts (`RCT/HQ/2627/00001`) |
| WhatsApp | **Meta Cloud API** template sender (queued, retried, logged), delivery-status webhook with signature check, admin-editable event → template mapping |
| Dashboard | Bookings, live jobs, complaints/SLA, collections, receivables, overdue (finance figures only for `reports.finance`) |
| Settings | Company profile, branding, tax defaults, complaint categories, time slots, SLA policies |

### Phase 2 additions

| Area | Built |
|---|---|
| Inventory | Products/spare parts (HSN, GST, barcode, reorder levels), warehouses incl. **one van per technician**, receive / transfer (store ↔ van) / stock-count adjust, append-only movement ledger, barcode/QR lookup |
| Stock on invoices | Parts billed on an invoice leave the van (technician) or the branch store (office) **when the invoice is issued**; a shortage blocks the issue; cancelling returns them |
| Low-stock alerts | Opened automatically when a store falls to its reorder level or a van to its minimum; resolved when restocked; shown on the dashboard |
| Attendance & location | GPS check-in/out (after a one-time consent), batched location pings, live technician map data, day trail for route replay |
| Route order | Technician's jobs sorted nearest-first from their position, with km per stop |
| Expenses | Claims with bill photo, manager approval (no self-approval), approved totals on the dashboard |
| Job evidence | Before/after photos and one customer signature per job |
| Offline sync | `Idempotency-Key` on all customer/technician writes: replays return the stored response instead of applying twice |
| Customer pay link | Hosted Razorpay page for the Android and web apps |

36 automated tests cover GST math, RBAC/branch isolation, the full booking → billing (from van stock) → Razorpay → WhatsApp flow, SLA breaches, webhooks, inventory, attendance, route order, idempotent replays, expenses and uploads.

The Flutter apps (technician + customer, Android APK + web) are in the separate `shwac-apps` package.

## Setup

Requirements: PHP 8.3+, Composer, MySQL 8 (or SQLite for a quick try), Redis recommended for queues.

```bash
composer install
cp .env.example .env
php artisan key:generate

# MySQL: set DB_CONNECTION=mysql and DB_* in .env.  Quick try: keep sqlite and run `touch database/database.sqlite`
php artisan migrate --seed          # roles, permissions, SLA policies, WhatsApp templates (+ demo data in local)
php artisan serve
php artisan test                    # run the test suite
```

Run `php artisan storage:link` so job photos and receipts get public URLs.

Demo logins (local only, password `Password@123`): `admin@shwac.test`, `manager@shwac.test`, `accounts@shwac.test`, `operator@shwac.test`, technicians `tech1@shwac.test` / `tech2@shwac.test`. Customer OTP login: `9876543210` — in `APP_ENV=local` the OTP is returned in the response.

### Production checklist
- `APP_ENV=production`, `APP_DEBUG=false`, `OTP_EXPOSE=false`
- `QUEUE_CONNECTION=database` (no Redis needed). The cron lines in `docs/HOSTING.md` run the scheduler and the queue.
- `php artisan storage:link` so photos and receipts are reachable
- Timezone defaults to `Asia/Kolkata` (invoice dates and financial year depend on it)

## Integrations

### WhatsApp: free click-to-send (default, `WHATSAPP_MODE=link`)
Each event prepares a message with a `wa.me` link. Nothing is sent automatically.
- **Office:** `GET /api/v1/admin/whatsapp/outbox` lists the messages waiting. Tapping one opens WhatsApp or WhatsApp Web (new tab) with the text filled in; press Send, then `POST /whatsapp/{id}/sent`.
- **Technician app:** the job screen shows the same messages with a **WhatsApp** button, and offers to send right after billing or collecting.
- **Message texts:** edit them with `POST /admin/notification-templates` (`body` with `{customer_name}`, `{invoice_number}`, `{amount}`, `{link}` …).
- **Business number:** use the WhatsApp Business app on the office phone so messages come from your business number.

### SMS OTP
Set `SMS_DRIVER=http` and `SMS_HTTP_URL` with your provider's API, using the placeholders `{phone}`, `{phone10}`, `{message}` and `{otp}`. Register the exact `SMS_OTP_TEXT` wording as a DLT template.

### UPI payments (default, `PAYMENT_GATEWAY=none`)
1. Set `UPI_VPA`. Every issued invoice gets a public page `https://yourdomain/i/<token>` with the GST invoice, a "Pay with a UPI app" button, a QR code and print-to-PDF. The WhatsApp invoice message contains this link.
2. The customer pays, then enters the UPI reference (UTR) on that page or in the app.
3. The office checks the bank/UPI app and confirms with `POST /admin/payments/{id}/verify` (approve or reject with a note). The receipt message is then prepared.
4. The dashboard `todo` block shows how many messages and payments are waiting.

### Optional later: Meta WhatsApp Cloud API (`WHATSAPP_MODE=cloud`, paid per message)
1. In Meta Business Manager create a WhatsApp Business app, add your number, generate a **permanent system-user token**.
2. Submit the templates listed in `database/seeders/SystemSeeder.php` (names `shwac_*`). `otp_login` must be an *Authentication* template; `invoice_issued` should have a **URL button** with base `https://rzp.io/` and a dynamic suffix.
3. `.env`: `WHATSAPP_ENABLED=true`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN`.
4. Webhook URL: `https://<your-domain>/api/v1/webhooks/whatsapp` (subscribe to `messages`).



### Optional later: Razorpay (`PAYMENT_GATEWAY=razorpay`, ≈2% per payment)
1. `.env`: `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`.
2. Dashboard → Webhooks: `https://<your-domain>/api/v1/webhooks/razorpay`, events `payment.captured`, `order.paid`, `payment_link.paid`.
3. Optional plain UPI: `UPI_VPA` gives a `upi://pay` link on unpaid invoices (manual reconciliation — staff enter the UTR).

## Code map

```
app/
  Services/
    Billing/GstCalculator.php      GST engine (paise math, CGST/SGST/IGST, round-off)
    Billing/InvoiceService.php     draft → issue (numbering, pay link, WhatsApp) → cancel
    Payments/PaymentService.php    manual, Razorpay orders/links, webhook reconciliation
    Payments/RazorpayClient.php
    Messaging/WhatsAppClient.php   Meta Cloud API
    Messaging/Notifier.php         event → template → queued send + log
    Operations/BookingService.php  dynamic form validation, booking → job
    Operations/JobService.php      assignment + job state machine
    Operations/ComplaintService.php  SLA, auto-assign, status flow, breach scan
    Auth/OtpService.php
    Inventory/InventoryService.php stock ledger, van stock, invoice consumption, low-stock alerts
    NumberGenerator.php            FY-aware gap-free document numbers
  Http/Controllers/Api/V1/{Admin,Customer,Technician,Webhooks}
  Models/Concerns/BranchScoped.php
config/shwac.php                   permission catalog, default role mapping
routes/api.php                     all endpoints (/api/v1)
```

## Next
Admin panel: done, at `/admin` (see `docs/ADMIN-PANEL.md`) · Phase 3: vendors/dealers, accounting & GST reports. Follow-ups for Phase 2: background location (Android foreground service) and instant push alerts (Firebase). See `docs/ARCHITECTURE.md`.
