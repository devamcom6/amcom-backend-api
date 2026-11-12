<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * Strips spaces, brackets, dots and dashes from phone inputs before validation, mirroring the
 * frontend check, so "+234 (803) 555-0101" validates and is stored as "+2348035550101".
 */
class NormalizePhoneNumbers extends TransformsRequest
{
    /** Top-level input keys treated as phone numbers. */
    public const KEYS = ['phone', 'alt_phone', 'referrer_phone', 'next_of_kin_phone'];

    protected function transform($key, $value)
    {
        return is_string($value) && in_array($key, self::KEYS, true) ? self::strip($value) : $value;
    }

    public static function strip(string $value): string
    {
        return preg_replace('/[\s().\-]/u', '', $value) ?? $value;
    }
}
