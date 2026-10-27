<?php

return [

    /*
    | Permission catalog. Seeded into `permissions`; roles map to these slugs.
    | Super Admin bypasses all checks.
    */
    'permissions' => [
        'dashboard.view' => ['Dashboard', 'View dashboard'],
        'branches.all' => ['Branches', 'Access all branches'],
        'branches.manage' => ['Branches', 'Manage branches'],
        'users.manage' => ['Users', 'Manage staff & roles'],
        'customers.view' => ['Customers', 'View customers'],
        'customers.manage' => ['Customers', 'Create/edit customers'],
        'services.manage' => ['Catalog', 'Manage service catalog'],
        'bookings.view' => ['Bookings', 'View bookings'],
        'bookings.manage' => ['Bookings', 'Create/edit bookings'],
        'jobs.view' => ['Jobs', 'View service jobs'],
        'jobs.manage' => ['Jobs', 'Create/assign jobs'],
        'complaints.view' => ['Complaints', 'View complaints'],
        'complaints.manage' => ['Complaints', 'Handle complaints'],
        'invoices.view' => ['Billing', 'View invoices'],
        'invoices.manage' => ['Billing', 'Create/issue/cancel invoices'],
        'payments.view' => ['Billing', 'View payments'],
        'payments.manage' => ['Billing', 'Record payments & send links'],
        'reports.finance' => ['Reports', 'Financial figures on dashboard'],
        'inventory.view' => ['Inventory', 'View products & stock'],
        'inventory.manage' => ['Inventory', 'Products, receive/transfer/adjust stock'],
        'field.view' => ['Field', 'Attendance & live technician map'],
        'expenses.manage' => ['Field', 'Approve or reject expenses'],
        'settings.manage' => ['Settings', 'System configuration & templates'],
    ],

    'roles' => [
        'super_admin' => ['Super Admin', ['*']],
        'branch_manager' => ['Branch Manager', [
            'dashboard.view', 'customers.view', 'customers.manage', 'bookings.view', 'bookings.manage',
            'jobs.view', 'jobs.manage', 'complaints.view', 'complaints.manage', 'invoices.view',
            'invoices.manage', 'payments.view', 'payments.manage', 'reports.finance', 'users.manage',
            'inventory.view', 'inventory.manage', 'field.view', 'expenses.manage',
        ]],
        'accountant' => ['Accountant', [
            'dashboard.view', 'branches.all', 'customers.view', 'invoices.view', 'invoices.manage',
            'payments.view', 'payments.manage', 'reports.finance', 'jobs.view', 'bookings.view',
            'inventory.view', 'expenses.manage',
        ]],
        'operator' => ['Operator', [
            'dashboard.view', 'customers.view', 'customers.manage', 'bookings.view', 'bookings.manage',
            'jobs.view', 'jobs.manage', 'complaints.view', 'complaints.manage', 'invoices.view', 'payments.view',
            'inventory.view', 'field.view',
        ]],
        'technician' => ['Technician', []],
        'customer' => ['Customer', []],
    ],

    'gst' => [
        'default_service_sac' => '998719',
        'default_rate' => 18,
    ],

    'otp' => [
        'ttl_minutes' => 5,
        'max_attempts' => 5,
        'length' => 6,
        // In local/testing the OTP is returned in the API response to ease development.
        'expose_in_response' => env('OTP_EXPOSE', false),
    ],

    'invoice' => [
        'due_days' => 7,
    ],

    'payments' => [
        // none     = (default, free) UPI QR / UPI app + customer reports the UPI reference, staff verify
        // razorpay = also offer the Razorpay hosted page (≈2% fee per payment, no fixed cost)
        'gateway' => env('PAYMENT_GATEWAY', 'none'),
    ],
];
