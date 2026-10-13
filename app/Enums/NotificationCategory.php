<?php

namespace App\Enums;

enum NotificationCategory: string
{
    use HasValues;

    case Payment = 'payment';
    case Investment = 'investment';
    case Listing = 'listing';
    case Inspection = 'inspection';
    case Application = 'application';
    case Account = 'account';
    case Security = 'security';
    case System = 'system';
}
