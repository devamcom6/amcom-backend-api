<?php

namespace App\Services\Admin;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\AuthService;
use App\Services\Auth\PasswordResetService;
use App\Services\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Administrator management (contract §15.11) with privilege-escalation guards:
 *  - nobody can modify or revoke themselves (422 self_modification);
 *  - only super admins can create, modify or revoke super admins, or grant that role;
 *  - the last active super admin can never be demoted or revoked.
 */
class AdminAccountService
{
    public function __construct(
        private readonly PasswordResetService $passwords,
        private readonly AuthService $auth,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {}

    public function invite(User $actor, string $name, string $email, Role $role): User
    {
        $this->assertCanAssign($actor, $role);
        $email = mb_strtolower($email);

        $existing = User::withTrashed()->where('email', $email)->first();

        if ($existing !== null) {
            throw ApiException::field('email', $existing->isStaff()
                ? 'This email already belongs to an administrator.'
                : 'This email belongs to a customer account. Use a staff email address.');
        }

        [$first, $last] = array_pad(explode(' ', trim($name), 2), 2, null);

        $admin = DB::transaction(function () use ($first, $last, $email, $role) {
            $admin = new User(['first_name' => $first, 'last_name' => $last, 'email' => $email, 'password' => Str::random(40)]);
            $admin->forceFill(['role' => $role->value, 'status' => UserStatus::Invited]);
            $admin->save();

            return $admin;
        });

        $hours = (int) config('amcom.auth.invite_ttl_hours');
        $token = $this->passwords->createResetToken($admin, $hours * 60);

        $this->notifier->user($admin, 'admin_invited', [
            'inviter' => $actor->displayName(),
            'role_label' => $this->label($role),
            'hours' => $hours,
            'invite_path' => '/forgot-password?step=password&email='.rawurlencode($email).'&reset_token='.$token['reset_token'],
        ]);

        $this->audit->log('admin.invited', $admin, null, ['email' => $email, 'role' => $role->value], 'invited administrator', $actor, $admin->displayName());

        return $admin;
    }

    public function changeRole(User $actor, User $target, Role $role): User
    {
        $this->assertNotSelf($actor, $target);
        $this->assertCanManage($actor, $target);
        $this->assertCanAssign($actor, $role);

        $old = $target->role;

        if ($old === $role->value) {
            return $target;
        }

        DB::transaction(function () use ($target, $role) {
            if ($target->isSuperAdmin()) {
                $this->assertNotLastSuperAdmin($target);
            }

            $target->forceFill(['role' => $role->value])->save();
        });

        $target->flushPermissionCache();

        $this->notifier->user($target, 'admin_role_changed', ['role_label' => $this->label($role)]);
        $this->audit->log('admin.role_changed', $target, ['role' => $old], ['role' => $role->value], 'changed admin role', $actor, $target->displayName());

        return $target->fresh();
    }

    /** Revokes console access: the account is demoted to a customer and signed out everywhere. */
    public function revoke(User $actor, User $target): void
    {
        $this->assertNotSelf($actor, $target);
        $this->assertCanManage($actor, $target);

        $old = $target->role;

        DB::transaction(function () use ($target) {
            if ($target->isSuperAdmin()) {
                $this->assertNotLastSuperAdmin($target);
            }

            if ($target->status === UserStatus::Invited) {
                $target->forceDelete();

                return;
            }

            $target->forceFill(['role' => Role::User->value])->save();
        });

        if ($target->exists) {
            $this->auth->revokeAllTokens($target);
            $this->notifier->user($target, 'admin_access_revoked');
        }

        $this->audit->log('admin.revoked', $target->exists ? $target : null, ['role' => $old], ['role' => $target->exists ? Role::User->value : null], 'revoked admin access', $actor, $target->displayName());
    }

    private function assertNotSelf(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw new ApiException('You cannot change or revoke your own administrator access.', 'self_modification', 422);
        }
    }

    private function assertCanManage(User $actor, User $target): void
    {
        if ($target->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            throw ApiException::forbidden('Only a super admin can manage super admin accounts.');
        }
    }

    private function assertCanAssign(User $actor, Role $role): void
    {
        if ($role === Role::User) {
            throw ApiException::field('role', 'The selected role is invalid.');
        }

        if ($role === Role::SuperAdmin && ! $actor->isSuperAdmin()) {
            throw ApiException::forbidden('Only a super admin can grant the super admin role.');
        }
    }

    private function assertNotLastSuperAdmin(User $target): void
    {
        $remaining = User::query()
            ->where('role', Role::SuperAdmin->value)
            ->where('status', UserStatus::Active)
            ->whereKeyNot($target->id)
            ->lockForUpdate()
            ->count();

        if ($remaining === 0) {
            throw new ApiException('At least one active super admin is required.', 'last_super_admin', 422);
        }
    }

    private function label(Role $role): string
    {
        return $role === Role::SuperAdmin ? 'Super Admin' : 'Admin';
    }
}
