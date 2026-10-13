<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** Development driver: records that an SMS would be sent. Codes are only logged outside production. */
class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::info('sms.log_driver', [
            'to' => substr($phone, 0, 4).'•••'.substr($phone, -3),
            'message' => app()->isProduction() ? '[hidden]' : $message,
        ]);
    }
}
