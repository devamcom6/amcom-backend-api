<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Display formatting for backend-generated copy (payment summaries, emails, PDFs).
 */
final class Format
{
    public static function naira(int|float|null $amount, bool $signed = false): string
    {
        $amount = (int) round((float) $amount);
        $prefix = $signed && $amount > 0 ? '+' : ($amount < 0 ? '-' : '');

        return $prefix.'₦'.number_format(abs($amount));
    }

    public static function percent(int|float|string|null $value): string
    {
        $value = (float) $value;

        return (fmod($value, 1.0) === 0.0 ? (string) (int) $value : rtrim(rtrim(number_format($value, 2), '0'), '.')).'%';
    }

    /** "Aug 5, 2026" */
    public static function date(CarbonInterface|string|null $date): string
    {
        return $date ? Carbon::parse($date)->format('M j, Y') : '';
    }

    /** "5 Aug 2027" */
    public static function dateLong(CarbonInterface|string|null $date): string
    {
        return $date ? Carbon::parse($date)->format('j M Y') : '';
    }

    public static function months(int $months): string
    {
        return $months.' '.($months === 1 ? 'month' : 'months');
    }

    /** ISO-8601 with timezone, or null (contract §1.7). */
    public static function iso(\DateTimeInterface|string|null $value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601ZuluString() : null;
    }

    public static function ymd(\DateTimeInterface|string|null $value): ?string
    {
        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    public static function maskPhone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        if (strlen($digits) < 4) {
            return '•••';
        }

        $prefix = str_starts_with((string) $phone, '+') ? '+'.substr($digits, 0, 3).' ' : '';

        return $prefix.'••• ••• '.substr($digits, -4);
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));

        return $visible.str_repeat('•', 4).'@'.$domain;
    }
}
