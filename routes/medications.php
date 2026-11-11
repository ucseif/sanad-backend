<?php

use App\Http\Controllers\MedicationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/patients/{patient}')->middleware('auth:sanctum')->group(function (): void {
    $routes = [
        ['GET', 'medication-settings', 'settings'], ['PATCH', 'medication-settings', 'settings-update'],
        ['GET', 'medications', 'index'], ['POST', 'medications', 'store'],
        ['GET', 'medications/{medication}', 'show'], ['PATCH', 'medications/{medication}', 'update'],
        ['POST', 'medications/{medication}/archive', 'archive'], ['GET', 'medications/{medication}/schedule-revisions', 'revisions'],
        ['PATCH', 'medications/{medication}/reminder-preferences', 'reminders'], ['PATCH', 'medications/{medication}/escalation-consent', 'consent'],
        ['GET', 'medication-dashboard', 'dashboard'], ['GET', 'medication-doses', 'doses'],
        ['GET', 'medication-doses/{dose}', 'dose'], ['POST', 'medication-doses/{dose}/taken', 'taken'],
        ['POST', 'medication-doses/{dose}/skipped', 'skipped'], ['POST', 'medication-doses/{dose}/late-intake', 'late-intake'],
        ['POST', 'medication-doses/{dose}/corrections', 'corrections'], ['POST', 'medications/{medication}/prn-intakes', 'prn'],
        ['GET', 'medications/{medication}/adherence', 'adherence'],
    ];
    foreach ($routes as [$method, $path, $name]) {
        Route::match([$method], $path, MedicationController::class)->name('medication.'.$name)
            ->middleware('throttle:medication-'.($method === 'GET' ? 'read' : 'write'));
    }
});
