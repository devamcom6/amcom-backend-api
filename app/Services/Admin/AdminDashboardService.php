<?php

namespace App\Services\Admin;

use App\Enums\InvestmentStatus;
use App\Enums\ListingStatus;
use App\Enums\RequestStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Investment;
use App\Models\Property;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Single aggregated payload for the admin dashboard (contract §15.1).
 * All figures come from grouped queries — no per-row lookups.
 */
class AdminDashboardService
{
    public function __construct(private readonly TimeSeries $series) {}

    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->startOfDay();
        $end = $to->endOfDay();
        $days = (int) $start->diffInDays($end) + 1;
        $prevStart = $start->subDays($days);
        $prevEnd = $start->subSecond();

        $customers = User::query()->customers();
        $revenue = $this->revenue($start, $end);
        $prevRevenue = $this->revenue($prevStart, $prevEnd);
        $transactions = Transaction::query()->whereBetween('created_at', [$start, $end])->count();
        $prevTransactions = Transaction::query()->whereBetween('created_at', [$prevStart, $prevEnd])->count();
        $signups = (clone $customers)->whereBetween('created_at', [$start, $end])->count();
        $prevSignups = (clone $customers)->whereBetween('created_at', [$prevStart, $prevEnd])->count();

        $aumStatuses = [InvestmentStatus::Active, InvestmentStatus::Matured];
        $aum = (int) Investment::query()->whereIn('status', $aumStatuses)->sum('principal');
        $aumPrevious = (int) Investment::query()->whereIn('status', $aumStatuses)->where('started_at', '<', $start->toDateString())->sum('principal');

        $investmentStatus = Investment::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'range' => ['from' => $start->toDateString(), 'to' => $to->toDateString()],
            'summary' => [
                'total_users' => (clone $customers)->count(),
                'active_users' => (clone $customers)->where('status', UserStatus::Active)->count(),
                'total_transactions' => $transactions,
                'total_revenue' => $revenue,
                'assets_under_management' => $aum,
                'pending_listings' => Property::query()->where('status', ListingStatus::PendingVerification)->count(),
                'open_requests' => ServiceRequest::query()->whereIn('status', [RequestStatus::New, RequestStatus::InProgress])->count(),
            ],
            'trends' => [
                'users' => $this->change($signups, $prevSignups),
                'revenue' => $this->change($revenue, $prevRevenue),
                'transactions' => $this->change($transactions, $prevTransactions),
                'assets_under_management' => $this->change($aum, $aumPrevious),
            ],
            'revenue_series' => $this->series->build(
                Transaction::query()->successful()->whereBetween('paid_at', [$start, $end])->get(['paid_at', 'amount']),
                'paid_at', fn ($rows) => (int) $rows->sum('amount'), $start, $end,
            ),
            'signups_series' => $this->series->build(
                (clone $customers)->whereBetween('created_at', [$start, $end])->get(['created_at']),
                'created_at', fn ($rows) => $rows->count(), $start, $end,
            ),
            'status_distribution' => collect([
                ['key' => 'active', 'label' => 'Active', 'value' => (int) ($investmentStatus[InvestmentStatus::Active->value] ?? 0)],
                ['key' => 'pending', 'label' => 'Pending', 'value' => (int) collect([InvestmentStatus::PendingSignature, InvestmentStatus::PendingPayment, InvestmentStatus::PaymentProcessing])->sum(fn ($s) => $investmentStatus[$s->value] ?? 0)],
                ['key' => 'matured', 'label' => 'Matured', 'value' => (int) ($investmentStatus[InvestmentStatus::Matured->value] ?? 0)],
                ['key' => 'paid_out', 'label' => 'Paid out', 'value' => (int) ($investmentStatus[InvestmentStatus::PaidOut->value] ?? 0)],
                ['key' => 'cancelled', 'label' => 'Cancelled', 'value' => (int) ($investmentStatus[InvestmentStatus::Cancelled->value] ?? 0)],
            ])->all(),
            'transaction_summary' => Transaction::query()
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw('status, count(*) as count, coalesce(sum(amount), 0) as amount')
                ->groupBy('status')
                ->get()
                ->map(fn (Transaction $row) => ['status' => $row->status->value, 'count' => (int) $row->getAttribute('count'), 'amount' => (int) $row->getAttribute('amount')])
                ->values()
                ->all(),
            'recent_activity' => AuditLog::query()
                ->with('actor:id,first_name,last_name,email')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'actor' => $log->actor?->displayName() ?? 'System',
                    'action' => $log->description ?? str_replace(['.', '_'], ' ', $log->action),
                    'subject' => $log->entity_label,
                    'created_at' => $log->created_at->toIso8601ZuluString(),
                ])
                ->all(),
        ];
    }

    private function revenue(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) Transaction::query()->successful()->whereBetween('paid_at', [$from, $to])->sum('amount');
    }

    private function change(int|float $current, int|float $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
