<?php

namespace App\Enums;

enum Furnishing: string
{
    use HasValues;

    case Furnished = 'furnished';
    case SemiFurnished = 'semi_furnished';
    case Unfurnished = 'unfurnished';
}
