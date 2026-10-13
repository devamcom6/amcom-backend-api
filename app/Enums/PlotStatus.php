<?php

namespace App\Enums;

enum PlotStatus: string
{
    use HasValues;

    case Available = 'available';
    case Reserved = 'reserved';
    case Sold = 'sold';
}
