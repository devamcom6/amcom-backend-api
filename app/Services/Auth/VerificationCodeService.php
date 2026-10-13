<?php

namespace App\Services\Auth;

use App\Enums\VerificationPurpose;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\DB;

/**
 * Issues and checks 6-digit one-time codes. Codes are stored as keyed hashes,
 * expire after 15 minutes and are invalidated after too many wrong attempts.
 */
class VerificationCodeService
{
    public function issue(VerificationPurpose $purpose, ?User $user = null, ?string $email = null, array $meta = []): string
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($purpose, $user, $email, $meta, $code) {
            $this->query($purpose, $user, $email)->whereNull('consumed_at')->update(['consumed_at' => now()]);

            VerificationCode::create([
                'user_id' => $user?->id,
                'email' => $email !== null ? mb_strtolower($email) : null,
                'purpose' => $purpose,
                'code_hash' => $this->hash($code),
                'meta' => $meta ?: null,
                'expires_at' => now()->addMinutes($this->ttl()),
            ]);
        });

        return $code;
    }

    /**
     * Consumes the code if it matches. Returns the record (with meta) or null.
     */
    public function consume(VerificationPurpose $purpose, string $code, ?User $user = null, ?string $email = null): ?VerificationCode
    {
        $record = $this->query($purpose, $user, $email)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($record === null) {
            return null;
        }

        if (! hash_equals($record->code_hash, $this->hash($code))) {
            $record->increment('attempts');

            if ($record->attempts >= (int) config('amcom.auth.code_max_attempts')) {
                $record->forceFill(['consumed_at' => now()])->save();
            }

            return null;
        }

        $record->forceFill(['consumed_at' => now()])->save();

        return $record;
    }

    public function ttl(): int
    {
        return (int) config('amcom.auth.code_ttl_minutes');
    }

    private function query(VerificationPurpose $purpose, ?User $user, ?string $email)
    {
        return VerificationCode::query()
            ->where('purpose', $purpose)
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->when($user === null && $email !== null, fn ($q) => $q->where('email', mb_strtolower((string) $email)));
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
