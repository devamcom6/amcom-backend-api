<?php

namespace App\Enums;

enum MaintenanceStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
}
