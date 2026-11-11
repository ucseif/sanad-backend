<?php

namespace App\Policies;

use App\Models\User;

final class DosePolicy
{
    public function view(User $actor, User $patient): bool
    {
        return app(MedicationPolicy::class)->view($actor, $patient);
    }

    public function record(User $actor, User $patient): bool
    {
        return app(MedicationPolicy::class)->consent($actor, $patient);
    }
}
