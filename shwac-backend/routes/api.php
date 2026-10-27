<?php

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\Customer\PortalController;
use App\Http\Controllers\Api\V1\Technician\FieldController as TechField;
use App\Http\Controllers\Api\V1\Technician\JobController as TechJobs;
use App\Http\Controllers\Api\V1\Webhooks\RazorpayWebhookController;
use App\Http\Controllers\Api\V1\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ---- Public ------------------------------------------------------------
    Route::get('catalog', [CatalogController::class, 'index']);
    Route::get('catalog/services/{service}', [CatalogController::class, 'show']);

    Route::prefix('auth')->middleware('throttle:20,1')->group(function () {
        Route::post('login', [AuthController::class, 'login']);           // staff & technicians
        Route::post('otp/request', [AuthController::class, 'requestOtp']); // customers
        Route::post('otp/verify', [AuthController::class, 'verifyOtp']);
    });

    Route::prefix('webhooks')->group(function () {
        Route::post('razorpay', RazorpayWebhookController::class);
        Route::get('whatsapp', [WhatsAppWebhookController::class, 'verify']);
        Route::post('whatsapp', [WhatsAppWebhookController::class, 'handle']);
    });

    // ---- Authenticated -----------------------------------------------------
    Route::middleware(['auth:sanctum', 'user.type'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // ---- Admin panel (staff, permission-checked) -----------------------
        Route::prefix('admin')->middleware('user.type:staff')->group(function () {
            Route::get('dashboard', Admin\DashboardController::class)->middleware('permission:dashboard.view');

            Route::get('branches', [Admin\BranchController::class, 'index']);
            Route::middleware('permission:branches.manage')->group(function () {
                Route::post('branches', [Admin\BranchController::class, 'store']);
                Route::patch('branches/{branch}', [Admin\BranchController::class, 'update']);
            });

            Route::middleware('permission:users.manage')->group(function () {
                Route::get('users', [Admin\UserController::class, 'index']);
                Route::post('users', [Admin\UserController::class, 'store']);
                Route::patch('users/{user}', [Admin\UserController::class, 'update']);
                Route::get('roles', [Admin\UserController::class, 'roles']);
            });
            Route::put('roles/{role}', [Admin\UserController::class, 'updateRole'])->middleware('permission:settings.manage');

            Route::middleware('permission:customers.view')->group(function () {
                Route::get('customers', [Admin\CustomerController::class, 'index']);
                Route::get('customers/{customer}', [Admin\CustomerController::class, 'show']);
            });
            Route::middleware('permission:customers.manage')->group(function () {
                Route::post('customers', [Admin\CustomerController::class, 'store']);
                Route::patch('customers/{customer}', [Admin\CustomerController::class, 'update']);
                Route::post('customers/{customer}/addresses', [Admin\CustomerController::class, 'storeAddress']);
                Route::patch('customers/{customer}/addresses/{address}', [Admin\CustomerController::class, 'updateAddress']);
                Route::post('customers/{customer}/products', [Admin\CustomerController::class, 'storeProduct']);
                Route::patch('customers/{customer}/products/{product}', [Admin\CustomerController::class, 'updateProduct']);
            });

            Route::get('catalog/categories', [Admin\CatalogController::class, 'categories']);
            Route::get('catalog/services', [Admin\CatalogController::class, 'services']);
            Route::middleware('permission:services.manage')->group(function () {
                Route::post('catalog/categories', [Admin\CatalogController::class, 'storeCategory']);
                Route::patch('catalog/categories/{category}', [Admin\CatalogController::class, 'updateCategory']);
                Route::post('catalog/services', [Admin\CatalogController::class, 'storeService']);
                Route::patch('catalog/services/{service}', [Admin\CatalogController::class, 'updateService']);
            });

            Route::middleware('permission:bookings.view')->group(function () {
                Route::get('bookings', [Admin\BookingController::class, 'index']);
                Route::get('bookings/{booking}', [Admin\BookingController::class, 'show']);
            });
            Route::middleware('permission:bookings.manage')->group(function () {
                Route::post('bookings', [Admin\BookingController::class, 'store']);
                Route::patch('bookings/{booking}', [Admin\BookingController::class, 'update']);
                Route::post('bookings/{booking}/cancel', [Admin\BookingController::class, 'cancel']);
            });

            Route::middleware('permission:jobs.view')->group(function () {
                Route::get('jobs', [Admin\JobController::class, 'index']);
                Route::get('jobs/{job}', [Admin\JobController::class, 'show']);
                Route::get('technicians', [Admin\JobController::class, 'technicians']);
            });
            Route::middleware('permission:jobs.manage')->group(function () {
                Route::post('jobs/{job}/assign', [Admin\JobController::class, 'assign']);
                Route::post('jobs/{job}/status', [Admin\JobController::class, 'updateStatus']);
            });

            Route::middleware('permission:complaints.view')->group(function () {
                Route::get('complaints', [Admin\ComplaintController::class, 'index']);
                Route::get('complaints/{complaint}', [Admin\ComplaintController::class, 'show']);
            });
            Route::middleware('permission:complaints.manage')->group(function () {
                Route::post('complaints', [Admin\ComplaintController::class, 'store']);
                Route::patch('complaints/{complaint}', [Admin\ComplaintController::class, 'update']);
                Route::post('complaints/{complaint}/assign', [Admin\ComplaintController::class, 'assign']);
                Route::post('complaints/{complaint}/status', [Admin\ComplaintController::class, 'updateStatus']);
                Route::post('complaints/{complaint}/comments', [Admin\ComplaintController::class, 'comment']);
            });

            Route::middleware('permission:invoices.view')->group(function () {
                Route::get('invoices', [Admin\InvoiceController::class, 'index']);
                Route::get('invoices/{invoice}', [Admin\InvoiceController::class, 'show']);
                Route::get('invoices/{invoice}/print', [Admin\InvoiceController::class, 'print']);
            });
            Route::middleware('permission:invoices.manage')->group(function () {
                Route::post('invoices', [Admin\InvoiceController::class, 'store']);
                Route::put('invoices/{invoice}', [Admin\InvoiceController::class, 'update']);
                Route::post('invoices/{invoice}/issue', [Admin\InvoiceController::class, 'issue']);
                Route::post('invoices/{invoice}/cancel', [Admin\InvoiceController::class, 'cancel']);
            });

            Route::get('payments', [Admin\PaymentController::class, 'index'])->middleware('permission:payments.view');
            Route::middleware('permission:payments.manage')->group(function () {
                Route::post('payments', [Admin\PaymentController::class, 'store']);
                Route::post('payments/{payment}/verify', [Admin\PaymentController::class, 'verify']);
                Route::post('invoices/{invoice}/reminder', [Admin\WhatsAppController::class, 'reminder']);
                Route::post('invoices/{invoice}/payment-link', [Admin\InvoiceController::class, 'paymentLink']);
            });

            // WhatsApp click-to-send outbox (free)
            Route::middleware('permission:dashboard.view')->group(function () {
                Route::get('whatsapp/outbox', [Admin\WhatsAppController::class, 'outbox']);
                Route::post('whatsapp/{log}/sent', [Admin\WhatsAppController::class, 'markSent']);
            });
            Route::post('whatsapp/compose', [Admin\WhatsAppController::class, 'compose'])->middleware('permission:customers.view');

            // Inventory (Phase 2)
            Route::middleware('permission:inventory.view')->group(function () {
                Route::get('products', [Admin\InventoryController::class, 'products']);
                Route::get('products/lookup', [Admin\InventoryController::class, 'lookup']);
                Route::get('warehouses', [Admin\InventoryController::class, 'warehouses']);
                Route::get('stock', [Admin\InventoryController::class, 'stock']);
                Route::get('stock/movements', [Admin\InventoryController::class, 'movements']);
                Route::get('stock/alerts', [Admin\InventoryController::class, 'alerts']);
            });
            Route::middleware('permission:inventory.manage')->group(function () {
                Route::post('products', [Admin\InventoryController::class, 'storeProduct']);
                Route::patch('products/{product}', [Admin\InventoryController::class, 'updateProduct']);
                Route::post('warehouses', [Admin\InventoryController::class, 'storeWarehouse']);
                Route::post('technicians/{user}/van', [Admin\InventoryController::class, 'technicianVan']);
                Route::post('stock/receive', [Admin\InventoryController::class, 'receive']);
                Route::post('stock/transfer', [Admin\InventoryController::class, 'transfer']);
                Route::post('stock/adjust', [Admin\InventoryController::class, 'adjust']);
            });

            // Field operations (Phase 2)
            Route::middleware('permission:field.view')->group(function () {
                Route::get('field/live', [Admin\FieldOpsController::class, 'live']);
                Route::get('field/attendance', [Admin\FieldOpsController::class, 'attendance']);
                Route::get('field/technicians/{user}/trail', [Admin\FieldOpsController::class, 'trail']);
            });
            Route::middleware('permission:expenses.manage')->group(function () {
                Route::get('expenses', [Admin\FieldOpsController::class, 'expenses']);
                Route::post('expenses/{expense}/review', [Admin\FieldOpsController::class, 'reviewExpense']);
            });

            Route::middleware('permission:settings.manage')->group(function () {
                Route::get('settings', [Admin\SettingsController::class, 'index']);
                Route::patch('settings', [Admin\SettingsController::class, 'update']);
                Route::put('settings/sla', [Admin\SettingsController::class, 'updateSla']);
                Route::get('notification-templates', [Admin\SettingsController::class, 'templates']);
                Route::post('notification-templates', [Admin\SettingsController::class, 'upsertTemplate']);
                Route::get('notification-logs', [Admin\SettingsController::class, 'notificationLogs']);
            });
        });

        // ---- Customer portal (website / app) --------------------------------
        Route::prefix('customer')->middleware(['user.type:customer', 'idempotent'])->group(function () {
            Route::get('profile', [PortalController::class, 'profile']);
            Route::patch('profile', [PortalController::class, 'updateProfile']);
            Route::post('addresses', [PortalController::class, 'storeAddress']);
            Route::post('products', [PortalController::class, 'storeProduct']);

            Route::get('bookings', [PortalController::class, 'bookings']);
            Route::post('bookings', [PortalController::class, 'storeBooking']);
            Route::get('bookings/{booking}', [PortalController::class, 'showBooking']);
            Route::post('bookings/{booking}/cancel', [PortalController::class, 'cancelBooking']);

            Route::get('complaints', [PortalController::class, 'complaints']);
            Route::post('complaints', [PortalController::class, 'storeComplaint']);
            Route::get('complaints/{complaint}', [PortalController::class, 'showComplaint']);
            Route::post('complaints/{complaint}/comments', [PortalController::class, 'commentComplaint']);

            Route::get('invoices', [PortalController::class, 'invoices']);
            Route::get('invoices/{invoice}', [PortalController::class, 'showInvoice']);
            Route::post('invoices/{invoice}/pay', [PortalController::class, 'payInvoice']);
            Route::post('invoices/{invoice}/payment-link', [PortalController::class, 'invoicePaymentLink']);
            Route::post('invoices/{invoice}/upi-claim', [PortalController::class, 'upiClaim']);
            Route::post('payments/confirm', [PortalController::class, 'confirmPayment']);
        });

        // ---- Technician app --------------------------------------------------
        Route::prefix('technician')->middleware(['user.type:technician', 'idempotent'])->group(function () {
            Route::get('today', [TechField::class, 'today']);
            Route::post('consent', [TechField::class, 'consent']);

            Route::get('jobs', [TechJobs::class, 'index']);
            Route::get('jobs/{job}', [TechJobs::class, 'show']);
            Route::post('jobs/{job}/status', [TechJobs::class, 'updateStatus']);
            Route::post('jobs/{job}/invoice', [TechJobs::class, 'invoice']);
            Route::post('jobs/{job}/collect', [TechJobs::class, 'collect']);
            Route::post('jobs/{job}/attachments', [TechJobs::class, 'attach']);
            Route::post('messages/{log}/sent', [TechJobs::class, 'messageSent']);

            Route::post('attendance/check-in', [TechField::class, 'checkIn']);
            Route::post('attendance/check-out', [TechField::class, 'checkOut']);
            Route::get('attendance', [TechField::class, 'attendanceHistory']);
            Route::post('location', [TechField::class, 'location'])->middleware('throttle:30,1');

            Route::get('expenses', [TechField::class, 'expenses']);
            Route::post('expenses', [TechField::class, 'storeExpense']);

            Route::get('van', [TechField::class, 'van']);
            Route::get('parts/lookup', [TechField::class, 'lookup']);
        });
    });
});
