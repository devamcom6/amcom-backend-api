<?php

namespace App\Enums;

enum CheckoutMode: string
{
    use HasValues;

    case Direct = 'direct';
    case Interest = 'interest';
}
