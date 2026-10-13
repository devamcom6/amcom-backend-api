<?php

namespace App\Models;

use App\Enums\InvestmentStatus;
use App\Enums\KycStatus;
use App\Enums\Role as RoleEnum;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $email
 * @property string|null $phone
 * @property string $role
 * @property UserStatus $status
 * @property KycStatus $kyc_status
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Only profile fields are mass assignable. `role`, `status`, `kyc_status`,
     * `email_verified_at` and 2FA fields are always set explicitly by services.
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'google_id',
    ];

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'kyc_status' => KycStatus::class,
            'two_factor_enabled' => 'boolean',
            'two_factor_recovery_codes' => 'encrypted:array',
            'profile_completed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'deletion_scheduled_at' => 'datetime',
        ];
    }

    /** @return Attribute<non-falsy-string|null, never> Full name, or null when no name is set. */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): ?string => trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: null);
    }

    /** Name for greetings/labels, falling back to the email local part. */
    public function displayName(): string
    {
        return $this->name ?? ucfirst(strtok($this->email, '@'));
    }

    // ---------------------------------------------------------------- relations

    /** @return BelongsTo<Role, $this> */
    public function roleDefinition(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role', 'name');
    }

    /** @return HasOne<UserProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function avatar(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'avatar_media_id');
    }

    /** @return HasMany<IdentityVerification, $this> */
    public function identityVerifications(): HasMany
    {
        return $this->hasMany(IdentityVerification::class);
    }

    /** @return HasOne<IdentityVerification, $this> */
    public function latestIdentityVerification(): HasOne
    {
        return $this->hasOne(IdentityVerification::class)->latestOfMany();
    }

    /** @return HasMany<Investment, $this> */
    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class);
    }

    /** @return HasMany<PayoutAccount, $this> */
    public function payoutAccounts(): HasMany
    {
        return $this->hasMany(PayoutAccount::class);
    }

    /** @return HasMany<Property, $this> */
    public function listings(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /** @return HasMany<PropertyHolding, $this> */
    public function holdings(): HasMany
    {
        return $this->hasMany(PropertyHolding::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<NotificationPreference, $this> */
    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /** @return HasMany<LoginActivity, $this> */
    public function loginActivities(): HasMany
    {
        return $this->hasMany(LoginActivity::class);
    }

    // ---------------------------------------------------------------- roles & permissions

    public function isStaff(): bool
    {
        return in_array($this->role, RoleEnum::staff(), true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === RoleEnum::SuperAdmin->value;
    }

    /**
     * Permissions are resolved from the database on every request (never stored in the token),
     * so role edits and revocations apply immediately (contract §2 "Revocation").
     *
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        if (! $this->isStaff()) {
            return $this->permissionCache = [];
        }

        if ($this->isSuperAdmin()) {
            return $this->permissionCache = Permission::query()->orderBy('id')->pluck('key')->all();
        }

        return $this->permissionCache = Permission::query()
            ->whereHas('roles', fn (Builder $q) => $q->where('name', $this->role))
            ->orderBy('id')
            ->pluck('key')
            ->all();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->isStaff() && in_array($permission, $this->permissionKeys(), true);
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
    }

    // ---------------------------------------------------------------- helpers

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function totalInvested(): int
    {
        return (int) $this->investments()
            ->whereIn('status', [InvestmentStatus::Active, InvestmentStatus::Matured, InvestmentStatus::PaidOut])
            ->sum('principal');
    }

    /** Whether an optional (preference-controlled) notification may be sent. */
    public function wantsNotification(string $key): bool
    {
        $definition = config("amcom.notification_preferences.$key");

        if ($definition === null || ($definition['locked'] ?? false)) {
            return true;
        }

        $stored = $this->relationLoaded('notificationPreferences')
            ? $this->notificationPreferences->firstWhere('key', $key)
            : $this->notificationPreferences()->where('key', $key)->first();

        return $stored ? (bool) $stored->enabled : (bool) $definition['default'];
    }

    public function scopeCustomers(Builder $query): Builder
    {
        return $query->where('role', RoleEnum::User->value);
    }

    public function scopeStaff(Builder $query): Builder
    {
        return $query->whereIn('role', RoleEnum::staff());
    }
}
