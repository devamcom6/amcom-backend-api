<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Records security-sensitive and administrative actions (who, what, before/after, where).
 * Secrets are stripped before persisting.
 */
class AuditLogger
{
    private const REDACTED_KEYS = [
        'password', 'password_confirmation', 'current_password', 'token', 'reset_token', 'code',
        'two_factor_code', 'two_factor_recovery_codes', 'remember_token', 'account_number',
        'recipient_code', 'authorization_code', 'secret', 'api_key', 'access_code',
    ];

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?string $description = null,
        ?User $actor = null,
        ?string $subjectLabel = null,
    ): AuditLog {
        $actor ??= $this->request->user();

        return AuditLog::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'description' => $description,
            'entity_type' => $subject ? $subject->getMorphClass() : null,
            'entity_id' => $subject?->getKey(),
            'entity_label' => $subjectLabel ?? $this->labelFor($subject),
            'old_values' => $old === null ? null : $this->redact($old),
            'new_values' => $new === null ? null : $this->redact($new),
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 512),
        ]);
    }

    /** @param array<string, mixed> $values */
    private function redact(array $values): array
    {
        foreach (Arr::dot($values) as $key => $value) {
            $leaf = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;

            if (in_array($leaf, self::REDACTED_KEYS, true)) {
                Arr::set($values, $key, '[redacted]');
            }
        }

        return $values;
    }

    private function labelFor(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        foreach (['title', 'name', 'reference', 'email', 'key', 'slug'] as $attribute) {
            $value = $subject->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 255);
            }
        }

        return class_basename($subject).' #'.$subject->getKey();
    }
}
