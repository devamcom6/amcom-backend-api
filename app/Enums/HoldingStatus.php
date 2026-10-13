<?php

namespace App\Enums;

enum HoldingStatus: string
{
    use HasValues;

    case Active = 'active';
    case Expiring = 'expiring';
    case Expired = 'expired';
    case Transferred = 'transferred';
    case Pending = 'pending';
}
