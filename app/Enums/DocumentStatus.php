<?php

namespace App\Enums;

enum DocumentStatus: string
{
    use HasValues;

    case Available = 'available';
    case PendingReview = 'pending_review';
    case NotSigned = 'not_signed';
    case IssuedAfterSigning = 'issued_after_signing';
}
