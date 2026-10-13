<?php

namespace App\Enums;

enum IdType: string
{
    use HasValues;

    case Nin = 'nin';
    case Bvn = 'bvn';
    case DriversLicense = 'drivers_license';
    case InternationalPassport = 'international_passport';
    case VotersCard = 'voters_card';
}
