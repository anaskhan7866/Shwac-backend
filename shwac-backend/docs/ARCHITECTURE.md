# SHWAC Service Platform — Architecture & Build Plan

Shree Hargangeshwar Water Care (SHWAC): service management for water purifiers, home appliances and solar, built for India (GST, UPI, WhatsApp).

> **Cost policy (20 Oct 2026):** no running cost except domain, Linux hosting and SMS. Paid services (WhatsApp Cloud API, payment gateway, Google Maps APIs) are switched off and kept as later-stage options. See `docs/RUNNING-COSTS.md` and `docs/HOSTING.md`.

---

## 1. Scope review — gaps worth deciding early

The 7-module scope is solid. These items are implied by it but not written down; each one changes the schema or a vendor contract, so settle them before the phase that needs them.

| # | Gap | Why it matters | Phase |
|---|-----|----------------|-------|
| 1 | **Credit notes / returns** | GST law does not allow editing an issued invoice. Cancellations, refunds and returned parts need credit notes. | 3 |
| 2 | **E-invoicing (IRN/QR)** | Mandatory once aggregate turnover crosses ₹5 Cr. Design invoices so an IRN can be attached later. | 3 |
| 3 | **SMS DLT registration** | Every commercial SMS in India needs a DLT-registered sender ID and template. Takes 1–3 weeks. | 1 (start now) |
| 4 | **WhatsApp template approval** | Only needed if automatic sending (paid Cloud API) is turned on later. The default click-to-send mode needs no approval. | Later stage |
| 5 | **Technician commissions / incentives** | Feedback and ratings are meant to drive performance; payout rules aren't defined. | 4 |
| 6 | **Warehouse ↔ van stock** | Technicians carry spare parts. Stock must move warehouse → technician van → consumed at job, or "stock reduces as parts are used" can't be tracked per person. | 2 |
| 7 | **Multi-GSTIN branches** | If branches are in different states, each needs its own GSTIN and invoice series. Schema supports this from Phase 1. | 1 ✅ |
| 8 | **Data protection (DPDP Act 2023)** | Customer phone/address/location consent, retention and deletion requests. Live location of staff needs written consent. | 1 |
| 9 | **TDS on vendor bills** | Vendor bill clearance (Module 4) may need TDS deduction under 194C/194J. | 3 |
| 10 | **iOS** | Scope says Android for technicians. Flutter gives iOS for free if needed; customer app should ship on both. | 2 |

---

## 2. Tech stack

| Layer | Choice | Notes |
|---|---|---|
| Backend API | **Laravel 13 (PHP 8.3+)** | REST JSON API at `/api/v1`, token auth via **Sanctum** |
| Database | **MySQL 8** (prod) / SQLite (tests) | `decimal(12,2)` money, all math done in paise (integers) |
| Queue / cache | **Database** queue & cache + cron | No Redis/Supervisor needed; `schedule:run` and `queue:work --stop-when-empty` from cron |
| Scheduler | Laravel scheduler (cron every minute) | SLA breach scan, AMC renewal reminders, low-stock alerts |
| Admin panel | **Filament 4** on the same Laravel app *(Phase 1b)* | Fastest route to a full RBAC admin with tables, filters and forms |
| Customer website | Laravel Blade + Livewire or Next.js | SEO-friendly catalog + booking; same API |
| Mobile apps | **Flutter** (Technician app, Customer app) | Offline-first: Drift (SQLite) local DB + sync queue |
| Maps & location | Phone/browser GPS · Google Maps **URLs** for navigation · Leaflet + OpenStreetMap for the admin map | All free, no API keys. Route order is computed on our server; address coordinates come from the technician's GPS |
| Payments | **UPI direct** (UPI app intent + QR) · cash · customer-reported UTR verified by office | Free. Razorpay kept as optional (`PAYMENT_GATEWAY=razorpay`) |
| WhatsApp | **Click-to-send `wa.me` links** from staff/technician's own WhatsApp | Free. Meta Cloud API kept as optional (`WHATSAPP_MODE=cloud`) |
| SMS | Any DLT provider via generic HTTP driver | Customer login OTP (the only paid message channel) |
| PDFs | Browser print / save as PDF from the invoice page; dompdf later | Free |
| Storage | Server disk (`storage/app/public`) | Job photos, receipts, signatures; back up nightly |
| Hosting | Your Linux server (VPS 2 vCPU / 4 GB, or cPanel with SSH) + MySQL + free Let's Encrypt SSL | Manual deploy steps in `docs/HOSTING.md`; APKs downloadable from `/apps` |

