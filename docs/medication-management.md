# SANAD Phase 6A — Medication management

Backend contract version **1**. All records belong to the patient, including records
created by caregivers. Flutter was not changed. Development migrations are pending.

## Boundaries and meaning

`missed` means **no qualifying intake was recorded by the deadline**. It is not
proof that the medication was not consumed. The patient can later report an earlier
actual intake. The 30-minute tracking policy is not dosing advice, permission to
take medication early/late, or advice to take an extra dose.

The existing maximum of **two** pending/active caregivers is unchanged. Any currently
active caregiver may manage a linked patient's medication independently of the
patient's app or phone. Revocation removes subsequent access without deleting,
archiving, or transferring medicines. Other authorized viewers continue using the
same records. Refresh/polling is the future integration mechanism; no broadcast or
notification transport exists in this phase.

## Backend structure and storage

`MedicationService` owns patient/resource scoping, CRUD, immutable prescription
revisions, preferences, audit, optimistic versions and idempotency. `Recurrence`
is a pure UTC/wall-calendar recurrence engine. `DoseMaterializer` generates bounded
occurrences. `MedicationTracking` owns effective status, durable reconciliation,
intake/correction/PRN and dashboard/history. `MedicationEvaluator` creates or
suppresses eligibility candidates; the console command performs bounded catch-up.
The storage layer uses explicit query-builder projections instead of exposing
Eloquent model serialization.

| Table | Key contents / integrity |
|---|---|
| `medication_patient_settings` | Patient PK/FK, confirmed IANA timezone/time, version. Patient role required. |
| `medications` | UUID, immutable patient owner, version, created/updated/archived actor and timestamps. |
| `medication_schedule_revisions` | UUID, medication/patient composite FK, unique revision number, immutable prescription JSON snapshot, recurrence fields, IANA timezone, inclusive dates, absolute `[effective_from,effective_to)`, supersession, grace policy snapshot, generation cursor. |
| `medication_schedule_times` | Unique `(revision_id,local_time)`; immutable. |
| `medication_schedule_weekdays` | Unique `(revision_id,weekday)`; integers 1..7; immutable. |
| `medication_dose_occurrences` | Deterministic scheduled UUID, composite owner/revision FK, UTC instant/deadline, original and resolved local representations, resolution/time/version/cancellation. PRN has no scheduled time or deadline. |
| `medication_intake_events` | Append-only, causal identity sequence, patient/system attribution, previous/new resolution, actual time, effective and recorded times, correction reason. One automatic Missed event per occurrence. |
| `medication_preferences` | Reminders, patient consent/actor/time/version, delay 15/30/60, optimistic version. |
| `medication_notification_candidates` | Occurrence/type/recipient/link identity, eligible time, consent version, current eligible/suppressed state and reason. Never represents delivery. |
| `medication_audit_events` | Append-only management/preference/intake audit referencing immutable revisions or actions. No public actor profile serialization. |
| `medication_mutation_requests` | Actor + UUID key, canonical request hash, original JSON response/status, recorded time. No automatic pruning. |

All new foreign keys restrict deletion. Composite FKs prevent cross-patient child
records. Database checks validate known values, positive amount, lifecycle fields
and recurrence configuration. A deferrable trigger compares normalized times/days
with the immutable recurrence snapshot and enforces frequency cardinality. A
patient-role/ownership trigger prevents reassignment. A medication lock and range
check prevent overlapping non-superseded revisions without installing extensions.
Event/audit and schedule child UPDATE/DELETE are rejected.

Occurrence identity is UUIDv5 of revision + absolute scheduled timestamp. Permanent
uniqueness on `(revision_id,scheduled_at)` prevents regeneration. Partial uniqueness
on `(medication_id,scheduled_at)` applies to uncancelled scheduled rows, allowing
replacement while retaining cancelled history. Indexes support patient lists,
effective revisions, daily occurrences, unresolved deadlines and eligible candidates.

## Authorization and concurrency

All routes require Sanctum. `MedicationPolicy` controls viewing/management/consent;
`DosePolicy` restricts recording to the owning patient. The relationship gate is
reused as eligibility, not as blanket permission for every medication operation.

