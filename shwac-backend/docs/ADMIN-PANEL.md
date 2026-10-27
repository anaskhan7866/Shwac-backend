# Admin panel

Open `https://<your API domain>/admin` and log in with an office staff account (email or phone + password).
Technicians cannot log in here (they use the Android app); customers use the customer app.

It is a single page served by this Laravel app. No build step and no npm: the files are plain
HTML/CSS/JS in `public/admin-assets/` and the shell is `resources/views/admin.blade.php`.
It talks to the same tested API (`/api/v1/admin/...`) with a staff login token kept in the browser.

## Screens (each shown only to roles with the permission)

| Menu | What it does | Permission |
|---|---|---|
| Dashboard | Bookings, live jobs, open complaints and SLA, collections, to-do counts | dashboard.view |
| Today's to-do | WhatsApp to send, UPI to verify, unassigned jobs, overdue invoices with reminder | dashboard.view |
| Complaints | Filters (open / past SLA / due in 2h / not assigned), new complaint, assign, notes, status | complaints.* |
| Bookings | List, new booking with the service's own form questions, reschedule, cancel | bookings.* |
| Service jobs | List by status/day, quick assign, job history with GPS stamps, status correction | jobs.* |
| Customers | Search, create, edit, addresses, appliances, history, WhatsApp message | customers.* |
| Live technician map | OpenStreetMap (free), technicians on duty, current job, today's trail | field.view |
| Invoices | GST invoices: create (service / spare part / free line), draft edit, issue, cancel, print/PDF, customer link, record payment, WhatsApp reminder | invoices.*, payments.manage |
| UPI payments to verify | Match the customer's UTR with your bank app → Received / Not found | payments.manage |
| Payments | All receipts with filters | payments.view |
| WhatsApp outbox | Prepared messages → open WhatsApp with the text → marked sent | dashboard.view |
| Message texts | Edit WhatsApp templates with live preview | settings.manage |
| Inventory | Stock per store/van, count (adjust), receive purchase, issue to van / return, alerts, movements, parts list | inventory.* |
| Expense approvals | Approve / reject technician claims (reason required) | expenses.manage |
| Attendance | Check-in/out times, GPS, hours per day | field.view |
| Services & prices | Categories, services, GST/SAC, booking form questions | services.manage |
| Staff & roles | Office and technician logins, block a login, role permission matrix | users.manage, settings.manage |
| Branches | GSTIN, state, invoice prefix | branches.manage |
| Settings | Business details, invoice terms, GST defaults, complaint categories, time slots, SLA, message log | settings.manage |

Super Admin and other all-branch roles get a **Branch** selector at the top of the menu.

## WhatsApp (free click-to-send)
Whenever an action prepares a customer message (booking, complaint, technician assigned, invoice issued,
payment received, reminder) the panel shows it with an **Open WhatsApp** button. It opens WhatsApp / WhatsApp Web
with the text filled in; press Send there. Messages you skip stay in the **WhatsApp outbox**.

## Updating
Upload the new files and run `php artisan view:clear`. File names carry a version stamp, so browsers pick up new files automatically.
