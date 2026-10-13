<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Internal alert to the operations inbox (admin_alert_email setting). */
class OpsAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @param  array<string, scalar|null>  $details
     */
    public function __construct(
        public readonly string $type,
        public readonly string $subject,
        public readonly array $details = [],
        public readonly ?string $adminPath = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject('[Amcom Ops] '.$this->subject)->greeting($this->subject);

        foreach ($this->details as $label => $value) {
            if ($value !== null && $value !== '') {
                $message->line(ucfirst(str_replace('_', ' ', (string) $label)).': '.$value);
            }
        }

        if ($this->adminPath) {
            $message->action('Open in console', config('amcom.frontend_url').$this->adminPath);
        }

        return $message;
    }
}