| Operation | Owning patient | Active caregiver |
|---|---|---|
| List/details/dashboard/schedules/history/adherence | Allow | Allow |
| Create/update/future schedule/archive | Allow | Allow |
| Ordinary reminder preferences | Allow | Allow |
| Taken/Skipped/late reporting/correction/PRN | Allow | Deny |
| Caregiver escalation consent and delay | Allow | Deny |
| Confirm patient default timezone | Allow | Deny |

Pending, terminal, unrelated links and other patients have no access. Scope misses
use 404; a forbidden operation on an authorized patient uses 403. Every request
checks current access, including idempotency replay. The server never trusts body
identity, role, owner, actor or computed status fields.

Transactions lock patient -> active relationship when applicable -> medication ->
occurrence/preferences. Patient locking matches the existing revocation service;
the operation that obtains that lock first completes before its competitor. Any
request beginning after committed revocation is denied. Read reconciliation uses
the same target-patient serialization and one captured server time.

Mutations require `Idempotency-Key: <UUID>`. Identical actor/key/method/path/body
replays return equivalent original JSON and status; changed content returns
409 `idempotency-conflict`. Object member order is canonicalized. PostgreSQL JSONB
may reorder response members. Use a fresh key for a new intention. Keys and
responses are stored server-side; do not log or expose this table.

`expected_version` is mandatory for updates and intake. Medication, preferences,
dose and settings have separate versions. Settings use 0 for the first confirmation.
Stale versions return 409 `version-conflict`. Intake increments the dose version,
not the medication version. Same-key retries are checked before stale-version checks.

## Timezone prerequisite — future Flutter integration

The current Flutter medication form has **no timezone selector**. It is untouched.
Future integration must perform this backend-only prerequisite before relying on
timezone defaults:

1. Read `GET /api/v1/patients/{patient}/medication-settings`.
2. If `requires_confirmation` is true, obtain the patient's explicit confirmation
   of an IANA timezone in a separately approved onboarding flow. A device timezone
   or `Africa/Cairo` may be suggested, never silently accepted.
3. As that patient, PATCH the settings with `{ "timezone": "Asia/Tokyo",
   "expected_version": 0 }` and an idempotency key.
4. New medication schedules may omit timezone and inherit this confirmed value.
   An explicitly supplied valid timezone always identifies that new schedule.

GET before confirmation returns `confirmed_timezone: null`, `confirmed_at: null`,
`version: 0`, `requires_confirmation: true`. No Egypt assumption is made. Caregivers
can read the setting and independently create medication with an explicit IANA
timezone even before bootstrap; they cannot confirm the patient's default for them.
If neither explicit timezone nor a confirmed default exists, creation returns 422.

Changing the default affects only new schedules. Phone timezone changes never
modify prescriptions. A caregiver viewing from a different zone selects a display
timezone; original schedule zone/local times remain in every occurrence response.

## Prescription and recurrence contract

Create payload:

```json
{
  "prescription": {
    "name": "Example medication",
    "dose_amount": "1",
    "dose_unit": "tablet",
    "form": "tablet",
    "relation_to_meals": "no_preference",
    "instructions": "",
    "special_notes": ""
  },
  "schedule": {
    "frequency": "once_daily",
    "timezone": "Asia/Tokyo",
    "start_date": "2026-11-11",
    "end_date": null,
    "times": ["08:00"],
    "weekdays": [],
    "interval_hours": null,
    "anchor_time": null
  },
  "reminder_preferences": {
    "enable_medication_reminders": true,
    "notify_caregiver_if_missed": false,
    "escalation_delay_minutes": 30
  }
}
```

Amounts are positive decimal **strings**, at most 12 whole digits and 6 decimal
digits. The backend does not infer ingredient strength, convert units, calculate
dosages, or invent instructions. Name: 1..160 characters; instructions/notes: at
most 4000 each. Optional blank instruction fields normalize to empty strings.

- Units: `mg`, `g`, `ml`, `mcg`, `IU`, `tablet`, `capsule`, `drop`, `puff`.
- Forms: `tablet`, `capsule`, `syrup`, `drops`, `injection`, `inhaler`, `cream`, `other`.
- Meals: `before_food`, `after_food`, `with_food`, `no_preference`.
- Frequencies: `once_daily`, `twice_daily`, `three_times_daily`, `specific_days`,
  `every_hours`, `as_needed`.

