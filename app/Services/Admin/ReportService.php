<?php

namespace App\Services\Admin;

use App\Enums\ListingStatus;
use App\Enums\Product;
use App\Enums\ReportType;
use App\Models\Investment;
use App\Models\Property;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin reports (contract §15.10) and their CSV export. */
class ReportService
{
    public function __construct(private readonly TimeSeries $series) {}

    public function build(ReportType $type, CarbonImmutable $from, CarbonImmutable $to, string $groupBy): array
    {
        $start = $from->startOfDay();
        $end = $to->endOfDay();
        $days = (int) $start->diffInDays($end) + 1;
        $prevStart = $start->subDays($days);
        $prevEnd = $start->subSecond();

        [$rows, $previousRows, $dateColumn, $aggregate, $valueLabel, $format, $totalLabel, $extraTotals] = match ($type) {
            ReportType::Revenue => [
                Transaction::query()->successful()->whereBetween('paid_at', [$start, $end])->get(['paid_at', 'amount', 'payable_type']),
                Transaction::query()->successful()->whereBetween('paid_at', [$prevStart, $prevEnd])->get(['amount']),
                'paid_at', fn ($r) => (int) $r->sum('amount'), 'Revenue', 'currency', 'Total revenue',
                fn ($r) => [['label' => 'Successful payments', 'value' => $r->count(), 'format' => 'number']],
            ],
            ReportType::Investments => [
                Investment::query()->whereNotNull('started_at')->whereBetween('started_at', [$start->toDateString(), $end->toDateString()])->get(['started_at', 'principal', 'product']),
                Investment::query()->whereNotNull('started_at')->whereBetween('started_at', [$prevStart->toDateString(), $prevEnd->toDateString()])->get(['principal']),
                'started_at', fn ($r) => (int) $r->sum('principal'), 'Amount invested', 'currency', 'Total invested',
                fn ($r) => [
                    ['label' => 'New positions', 'value' => $r->count(), 'format' => 'number'],
                    ['label' => 'Buyback', 'value' => (int) $r->where('product', Product::Buyback)->sum('principal'), 'format' => 'currency'],
                    ['label' => 'Capital investment', 'value' => (int) $r->where('product', Product::CapitalInvestment)->sum('principal'), 'format' => 'currency'],
                ],
            ],
            ReportType::Users => [
                User::query()->customers()->whereBetween('created_at', [$start, $end])->get(['created_at', 'email_verified_at']),
                User::query()->customers()->whereBetween('created_at', [$prevStart, $prevEnd])->get(['id']),
                'created_at', fn ($r) => $r->count(), 'Sign-ups', 'number', 'New users',
                fn ($r) => [['label' => 'Verified email', 'value' => $r->whereNotNull('email_verified_at')->count(), 'format' => 'number']],
            ],
            ReportType::Listings => [
                Property::query()->whereNotNull('user_id')->whereBetween('created_at', [$start, $end])->get(['created_at', 'status']),
                Property::query()->whereNotNull('user_id')->whereBetween('created_at', [$prevStart, $prevEnd])->get(['id']),
                'created_at', fn ($r) => $r->count(), 'Listings created', 'number', 'Listings created',
                fn ($r) => [
                    ['label' => 'Published', 'value' => $r->where('status', ListingStatus::Published)->count(), 'format' => 'number'],
                    ['label' => 'Pending verification', 'value' => $r->where('status', ListingStatus::PendingVerification)->count(), 'format' => 'number'],
                ],
            ],
        };

        $total = $aggregate($rows);
        $previous = $aggregate($previousRows);
        $groups = $this->series->group($rows, $dateColumn, $groupBy, $start, $end);
        $series = array_map(fn ($g) => ['label' => $g['label'], 'value' => $aggregate($g['rows'])], $groups);

        return [
            'type' => $type->value,
            'range' => ['from' => $start->toDateString(), 'to' => $to->toDateString()],
            'group_by' => $groupBy,
            'totals' => array_merge(
                [['label' => $totalLabel, 'value' => $total, 'format' => $format]],
                [['label' => 'Change vs previous', 'value' => $this->change($total, $previous), 'format' => 'percent']],
                $extraTotals($rows),
            ),
            'series' => $series,
            'columns' => [
                ['key' => 'period', 'label' => 'Period'],
                ['key' => 'value', 'label' => $valueLabel, 'format' => $format],
                ['key' => 'share', 'label' => 'Share of total'],
            ],
            'rows' => array_map(fn ($point) => [
                'period' => $point['label'],
                'value' => $point['value'],
                'share' => $total > 0 ? round($point['value'] / $total * 100, 1).'%' : '0%',
            ], $series),
        ];
    }

    public function exportCsv(array $report): StreamedResponse
    {
        $filename = sprintf('amcom-%s-report-%s-to-%s.csv', $report['type'], $report['range']['from'], $report['range']['to']);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_column($report['columns'], 'label'));

            foreach ($report['rows'] as $row) {
                fputcsv($out, array_map(fn ($col) => $this->csvSafe($row[$col['key']] ?? ''), $report['columns']));
            }

            fputcsv($out, []);
            foreach ($report['totals'] as $total) {
                fputcsv($out, [$total['label'], $total['value']]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Prevents CSV/formula injection when opened in spreadsheets. */
    private function csvSafe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function change(int|float $current, int|float $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
