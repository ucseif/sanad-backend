<?php

namespace App\Http\Controllers;

use App\Application\Caregiving\CaregiverLinks;
use App\Application\Caregiving\LinkFailure;
use App\Enums\UserRole;
use App\Http\Requests\CaregiverLinkRequest;
use App\Http\Resources\CaregiverLinkResource;
use App\Models\CaregiverLink;
use App\Support\AuthApiResponse;

class CaregiverLinkController extends Controller
{
    public function lookup(CaregiverLinkRequest $request, CaregiverLinks $links)
    {
        $target = $links->lookup($request->user(), $request->validated('username'));

        return AuthApiResponse::success('caregiver-found', ['caregiver' => ['name' => $target->name, 'username' => $target->username]]);
    }

    public function store(CaregiverLinkRequest $request, CaregiverLinks $links)
    {
        $link = $links->invite($request->user(), $request->validated('caregiver_username'));

        return AuthApiResponse::success('caregiver-invitation-created', ['link' => (new CaregiverLinkResource($link))->resolve($request)])->setStatusCode(201);
    }

    public function index(CaregiverLinkRequest $request, CaregiverLinks $links)
    {
        if (! in_array($request->user()->role, [UserRole::Patient, UserRole::Caregiver], true)) {
            throw new LinkFailure('forbidden', 403);
        }
        $query = $links->scoped($request->user());
        $status = $request->validated('status');
        if ($status === null) {
            $query->whereIn('status', ['pending', 'active']);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        $page = $query->with(['patient', 'caregiver'])->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));
        $data = ['links' => $page->getCollection()->map(fn ($link) => (new CaregiverLinkResource($link))->resolve($request))->all(),
            'pagination' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]];
        if ($request->user()->role === UserRole::Patient) {
            $occupied = $links->scoped($request->user())->whereIn('status', ['pending', 'active'])->count();
            $data['slots'] = ['maximum' => CaregiverLink::MAX_CAREGIVERS, 'occupied' => $occupied,
                'available' => max(0, CaregiverLink::MAX_CAREGIVERS - $occupied)];
        }

        return AuthApiResponse::success('caregiver-links-listed', $data);
    }

    public function transition(CaregiverLinkRequest $request, CaregiverLinks $links, string $id, string $action)
    {
        $link = $links->transition($request->user(), $id, $action);

        return AuthApiResponse::success('caregiver-link-updated', ['link' => (new CaregiverLinkResource($link))->resolve($request)]);
    }
}
