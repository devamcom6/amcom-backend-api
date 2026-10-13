<?php

namespace App\Services\Sms;

interface SmsSender
{
    /** Sends a plain-text SMS. Implementations must not log message bodies containing codes in production. */
    public function send(string $phone, string $message): void;
}
