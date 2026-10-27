# API reference

Base URL `/api/v1`. Send `Accept: application/json` and `Authorization: Bearer <token>` (from `/auth/login` or `/auth/otp/verify`).

Errors: `422` validation (`{message, errors}`), `403` permission, `404` not found or outside your branch, `409` idempotency key reused. Lists are paginated (`?page=&per_page=`, max 100).

**Offline-safe writes:** customer and technician POSTs accept an `Idempotency-Key` header (UUID). A repeat returns the first response (`Idempotent-Replayed: true`) without applying it again.

**WhatsApp share objects:** actions that prepare a customer message (assign job, on the way, booking, complaint, issue invoice, payment) return `whatsapp: {id, event, label, to, message, url, sent}`. Open `url` (wa.me) to send; then mark it with `POST /admin/whatsapp/{id}/sent` or `POST /technician/messages/{id}/sent`.

## Public web pages (no login)

| Method | Path | Purpose |
|---|---|---|
| GET | `/i/{token}` | Invoice page sent on WhatsApp: GST invoice, UPI app button, UPI QR, print/PDF |
| POST | `/i/{token}/paid` | Customer reports a UPI reference (UTR) → payment waits for office verification |
| GET | `/i/{token}/pay-online` | Only with `PAYMENT_GATEWAY=razorpay`: redirect to the gateway page |
| GET | `/apps` | APK download page (files in `public/downloads/`) |

## Auth

| Method | Path | Permission |
|---|---|---|
| POST | `/api/v1/auth/login` |  |
| POST | `/api/v1/auth/logout` |  |
| GET | `/api/v1/auth/me` |  |
| POST | `/api/v1/auth/otp/request` |  |
| POST | `/api/v1/auth/otp/verify` |  |

## Public catalog

| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/catalog` |  |
| GET | `/api/v1/catalog/services/{service}` |  |

## Admin (staff)

| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/admin/bookings` | bookings.view |
| POST | `/api/v1/admin/bookings` | bookings.manage |
| GET | `/api/v1/admin/bookings/{booking}` | bookings.view |
| PATCH | `/api/v1/admin/bookings/{booking}` | bookings.manage |
| POST | `/api/v1/admin/bookings/{booking}/cancel` | bookings.manage |
| GET | `/api/v1/admin/branches` |  |
| POST | `/api/v1/admin/branches` | branches.manage |
| PATCH | `/api/v1/admin/branches/{branch}` | branches.manage |
| GET | `/api/v1/admin/catalog/categories` |  |
| POST | `/api/v1/admin/catalog/categories` | services.manage |
| PATCH | `/api/v1/admin/catalog/categories/{category}` | services.manage |
| GET | `/api/v1/admin/catalog/services` |  |
| POST | `/api/v1/admin/catalog/services` | services.manage |
| PATCH | `/api/v1/admin/catalog/services/{service}` | services.manage |
| GET | `/api/v1/admin/complaints` | complaints.view |
| POST | `/api/v1/admin/complaints` | complaints.manage |
| GET | `/api/v1/admin/complaints/{complaint}` | complaints.view |
| PATCH | `/api/v1/admin/complaints/{complaint}` | complaints.manage |
| POST | `/api/v1/admin/complaints/{complaint}/assign` | complaints.manage |
| POST | `/api/v1/admin/complaints/{complaint}/comments` | complaints.manage |
| POST | `/api/v1/admin/complaints/{complaint}/status` | complaints.manage |
| GET | `/api/v1/admin/customers` | customers.view |
| POST | `/api/v1/admin/customers` | customers.manage |
| GET | `/api/v1/admin/customers/{customer}` | customers.view |
| PATCH | `/api/v1/admin/customers/{customer}` | customers.manage |
| POST | `/api/v1/admin/customers/{customer}/addresses` | customers.manage |
| PATCH | `/api/v1/admin/customers/{customer}/addresses/{address}` | customers.manage |
| POST | `/api/v1/admin/customers/{customer}/products` | customers.manage |
| PATCH | `/api/v1/admin/customers/{customer}/products/{product}` | customers.manage |
| GET | `/api/v1/admin/dashboard` | dashboard.view |
| GET | `/api/v1/admin/expenses` | expenses.manage |
| POST | `/api/v1/admin/expenses/{expense}/review` | expenses.manage |
| GET | `/api/v1/admin/field/attendance` | field.view |
| GET | `/api/v1/admin/field/live` | field.view |
| GET | `/api/v1/admin/field/technicians/{user}/trail` | field.view |
| GET | `/api/v1/admin/invoices` | invoices.view |
| POST | `/api/v1/admin/invoices` | invoices.manage |
| GET | `/api/v1/admin/invoices/{invoice}` | invoices.view |
| PUT | `/api/v1/admin/invoices/{invoice}` | invoices.manage |
| POST | `/api/v1/admin/invoices/{invoice}/cancel` | invoices.manage |
| POST | `/api/v1/admin/invoices/{invoice}/issue` | invoices.manage |
| POST | `/api/v1/admin/invoices/{invoice}/payment-link` | payments.manage |
| GET | `/api/v1/admin/invoices/{invoice}/print` | invoices.view |
| POST | `/api/v1/admin/invoices/{invoice}/reminder` | payments.manage |
| GET | `/api/v1/admin/jobs` | jobs.view |
| GET | `/api/v1/admin/jobs/{job}` | jobs.view |
| POST | `/api/v1/admin/jobs/{job}/assign` | jobs.manage |
| POST | `/api/v1/admin/jobs/{job}/status` | jobs.manage |
| GET | `/api/v1/admin/notification-logs` | settings.manage |
| GET | `/api/v1/admin/notification-templates` | settings.manage |
| POST | `/api/v1/admin/notification-templates` | settings.manage |
| GET | `/api/v1/admin/payments` | payments.view |
| POST | `/api/v1/admin/payments` | payments.manage |
| POST | `/api/v1/admin/payments/{payment}/verify` | payments.manage |
| GET | `/api/v1/admin/products` | inventory.view |
| POST | `/api/v1/admin/products` | inventory.manage |
| GET | `/api/v1/admin/products/lookup` | inventory.view |
| PATCH | `/api/v1/admin/products/{product}` | inventory.manage |
| GET | `/api/v1/admin/roles` | users.manage |
| PUT | `/api/v1/admin/roles/{role}` | settings.manage |
| GET | `/api/v1/admin/settings` | settings.manage |
| PATCH | `/api/v1/admin/settings` | settings.manage |
| PUT | `/api/v1/admin/settings/sla` | settings.manage |
| GET | `/api/v1/admin/stock` | inventory.view |
| POST | `/api/v1/admin/stock/adjust` | inventory.manage |
| GET | `/api/v1/admin/stock/alerts` | inventory.view |
| GET | `/api/v1/admin/stock/movements` | inventory.view |
| POST | `/api/v1/admin/stock/receive` | inventory.manage |
| POST | `/api/v1/admin/stock/transfer` | inventory.manage |
| GET | `/api/v1/admin/technicians` | jobs.view |
| POST | `/api/v1/admin/technicians/{user}/van` | inventory.manage |
| GET | `/api/v1/admin/users` | users.manage |
| POST | `/api/v1/admin/users` | users.manage |
| PATCH | `/api/v1/admin/users/{user}` | users.manage |
| GET | `/api/v1/admin/warehouses` | inventory.view |
| POST | `/api/v1/admin/warehouses` | inventory.manage |
| POST | `/api/v1/admin/whatsapp/compose` | customers.view |
| GET | `/api/v1/admin/whatsapp/outbox` | dashboard.view |
| POST | `/api/v1/admin/whatsapp/{log}/sent` | dashboard.view |

