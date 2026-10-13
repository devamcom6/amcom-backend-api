<?php

namespace App\Enums;

enum OnboardingStep: string
{
    use HasValues;

    case Identity = 'identity';
    case Personal = 'personal';
    case Contact = 'contact';
    case NextOfKin = 'next_of_kin';
    case Completed = 'completed';
}
