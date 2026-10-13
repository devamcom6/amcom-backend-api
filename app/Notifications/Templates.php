<?php

namespace App\Notifications;

use InvalidArgumentException;

/**
 * Central registry of transactional email / in-app notification copy (contract §16).
 *
 * Each template:
 *  - category    in-app category (contract §12)
 *  - preference  optional preference key (contract §5.9); null = always sent (security/transactional)
 *  - in_app      also stored as an in-app notification for registered users
 *  - subject, lines[], action_text, action_path  — email
 *  - title, body — in-app (defaults to subject / first line)
 * Placeholders use :name syntax and are filled from the event data.
 */
final class Templates
{
    public static function get(string $key): array
    {
        $templates = self::all();

        if (! isset($templates[$key])) {
            throw new InvalidArgumentException("Unknown notification template [$key].");
        }

        return $templates[$key] + ['preference' => null, 'in_app' => false, 'action_text' => null, 'action_path' => null, 'title' => null, 'body' => null];
    }

    public static function render(string $text, array $data): string
    {
        $replacements = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replacements[':'.$key] = (string) $value;
            }
        }

        // Longest keys first so ":amount_paid" is not clobbered by ":amount".
        uksort($replacements, fn ($a, $b) => strlen($b) <=> strlen($a));

        return strtr($text, $replacements);
    }

    private static function all(): array
    {
        return [
            // ------------------------------------------------------------ account & security
            'welcome' => [
                'category' => 'account', 'in_app' => true,
                'subject' => 'Welcome to Amcom',
                'lines' => ['Your Amcom account has been created.', 'Complete your profile to start investing in buyback estates, capital plans and verified properties.'],
                'action_text' => 'Complete your profile', 'action_path' => '/onboarding',
                'body' => 'Your account is ready. Complete your profile to start investing.',
            ],
            'login_alert' => [
                'category' => 'security', 'preference' => 'login_alerts', 'in_app' => true,
                'subject' => 'New sign-in to your Amcom account',
                'lines' => ['We noticed a sign-in from a new device: :device (:ip_address) at :time.', 'If this was you, no action is needed. If not, change your password immediately.'],
                'action_text' => 'Review security settings', 'action_path' => '/dashboard/settings/security',
                'title' => 'New sign-in', 'body' => 'New sign-in from :device.',
            ],
            'password_changed' => [
                'category' => 'security', 'in_app' => true,
                'subject' => 'Your password was changed',
                'lines' => ['The password for your Amcom account was changed on :time.', 'If you did not make this change, reset your password immediately and contact support.'],
                'action_text' => 'Reset password', 'action_path' => '/forgot-password',
                'title' => 'Password changed', 'body' => 'Your password was changed.',
            ],
            'identity_verified' => [
                'category' => 'account', 'in_app' => true,
                'subject' => 'Your identity has been verified',
                'lines' => ['Thanks, :first_name. Your :id_type has been verified successfully.'],
                'title' => 'Identity verified', 'body' => 'Your identity has been verified.',
            ],
            'identity_failed' => [
                'category' => 'account', 'in_app' => true,
                'subject' => 'We could not verify your identity',
                'lines' => ['We were unable to verify the ID you submitted.', 'Please check the number and try again, or contact support if the problem persists.'],
                'action_text' => 'Try again', 'action_path' => '/onboarding',
                'title' => 'Verification failed', 'body' => 'We could not verify your ID. Please try again.',
            ],
            'profile_completed' => [
                'category' => 'account', 'in_app' => true,
                'subject' => 'Your profile setup is complete',
                'lines' => ['Your Amcom profile is complete. You can now invest and manage your properties.'],
                'action_text' => 'Explore investments', 'action_path' => '/buyback',
                'title' => 'Profile complete', 'body' => 'Your profile setup is complete.',
            ],
            'account_updated' => [
                'category' => 'account', 'in_app' => true,
                'subject' => 'Your account details were updated',
                'lines' => ['Your account details were updated on :time.', 'If you did not make this change, contact support immediately.'],
                'title' => 'Account updated', 'body' => 'Your account details were updated.',
            ],
            'phone_changed' => [
                'category' => 'security', 'in_app' => true,
                'subject' => 'Your phone number was changed',
                'lines' => ['The phone number on your Amcom account was changed to :phone_masked.', 'If you did not make this change, contact support immediately.'],
                'title' => 'Phone number changed', 'body' => 'Your phone number was changed.',
            ],
            'payout_account_added' => [
                'category' => 'security', 'in_app' => true,
                'subject' => 'A payout account was added',
                'lines' => [':bank_name account :account_masked (:account_name) was added to your payout accounts.', 'If you did not make this change, contact support immediately.'],
                'title' => 'Payout account added', 'body' => ':bank_name :account_masked was added.',
            ],
            'payout_account_removed' => [
                'category' => 'security', 'in_app' => true,
                'subject' => 'A payout account was removed',
                'lines' => [':bank_name account :account_masked was removed from your payout accounts.'],
                'title' => 'Payout account removed', 'body' => ':bank_name :account_masked was removed.',
            ],
            'two_factor_enabled' => [
                'category' => 'security', 'in_app' => true,
                'subject' => 'Two-factor authentication enabled',
                'lines' => ['Two-factor authentication is now on for your Amcom account. Keep your recovery codes somewhere safe.'],
                'title' => '2FA enabled', 'body' => 'Two-factor authentication is now on.',
            ],
            'two_factor_disabled' => [
                'category' => 'security', 'in_app' => true,
                'subject' => 'Two-factor authentication disabled',
                'lines' => ['Two-factor authentication was turned off for your Amcom account.', 'If you did not make this change, reset your password immediately.'],
                'title' => '2FA disabled', 'body' => 'Two-factor authentication was turned off.',
            ],
            'account_deletion_scheduled' => [
                'category' => 'account',
                'subject' => 'Your account deletion has been scheduled',
                'lines' => ['We have received your request to delete your Amcom account. You have been signed out of all devices.', 'Personal data will be removed in line with our data-retention policy; financial records are retained in anonymised form as required by law.'],
            ],
            'account_suspended' => [
                'category' => 'account',
                'subject' => 'Your account has been suspended',
                'lines' => ['Your Amcom account has been suspended.', ':reason', 'Contact support if you believe this is a mistake.'],
            ],
            'account_reactivated' => [
                'category' => 'account', 'in_app' => true,
                'subject' => 'Your account has been reactivated',
                'lines' => ['Your Amcom account is active again. You can sign in as usual.'],
                'action_text' => 'Sign in', 'action_path' => '/login',
                'title' => 'Account reactivated', 'body' => 'Your account is active again.',
            ],

            // ------------------------------------------------------------ leads
            'interest_received' => [
                'category' => 'investment',
                'subject' => 'We received your interest — :reference',
                'lines' => ['Thank you, :name. We have received your interest in :product_label (:summary).', 'One of our agents will reach out within 24–48 hours.', 'Reference: :reference'],
            ],
            'application_received' => [
                'category' => 'application',
                'subject' => 'Application received — :property_title',
                'lines' => ['Thank you, :name. Your application for :property_title has been received.', 'Our team will contact you shortly.', 'Reference: :reference'],
            ],
            'new_inquiry' => [
                'category' => 'listing', 'preference' => 'new_inquiry_alerts', 'in_app' => true,
                'subject' => 'New inquiry on :property_title',
                'lines' => ['Someone has shown interest in your listing :property_title. Our team will coordinate next steps with you.'],
                'action_text' => 'View listing', 'action_path' => '/dashboard/property-management/listings/:listing_id',
                'title' => 'New inquiry', 'body' => 'New inquiry on :property_title.',
            ],
            'inspection_booked' => [
                'category' => 'inspection',
                'subject' => 'Inspection booked — :property_title',
                'lines' => ['Your :inspection_type inspection of :property_title is booked for :scheduled_label.', 'A calendar invite is attached.', 'Reference: :reference'],
            ],
            'inspection_reminder' => [
                'category' => 'inspection',
                'subject' => 'Reminder: inspection tomorrow — :property_title',
                'lines' => ['This is a reminder of your :inspection_type inspection of :property_title on :scheduled_label.'],
            ],
            'contact_received' => [
                'category' => 'system',
                'subject' => 'We received your message — :reference',
                'lines' => ['Thank you for contacting Amcom, :name. Our team will respond as soon as possible.', 'Subject: :subject', 'Reference: :reference'],
            ],
            'support_received' => [
                'category' => 'system', 'in_app' => true,
                'subject' => 'We received your request — :reference',
                'lines' => ['Thank you for contacting support. We have logged your request ":subject" and will respond shortly.', 'Reference: :reference'],
                'title' => 'Support request received', 'body' => 'Your request :reference has been logged.',
            ],

            // ------------------------------------------------------------ investments & payments
            'agreement_signed' => [
                'category' => 'investment', 'in_app' => true,
                'subject' => ':agreement_title signed — :reference',
                'lines' => ['You signed the :agreement_title for :reference on :time.', 'A copy is attached for your records. Complete your payment to activate the investment.'],
                'action_text' => 'Complete payment', 'action_path' => ':checkout_path',
                'title' => 'Agreement signed', 'body' => 'You signed the :agreement_title for :reference.',
            ],
            'payment_successful' => [
                'category' => 'payment', 'in_app' => true,
                'subject' => 'Payment successful — :amount',
                'lines' => ['Your payment of :amount for :description was successful.', 'Payment reference: :reference', 'Your receipt is available in your dashboard.'],
                'action_text' => 'Go to dashboard', 'action_path' => ':next_url',
                'title' => 'Payment confirmed', 'body' => 'Your payment of :amount for :description was verified.',
            ],
            'payment_failed' => [
                'category' => 'payment', 'in_app' => true,
                'subject' => 'Payment unsuccessful — :description',
                'lines' => ['Your payment of :amount for :description was not completed (:gateway_response).', 'No money was taken for this attempt. You can try again at any time.'],
                'action_text' => 'Try again', 'action_path' => ':retry_path',
                'title' => 'Payment failed', 'body' => 'Your payment of :amount for :description did not go through.',
            ],
            'documents_ready' => [
                'category' => 'investment', 'in_app' => true,
                'subject' => 'Your :product_label documents are ready',
                'lines' => ['Your documents for :reference (:document_list) are ready in your dashboard.'],
                'action_text' => 'View documents', 'action_path' => ':next_url',
                'title' => 'Documents ready', 'body' => 'Documents for :reference are ready.',
            ],
            'holding_payment_received' => [
                'category' => 'payment', 'in_app' => true,
                'subject' => 'Payment received — :property_title',
                'lines' => ['We received your payment of :amount for :property_title.', 'Your current period now runs until :period_end.'],
                'action_text' => 'View property', 'action_path' => '/dashboard/property-management/holdings/:holding_id',
                'title' => 'Payment received', 'body' => 'Payment of :amount received for :property_title.',
            ],
            'payout_requested' => [
                'category' => 'investment', 'in_app' => true,
                'subject' => 'Payout requested — :reference',
                'lines' => ['We received your payout request for :reference (:amount).', 'Funds will be sent to :bank_name :account_masked once processed by our finance team.'],
                'title' => 'Payout requested', 'body' => 'Payout of :amount for :reference requested.',
            ],
            'payout_sent' => [
                'category' => 'investment', 'in_app' => true,
                'subject' => 'Payout sent — :reference',
                'lines' => ['Your payout of :amount for :reference has been sent.'],
                'title' => 'Payout sent', 'body' => 'Your payout of :amount for :reference has been sent.',
            ],
            'investment_cancelled' => [
                'category' => 'investment', 'in_app' => true,
                'subject' => 'Investment cancelled — :reference',
                'lines' => ['Your :product_label order :reference has been cancelled.', 'Contact support if you have any questions.'],
                'title' => 'Order cancelled', 'body' => 'Your order :reference was cancelled.',
            ],
            'maturity_reminder' => [
                'category' => 'investment', 'preference' => 'maturity_alerts', 'in_app' => true,
                'subject' => 'Your investment matures on :maturity_date',
                'lines' => [':reference (:plan) matures on :maturity_date with an expected payout of :amount.'],
                'action_text' => 'View position', 'action_path' => ':next_url',
                'title' => 'Maturity approaching', 'body' => ':reference matures on :maturity_date.',
            ],
            'roi_update' => [
                'category' => 'investment', 'preference' => 'roi_updates', 'in_app' => true,
                'subject' => 'Your monthly investment update',
                'lines' => ['You have :count active position(s) with a total principal of :principal and a projected payout of :projected.'],
                'action_text' => 'View portfolio', 'action_path' => '/dashboard/capital-investment',
                'title' => 'Monthly update', 'body' => 'Projected payout across :count position(s): :projected.',
            ],
            'renewal_reminder' => [
                'category' => 'payment', 'preference' => 'renewal_reminders', 'in_app' => true,
                'subject' => 'Renewal due — :property_title',
                'lines' => ['Your :holding_type for :property_title is due for renewal on :renewal_date (:amount).'],
                'action_text' => 'Pay / renew', 'action_path' => '/dashboard/property-management/holdings/:holding_id',
                'title' => 'Renewal due', 'body' => ':property_title renewal is due on :renewal_date.',
            ],

            // ------------------------------------------------------------ listings & holdings
            'listing_submitted' => [
                'category' => 'listing', 'in_app' => true,
                'subject' => 'Listing submitted — :title',
                'lines' => ['Thank you. Your listing :title (:reference) has been submitted and your registration fee received.', 'Our team will inspect and verify the property within 3–5 business days. You will receive an update via email.'],
                'action_text' => 'View listing', 'action_path' => '/dashboard/property-management/listings/:listing_id',
                'title' => 'Listing submitted', 'body' => ':title is awaiting verification.',
            ],
            'listing_approved' => [
                'category' => 'listing', 'preference' => 'listing_status_changes', 'in_app' => true,
                'subject' => 'Your listing is live — :title',
                'lines' => ['Good news! :title has been verified and is now published on the Amcom marketplace.'],
                'action_text' => 'View listing', 'action_path' => '/dashboard/property-management/listings/:listing_id',
                'title' => 'Listing published', 'body' => ':title is now live.',
            ],
            'listing_rejected' => [
                'category' => 'listing', 'preference' => 'listing_status_changes', 'in_app' => true,
                'subject' => 'Your listing needs changes — :title',
                'lines' => ['Our team reviewed :title and needs a few changes before it can be published:', ':reason', 'Update the listing and resubmit it from your dashboard.'],
                'action_text' => 'Update listing', 'action_path' => '/list-property/:listing_id/edit',
                'title' => 'Listing needs changes', 'body' => ':reason',
            ],
            'maintenance_logged' => [
                'category' => 'system', 'in_app' => true,
                'subject' => 'Maintenance request logged — :title',
                'lines' => ['We have logged your maintenance request ":title" for :property_title. Our facility team will be in touch.'],
                'title' => 'Maintenance request logged', 'body' => ':title',
            ],

            // ------------------------------------------------------------ administration
            'admin_invited' => [
                'category' => 'account',
                'subject' => "You've been invited to Amcom Console",
                'lines' => [':inviter has invited you to the Amcom Console as :role_label.', 'Set your password to get started. This link expires in :hours hours.'],
                'action_text' => 'Set your password', 'action_path' => ':invite_path',
            ],
            'admin_role_changed' => [
                'category' => 'account', 'in_app' => true,
                'subject' => 'Your admin role changed',
                'lines' => ['Your Amcom Console role is now :role_label.'],
                'title' => 'Role changed', 'body' => 'Your role is now :role_label.',
            ],
            'admin_access_revoked' => [
                'category' => 'account',
                'subject' => 'Admin access revoked',
                'lines' => ['Your access to the Amcom Console has been revoked.'],
            ],
            'export_ready' => [
                'category' => 'system',
                'subject' => 'Your user export is attached',
                'lines' => ['The export of :count user(s) you requested is attached as a CSV file.'],
            ],
        ];
    }
}
