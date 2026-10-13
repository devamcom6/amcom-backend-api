<?php

namespace App\Notifications;

use App\Enums\VerificationPurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One-time codes (email verification, password reset, 2FA, phone change).
 * Mail only — codes are never stored as in-app notifications.
 */
class VerificationCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly VerificationPurpose $purpose,
        #[\SensitiveParameter] public readonly string $code,
        public readonly int $minutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $intro] = match ($this->purpose) {
            VerificationPurpose::EmailVerification => ['Verify your email', 'Use this code to verify your email address:'],
            VerificationPurpose::PasswordReset => ['Your password reset code', 'Use this code to reset your Amcom password:'],
            VerificationPurpose::TwoFactor => ['Your sign-in code', 'Use this code to finish signing in:'],
            VerificationPurpose::PhoneChange => ['Confirm your new phone number', 'Use this code to confirm your new phone number:'],
        };

        return (new MailMessage)
            ->subject($subject.' — '.$this->code)
            ->line($intro)
            ->line('**'.$this->code.'**')
            ->line("This code expires in {$this->minutes} minutes. If you did not request it, you can ignore this email.")
            ->salutation('— The Amcom team');
    }
}