### Why one Laravel app
A single codebase serves the API, the admin panel and webhooks. Module boundaries live in folders (`app/Services/*`, `app/Http/Controllers/Api/V1/{Admin,Customer,Technician}`), not in separate services. That keeps one database transaction around "complete job → issue invoice → reduce stock → post ledger entries", which matters for accounting correctness.

---

## 3. System shape

```
 Customer web/app ─┐
 Technician app ───┼──►  Laravel API (/api/v1, Sanctum)  ──►  MySQL
 Admin (Filament) ─┘        │        │          │
                            │        │          └──► wa.me links (staff tap Send)   SMS OTP (DLT)
                            │        └──► Public invoice page /i/{token} + UPI QR
 Cron: schedule:run ────────┘     Scheduler: SLA scan · AMC renewals · low stock
```

### Roles (RBAC)

| Role | Sees | Can do |
|---|---|---|
| Super Admin | Everything, all branches | Everything incl. settings, users, taxes |
| Branch Manager | Own branch | Customers, bookings, jobs, complaints, invoices, staff |
| Accountant | All branches (finance) | Invoices, payments, ledgers, reports |
| Operator | Own branch | Customers, bookings, complaints, assigning jobs |
| Technician | Own jobs | Job status, on-site billing, expenses, attendance |
| Customer | Own records | Book, complain, pay, view history/AMC/warranty |
| Vendor / Dealer / Distributor *(Phase 3)* | Own records | POs, stock, bills, assigned jobs |

Permissions are strings (`invoices.manage`, `complaints.view` …) attached to roles, so new roles can be added from the admin without code changes. `branches.all` lifts the branch filter.

---

## 4. Database schema

✅ = built in Phase 1 (this delivery). Everything else is the planned shape.

### 4.1 Core & access
| Table | Key columns |
|---|---|
| ✅ `branches` | name, code, gstin, state, state_code, address, phone, invoice_prefix |
| ✅ `users` | name, email, phone, password, user_type (staff/technician/customer/vendor), branch_id, is_active |
| ✅ `roles`, `permissions`, `role_user`, `permission_role` | slug-based RBAC |
| ✅ `settings` | key, value (json) — company profile, GST state, branding, defaults |
| ✅ `number_sequences` | key, financial_year, prefix, next_number — gap-free GST numbering per FY |
| ✅ `otp_codes` | phone, code_hash, expires_at, attempts |
| `audit_logs` | user, action, subject, before/after json |

### 4.2 Customers & catalog
| Table | Key columns |
|---|---|
| ✅ `customers` | code, branch_id, user_id, name, phone, whatsapp, email, gstin, state_code |
| ✅ `customer_addresses` | line1, city, state_code, pincode, lat, lng, is_default |
| ✅ `customer_products` | product_name, brand, model, serial_no, purchase/install date, warranty_until |
| ✅ `service_categories` | name, slug |
| ✅ `services` | name, code, base_price, tax_rate, sac_code, duration_minutes, **form_schema (json)** for dynamic booking forms |

