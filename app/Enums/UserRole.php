<?php

namespace App\Enums;

enum UserRole: string
{
    case Patient = 'patient';
    case Caregiver = 'caregiver';
}
