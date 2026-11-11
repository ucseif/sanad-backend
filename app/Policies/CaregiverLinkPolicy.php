<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CaregiverLink;
use App\Models\User;

final class CaregiverLinkPolicy
{
    public function invite(User $actor): bool
    {
        return $actor->role === UserRole::Patient;
    }

    public function accept(User $actor, CaregiverLink $link): bool
    {
        return $actor->role === UserRole::Caregiver && $actor->id === $link->caregiver_id;
    }

    public function decline(User $actor, CaregiverLink $link): bool
    {
        return $this->accept($actor, $link);
    }

    public function cancel(User $actor, CaregiverLink $link): bool
    {
        return $actor->role === UserRole::Patient && $actor->id === $link->patient_id;
    }

    public function revoke(User $actor, CaregiverLink $link): bool
    {
        return $this->accept($actor, $link) || $this->cancel($actor, $link);
    }
}