## Customer app

| Method | Path | Permission |
|---|---|---|
| POST | `/api/v1/customer/addresses` |  |
| GET | `/api/v1/customer/bookings` |  |
| POST | `/api/v1/customer/bookings` |  |
| GET | `/api/v1/customer/bookings/{booking}` |  |
| POST | `/api/v1/customer/bookings/{booking}/cancel` |  |
| GET | `/api/v1/customer/complaints` |  |
| POST | `/api/v1/customer/complaints` |  |
| GET | `/api/v1/customer/complaints/{complaint}` |  |
| POST | `/api/v1/customer/complaints/{complaint}/comments` |  |
| GET | `/api/v1/customer/invoices` |  |
| GET | `/api/v1/customer/invoices/{invoice}` |  |
| POST | `/api/v1/customer/invoices/{invoice}/pay` |  |
| POST | `/api/v1/customer/invoices/{invoice}/payment-link` |  |
| POST | `/api/v1/customer/invoices/{invoice}/upi-claim` |  |
| POST | `/api/v1/customer/payments/confirm` |  |
| POST | `/api/v1/customer/products` |  |
| GET | `/api/v1/customer/profile` |  |
| PATCH | `/api/v1/customer/profile` |  |

## Technician app

| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/technician/attendance` |  |
| POST | `/api/v1/technician/attendance/check-in` |  |
| POST | `/api/v1/technician/attendance/check-out` |  |
| POST | `/api/v1/technician/consent` |  |
| GET | `/api/v1/technician/expenses` |  |
| POST | `/api/v1/technician/expenses` |  |
| GET | `/api/v1/technician/jobs` |  |
| GET | `/api/v1/technician/jobs/{job}` |  |
| POST | `/api/v1/technician/jobs/{job}/attachments` |  |
| POST | `/api/v1/technician/jobs/{job}/collect` |  |
| POST | `/api/v1/technician/jobs/{job}/invoice` |  |
| POST | `/api/v1/technician/jobs/{job}/status` |  |
| POST | `/api/v1/technician/location` |  |
| POST | `/api/v1/technician/messages/{log}/sent` |  |
| GET | `/api/v1/technician/parts/lookup` |  |
| GET | `/api/v1/technician/today` |  |
| GET | `/api/v1/technician/van` |  |

## Webhooks (only for optional paid integrations)

| Method | Path | Permission |
|---|---|---|
| POST | `/api/v1/webhooks/razorpay` |  |
| GET | `/api/v1/webhooks/whatsapp` |  |
| POST | `/api/v1/webhooks/whatsapp` |  |

## Examples

**Technician bills a job from van stock**
```json
POST /api/v1/technician/jobs/40/invoice
Idempotency-Key: 3f0c…
{"items": [{"service_id": 2, "quantity": 1}, {"product_id": 1, "quantity": 2}]}
```
Response includes `public_url` and `whatsapp` (invoice message with the link).

**Customer reports a UPI payment**
```json
POST /api/v1/customer/invoices/12/upi-claim
{"reference": "429815736201"}
```
**Office verifies it**
```json
POST /api/v1/admin/payments/55/verify
{"approve": true}            // or {"approve": false, "note": "Not in bank statement"}
```

**Payment reminder**: `POST /api/v1/admin/invoices/{id}/reminder` → share object with the reminder text and invoice link.

**Jobs in route order**: `GET /api/v1/technician/jobs?lat=28.98&lng=77.70` → `route_position`, `leg_km`, `route_total_km`.

Job status flow: `pending → assigned → accepted → en_route → in_progress → completed` (or `cancelled`).
Complaint status flow: `open → assigned → in_progress → resolved → closed`, with `reopened`.
Payment status: `success`, `pending_verification` (customer-reported UPI), `failed` (rejected), `pending` (gateway order).
