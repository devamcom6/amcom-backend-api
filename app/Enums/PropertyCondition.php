<?php

namespace App\Enums;

enum PropertyCondition: string
{
    use HasValues;

    case NewlyBuilt = 'newly_built';
    case FairlyUsed = 'fairly_used';
    case Renovated = 'renovated';
    case UnderConstruction = 'under_construction';
}
