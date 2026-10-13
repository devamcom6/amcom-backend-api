<?php

namespace App\Enums;

enum KycStatus: string
{
    use HasValues;

    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
}