### 4.3 Operations
| Table | Key columns |
|---|---|
| ✅ `bookings` | number, customer, address, service, scheduled_for, status, **form_data (json)**, source |
| ✅ `service_jobs` | number, type, booking_id / complaint_id, technician_id, status, scheduled/assigned/started/completed timestamps |
| ✅ `job_status_logs` | from → to, user, lat/lng, note |
| ✅ `complaints` | number, priority, status, assigned_to, **sla_response_due_at, sla_resolution_due_at**, first_response_at, resolved_at, sla_breached |
| ✅ `complaint_updates` | body, type (comment/status), is_internal |
| ✅ `sla_policies` | priority, response_minutes, resolution_minutes |
| ✅ `job_attachments` | before/after/other photos and the customer signature |
| `feedback` | job_id, rating 1–5, comment, technician_id |
| ✅ `attendances` | user_id, date, check_in/out time + lat/lng, worked_minutes |
| ✅ `location_pings` | user_id, lat, lng, accuracy, battery, recorded_at (partition by month at scale) |
| ✅ `expenses` | user_id, category, amount, receipt, status (pending/approved/rejected), reviewer |
| ✅ `idempotency_keys` | user_id, key, route, stored response — offline replay safety |

### 4.4 Billing & payments
| Table | Key columns |
|---|---|
| ✅ `invoices` | number, branch, customer, job, invoice_date, place_of_supply, supply_type (intra/inter), subtotal, discount, taxable_value, cgst, sgst, igst, round_off, total, amount_paid, balance_due, status |
| ✅ `invoice_items` | type (service/part), hsn_sac, qty, unit_price, discount, taxable_value, tax_rate, cgst/sgst/igst rate+amount, total |
| ✅ `payments` | number, invoice, method (cash/upi/card/razorpay/bank), status, gateway ids, paid_at |
| ✅ `payment_links` | gateway link id, short_url, amount, status, expires_at |
| `credit_notes`, `credit_note_items` | against invoice, same GST breakup |

### 4.5 Messaging
| Table | Key columns |
|---|---|
| ✅ `notification_templates` | event, channel, provider_template, language, variables (ordered), is_active |
| ✅ `notification_logs` | channel, to, event, payload, status (queued/sent/delivered/read/failed), provider_message_id |

### 4.6 Inventory (Phase 2)
| Table | Key columns |
|---|---|
| ✅ `products` (spare parts & goods) | sku, name, hsn, unit, tax_rate, mrp, sale_price, purchase_price, barcode, reorder_level, van_min_level, warranty_months |
| ✅ `warehouses` | branch_id, name, type (main/van), user_id (van owner) |
| ✅ `stock_levels` | product × warehouse → qty (row-locked on update) |
| ✅ `stock_movements` | product, from/to warehouse, qty, reason (purchase/transfer/consumed/returned/adjustment), reference (invoice), unit_cost |
| ✅ `stock_alerts` | product, warehouse, qty, threshold, open/resolved |
| `serial_numbers` *(later)* | product, serial, status, warehouse, sold_on_invoice |

### 4.7 Supply chain (Phase 3)
| Table | Key columns |
|---|---|
| `partners` | type (vendor/distributor/dealer), parent_id (Company → Distributor → Dealer), gstin, pan, credit_limit, credit_days |
| `partner_kyc_documents` | doc_type, file, verified_by, status |
| `price_tiers`, `price_tier_slabs` | per-dealer discount %, min qty, margin |
| `purchase_orders`, `purchase_order_items` | partner, status (draft/approved/dispatched/received), totals |
| `vendor_bills` | partner, job_ids, amount, TDS, status (pending/approved/paid) |

### 4.8 Accounting (Phase 3)
Double-entry. Every business event posts a balanced journal; reports are queries over `journal_lines`.

| Table | Key columns |
|---|---|
| `accounts` | code, name, type (asset/liability/equity/income/expense), parent_id, party (customer/vendor) |
| `journal_entries` | date, number, source (invoice/payment/expense/bill), source_id |
| `journal_lines` | account_id, debit, credit, branch_id |

Auto-posting examples: issued invoice → Dr Customer, Cr Service Income, Cr Output CGST/SGST/IGST. Payment → Dr Bank/Cash/Razorpay Clearing, Cr Customer.

### 4.9 AMC & warranty (Phase 4)
| Table | Key columns |
|---|---|
| `amc_plans` | name, duration_months, visits_included, price, covers (json) |
| `amc_contracts` | customer_product, plan, start/end, visits_used, status, renewal_reminded_at |
| `amc_visits` | contract, scheduled_for, job_id |
| `warranties` | subject (customer_product / part serial), type (warranty/guarantee), starts_on, ends_on, terms |

