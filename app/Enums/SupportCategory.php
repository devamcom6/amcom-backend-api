<?php

namespace App\Enums;

enum SupportCategory: string
{
    use HasValues;

    case Account = 'account';
    case Payments = 'payments';
    case Investments = 'investments';
    case Property = 'property';
    case Other = 'other';
}
