<?php

namespace App\Enums;

/**
 * Built-in roles. Role definitions (labels, permission sets) live in the `roles` table;
 * `user` and `super_admin` are system roles whose permissions cannot be edited.
 */
enum Role: string
{
    use HasValues;

    case User = 'user';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';

    /** @return list<string> */
    public static function staff(): array
    {
        return [self::Admin->value, self::SuperAdmin->value];
    }
}
