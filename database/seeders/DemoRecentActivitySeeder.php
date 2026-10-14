<?php

namespace Database\Seeders;

use App\Enums\InvestmentStatus;
use App\Enums\PayableType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\Product;
use App\Enums\RequestKind;
use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Investments\QuoteCalculator;
use App\Support\Reference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Recent payments, sign-ups and requests spread over the last 30 days, so the admin dashboard
 * and reports show realistic trends. Safe to re-run: skips if recent demo activity exists.
 *
 *   php artisan db:seed --class=DemoRecentActivitySeeder
 */
class DemoRecentActivitySeeder extends Seeder
{
    public function run(QuoteCalculator $quotes): void
    {
        if (app()->isProduction()) {
            return;
        }

        if (Transaction::query()->where('metadata->demo', 'recent')->exists()) {
            $this->command->info('Recent demo activity already present; skipping.');

            return;
        }

        $customers = User::query()->customers()->where('status', 'active')->orderBy('id')->get();

        if ($customers->isEmpty()) {
            $this->command->warn('No customers to attach activity to; run DemoSeeder first.');

            return;
        }

        // [days ago, amount, tenure, roi, status]
        $payments = [
            [1, 1_500_000, 6, 18, PaymentStatus::Success],
            [3, 5_000_000, 12, 27, PaymentStatus::Success],
            [5, 2_000_000, 6, 18, PaymentStatus::Failed],
            [8, 10_000_000, 12, 27, PaymentStatus::Success],
            [11, 3_000_000, 6, 18, PaymentStatus::Success],
            [14, 1_000_000, 6, 18, PaymentStatus::Abandoned],
            [18, 7_500_000, 12, 27, PaymentStatus::Success],
            [22, 2_500_000, 6, 18, PaymentStatus::Success],
            [26, 4_000_000, 12, 27, PaymentStatus::Success],
            [29, 1_200_000, 6, 18, PaymentStatus::Success],
        ];

        foreach ($payments as $i => [$daysAgo, $amount, $tenure, $roi, $status]) {
            $user = $customers[$i % $customers->count()];
            $when = CarbonImmutable::now()->subDays($daysAgo)->setTime(10 + $i % 6, 15);
            $paid = $status === PaymentStatus::Success;
            $quote = $quotes->quote($amount, $tenure, $roi, $when);

            $investment = $user->investments()->create([
                'product' => Product::CapitalInvestment,
                'reference' => Reference::temporary(),
                'status' => $paid ? InvestmentStatus::Active : InvestmentStatus::PendingPayment,
                'principal' => $amount,
                'tenure_months' => $tenure,
                'roi_percent' => $roi,
                'projected_return' => $quote['projected_return'],
                'started_at' => $paid ? $when->toDateString() : null,
                'maturity_date' => $quote['maturity_date'],
                'agreement_signed_at' => $when->subMinutes(10),
            ]);
            $investment->forceFill(['reference' => Reference::yearly('CI', $investment->id), 'created_at' => $when, 'updated_at' => $when])->saveQuietly();

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'reference' => Reference::payment(),
                'provider' => 'paystack',
                'provider_reference' => (string) random_int(100000000, 999999999),
                'payable_type' => PayableType::CapitalInvestment,
                'payable_id' => $investment->id,
                'description' => 'Capital investment — '.$tenure.'-month plan',
                'amount' => $amount,
                'fee' => $paid ? min(2000, (int) round($amount * 0.015)) : 0,
                'currency' => 'NGN',
                'method' => $i % 3 === 0 ? PaymentMethod::BankTransfer : PaymentMethod::Card,
                'channel' => $i % 3 === 0 ? 'bank_transfer' : 'card',
                'status' => $status,
                'gateway_response' => match ($status) {
                    PaymentStatus::Success => 'Approved',
                    PaymentStatus::Failed => 'Declined by issuing bank',
                    default => 'The transaction was not completed',
                },
                'metadata' => ['demo' => 'recent'],
                'paid_at' => $paid ? $when : null,
                'failed_at' => $paid ? null : $when,
                'verified_at' => $when,
                'effects_applied_at' => $paid ? $when : null,
            ]);
            $transaction->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();
        }

        // New sign-ups across the window.
        foreach (range(1, 12) as $i) {
            $user = User::factory()->create();
            $at = now()->subDays(($i * 5) % 30)->subHours($i);
            $user->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
        }

        // Fresh leads for the requests queue.
        foreach ([RequestKind::Interest, RequestKind::Inspection, RequestKind::Contact] as $i => $kind) {
            $request = ServiceRequest::create([
                'kind' => $kind,
                'reference' => Reference::temporary(),
                'name' => fake()->name(),
                'email' => fake()->safeEmail(),
                'phone' => '+23480'.fake()->numerify('########'),
                'subject_line' => ucfirst($kind->value).' (recent demo)',
                'message' => fake()->sentence(),
                'status' => RequestStatus::New,
                'scheduled_for' => $kind === RequestKind::Inspection ? now()->addDays(2)->setTime(11, 0) : null,
            ]);
            $request->forceFill(['reference' => Reference::short(['INT', 'INS', 'MSG'][$i], $request->id)])->save();
        }

        $this->command->info('Seeded recent demo activity (10 payments, 12 sign-ups, 3 requests) over the last 30 days.');
    }
}
