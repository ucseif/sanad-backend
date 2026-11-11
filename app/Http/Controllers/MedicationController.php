<?php

namespace App\Http\Controllers;

use App\Application\Medication\MedicationService;
use App\Http\Requests\MedicationRequest;

final class MedicationController extends Controller
{
    public function __invoke(MedicationRequest $request, MedicationService $service)
    {
        [$body, $status] = $service->handle($request, $request->validated());

        return response()->json($body, $status, ['Cache-Control' => 'no-store, private']);
    }
}
