<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

final class MedicationPolicy
{
    public function view(User $actor, User $patient): bool
    {
        return app(PatientDataPolicy::class)->view($actor, $patient);
    }

    public function manage(User $actor, User $patient): bool
    {
        return $this->view($actor, $patient);
    }

    public function consent(User $actor, User $patient): bool
    {
        return $actor->role === UserRole::Patient && $actor->id === $patient->id;
    }
}