Daily frequencies require exactly 1/2/3 distinct `HH:mm` times. Specific days require
1..7 ISO weekdays (Monday=1, Sunday=7), supplied as JSON **integers**, and 1..24
distinct times. Other types cannot include weekdays. Interval schedules require
integer hours 1..168 and one `anchor_time`, with an empty times list. They repeat
elapsed absolute hours from `start_date + anchor_time` and continue overnight.
PRN has no times, weekdays, interval or anchor and never contributes to a scheduled
denominator or automatic Missed event.

Start/end dates are inclusive in the schedule timezone. An earlier start date does
not create obligations before the medication's initial effective creation time.
Daily recurrence follows wall-calendar dates. A missing DST time shifts forward by
the transition gap; an ambiguous time selects the earliest absolute occurrence.
Colliding resolved times coalesce into one occurrence, whose
`requested_local_times` identifies both requested times. `resolved_local_time`
includes the actual offset. Intervals retain elapsed hours across DST.

All prescription edits, including dose and instructions, create immutable revisions
effective at the next midnight of the **currently effective** schedule timezone.
Today's prescription/doses remain unchanged. A timezone edit is explicit and uses
the old zone to determine this boundary. Further edits replace the pending revision
at the same boundary, retaining superseded rows/events and cancelling obsolete
future occurrences/candidates. Pending revision intake is rejected until activation,
even if its new timezone is already on the next calendar day.

Archiving immediately hides the active medicine and cancels unresolved occurrences
at or after archive time; older due/missed/resolved history remains. Existing
early-Taken future occurrences retain history. Resetting one to unresolved after
archive cancels it instead of resurrecting it. Archived medicines cannot accept
new PRN entries; historical corrections remain available.

## Dose lifecycle, reporting and audit

For uncancelled scheduled doses, a saved Taken/Skipped resolution wins. Otherwise:

| Server time | Effective state |
|---|---|
| Before `scheduled_at` | `pending` |
| `scheduled_at <= now < missed_at` | `overdue` |
| `now >= missed_at` | `missed` |

`missed_at = scheduled_at + grace_seconds`. The default policy version is 1 and
grace is 1800 seconds, centrally configured and snapshotted per revision. Changes
do not retroactively change existing deadlines. PRN has no deadline.

These are separate mechanisms:

1. **Read-time classification** computes the correct state without a worker.
2. **Durable reconciliation** appends one automatic Missed event when an unresolved
   threshold is observed. `effective_at` is the threshold; `recorded_at` is detection.
3. **Scheduled processing** catches up/materializes and reconciles if explicitly
   activated. Read correctness does not depend on it.
4. **Escalation eligibility** records a consent/link-qualified candidate.
5. **Delivery** is not implemented. There is no push/email/SMS/WhatsApp dispatch.

Opening a dose only reads/reconciles tracking; it never marks Taken. An ordinary
Taken or Skipped request accepts `{ "expected_version": 1 }`; Taken optionally
includes `actual_intake_at` in RFC3339 format, e.g. `2026-11-11T08:05:00+00:00`.
Without a supplied actual time, server time is used. Actual time cannot be future
or earlier than the scheduled local day's midnight. Thus early intake can be
reported in that same local day, without clinical safety endorsement.

Once Missed, ordinary Taken/Skipped returns 409 `late-intake-required`. Explicit
`late-intake` accepts the same version/time fields and preserves the Missed event,
including when reported actual intake occurred before the deadline while offline.
An already-resolved dose does not acquire a fabricated Missed event simply because
a later correction arrives after the deadline.

Correction payload:

```json
{
  "expected_version": 2,
  "target_status": "taken",
  "actual_intake_at": "2026-11-11T08:05:00+00:00",
  "reason": "Correcting the reported intake time"
}
```

