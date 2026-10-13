<?php

namespace App\Enums;

enum PaymentStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Processing = 'processing';
    case Success = 'success';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
    case Reversed = 'reversed';

    public static function fromPaystack(?string $status): self
    {
        return match (strtolower((string) $status)) {
            'success' => self::Success,
            'failed' => self::Failed,
            'abandoned' => self::Abandoned,
            'reversed' => self::Reversed,
            'processing', 'ongoing', 'queued' => self::Processing,
            default => self::Pending,
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Success, self::Failed, self::Abandoned, self::Reversed], true);
    }
}
