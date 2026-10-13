<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\OpsAlert;
use App\Notifications\UserEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Convenience facade over Laravel notifications for business events.
 * All notifications are queued; a failure to enqueue never breaks the business operation.
 */
class Notifier
{
    public function __construct(private readonly SettingsService $settings) {}

    public function user(User $user, string $event, array $data = [], array $documentIds = [], array $rawAttachments = []): void
    {
        $this->safely($event, fn () => $user->notify(new UserEvent($event, $data, $documentIds, $rawAttachments)));
    }

    /** For visitors without an account (leads). */
    public function email(string $email, string $event, array $data = [], array $rawAttachments = []): void
    {
        $this->safely($event, fn () => Notification::route('mail', $email)->notify(new UserEvent($event, $data, [], $rawAttachments)));
    }

    public function ops(string $type, string $subject, array $details = [], ?string $adminPath = null): void
    {
        $to = $this->settings->adminAlertEmail();

        if (! $to) {
            return;
        }

        $this->safely('ops.'.$type, fn () => Notification::route('mail', $to)->notify(new OpsAlert($type, $subject, $details, $adminPath)));
    }

    private function safely(string $event, callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            Log::error('notification.dispatch_failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
