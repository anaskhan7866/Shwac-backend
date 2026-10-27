# Running costs: what SHWAC uses and what it costs

**Goal:** no running cost except **domain + Linux hosting + SMS**. Everything else is free, or switched off and kept ready for a later stage.

## What the platform uses now (default setup)

| Need | What we use | Cost |
|---|---|---|
| Server | Your Linux server (VPS or cPanel hosting with SSH): PHP 8.3, MySQL, cron | **Hosting** (you pay) |
| Domain + HTTPS | Your domain + free Let's Encrypt certificate | **Domain** (you pay); SSL free |
| Login OTP for customers | Your SMS provider's HTTP API (any DLT-registered provider: MSG91, Fast2SMS, Textlocal, 2Factor…) | **SMS** (you pay, ~₹0.15–0.25 per OTP) |
| WhatsApp messages (booking, technician details, on the way, invoice, receipt, reminder) | **Click-to-send links (wa.me).** The system prepares the message; staff or the technician tap "WhatsApp", their own WhatsApp / WhatsApp Business opens with the text filled in, they press Send. On a computer it opens WhatsApp Web in a new tab. | Free |
| Customer payments | **UPI direct to your business UPI ID:** "Pay by UPI" opens GPay / PhonePe / Paytm / BHIM with the amount filled in; a QR on the web page / technician's phone; cash at the door. The customer enters the UPI reference (UTR) and the office confirms it in one tap. | Free (no gateway fee) |
| Invoice delivery | Public invoice page on your own domain (`yourdomain/i/…`) with GST invoice, UPI QR, print/save as PDF; link goes in the WhatsApp message | Free |
| Technician GPS (check-in, job stamps, live position) | Phone GPS through Android location services / browser location | Free (no API key) |
| Navigation to the customer | Opens the Google Maps app with the destination (Maps URLs) | Free (no API key, no billing) |
| Route order of the day's jobs | Our own nearest-first calculation on the server | Free |
| Address coordinates | Saved automatically from the technician's GPS the first time work starts at an address | Free (no geocoding API) |
| Admin live map (admin panel, Phase 1b) | Leaflet + OpenStreetMap tiles, with attribution | Free for normal business use (OSM fair-use policy) |
| New-job alerts in the technician app | App checks every 60 s while open, with sound and vibration | Free |
| Background jobs | Laravel database queue + cron (no Redis needed) | Free |
| File storage (photos, signatures, receipts) | Server disk (`storage/app/public`) | Free (uses your disk) |
| PDFs | Browser "Print / Save as PDF" on the invoice page; dompdf later if needed | Free |
| Building the APKs | GitHub Actions (free minutes; a public repo is unlimited, a private one gets 2,000 min/month ≈ 100 builds) | Free |
| Installing the apps | APK download page on your own domain: `yourdomain/apps` (no Play Store) | Free |
| Web apps | Same server, static files (e.g. `tech.yourdomain`, `app.yourdomain`) | Free |

## Paid options kept ready for a later stage (switched off)

| Option | Turn on with | Cost | When it's worth it |
|---|---|---|---|
| Automatic WhatsApp (Meta Cloud API) | `WHATSAPP_MODE=cloud` + Meta app & approved templates | Per message (utility templates ≈ ₹0.12–0.15 each in India) | When manual sending takes too much staff time |
| Card / netbanking gateway (Razorpay) | `PAYMENT_GATEWAY=razorpay` + keys | ≈2% per online payment, no fixed fee | When customers ask for cards, or to stop checking UTRs by hand (automatic confirmation) |
| Push alerts with the app closed (Firebase Cloud Messaging) | Firebase project + `google-services.json` | Free (needs a Google account, no billing) | When technicians need alerts while the app is closed |
| Background location (app closed) | Android foreground-service package (free, open source) | Free | When the live map must keep updating with the app in the background |
| Google Maps distance / road routing | Google Maps Platform key | Paid after free credit | Only if straight-line route order isn't good enough |
| Play Store listing | Google Play developer account | One-time $25 | If you want customers to install from the Play Store |
| Automatic UPI confirmation without a gateway | Bank's UPI collect / merchant API | Depends on bank | Larger volumes |

## What changed in the code

- **WhatsApp:** messages are now prepared, not sent. Every booking, assignment, "on the way", invoice, receipt and reminder creates a message with a `wa.me` link.
  - The office sees them in **WhatsApp outbox** (`GET /admin/whatsapp/outbox`).
  - The technician sees them on the job screen with a **WhatsApp** button.
  - After opening it, the message is marked sent.
  - Message texts are editable in Settings (template `body` with `{placeholders}`).
- **OTP:** now goes by **SMS** through a provider-neutral HTTP driver (`SMS_DRIVER=http`, `SMS_HTTP_URL=…`). WhatsApp OTP is switched off.
- **Payments:**
  - Each issued invoice gets a public page `/i/{token}` with a UPI QR and "Pay with a UPI app".
  - Customers report the UTR there or in the app, and the office verifies it (`POST /admin/payments/{id}/verify`).
  - Razorpay stays in the code but is off unless `PAYMENT_GATEWAY=razorpay`.
- **Location:** no paid APIs anywhere. The first GPS reading at a job is saved on the customer's address.
- **Dashboard:** new "to do" counts: WhatsApp messages to send and UPI payments to verify.

## What you need to buy / set up

1. **Domain** (e.g. `shwac.in`) and point it to your server.
2. **Linux server**: a small VPS (2 vCPU / 4 GB) is comfortable; cPanel hosting with SSH, PHP 8.3 and cron also works.
3. **SMS provider** with DLT: register your company, a sender ID (e.g. `SHWACC`) and the OTP template text on the DLT portal (Jio/Vodafone/Airtel/BSNL). Use the same wording as `SMS_OTP_TEXT`.
4. **Business UPI ID** (`UPI_VPA`), e.g. a current-account UPI ID or a free merchant QR from your bank, PhonePe Business, Paytm Business or Google Pay for Business.
5. A **WhatsApp Business** app on the office phone (free) so messages go from your business number.
