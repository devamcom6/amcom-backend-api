<?php

namespace App\Enums;

enum RequestStatus: string
{
    use HasValues;

    case New = 'new';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
