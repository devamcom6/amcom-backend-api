<?php

namespace App\Enums;

enum ReportType: string
{
    use HasValues;

    case Revenue = 'revenue';
    case Investments = 'investments';
    case Users = 'users';
    case Listings = 'listings';
}
