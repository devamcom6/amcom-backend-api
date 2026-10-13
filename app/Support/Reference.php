<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Human-readable references. Sequential ones are derived from the primary key
 * after insert, so they are unique without extra locking.
 */
final class Reference
{
    /** e.g. BB-2026-05012 */
    public static function yearly(string $prefix, int $id): string
    {
        return sprintf('%s-%s-%05d', $prefix, now()->format('Y'), $id);
    }

    /** e.g. INT-5081 */
    public static function short(string $prefix, int $id): string
    {
        return sprintf('%s-%d', $prefix, 5000 + $id);
    }

    /** Payment reference: unguessable, unique, Paystack-safe. e.g. PAY-7KQ2M9XA4D1B */
    public static function payment(): string
    {
        return 'PAY-'.strtoupper(Str::random(12));
    }

    /** Placeholder unique value used before the id-based reference is known. */
    public static function temporary(): string
    {
        return 'TMP-'.Str::uuid()->toString();
    }
}