Scheduled targets: `taken`, `skipped`, `unresolved`. Reset recomputes status and
reconciles a newly unresolved deadline, preserving all prior history. A newly
unresolved event after the deadline is effective no earlier than that reset.
PRN targets: `taken` or `void`; never Skipped/unresolved. PRN time correction must
remain within the original effective PRN prescription and inclusive dates, and
updates its local day. To change to another prescription, void and log a separate
authorized intake. A voided PRN remains discoverable in dated history using its
last reported actual intake time; it never counts as Taken or scheduled. Event
history is ordered by a durable causal sequence, including
multiple events sharing the same recorded timestamp.

## API routes

Prefix **`/api/v1/patients/{patient}`**. Child IDs are scoped to that patient.
Every mutation uses an idempotency key. `expected_version` targets the dose for
intake/corrections, preferences for preference endpoints, and medication for
update/archive/PRN. Creation has no expected version.

| Method | Suffix | Body / query |
|---|---|---|
| GET | `/medication-settings` | None |
| PATCH | `/medication-settings` | `timezone`, `expected_version` (0 initially) |
| GET | `/medications` | `status=active|archived|all`, `page`, `per_page` |
| POST | `/medications` | Full create payload above |
| GET | `/medications/{medication}` | None |
| PATCH | `/medications/{medication}` | `expected_version`, partial `prescription` and/or full `schedule` |
| POST | `/medications/{medication}/archive` | `expected_version` |
| GET | `/medications/{medication}/schedule-revisions` | `page`, `per_page` |
| PATCH | `/medications/{medication}/reminder-preferences` | `expected_version`, `enable_medication_reminders` |
| PATCH | `/medications/{medication}/escalation-consent` | `expected_version`, `notify_caregiver_if_missed`, optional `escalation_delay_minutes` |
| GET | `/medication-dashboard` | Required `date`, `timezone`; `page`, `missed_page`, `per_page` |
| GET | `/medication-doses` | Required `from`, `to`, `timezone`; `page`, `per_page` |
| GET | `/medication-doses/{dose}` | None; includes events |
| POST | `/medication-doses/{dose}/taken` | `expected_version`, optional `actual_intake_at` |
| POST | `/medication-doses/{dose}/skipped` | `expected_version` |
| POST | `/medication-doses/{dose}/late-intake` | `expected_version`, optional `actual_intake_at` |
| POST | `/medication-doses/{dose}/corrections` | `expected_version`, `target_status`, `reason`, optional `actual_intake_at` |
| POST | `/medications/{medication}/prn-intakes` | `expected_version`, optional `actual_intake_at` |
| GET | `/medications/{medication}/adherence` | Required `from`, `to`, `timezone`; `page`, `per_page` |

Pagination defaults to 20, maximum 100. Date ranges are inclusive and at most 31
calendar days, with the last date no later than 31 days ahead in the viewer zone.
Ordering uses scheduled time, medication UUID, occurrence UUID. Each requested
period is materialized independently of background cursors. Background generation
uses seven-day chunks up to now + seven days, including historical revisions and
pre-archive history; repeated passes progressively catch up without duplicates.

Read rate limits: 60/min/user, 120/min/IP. Writes: 20/min/user, 60/min/IP. Unknown
query/body/nested fields, including forged ownership/status/actor fields, return
422. Success responses always use private/no-store headers and:

```json
{
  "code": "medication-created",
  "data": {
    "contract_version": 1,
    "server_now": "2026-11-11T06:00:00+00:00",
    "medication": {
      "id": "...",
      "version": 1,
      "archived_at": null,
      "current_revision": {},
      "pending_revision": null,
      "reminder_preferences": {},
      "allowed_actions": ["view", "update", "archive", "change_reminders"]
    }
  }
}
```

Creation/PRN: 201. Other successes: 200. Dashboard code:
`medication-dashboard-retrieved`; remaining operation codes:
`medication-{route operation}-completed` (`settings`, `settings-update`, `index`,
`show`, `update`, `archive`, `revisions`, `reminders`, `consent`, `doses`, `dose`,
`taken`, `skipped`, `late-intake`, `corrections`, `prn`, `adherence`).

Errors use existing conventions:

```json
{"code":"version-conflict","message":"version-conflict"}
```

422 adds `errors` and `error_codes` with `code: validation-failed`. Other codes:
401 `unauthenticated`; 403 `forbidden`; 404 `patient-not-found`,
`medication-not-found`, `dose-not-found`; 409 `version-conflict`,
`idempotency-conflict`, `medication-archived`, `dose-cancelled`,
`revision-not-effective`, `dose-already-resolved`, `dose-not-missed`,
`late-intake-required`; 429 `too-many-requests` with Retry-After.

