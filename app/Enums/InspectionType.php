<?php

namespace App\Enums;

enum InspectionType: string
{
    use HasValues;

    case InPerson = 'in_person';
    case Virtual = 'virtual';
}
