<?php

namespace App\Enums;

enum PaymentMethod: string
{
    use HasValues;

    case Card = 'card';
    case BankTransfer = 'bank_transfer';
}
