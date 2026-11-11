<?php

namespace App\Enums;

enum CaregiverLinkStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Declined = 'declined';
    case Revoked = 'revoked';
    case Cancelled = 'cancelled';
}
