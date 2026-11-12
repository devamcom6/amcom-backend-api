<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Password;

/** Shared validation rules. */
final class Rules
{
    /**
     * Mirrors the frontend check (input is already stripped of spaces, brackets and dashes by
     * NormalizePhoneNumbers): a Nigerian mobile as 08031234567, 8031234567, 2348031234567 or
     * +2348031234567, or any international number of 10–15 digits with an optional +.
     */
    public const PHONE_REGEX = '/^((\+?234|0)?[789][01]\d{8}|\+?\d{10,15})$/';

    public static function phone(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:20', 'regex:'.self::PHONE_REGEX];
    }

    /** Optional "who referred you" fields shared by interest and application forms. */
    public static function referrer(): array
    {
        return [
            'referrer_name' => ['nullable', 'string', 'max:120'],
            'referrer_phone' => self::phone(false),
        ];
    }

    public static function password(): array
    {
        return ['required', 'string', 'max:128', 'confirmed', Password::min(8)->mixedCase()->numbers()];
    }

    public static function code(): array
    {
        return ['required', 'string', 'regex:/^\d{6}$/'];
    }

    public static function personName(bool $required = true): array
    {
        return [$required ? 'required' : 'sometimes', 'string', 'min:2', 'max:100'];
    }

    /** jpeg/png/webp checked by content sniffing (mimetypes) and extension (mimes). */
    public static function image(int $maxKb, int $minWidth = 200, int $minHeight = 200): array
    {
        return [
            'required',
            'file',
            'image',
            'mimes:jpeg,jpg,png,webp',
            'mimetypes:image/jpeg,image/png,image/webp',
            'max:'.$maxKb,
            "dimensions:min_width={$minWidth},min_height={$minHeight},max_width=12000,max_height=12000",
        ];
    }
}
