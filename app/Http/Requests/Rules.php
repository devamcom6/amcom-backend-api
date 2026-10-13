<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Password;

/** Shared validation rules. */
final class Rules
{
    /** E.164 (+2348031234567) or local Nigerian format (08031234567). */
    public const PHONE_REGEX = '/^(\+[1-9]\d{7,14}|0[789][01]\d{8})$/';

    public static function phone(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:20', 'regex:'.self::PHONE_REGEX];
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
