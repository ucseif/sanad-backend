# Phase 6A implementation ledger

Approved plan: conversation, 2026-11-11. Backend only; no Flutter changes, commits,
pushes, deployment, real delivery, or development-database migrations.

## Constraints and decisions

- Two caregivers remain unchanged. Active links grant management, never intake or consent.
- Explicit IANA schedule timezone; confirmed patient default bootstrapped by patient API.
- Missed means no qualifying report by deadline, not proof of non-consumption.
- Grace 1800 seconds; escalation additional 1800 seconds by default.
- Routine prescription/schedule changes start the next local day.
- Due first, nearest future second; missed collection separate.
- Ruling: work in the approved backend checkout, leaving changes uncommitted, as requested.
- Ruling: do not install Boost or other packages; user explicitly prohibits unnecessary installs.

## Checkpoints

- 6A.1: passed initial checkpoint — schema, recurrence, immutable history (5 tests / 34 assertions).
- 6A.2: passed — scoped API, authorization, optimistic concurrency/idempotency (22 cumulative tests / 216 assertions).
- 6A.3: passed — intake, dashboard, missed reconciliation and eligibility; focused cumulative gate: 51 tests / 613 assertions before final review regressions.
- 6A.4: complete — regression, independent review fixes, docs/Postman, manual migration handoff.

## Verification

Herd PHP: `C:\Users\Ouffaa\.config\herd\bin\php85\php.exe` (8.5.12).
Read-only preflight passed: PostgreSQL 18.6, local `sanad_test_db`, `sanad_app`,
UTC, CONNECT/CREATE granted, no residual race schemas. No `sanad_db` migrations run.

Baseline full Laravel suite: 202 tests / 2538 assertions passed.
API RED: 17 tests failed on missing routes before implementation.
Ruling: JSONB normalizes object key order; idempotency guarantees equivalent JSON,
not byte-for-byte object member order. Tests compare semantic JSON responses.

## Review and follow-up verification

Independent read-only code review found four important issues. Each was reproduced
with a failing regression, fixed, and passed:

1. Strict JSON integer weekdays prevent numeric-string snapshots / duplicate-key errors.
2. Durable event sequence preserves causal Missed -> Taken order at equal timestamps.
3. Pending-revision intake is denied before effective_from, including timezone shifts.
4. Resetting early-Taken future history after archive cancels instead of resurrecting it.

Additional RED -> GREEN checks cover SQL NULL integrity, immutable specific-day
recurrence, malformed nested input types, PRN correction date limits, and keeping
voided PRN entries discoverable in dated history without counting them as Taken.

Ruling: background catch-up advances at most seven days per revision per pass;
repeated passes may add the final partial chunk before the horizon is saturated.
Idempotency prevents duplicate occurrences, not legitimate progressive catch-up.
Ruling: scheduled cycle mode uses the existing persistent shared cache for a locked
patient cursor; this avoids starving patients beyond the first 100 without adding
infrastructure. Explicit manual cursors remain supported. Activation requires a
persistent lock-capable cache and separate operator approval.
Ruling: scheduled doses from pending revisions cannot accept intake until activation;
this preserves the approved same-day early rule on effective prescriptions while
preventing an obsolete pending prescription from acquiring intake history.
Ruling: PRN corrections stay within their original effective prescription; a different
prescription requires audited void + new intake, preserving attribution.

Initial full regression after review fixes: 257 tests / 3212 assertions, all passed.
Final PRN-history regression added two assertions; final full-suite result:
**257 passed / 3214 assertions / 0 failures / 0 errors**, 85.154 seconds.
Pint --test passed; syntax passed for all 27 changed PHP files.
Phase 6 Postman static verifier passed 32 requests, blank secrets, local-only origin,
UUID mutation guard and script/JSON checks; Phase 3 static verifier also passed.
No external requests were sent by these static checks.

Final commands (from backend root, `$phpExe` is the Herd executable above):

```powershell
& $phpExe -d opcache.enable_cli=0 vendor/phpunit/phpunit/phpunit --do-not-cache-result
# 257 tests passed; 3214 assertions; no failures/errors.
& $phpExe vendor/bin/pint --test <the 27 changed PHP files>
# passed
# php -l was also run for each of those 27 PHP files; all passed.
node tests/verify-phase6-postman.cjs
# 32 requests, all static checks passed; no HTTP sent.
node tests/verify-phase3-postman.cjs
# existing two-slot collection static checks passed; no HTTP sent.
git diff --check
# passed
```

Test output is also retained locally in the ignored
`storage/logs/medication-phase6-suite.json`. No manual Postman HTTP requests were
sent and no device/UI verification is claimed. No real delivery is implemented.

## Changed-file inventory

31 files, all inside sanad_backend. Existing files modified only:

- app/Providers/AppServiceProvider.php — medication rate-limit registrations.
- routes/api.php — include isolated medication routes.
- routes/console.php — disabled-by-default medication schedule registration.

New runtime/config/schema files:

- app/Application/Medication/DoseMaterializer.php
- app/Application/Medication/MedicationEvaluator.php
- app/Application/Medication/MedicationFailure.php
- app/Application/Medication/MedicationService.php
- app/Application/Medication/MedicationTracking.php
- app/Application/Medication/Recurrence.php
- app/Console/Commands/EvaluateMedicationDoses.php
- app/Http/Controllers/MedicationController.php
- app/Http/Requests/MedicationRequest.php
- app/Policies/DosePolicy.php
- app/Policies/MedicationPolicy.php
- config/medication.php
- database/migrations/phase6/2026_11_11_000000_create_medication_management.php
- routes/medications.php

New tests:

- tests/Feature/MedicationApiTest.php
- tests/Feature/MedicationConcurrencyTest.php
- tests/Feature/MedicationIntegrityTest.php
- tests/Feature/MedicationMaintenanceTest.php
- tests/Feature/MedicationSchemaTest.php
- tests/Feature/MedicationTrackingTest.php
- tests/Support/MedicationTables.php
- tests/Support/MedicationTestCase.php
- tests/Unit/MedicationRecurrenceTest.php
- tests/medication-race-worker.php
- tests/verify-phase6-postman.cjs

Handoff artifacts:

- docs/medication-management.md — full API/schema/invariants/examples, timezone
  bootstrap prerequisite, notification boundaries, scheduler and migration checklist.
- docs/medication-management-progress.md — this ledger, results and inventory.
- postman/SANAD-Phase-6.postman_collection.json — 32 manual local-only requests.

No changes to existing authentication, caregiver-link rules, PPG, dependency files,
Flutter or environment credentials. No commits, pushes, deployments, real delivery,
development migrations or scheduler activation. Review found no deferred minor issues.
