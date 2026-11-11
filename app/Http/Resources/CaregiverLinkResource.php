<?php

namespace App\Http\Resources;

use App\Enums\CaregiverLinkStatus;
use App\Policies\CaregiverLinkPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaregiverLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $counterpart = $actor->id === $this->patient_id ? $this->caregiver : $this->patient;
        $policy = app(CaregiverLinkPolicy::class);
        $candidates = match ($this->status) {
            CaregiverLinkStatus::Pending => ['accept', 'decline', 'cancel'],
            CaregiverLinkStatus::Active => ['revoke'], default => [],
        };

        return ['id' => $this->id, 'status' => $this->status->value,
            'counterpart' => ['name' => $counterpart->name, 'username' => $counterpart->username],
            'invited_at' => $this->invited_at->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(), 'ended_at' => $this->ended_at?->toIso8601String(),
            'allowed_actions' => array_values(array_filter($candidates, fn ($action) => $policy->{$action}($actor, $this->resource)))];
    }
}
