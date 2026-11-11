<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CaregiverLink;
use App\Models\User;

/** Relationship eligibility only: health endpoints must also enforce their own permissions. */
final class PatientDataPolicy
{
    public function view(User $actor, User $patient): bool
    {
        if ($patient->role !== UserRole::Patient) {
            return false;
        }
        if ($actor->role === UserRole::Patient) {
            return $actor->id === $patient->id;
        }

        return $actor->role === UserRole::Caregiver && CaregiverLink::query()
            ->where('patient_id', $patient->id)->where('caregiver_id', $actor->id)->where('status', 'active')->exists();
    }
}
