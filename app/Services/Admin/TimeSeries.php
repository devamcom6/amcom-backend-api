<?php

namespace App\Services\Admin;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Buckets rows into a labelled time series in PHP (portable across MySQL/PostgreSQL/SQLite).
 * Callers select only the needed columns for the requested range.
 */
class TimeSeries
{
    /**
     * Auto-sized buckets (≤ 12) across the range — used by the dashboard.
     *
     * @return list<array{label: string, value: int|float}>
     */
    public function build(Collection $rows, string $dateColumn, callable $aggregate, CarbonImmutable $from, CarbonImmutable $to, int $maxBuckets = 12): array
    {
        $days = (int) $from->startOfDay()->diffInDays($to->endOfDay()) + 1;
        $size = max(1, (int) ceil($days / $maxBuckets));
        $buckets = [];

        for ($cursor = $from->startOfDay(); $cursor->lte($to); $cursor = $cursor->addDays($size)) {
            $bucketEnd = $cursor->addDays($size)->subSecond()->min($to->endOfDay());
            $inBucket = $rows->filter(function ($row) use ($dateColumn, $cursor, $bucketEnd) {
                $date = CarbonImmutable::parse($row->{$dateColumn});

                return $date->betweenIncluded($cursor, $bucketEnd);
            });

            $buckets[] = ['label' => $cursor->format('j M'), 'value' => $aggregate($inBucket)];
        }

        return $buckets;
    }

    /**
     * Calendar buckets by day | week | month — used by reports.
     *
     * @return list<array{label: string, start: CarbonInterface, rows: Collection}>
     */
    public function group(Collection $rows, string $dateColumn, string $groupBy, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $groups = [];
        $cursor = match ($groupBy) {
            'day' => $from->startOfDay(),
            'week' => $from->startOfWeek(),
            default => $from->startOfMonth(),
        };

        while ($cursor->lte($to)) {
            $next = match ($groupBy) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addWeek(),
                default => $cursor->addMonthNoOverflow(),
            };

            $label = match ($groupBy) {
                'day' => $cursor->format('j M'),
                'week' => 'Wk of '.$cursor->format('j M'),
                default => $cursor->format($from->year === $to->year ? 'M' : 'M Y'),
            };

            $groups[] = [
                'label' => $label,
                'start' => $cursor,
                'rows' => $rows->filter(function ($row) use ($dateColumn, $cursor, $next) {
                    $date = CarbonImmutable::parse($row->{$dateColumn});

                    return $date->gte($cursor) && $date->lt($next);
                })->values(),
            ];

            $cursor = $next;
        }

        return $groups;
    }
}
