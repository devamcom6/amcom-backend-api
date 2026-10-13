<?php

namespace App\Enums;

enum ListingStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case AwaitingPayment = 'awaiting_payment';
    case PendingVerification = 'pending_verification';
    case Published = 'published';
    case Sold = 'sold';
    case Rented = 'rented';
    case Archived = 'archived';
    case Rejected = 'rejected';
}
