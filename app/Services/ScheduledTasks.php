<?php

namespace App\Services;

use App\Enums\HoldingStatus;
use App\Enums\HoldingType;
use App\Enums\InvestmentStatus;
use App\Enums\RequestKind;
use App\Enums\RequestStatus;
use App\Enums\UserStatus;
use App\Models\Investment;
use App\Models\Property;
use App\Models\PropertyHolding;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Business logic behind the scheduled console commands (routes/console.php). */
class ScheduledTasks
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly SettingsService $settings,
    ) {}

    /** active → matured when the maturity date is reached. */
    public function matureInvestments(): int
    {
        $count = Investment::query()
            ->where('status', InvestmentStatus::Active)
            ->whereDate('maturity_date', '<=', today())
            ->update(['status' => InvestmentStatus::Matured, 'updated_at' => now()]);

        Log::info('scheduler.investments_matured', ['count' => $count]);

        return $count;
    }

    /** Reminder N days before maturity (setting notifications.send_maturity_reminders_days_before). */
    public function maturityReminders(): int
    {
        $days = (int) $this->settings->get('notifications.send_maturity_reminders_days_before', 30);
        $sent = 0;

        Investment::query()
            ->with('user')
            ->where('status', InvestmentStatus::Active)
            ->whereDate('maturity_date', today()->addDays($days))
            ->chunkById(200, function ($investments) use (&$sent) {
                foreach ($investments as $investment) {
                    $this->notifier->user($investment->user, 'maturity_reminder', [
                        'reference' => $investment->reference,
                        'plan' => $investment->isBuyback() ? 'buyback' : $investment->planName(),
                        'maturity_date' => Format::dateLong($investment->maturity_date),
                        'amount' => Format::naira($investment->projected_return),
                        'next_url' => $investment->isBuyback() ? '/dashboard/buyback/'.$investment->id : '/dashboard/capital-investment/'.$investment->id,
                    ]);
                    $sent++;
                }
            });

        return $sent;
    }

    /** Lease/rent renewals: reminder on the renewal date; flags holdings as expiring/expired. */
    public function renewalReminders(): int
    {
        $sent = 0;

        PropertyHolding::query()
            ->whereIn('holding_type', [HoldingType::Lease, HoldingType::Rent])
            ->where('status', HoldingStatus::Active)
            ->whereDate('renewal_date', '<=', today())
            ->update(['status' => HoldingStatus::Expiring]);

        PropertyHolding::query()
            ->whereIn('holding_type', [HoldingType::Lease, HoldingType::Rent])
            ->whereIn('status', [HoldingStatus::Active, HoldingStatus::Expiring])
            ->whereDate('period_end', '<', today())
            ->update(['status' => HoldingStatus::Expired]);

        PropertyHolding::query()
            ->with(['user', 'property'])
            ->whereIn('holding_type', [HoldingType::Lease, HoldingType::Rent])
            ->whereDate('renewal_date', today())
            ->chunkById(200, function ($holdings) use (&$sent) {
                foreach ($holdings as $holding) {
                    $this->notifier->user($holding->user, 'renewal_reminder', [
                        'holding_type' => $holding->holding_type->value,
                        'property_title' => $holding->property?->title,
                        'renewal_date' => Format::dateLong($holding->renewal_date),
                        'amount' => Format::naira($holding->rate),
                        'holding_id' => $holding->id,
                    ]);
                    $sent++;
                }
            });

        return $sent;
    }

    /** Monthly portfolio summary to customers with open positions (preference roi_updates). */
    public function roiUpdates(): int
    {
        $sent = 0;

        User::query()
            ->customers()
            ->where('status', UserStatus::Active)
            ->whereHas('investments', fn ($q) => $q->where('status', InvestmentStatus::Active))
            ->withCount(['investments as active_count' => fn ($q) => $q->where('status', InvestmentStatus::Active)])
            ->withSum(['investments as active_principal' => fn ($q) => $q->where('status', InvestmentStatus::Active)], 'principal')
            ->withSum(['investments as active_projected' => fn ($q) => $q->where('status', InvestmentStatus::Active)], 'projected_return')
            ->chunkById(200, function ($users) use (&$sent) {
                foreach ($users as $user) {
                    $this->notifier->user($user, 'roi_update', [
                        'count' => (int) $user->getAttribute('active_count'),
                        'principal' => Format::naira((int) $user->getAttribute('active_principal')),
                        'projected' => Format::naira((int) $user->getAttribute('active_projected')),
                    ]);
                    $sent++;
                }
            });

        return $sent;
    }

    /** Inspection reminders ~24 h ahead (run hourly; each booking is reminded once). */
    public function inspectionReminders(): int
    {
        $sent = 0;
        $tz = config('amcom.inspections.timezone');

        ServiceRequest::query()
            ->with('subject')
            ->where('kind', RequestKind::Inspection)
            ->whereIn('status', [RequestStatus::New, RequestStatus::InProgress])
            ->whereBetween('scheduled_for', [now()->addHours(23), now()->addHours(25)])
            ->chunkById(200, function ($requests) use (&$sent, $tz) {
                foreach ($requests as $request) {
                    if ($request->meta['reminded_at'] ?? null) {
                        continue;
                    }

                    $this->notifier->email($request->email, 'inspection_reminder', [
                        'name' => $request->name,
                        'property_title' => $request->subject instanceof Property ? $request->subject->title : 'the property',
                        'inspection_type' => ($request->meta['type'] ?? 'in_person') === 'virtual' ? 'virtual' : 'in-person',
                        'scheduled_label' => CarbonImmutable::parse($request->scheduled_for)->timezone($tz)->format('l, j M Y \a\t g:i A'),
                    ]);

                    $request->forceFill(['meta' => array_merge($request->meta ?? [], ['reminded_at' => now()->toIso8601String()])])->save();
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * Anonymises accounts 30 days after deletion was requested. Financial records
     * (investments, transactions) are retained but no longer linked to personal data.
     */
    public function anonymiseDeletedAccounts(int $graceDays = 30): int
    {
        $count = 0;

        User::query()
            ->where('status', UserStatus::PendingDeletion)
            ->where('deletion_scheduled_at', '<=', now()->subDays($graceDays))
            ->chunkById(100, function ($users) use (&$count) {
                foreach ($users as $user) {
                    DB::transaction(function () use ($user) {
                        $user->forceFill([
                            'first_name' => 'Deleted',
                            'last_name' => 'User',
                            'email' => 'deleted-'.$user->id.'-'.Str::lower(Str::random(6)).'@deleted.invalid',
                            'phone' => null,
                            'google_id' => null,
                            'password' => Str::random(64),
                            'two_factor_enabled' => false,
                            'two_factor_recovery_codes' => null,
                            'avatar_media_id' => null,
                        ])->save();

                        $user->profile()->delete();
                        $user->payoutAccounts()->withTrashed()->forceDelete();
                        $user->loginActivities()->delete();
                        $user->notifications()->delete();
                        $user->notificationPreferences()->delete();
                        $user->identityVerifications()->update([
                            'first_name' => null, 'last_name' => null, 'date_of_birth' => null,
                            'gender' => null, 'nationality' => null, 'id_number_last4' => null,
                        ]);
                        $user->delete(); // soft delete
                    });
                    $count++;
                }
            });

        Log::info('scheduler.accounts_anonymised', ['count' => $count]);

        return $count;
    }
}
