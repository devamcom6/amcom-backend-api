<?php

/*
|--------------------------------------------------------------------------
| Amcom business configuration
|--------------------------------------------------------------------------
| Non-secret application rules. Secrets live in config/services.php (env only).
| Values in `settings_defaults` can be overridden at runtime by super admins
| through PUT /api/v1/admin/settings (stored in the `settings` table).
*/

$frontendUrls = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FRONTEND_URLS', env('FRONTEND_URL', 'http://localhost:5173'))),
)));

return [

    'company' => [
        'name' => env('COMPANY_LEGAL_NAME', 'HOUVINCITY Global Limited'),
        'brand' => env('APP_NAME', 'Amcom'),
    ],

    // Primary frontend URL (links in emails, OAuth redirect) and the full allow-list (CORS, payment callbacks).
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/'),
    'frontend_urls' => array_map(fn ($u) => rtrim($u, '/'), $frontendUrls),

    'auth' => [
        'token_ttl_minutes' => (int) env('AUTH_TOKEN_TTL_MINUTES', 60 * 24),               // 24 h
        'remember_token_ttl_minutes' => (int) env('AUTH_REMEMBER_TTL_MINUTES', 60 * 24 * 30), // 30 days
        'code_ttl_minutes' => 15,
        'code_max_attempts' => 5,
        'reset_token_ttl_minutes' => 15,
        'invite_ttl_hours' => 72,
        'two_factor_channel' => env('TWO_FACTOR_CHANNEL', 'email'), // email | sms
        'max_login_activities' => 20,
    ],

    // Development seeding only (database/seeders/DemoSeeder.php).
    'seed' => [
        'password' => env('SEED_PASSWORD', 'Password123!'),
        'upload_images' => (bool) env('SEED_UPLOAD_IMAGES', false),
    ],

    'buyback' => [
        'reservation_minutes' => (int) env('PLOT_RESERVATION_MINUTES', 30),
    ],

    'documents' => [
        'signed_url_ttl_minutes' => 5,
        'preview_url_ttl_minutes' => 30,
    ],

    'inspections' => [
        'days_ahead' => 10,
        'times' => ['09:00', '10:00', '11:00', '13:00', '14:00', '15:00'],
        'skip_sundays' => true,
        'timezone' => env('BUSINESS_TIMEZONE', 'Africa/Lagos'),
    ],

    'media' => [
        // Max upload size in KB.
        'max_photo_kb' => 5120,
        'max_avatar_kb' => 2048,
        'max_listing_photos' => 10,
        'variant_widths' => [480, 960, 1440],
        'hero_variant_widths' => [480, 960, 1440, 1920],
        'thumbnail_size' => 320,
        'avatar_size' => 400,
        'root_folder' => env('CLOUDINARY_ROOT_FOLDER', 'amcom'),
    ],

    'marketplace' => [
        'price_ranges' => [
            ['label' => 'Under ₦5M', 'min' => null, 'max' => 5_000_000],
            ['label' => '₦5M – ₦50M', 'min' => 5_000_000, 'max' => 50_000_000],
            ['label' => '₦50M – ₦150M', 'min' => 50_000_000, 'max' => 150_000_000],
            ['label' => 'Above ₦150M', 'min' => 150_000_000, 'max' => null],
        ],
    ],

    /*
    | Permission catalogue (contract §2). `admin` defaults are seeded; super_admin always has all.
    */
    'permissions' => [
        'dashboard.view' => ['label' => 'View dashboard', 'group' => 'Dashboard', 'description' => 'See KPIs, charts and recent activity.', 'admin' => true],
        'users.view' => ['label' => 'View users', 'group' => 'Users', 'description' => 'Browse and search customer accounts.', 'admin' => true],
        'users.manage' => ['label' => 'Manage users', 'group' => 'Users', 'description' => 'Suspend, reactivate and message customers.', 'admin' => true],
        'admins.manage' => ['label' => 'Manage administrators', 'group' => 'Administration', 'description' => 'Invite, re-role and revoke administrators.', 'admin' => false],
        'roles.manage' => ['label' => 'Manage roles', 'group' => 'Administration', 'description' => 'Edit role permission sets.', 'admin' => false],
        'estates.view' => ['label' => 'View estates', 'group' => 'Estates', 'description' => 'See estate inventory and plot availability.', 'admin' => true],
        'estates.manage' => ['label' => 'Manage estates', 'group' => 'Estates', 'description' => 'Publish/unpublish estates and change checkout mode.', 'admin' => false],
        'listings.view' => ['label' => 'View listings', 'group' => 'Listings', 'description' => 'See the listing verification queue.', 'admin' => true],
        'listings.review' => ['label' => 'Review listings', 'group' => 'Listings', 'description' => 'Approve or reject owner listings.', 'admin' => true],
        'investments.view' => ['label' => 'View investments', 'group' => 'Investments', 'description' => 'See buyback and capital investments.', 'admin' => true],
        'investments.manage' => ['label' => 'Manage investments', 'group' => 'Investments', 'description' => 'Cancel orders and mark payouts.', 'admin' => true],
        'transactions.view' => ['label' => 'View transactions', 'group' => 'Transactions', 'description' => 'See payment history.', 'admin' => true],
        'transactions.manage' => ['label' => 'Manage transactions', 'group' => 'Transactions', 'description' => 'Re-query payments with Paystack.', 'admin' => false],
        'requests.view' => ['label' => 'View requests', 'group' => 'Requests', 'description' => 'See leads, inspections, contact and support requests.', 'admin' => true],
        'requests.manage' => ['label' => 'Manage requests', 'group' => 'Requests', 'description' => 'Assign and close requests.', 'admin' => true],
        'content.manage' => ['label' => 'Manage content', 'group' => 'Content', 'description' => 'Edit FAQs and testimonials.', 'admin' => true],
        'media.manage' => ['label' => 'Manage media', 'group' => 'Content', 'description' => 'Upload and remove media library assets.', 'admin' => true],
        'reports.view' => ['label' => 'View reports', 'group' => 'Reports', 'description' => 'Run revenue, investment, user and listing reports.', 'admin' => true],
        'reports.export' => ['label' => 'Export reports', 'group' => 'Reports', 'description' => 'Download reports as CSV.', 'admin' => true],
        'settings.view' => ['label' => 'View settings', 'group' => 'Settings', 'description' => 'See system configuration.', 'admin' => true],
        'settings.manage' => ['label' => 'Manage settings', 'group' => 'Settings', 'description' => 'Change system configuration.', 'admin' => false],
    ],

    /*
    | Notification preferences (contract §5.9). `locked` keys are always on.
    */
    'notification_preferences' => [
        'payment_confirmations' => ['label' => 'Payment Confirmations', 'description' => 'Get notified when a payment is confirmed', 'group' => 'transactions', 'default' => true, 'locked' => false],
        'transaction_receipts' => ['label' => 'Transaction Receipts', 'description' => 'Receive receipts for completed transactions', 'group' => 'transactions', 'default' => true, 'locked' => false],
        'maturity_alerts' => ['label' => 'Maturity Alerts', 'description' => 'Get reminded when your investments are approaching maturity', 'group' => 'investments', 'default' => true, 'locked' => false],
        'renewal_reminders' => ['label' => 'Renewal Reminders', 'description' => 'Get reminded before a lease or rent renewal is due', 'group' => 'investments', 'default' => true, 'locked' => false],
        'roi_updates' => ['label' => 'ROI Updates', 'description' => 'Monthly updates on your investment returns', 'group' => 'investments', 'default' => true, 'locked' => false],
        'listing_status_changes' => ['label' => 'Listing Status Changes', 'description' => 'Know when your listing is approved, published or needs changes', 'group' => 'property', 'default' => true, 'locked' => false],
        'maintenance_updates' => ['label' => 'Maintenance Updates', 'description' => 'Updates on your maintenance requests', 'group' => 'property', 'default' => true, 'locked' => false],
        'new_inquiry_alerts' => ['label' => 'New Inquiry Alerts', 'description' => 'Get notified when someone enquires about your listing', 'group' => 'property', 'default' => true, 'locked' => false],
        'login_alerts' => ['label' => 'Login Alerts', 'description' => 'Get notified of sign-ins from new devices', 'group' => 'security', 'default' => true, 'locked' => false],
        'password_changes' => ['label' => 'Password Changes', 'description' => 'Always notified when your password changes', 'group' => 'security', 'default' => true, 'locked' => true],
    ],

    'settings_defaults' => [
        'general' => [
            'support_email' => env('SUPPORT_EMAIL', 'info@houvincity.com'),
            'support_phone' => env('SUPPORT_PHONE', '+234 707 469 3320'),
            'office_address' => '24 Marina Road, Victoria Island, Lagos, Nigeria.',
            'maintenance_mode' => false,
        ],
        'fees' => [
            'listing_fee' => 25000,
            'sale_service_fee_percent' => 5,
        ],
        'capital_investment' => [
            'min_amount' => 1_000_000,
            'max_self_serve_amount' => 50_000_000,
            'checkout_mode' => 'direct',
            'tenures' => [
                ['months' => 6, 'roi_percent' => 18],
                ['months' => 12, 'roi_percent' => 27],
            ],
            'pricing_examples' => [1_000_000, 2_000_000, 5_000_000, 10_000_000, 20_000_000],
        ],
        'notifications' => [
            'admin_alert_email' => env('ADMIN_ALERT_EMAIL', 'ops@amcom.test'),
            'facility_manager_email' => env('FACILITY_MANAGER_EMAIL'),
            'send_maturity_reminders_days_before' => 30,
        ],
    ],

    'lookups' => [
        'countries' => ['Nigeria', 'Ghana', 'United Kingdom', 'United States', 'Canada', 'South Africa', 'Kenya', 'United Arab Emirates', 'Germany', 'Ireland'],
        'relationships' => ['Spouse', 'Father', 'Mother', 'Brother', 'Sister', 'Son', 'Daughter', 'Uncle', 'Aunt', 'Cousin', 'Friend', 'Other'],
        'id_types' => [
            ['value' => 'nin', 'label' => 'National Identification Number (NIN)'],
            ['value' => 'bvn', 'label' => 'Bank Verification Number (BVN)'],
            ['value' => 'drivers_license', 'label' => "Driver's Licence"],
            ['value' => 'international_passport', 'label' => 'International Passport'],
            ['value' => 'voters_card', 'label' => "Voter's Card"],
        ],
        // Used when the Paystack bank list is unavailable.
        'fallback_banks' => [
            ['code' => '044', 'name' => 'Access Bank'],
            ['code' => '023', 'name' => 'Citibank Nigeria'],
            ['code' => '050', 'name' => 'Ecobank Nigeria'],
            ['code' => '070', 'name' => 'Fidelity Bank'],
            ['code' => '011', 'name' => 'First Bank of Nigeria'],
            ['code' => '214', 'name' => 'First City Monument Bank'],
            ['code' => '058', 'name' => 'Guaranty Trust Bank'],
            ['code' => '030', 'name' => 'Heritage Bank'],
            ['code' => '082', 'name' => 'Keystone Bank'],
            ['code' => '50515', 'name' => 'Moniepoint MFB'],
            ['code' => '999992', 'name' => 'OPay'],
            ['code' => '999991', 'name' => 'PalmPay'],
            ['code' => '076', 'name' => 'Polaris Bank'],
            ['code' => '221', 'name' => 'Stanbic IBTC Bank'],
            ['code' => '068', 'name' => 'Standard Chartered Bank'],
            ['code' => '232', 'name' => 'Sterling Bank'],
            ['code' => '032', 'name' => 'Union Bank of Nigeria'],
            ['code' => '033', 'name' => 'United Bank For Africa'],
            ['code' => '215', 'name' => 'Unity Bank'],
            ['code' => '035', 'name' => 'Wema Bank'],
            ['code' => '057', 'name' => 'Zenith Bank'],
        ],
    ],
];
