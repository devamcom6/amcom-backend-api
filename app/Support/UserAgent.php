<?php

namespace App\Support;

/** Minimal user-agent summariser for login activity ("Chrome on MacOS"). */
final class UserAgent
{
    public static function describe(?string $ua): string
    {
        $ua = (string) $ua;

        if ($ua === '') {
            return 'Unknown device';
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'okhttp') || str_contains($ua, 'Dart') => 'Mobile app',
            default => 'Browser',
        };

        $os = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'MacOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $os ? "$browser on $os" : $browser;
    }

    /** "102.89.x.x" — enough to recognise a network without exposing the full address. */
    public static function maskIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return $parts[0].'.'.$parts[1].'.x.x';
        }

        $parts = explode(':', $ip);

        return implode(':', array_slice($parts, 0, 3)).':x:x';
    }
}
