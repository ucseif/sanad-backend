<?php

namespace App\Models;

use App\Enums\CaregiverLinkStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaregiverLink extends Model
{
    public const MAX_CAREGIVERS = 2;

    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['status' => CaregiverLinkStatus::class, 'invited_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function caregiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caregiver_id');
    }
}
