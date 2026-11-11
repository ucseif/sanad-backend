# Phase 3 — Patient–Caregiver linking

Implementation is additive. The migration has **not** been applied to `sanad_db`. Automated tests use only guarded local `sanad_test_db` schemas and prevent external HTTP calls.

## Schema and authorization

`caregiver_links` contains UUID `id`; bigint `patient_id`, `caregiver_id`; `status` (pending/active/declined/revoked/cancelled); nullable smallint `slot_number`; timezone-aware `invited_at`, nullable `accepted_at`, nullable `ended_at`; nullable bigint `ended_by`; and `created_at`/`updated_at`. User references restrict deletion, retaining audit history.

Database checks enforce distinct participants, known statuses, live slots 1–2, terminal null slots, lifecycle timestamp presence/order and the appropriate ending actor. Partial unique indexes on live `(patient_id, caregiver_id)` and `(patient_id, slot_number)` reject duplicate live pairs and prevent more than two live slots even through direct inserts. Participant/status/date/ID indexes support lists.

The original three-slot migration is preserved as historical. The additive `2026_11_09_000000_limit_caregiver_links_to_two_slots.php` migration locks relationship writes and refuses to proceed if any patient has more than two pending/active links. It never chooses relationships to revoke. If at most two exist, a surviving slot 3 is reassigned to a free slot 1/2 without changing IDs, statuses, timestamps or history, then the stricter constraint is installed. Resolve excess live relationships explicitly before retrying. Both migrations are needed for a fresh installation; Laravel skips the first if already applied. Deploy the migrated backend before the two-slot Flutter client.

The service locks the patient user row before reading slot occupancy or locking/changing the relationship. Every lifecycle change rechecks participant authorization and state in a transaction. Pending invitations consume slots; acceptance retains the slot; decline/cancel/revoke release it. Terminal rows are never reused: reinvitation creates a new UUID. No invitation expiry or global caregiver limit is introduced. Unique violations are translated after rollback into stable 409 errors.

`CaregiverLinkPolicy` controls participant actions. The registered `view-linked-patient` gate calls `PatientDataPolicy`: a patient can access themselves; a caregiver needs an active relationship with that patient. This is relationship eligibility, not a grant of account ownership, profile editing, credentials access or general health-data permission. There are no new protected-health endpoints; future endpoints must explicitly enforce their own policies and field permissions.

## API

Every route requires `auth:sanctum`. Identity/role come from authentication. Unknown request fields, including supplied participant IDs, roles and status, are rejected.

| Method | Route | Request / permission |
| --- | --- | --- |
| POST | `/api/caregivers/lookup` | `{ "username": "Caregiver1" }`, patient only |
| POST | `/api/caregiver-links` | `{ "caregiver_username": "Caregiver1" }`, patient only |
| GET | `/api/caregiver-links` | Own pending/active links by default |
| POST | `/api/caregiver-links/{id}/accept` | Empty body; recipient caregiver, pending only |
| POST | `/api/caregiver-links/{id}/decline` | Empty body; recipient caregiver, pending only |
| POST | `/api/caregiver-links/{id}/cancel` | Empty body; inviting patient, pending only |
| POST | `/api/caregiver-links/{id}/revoke` | Empty body; either participant, active only |

Lookup is exact, case insensitive, trimmed and uses existing username rules/index normalization. It returns `code: caregiver-found`, `data.caregiver: {name, username}`. Unknown usernames and patient-role targets share `caregiver-not-found` (404). Creation resolves the username again; no client-provided user ID is accepted.

List query: `status=all` for history, or one lifecycle status; `page` defaults to 1, `per_page` to 20 (maximum 50). Ordering is created date then UUID, both descending. Response code is `caregiver-links-listed`, with `data.links`, `data.pagination: {page, per_page, total, last_page}` and, for patients, `data.slots: {maximum:2, occupied, available}`. Occupancy always counts all pending/active rows, independent of filters/pagination.

Creation returns 201/`caregiver-invitation-created`; transitions return 200/`caregiver-link-updated`. Both include `data.link`. A link exposes only `id`, `status`, `counterpart: {name, username}`, `invited_at`, nullable `accepted_at`, nullable `ended_at`, and server-derived `allowed_actions`. No internal user IDs, emails, phone numbers, addresses or security fields are serialized. Responses use existing private/no-store JSON envelopes.

