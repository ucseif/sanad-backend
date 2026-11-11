<?php

namespace App\Application\Caregiving;

use App\Enums\CaregiverLinkStatus;
use App\Enums\UserRole;
use App\Models\CaregiverLink;
use App\Models\User;
use App\Policies\CaregiverLinkPolicy;
use App\Support\UserInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CaregiverLinks
{
    public function __construct(private CaregiverLinkPolicy $policy) {}

    public function lookup(User $actor, string $username): User
    {
        if (! $this->policy->invite($actor)) {
            throw new LinkFailure('forbidden', 403);
        }
        $target = User::query()->where('role', UserRole::Caregiver->value)
            ->whereRaw('lower(trim(username)) = lower(trim(?))', [UserInput::trim($username)])->first();
        if (! $target) {
            throw new LinkFailure('caregiver-not-found', 404);
        }

        return $target;
    }

    public function scoped(User $actor): Builder
    {
        return CaregiverLink::query()->where(function (Builder $query) use ($actor): void {
            $query->where('patient_id', $actor->id)->orWhere('caregiver_id', $actor->id);
        });
    }

    public function invite(User $actor, string $username): CaregiverLink
    {
        return $this->atomic(function () use ($actor, $username): CaregiverLink {
            $patient = User::query()->lockForUpdate()->find($actor->id);
            $caregiver = $this->lookup($patient, $username);
            $live = CaregiverLink::query()->where('patient_id', $patient->id)->whereIn('status', ['pending', 'active'])->get();
            if ($live->contains('caregiver_id', $caregiver->id)) {
                throw new LinkFailure('duplicate-live-relationship', 409);
            }
            if ($live->count() >= CaregiverLink::MAX_CAREGIVERS) {
                throw new LinkFailure('caregiver-slots-full', 409);
            }
            $slot = collect(range(1, CaregiverLink::MAX_CAREGIVERS))->diff($live->pluck('slot_number'))->first();
            if ($slot === null) {
                throw new LinkFailure('caregiver-slots-full', 409);
            }
            $link = new CaregiverLink;
            $link->forceFill(['patient_id' => $patient->id, 'caregiver_id' => $caregiver->id,
                'status' => CaregiverLinkStatus::Pending, 'slot_number' => $slot, 'invited_at' => now()])->save();

            return $link;
        });
    }

    public function transition(User $actor, string $id, string $action): CaregiverLink
    {
        if (! Str::isUuid($id)) {
            throw new LinkFailure('relationship-not-found', 404);
        }
        $initial = $this->scoped($actor)->find($id);
        if (! $initial) {
            throw new LinkFailure('relationship-not-found', 404);
        }

        return $this->atomic(function () use ($actor, $initial, $action): CaregiverLink {
            User::query()->whereKey($initial->patient_id)->lockForUpdate()->firstOrFail();
            $link = $this->scoped($actor)->whereKey($initial->id)->lockForUpdate()->first();
            if (! $link) {
                throw new LinkFailure('relationship-not-found', 404);
            }
            if (! in_array($action, ['accept', 'decline', 'cancel', 'revoke'], true) || ! $this->policy->{$action}($actor, $link)) {
                throw new LinkFailure('forbidden', 403);
            }
            if ($link->status !== ($action === 'revoke' ? CaregiverLinkStatus::Active : CaregiverLinkStatus::Pending)) {
                throw new LinkFailure('invalid-relationship-state', 409);
            }
            $link->status = match ($action) {
                'accept' => CaregiverLinkStatus::Active, 'decline' => CaregiverLinkStatus::Declined,
                'cancel' => CaregiverLinkStatus::Cancelled, 'revoke' => CaregiverLinkStatus::Revoked,
            };
            if ($action === 'accept') {
                $link->accepted_at = now();
            } else {
                $link->slot_number = null;
                $link->ended_at = now();
                $link->ended_by = $actor->id;
            }
            $link->save();

            return $link;
        });
    }

    private function atomic(callable $operation): CaregiverLink
    {
        try {
            return DB::transaction($operation, 3);
        } catch (QueryException $error) {
            if ($error->getCode() === '23505') {
                if (str_contains($error->getMessage(), 'caregiver_links_live_pair_unique')) {
                    throw new LinkFailure('duplicate-live-relationship', 409);
                }
                if (str_contains($error->getMessage(), 'caregiver_links_live_slot_unique')) {
                    throw new LinkFailure('caregiver-slots-full', 409);
                }
            }
            throw $error;
        }
    }
}