## Dashboard and existing Flutter mapping

The selected day is the absolute interval between its local midnights in the
**requested viewer timezone**, not the phone/server's implicit zone. Original
schedule timezone/time remains available. Patient and authorized caregivers receive
the same underlying records, with role-specific allowed actions.

```json
{
  "code": "medication-dashboard-retrieved",
  "data": {
    "contract_version": 1,
    "server_now": "2026-11-11T08:10:00+00:00",
    "date": "2026-11-11",
    "timezone": "UTC",
    "summary": {
      "scheduled_doses": 4,
      "taken_doses": 1,
      "remaining_doses": 1,
      "untaken_doses": 3,
      "missed_doses": 1,
      "skipped_doses": 1,
      "progress_fraction": 0.25
    },
    "next_actionable_dose": null,
    "medications": [],
    "medications_pagination": {"page":1,"per_page":20,"total":0,"last_page":1},
    "missed_doses": [],
    "missed_doses_pagination": {"page":1,"per_page":20,"total":0,"last_page":1}
  }
}
```

The collections above are abbreviated shape examples, not a complete fixture.
`remaining_doses` counts pending + overdue only; `untaken_doses` includes missed
and skipped. Progress is Taken / scheduled; zero scheduled returns 0. PRN and
cancelled occurrences are excluded. Counts and next dose cover the complete day,
not the returned page. Medication and missed pagination are independent.

Up Next prioritizes oldest currently overdue dose, then nearest future pending
dose, with deterministic ties. Taken/Skipped/Missed/cancelled never appear there.
No eligible dose returns null. It does not recommend administration timing.

Cards include current/pending prescription, `doses`, `taken_doses`, and
`scheduled_doses`. Every dose supplies `id`, `medication_id`, `medicine_name`,
prescription, kind, UTC scheduled time, original local date/time, timezone,
resolved local time, requested wall times, effective status, threshold,
actual intake time, version and allowed actions. The separate `missed_doses`
collection additionally gives patient-only `can_report_late_intake` and
`report_late_intake`/`correct` allowed actions. Caregivers receive view only.

Future Flutter enum mappings: `onceDaily`->`once_daily`, `twiceDaily`->`twice_daily`,
`threeDaily`->`three_times_daily`, `specificDays`->`specific_days`,
`everyHours`->`every_hours`, `asNeeded`->`as_needed`; meal values map similarly
from camelCase. Dose amount/unit must remain separate. UI labels remain localized
by the client. The existing local cache is not migrated or used as server truth.
No Missed card, timezone onboarding screen, polling, or Flutter integration was
implemented; each requires the separately authorized Phase 6B.

## Reminder consent, scheduler activation and limits

Caregiver creation defaults to reminders enabled and caregiver escalation consent
false. Any caregiver-supplied consent/delay fields are rejected (403), including
attempts to disable existing patient consent. Ordinary edits preserve consent.
Patient opt-in defaults to an **additional** 30 minutes after Missed; 15/30/60 are
supported. Thus the default eligibility time is scheduled +30 +30 minutes.

Escalation requires current Missed state, unarchived medication, current patient
consent, and a current Active relationship accepted no later than the threshold.
Consent must also precede that threshold. No retrospective alerts are created for
old missed doses after new consent/linking. Re-evaluation suppresses candidates
after correction/archive/revocation. Current consent versions cannot revive a
candidate from withdrawn consent. Ordinary reminders are independent and eligible
only after due time while still within grace and enabled. Candidate rows persist;
no candidate state means queued or delivered.

**Default scheduler registration is disabled.** `MEDICATION_SCHEDULER_ENABLED`
defaults to false. Existing authentication scheduling is preserved. No scheduler
process was started and no delivery integration exists.

Manual commands after deliberate migrations/connection review:

```powershell
& $phpExe artisan medication:evaluate --patient=123 --materialize-only
& $phpExe artisan medication:evaluate --patient=123
& $phpExe artisan medication:evaluate --after-patient=0 --limit=100
```