---

## 5. GST rules implemented

- **Place of supply** = customer address state (B2C service at customer premises). Branch state = supplier state.
- Same state → **CGST + SGST** (rate split equally). Different state → **IGST** (full rate).
- Line amounts rounded to paise; invoice total rounded to the nearest rupee with a visible **round-off** line.
- **Invoice numbers** are sequential per branch per financial year (April–March), max 16 characters, e.g. `SHW/2627/00001`. Generated inside the DB transaction with a row lock, so no gaps or duplicates.
- Issued invoices are immutable; corrections go through credit notes (Phase 3).
- SAC 998719 (maintenance/repair of other goods) is the default for service lines; HSN per spare part.

---

## 6. WhatsApp message texts (editable; Meta approval only if the paid Cloud API is used later)

| Event key | Variables |
|---|---|
| `booking_confirmed` | name, booking no, service, date/slot |
| `technician_assigned` | name, technician name, phone, date |
| `complaint_registered` | name, complaint no, expected resolution |
| `technician_on_the_way` | name, technician name, phone |
| `invoice_issued` | name, invoice no, amount, due date, public invoice/UPI link |
| `payment_received` | name, amount, invoice no, receipt no, link |
| `payment_reminder` | name, invoice no, amount due, due date, link |

Login OTP goes by SMS, not WhatsApp.

Phase 4 adds `amc_renewal_due`, `feedback_request`, `warranty_expiring`.

---

## 7. Phased build plan

| Phase | Weeks | Deliverables |
|---|---|---|
| **1 — Core API** ✅ *(this delivery)* | 1–4 | Auth (staff password, customer WhatsApp OTP), RBAC + branch scoping, branches, customers/addresses/products, dynamic service catalog, bookings, service jobs + status log, complaints with auto-assign + SLA timers, GST invoicing, payments (cash/UPI/Razorpay orders + payment links + webhook), WhatsApp Cloud API notifications + webhook, settings, dashboard summary, tests |
| **1b — Admin panel** | 4–6 | Filament admin over the Phase 1 models, role-aware menus, live dashboard widgets |
| **2 — Field & inventory** ✅ *(+ zero-cost rework: click-to-send WhatsApp, SMS OTP, UPI direct)* *(backend + both Flutter apps delivered; admin screens in 1b)* | 6–11 | Flutter technician app (job alerts via FCM, navigation, offline sync, on-site billing with parts, photos/signature), attendance + live location + route ordering, expenses, products/warehouses/van stock, barcode/QR scanning, low-stock alerts, customer web booking site |
| **3 — Supply chain & accounting** | 11–17 | Vendors/distributors/dealers + KYC, hierarchy, tiered pricing, POs, credit limits, vendor bills; double-entry ledger with auto-posting, P&L, balance sheet, GST summaries (GSTR-1 / 3B export), receivables/payables ageing, credit notes |
| **4 — Value-adds** | 17–21 | AMC plans/contracts/visits with renewal automation, warranty register, feedback & ratings → technician scorecards, customer Flutter app, WhatsApp chatbot for booking/complaint intake |
| **5 — Hardening** | 21–24 | Load testing, backups/DR, audit logs, DPDP consent flows, Play Store release, staff training |

---

## 8. API conventions

- Base: `/api/v1`, JSON only, `Authorization: Bearer <sanctum token>`.
- Three audiences under one API: `/admin/*` (staff, permission-checked), `/customer/*` (customer tokens), `/technician/*` (technician tokens). Public: `/catalog/*`, `/auth/*`, `/webhooks/*`.
- Pagination: `?page=&per_page=` (max 100). Filters: `?status=&branch_id=&from=&to=&q=`.
- Money in responses: decimal strings (`"1180.00"`) to avoid float drift in clients.
- Mobile offline sync (Phase 2): every job mutation carries a client-generated `client_uuid` so replays are idempotent.

See `docs/API.md` for the Phase 1 endpoint list.
