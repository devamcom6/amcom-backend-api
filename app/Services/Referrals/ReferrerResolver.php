<?php

namespace App\Services\Referrals;

use App\Models\User;

/**
 * Turns the optional referrer form fields into the attributes stored on a lead or investment,
 * linking the account whose phone matches the referrer phone.
 */
class ReferrerResolver
{
    /**
     * @param  array{referrer_name?: ?string, referrer_phone?: ?string}  $data  validated input
     * @return array{referrer_name: ?string, referrer_phone: ?string, referrer_user_id: ?int}
     */
    public function attributes(array $data, ?User $submitter = null): array
    {
        $name = $data['referrer_name'] ?? null;
        $phone = $data['referrer_phone'] ?? null;

        return [
            'referrer_name' => $name !== '' ? $name : null,
            'referrer_phone' => $phone !== '' ? $phone : null,
            'referrer_user_id' => $phone ? $this->matchUser($phone, $submitter)?->id : null,
        ];
    }

    /**
     * The one account whose phone matches, in either local (080…) or international (+234 80…) form.
     * No link when nobody or more than one account matches, or when it is the submitter's own account.
     */
    public function matchUser(string $phone, ?User $submitter = null): ?User
    {
        $matches = User::query()->whereIn('phone', self::variants($phone))->limit(2)->get();

        if ($matches->count() !== 1 || $matches->first()->id === $submitter?->id) {
            return null;
        }

        return $matches->first();
    }

    /**
     * The stored forms the same Nigerian mobile can take (08031234567, 8031234567, 2348031234567,
     * +2348031234567); the first is the canonical local form. Other numbers match as written.
     *
     * @return list<string>
     */
    public static function variants(string $phone): array
    {
        if (preg_match('/^(?:\+?234|0)?([789][01]\d{8})$/', $phone, $m)) {
            return ['0'.$m[1], $m[1], '234'.$m[1], '+234'.$m[1]];
        }

        return [$phone];
    }
}