These commands mutate derived medication records on their configured database;
they are not read-only connection probes. Output counters include patients,
generated/evaluated, delivered=0, and next_after_patient. Manual bulk callers
advance the returned cursor and restart at 0 for another sweep.

Activation requires explicit operator approval, reviewed migrations, a confirmed
runtime connection, a **persistent shared cache** supporting locks (existing
database cache is suitable), tested `--cycle` advancement/wrap, performance and
backlog checks, then setting `MEDICATION_SCHEDULER_ENABLED=true` and reviewing
`artisan schedule:list`. Only then configure a scheduler runner. Registered
processing is `medication:evaluate --cycle` each minute with overlap prevention;
its locked durable cache cursor prevents starving patients beyond the first 100.
Array/null/nonpersistent per-process cache is unsuitable for activation. Monitor
command failures, execution duration, generation cursor lag and eligibility counts
without names, prescriptions, tokens or personal data in logs. No queue worker is
needed for this phase, and activating this scheduler still does not deliver alerts.

Limitations: patient-level serialization favors correctness over peak throughput;
large histories require repeated bounded catch-up; all requested-day materialization
and aggregate counts cover that patient's selected range before pagination. There
is no real-time transport, delivery guarantee, account-deletion workflow, medication
catalog, unit conversion, offline client queue, clinical adherence interpretation,
or automated retention deletion.

## Testing and manual Postman checklist

Automated DB tests use only guarded local `sanad_test_db` / `sanad_app` and isolated
schemas. Guards check uncached testing config, actual server identity and schema
privileges. Concurrency tests reuse the existing guarded race prefix, pass tokens
through stdin, execute real Sanctum HTTP requests, and clean only their own schema.
External HTTP is blocked; no new notification transport is called.

Static Postman verification: `node tests/verify-phase6-postman.cjs`. The collection
uses blank tokens/IDs/timezone, loopback-only base URL and explicit manual keys. It
is a set of manual alternatives, not a blind sequential runner. Do not populate
real personal medication examples or run it against unapproved accounts.

1. Set a confirmed local API base, approved synthetic accounts/tokens and patient
   ID. Confirm timezone as patient; test missing default and explicit caregiver zone.
2. Create as caregiver; read the same ID as patient and another active caregiver.
3. Edit with correct/stale versions; inspect unchanged current and pending next-day
   revision. Replace pending revision and inspect retained supersession/history.
4. Verify dashboard, day conversion, independent pages, current due priority,
   separate missed collection, and null Up Next after all eligible doses resolve.
5. Record actual early intake, reject future time, retry the identical key, and
   report earlier actual intake after Missed. Inspect ordered immutable events.
6. Verify caregiver cannot record/correct/PRN/consent. Revoke creator link, verify
   404 on known IDs, and verify other viewers retain unarchived medication.
7. Test PRN and archived history. Test opt-in/delay eligibility with synthetic data
   and explicit manual command only; no expectation of actual alerts.
8. Retest authentication/recovery/linking and verify two-slot behavior.

## Pending migration — manual only

**Not applied to `sanad_db`.** Never use migrate:fresh, refresh, seeders or
destructive rollback against development data. First take a verified database
backup using your approved connection process. Inspect the effective connection
without printing passwords or connection URLs; confirm intended local host,
database/user, migration status and existing Phase 1/3 prerequisites. Do not
blindly clear/rebuild cached config or run unrelated pending migrations.

From the backend repository:

```powershell
$phpExe = 'C:\Users\Ouffaa\.config\herd\bin\php85\php.exe'
& $phpExe artisan migrate:status --database=pgsql
& $phpExe artisan migrate --database=pgsql --path=database/migrations/phase6 --pretend
# Inspect generated SQL and backup before the following deliberate manual step:
& $phpExe artisan migrate --database=pgsql --path=database/migrations/phase6
```

The migration folder contains only `2026_11_11_000000_create_medication_management.php`.
The restricted `--path` excludes unrelated migrations. PostgreSQL transactional
DDL protects application of this additive table/function/trigger migration. After
manual migration, verify synthetic patient/caregiver API access before considering
the feature operational. Keep medication scheduler disabled until its separate
activation checklist is approved and completed.
