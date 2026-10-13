<?php

namespace App\Enums;

/**
 * Lifecycle of an investment (buyback order or capital investment).
 * The same row is the "order" before payment and the "position" afterwards.
 */
enum InvestmentStatus: string
{
    use HasValues;

    case InterestSubmitted = 'interest_submitted';
    case PendingSignature = 'pending_signature';
    case PendingPayment = 'pending_payment';
    case PaymentProcessing = 'payment_processing';
    case Active = 'active';
    case Matured = 'matured';
    case PaidOut = 'paid_out';
    case Cancelled = 'cancelled';

    /** Statuses that represent a funded position shown in portfolios. */
    public static function positionStatuses(): array
    {
        return [self::PaymentProcessing, self::Active, self::Matured, self::PaidOut];
    }

    /** Statuses whose plot reservations are still held. */
    public static function holdingReservation(): array
    {
        return [self::PendingSignature, self::PendingPayment, self::PaymentProcessing];
    }

    /** Position status as exposed by `BuybackPosition` / `CapitalPosition`. */
    public function positionStatus(): string
    {
        return match ($this) {
            self::Active => 'active',
            self::Matured => 'matured',
            self::PaidOut => 'paid_out',
            self::Cancelled => 'cancelled',
            default => 'pending',
        };
    }

    /** Admin-permitted manual transitions (contract §15.5). */
    public function canTransitionTo(self $target): bool
    {
        return match ($target) {
            self::Cancelled => in_array($this, [self::InterestSubmitted, self::PendingSignature, self::PendingPayment], true),
            self::PaidOut => $this === self::Matured,
            default => false,
        };
    }
}
