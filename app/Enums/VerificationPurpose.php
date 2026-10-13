<?php

namespace App\Enums;

enum VerificationPurpose: string
{
    use HasValues;

    case EmailVerification = 'email_verification';
    case PasswordReset = 'password_reset';
    case TwoFactor = 'two_factor';
    case PhoneChange = 'phone_change';
}
