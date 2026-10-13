<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Minimal RFC 5545 single-event calendar builder (inspection invites). */
final class IcsCalendar
{
    public static function event(string $uid, string $summary, string $description, string $location, CarbonInterface $start, int $durationMinutes = 60): string
    {
        $fmt = fn (CarbonInterface $d) => $d->copy()->utc()->format('Ymd\THis\Z');
        $escape = fn (string $v) => str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $v);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Amcom//Inspections//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.$fmt(now()),
            'DTSTART:'.$fmt($start),
            'DTEND:'.$fmt($start->copy()->addMinutes($durationMinutes)),
            'SUMMARY:'.$escape($summary),
            'DESCRIPTION:'.$escape($description),
            'LOCATION:'.$escape($location),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines)."\r\n";
    }
}