Errors: 401 `unauthenticated`; 403 `forbidden`; 404 `caregiver-not-found` or `relationship-not-found`; 409 `duplicate-live-relationship`, `caregiver-slots-full` or `invalid-relationship-state`; 422 `validation-failed`; 429 `too-many-requests` with Retry-After. Missing/malformed/unrelated relationship IDs share 404; an authorized participant using the other role's action gets 403. Repeated transitions get 409.

Throttles: lookup 10/min/user + 30/min/IP; writes 20/min/user + 60/min/IP; lists 60/min/user. Existing cache infrastructure is reused.

## Flutter and manual testing

Home and Profile open the same `/caregiver-links` section; five bottom tabs remain unchanged. The authenticated profile selects Caregivers/Caregiving presentation. Patients see two slots, exact lookup, a name/username confirmation card before sending, current links and history. Caregivers see pending invitations, active patients and history. Active removal/leave requires confirmation. All lifecycle states are localized; usernames remain LTR in Arabic. There is no notification/push dependency or patient health preview in this feature.

The new service/controller reuse the existing API client/token store. Structured GET query parameters preserve API origin/path restrictions. Optional expected-token binding prevents a relationship action from being dispatched under a replacement session. Successes and conflicts refresh server state. Lost responses block further mutations until a full replacement refresh succeeds; pagination cannot clear that safeguard. Requests are not automatically retried and the UI does not optimistically alter relationship state.

Postman: `postman/SANAD-Phase-3.postman_collection.json` (13 requests). Tokens are blank, requests are loopback-only, and scripts can be checked without sending HTTP with `node tests/verify-phase3-postman.cjs`. Use explicitly approved local test accounts. Accept/decline/cancel/revoke are alternatives for the appropriate state, not a single sequential runner workflow. Populate `unrelated_link_id` from another approved test pair for the safe-404 test. Repeat invitation creation to exercise duplicates/full slots.

## Pending migration — manual only

From the backend repository, first inspect the effective PostgreSQL connection and confirm it is the intended local `sanad_db`; clear configuration ambiguity and review migration status/SQL before applying. Do not use migrate:fresh, database refresh, seeders or destructive rollback on development data. Only the new table/constraints/indexes should be created.

Exact PowerShell command using the installed Herd PHP:

```powershell
& 'C:\Users\Ouffaa\.config\herd\bin\php85\php.exe' artisan migrate --database=pgsql --path=database/migrations/2026_11_08_000000_create_caregiver_links_table.php --path=database/migrations/2026_11_09_000000_limit_caregiver_links_to_two_slots.php
```

Then manually verify with approved accounts before considering Phase 3 operational on `sanad_db`.

Physical-device checklist:

1. Open the section from both Home and Profile as each role; check back navigation and all five tabs in English and Arabic.
2. Confirm a caregiver name/username; sending must require the explicit button. Verify pending access grants no protected patient data.
3. With approved test relationships only, accept, decline, cancel, remove/leave after confirmation and inspect terminal history from both accounts.
4. Fill two slots (including pending), verify the third is refused, release one and reinvite. Verify one caregiver can serve several patients.
5. Disconnect/reconnect networking during a write; verify refresh is required before retry and the server state wins. Test session expiry and simultaneous updates from two devices.
6. Check narrow screens, keyboard, larger text, RTL usernames, empty/error states and pagination. Recheck Signup/Login/WhatsApp OTP/Password Recovery/session restoration/Logout.

Remaining integration: deliberate migration, physical-device verification, and endpoint-specific health authorization when health APIs are built. No notification delivery is implemented or claimed.

## Two-slot revision verification — 2026-11-09

Full Laravel suite: 202 passed / 2538 assertions. Full Flutter suite: 204 passed; analysis clean. PHP syntax, Pint and sandboxed Postman contract checks passed. Tests cover refusing a third invitation, two active caregivers, pending-slot counting, last-slot races, unrestricted caregiver multi-patient links, safe compaction of old slot 3, refusal with three existing live rows, preserved lifecycle/history metadata, and rollback. Independent review found no blockers. The development migration remains a deliberate manual step, not an automated test action.
