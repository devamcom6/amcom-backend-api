<?php

namespace App\Enums;

enum RequestKind: string
{
    use HasValues;

    case Interest = 'interest';
    case Application = 'application';
    case Inspection = 'inspection';
    case Contact = 'contact';
    case Support = 'support';
}
