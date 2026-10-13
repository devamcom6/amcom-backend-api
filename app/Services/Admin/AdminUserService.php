<?php

namespace App\Services\Admin;

use App\Enums\UserStatus;
use App\Jobs\ExportUsersJob;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\AuthService;
use App\Services\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Customer account administration (contract §15.2). Staff accounts are managed via AdminAccountService. */
class AdminUserService
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    public function changeStatus(User $actor, User $user, UserStatus $status, ?string $reason): User
    {
        $old = $user->status;

        if ($old === $status) {
            return $user;
        }

        DB::transaction(function () use ($user, $status, $reason) {
            $user->forceFill(['status' => $status, 'status_reason' => $status === UserStatus::Suspended ? $reason : null])->save();
        });

        if ($status === UserStatus::Suspended) {
            $this->auth->revokeAllTokens($user);
            $this->notifier->user($user, 'account_suspended', ['reason' => $reason ? 'Reason: '.$reason : '']);
        } elseif ($status === UserStatus::Active) {
            $this->notifier->user($user, 'account_reactivated');
        }

        $this->audit->log(
            'user.status_changed',
            $user,
            ['status' => $old->value],
            ['status' => $status->value, 'reason' => $reason],
            $status === UserStatus::Suspended ? 'suspended user' : 'reactivated user',
            $actor,
            $user->displayName(),
        );

        return $user->fresh();
    }

    /**
     * @param  list<int>  $ids
     * @return array{processed: int, failed: int}
     */
    public function bulk(User $actor, string $action, array $ids): array
    {
        $users = User::query()->customers()->whereIn('id', $ids)->get();
        $processed = 0;
        $failed = count(array_unique($ids)) - $users->count(); // unknown / non-customer ids

        if ($action === 'export') {
            ExportUsersJob::dispatch($actor->id, $users->modelKeys());
            $this->audit->log('user.bulk_export', null, null, ['count' => $users->count()], 'exported users', $actor);

            return ['processed' => $users->count(), 'failed' => $failed];
        }

        foreach ($users as $user) {
            try {
                match ($action) {
                    'suspend' => $this->changeStatus($actor, $user, UserStatus::Suspended, 'Bulk action by administrator'),
                    'activate' => $this->changeStatus($actor, $user, UserStatus::Active, null),
                    'resend_verification' => $user->hasVerifiedEmail()
                        ? throw new \DomainException('already verified')
                        : $this->auth->sendEmailVerificationCode($user),
                };
                $processed++;
            } catch (Throwable $e) {
                $failed++;
                Log::info('admin.bulk_item_failed', ['action' => $action, 'user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }
}
