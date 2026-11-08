# SANAD Phase 2B — server-owned WhatsApp OTP

Implemented in sanad_backend only. Flutter, Firebase, the existing Flutter/Node Wexora code, and development user records are unchanged. Automated tests never send WhatsApp messages. No Phase 2B migration is automatically applied to sanad_db.

## Provider contract inspected before implementation

Source of truth: sibling `sanad/lib/data/repositories/wexora_otp_repository.dart`, OTP screen/models, and `sanad/server/wexora-otp.js`. Flutter currently calls Wexora directly; the Node proxy is not on that path. Laravel implements an independent server adapter and does not modify either client.

| Operation | Observed contract used by Laravel |
| --- | --- |
| Send | POST https://wexora.pro/api/v1/otp, JSON, Bearer API key, Idempotency-Key header |
| Send body | session_id, to (normalized phone), purpose, reference, language (ar/en) |
| Send success | HTTP 202, string id and expires_at |
| Verify | POST https://wexora.pro/api/v1/otp/{id}/verify with string code, same Bearer authentication |
| Verify success | HTTP 200 with a strict boolean verified=true |
| Invalid code | HTTP 200 false or the Node proxy's observed HTTP 422 verified=false |
| Expired/missing verification | Provider 404/410 mapped to otp-expired |

The existing integration uses provider purpose `sanad_signup` even during login. The adapter deliberately reuses that evidenced value rather than inventing a supported `sanad_login` provider value. **Laravel's independent purpose/account/attempt binding is authoritative.** Provider IDs never act as application authorization and are not exposed by these APIs. Plain SQL-format provider expiry is parsed as UTC, matching the Node implementation; explicit ISO offsets are respected. Send acceptance does not guarantee WhatsApp delivery. Optional delivery-status GET remains unnecessary for authentication.

No authoritative public Wexora documentation was available to extend this contract. The adapter is tested against the inspected contract, not a live provider account. Real delivery and current account/session permissions require a local credentialed smoke test later. No provider endpoints, webhook behaviors, retries or token-verification modes were invented.

## API compatibility and flow

The Phase 2A endpoints remain unchanged: availability, POST /api/auth/login (credential preparation only), and POST /api/auth/register/prepare (validation only). They still return their existing 200 preparation responses and cannot be redeemed for authentication. **Use the new start endpoints for real authentication.**

| Endpoint | Request | Success |
| --- | --- | --- |
| POST /api/auth/signup/start | Phase 1 registration fields plus optional language ar/en | 201 signup-started, pending attempt; no user, OTP send or bearer token |
| POST /api/auth/login/start | email/password plus optional language | 201 login-started after credential/profile validation; phone comes from user row |
| POST /api/auth/otp/send | attempt_id, attempt_token | 202 otp-sent; creates/replaces provider challenge; same endpoint for resend |
| POST /api/auth/otp/verify | attempt_id, attempt_token, purpose signup/login, code | Signup: 200 signup-verified plus one-time proof, no user/token. Login: 200 authenticated with user/token |
| POST /api/auth/register | attempt_id, attempt_token, verification_proof | 201 registered with atomically created user/token |
| GET /api/user | Authorization: Bearer authenticated token | 200 existing explicit authorized-user envelope |
| POST /api/auth/logout | Authorization: Bearer authenticated token | 204, revokes current token only |

All sensitive auth responses use `Cache-Control: no-store, private`. No endpoint starts a Laravel authentication session or sets authentication cookies. Sanctum's web-guard fallback is disabled; only valid bearer tokens authenticate these routes.

Signup start example (sample password is documentation only):

```json
{
  "username":"Amina_1","first_name":"أمينة","last_name":"Hassan",
  "email":"amina@example.com","password":"ExamplePass1!","password_confirmation":"ExamplePass1!",
  "role":"patient","phone_number":"+201012345678","country":"Egypt","region":"القاهرة",
  "address":"12 Nile Street","language":"ar"
}
```

Login start:

```json
{"email":"amina@example.com","password":"<account password>","language":"en"}
```

Both return an envelope like:

```json
{
  "code":"signup-started",
  "data":{
    "attempt_id":"<server UUID>","attempt_token":"<private random attempt secret>","purpose":"signup",
    "otp_started":false,"authenticated":false,"expires_at":"<UTC timestamp>"
  }
}
```

Send/resend uses that attempt ID/secret only; client-supplied phone/user/email/purpose fields cannot redirect delivery. Response contains only attempt ID, otp_started=true, authenticated=false, UTC expires_at and resend_at. A resend invalidates the prior local challenge before contacting Wexora; a failed resend leaves no usable prior local challenge.

Verification:

```json
{"attempt_id":"<UUID>","attempt_token":"<secret>","purpose":"signup","code":"<received code>"}
```

Signup success:

```json
{"code":"signup-verified","data":{"verification_proof":"<private single-use proof>","expires_at":"<UTC timestamp>","authenticated":false}}
```

Finalize without resubmitting or changing registration fields:

```json
{"attempt_id":"<UUID>","attempt_token":"<secret>","verification_proof":"<server proof>"}
```

Authenticated signup/login response:

```json
{"code":"authenticated","data":{"user":{"id":"<id>","role":"patient"},"token":"<private Sanctum token>","token_type":"Bearer","expires_at":"<UTC timestamp>","authenticated":true}}
```

Signup uses code registered; login uses authenticated. `user` is the full Phase 2A UserResource, abbreviated above. No passwords, credential hashes, provider IDs or verification metadata appear in it. Clients must store tokens securely and never log them. Every completed attempt can return a usable token only once; a lost successful response requires starting a new flow, not replaying the completed attempt.

## Security and persistence

The additive migration `2026_11_07_000001_create_auth_attempts_table.php` creates only auth_attempts. It does not alter users or existing tables. UUID primary key; unique SHA-256 attempt secret/proof/provider-ID hashes; nullable user foreign key with restrictive deletion; HMAC phone and credential binding; encrypted JSON payload/encrypted provider ID; state, counters and timezone-aware timestamps. PostgreSQL checks enforce purpose/state/nonnegative counters and login-user/signup-no-user association.

- Pending signup payload contains normalized profile and a Laravel password hash, encrypted with APP_KEY. Plaintext password and confirmation are discarded before persistence; no OTP code is stored.
- Signup password rules remain Phase 1 rules. New signup/start additionally fails explicitly with 422 weak-password above bcrypt's 72-byte safety boundary, rather than silently truncating. Existing preparation validation is unchanged. This is a documented hasher limitation, not a hidden change to the Phase 1 password validator.
- Start validates all registration fields. Finalization revalidates the stored profile and uniqueness. The original password was validated and hashed at start; it is not resubmitted or revalidated as if a hash were plaintext.
- Login requires a complete valid profile. Its binding covers account ID, current password hash and stored phone. Changes invalidate pending login. Client verification booleans, account IDs and another attempt's proof/secret never authorize anything.
- Attempt TTL 10 minutes. Provider challenge lifetime is capped by attempt expiry. Signup proof TTL is at most 5 minutes and never outlives the attempt. Verification checks expiry again after provider response.
- FOR UPDATE serializes operations on each attempt. A PostgreSQL per-phone advisory transaction lock serializes aggregate send/verification budgets across attempts. There are no automatic transaction retries around external provider calls.
- At most 3 sends per attempt and per phone/15 minutes; cooldown 60 seconds across all attempts for the phone. At most 5 verification reservations per attempt and 10 per phone/15 minutes. Phone accounting uses last_sent_at/last_verify_at and full counters conservatively, preventing old attempts from dropping recent guesses out of the window. Invalid codes and provider failures consume reserved budgets.
- IP middleware limits: send 10/minute; verify 20/minute; signup start/finalize 5/minute; login start uses existing 5/email+IP and 20/IP. Existing availability/preparation limits remain.
- Provider ID uniqueness prevents one current provider challenge from being attached to multiple attempts. Same-ID resend is rejected. Unexpected responses, missing credentials, timeouts and storage failures fail closed with sanitized errors and no provider response body/exception chain.
- Proof consumption, user/profile insertion and bearer-token creation share one transaction. Identity uniqueness races map to existing field error codes. Token storage failures roll back account creation and proof consumption.
- Tokens expire after 7 days. No refresh flow is added. Logout revokes only the current token; other independently verified tokens remain valid. No cookie fallback or production testing bypass exists.
- Credentials/codes/tokens/proofs are not logged. PHPUnit/parallel workers use the null log channel and forbid stray HTTP requests. There is no public mock flag or mock-provider API.

PostgreSQL connection timezone is now explicitly UTC. The existing local server default was Africa/Cairo; without this connection setting, timezone-aware expiry inserts interpreted UTC bindings in Cairo time and immediately expired attempts. Existing records are not rewritten. This aligns Windows and future Ubuntu operation.

Expected errors: 401 invalid-credentials/unauthenticated; 404 invalid-challenge (unknown/mismatched attempt secret or purpose/proof); 409 challenge-used/account-changed/profile-incomplete; 410 otp-expired; 422 invalid-code/validation-failed/weak-password; 429 too-many-requests; 503 otp-service-unavailable. Rate errors include Retry-After. A locked attempt must be restarted after phone/IP limits permit; Retry-After does not reset its per-attempt budget.

## Local setup and migration

Use existing Herd PHP if php is absent from PATH. Do not replace dependencies or reset databases. Fill these keys **only in Laravel's ignored local .env**, never Flutter/Postman/source:

```dotenv
WEXORA_API_KEY=
WEXORA_SESSION_ID=
```

Use the existing valid Laravel APP_KEY for encrypted pending state; do not rotate it while attempts are active. The .env.example placeholders contain no credentials. Wexora needs the existing linked WhatsApp session and send/verify permissions. Missing credentials return sanitized 503; none were copied from Flutter or printed.

The migration is pending in development. After reviewing the target (APP_ENV=local, DB_CONNECTION=pgsql, DB_DATABASE=sanad_db, loopback host), backup and SQL:

```powershell
php artisan migrate:status
php artisan migrate --pretend --path=database/migrations/2026_11_07_000001_create_auth_attempts_table.php
php artisan migrate --path=database/migrations/2026_11_07_000001_create_auth_attempts_table.php
```

No development migration was run automatically. No existing user or Phase 1 migration was modified. Rollback would delete authentication attempt state, so do not use it as a normal repair path.

Cleanup retains expired attempt state for 24 hours beyond expires_at. `php artisan auth:prune-attempts` deletes only eligible auth_attempts, never users or bearer tokens. It is scheduled daily through Laravel; on local development run manually unless the scheduler is running. Do not run pruning until its table is migrated.

## Postman and mocks

Import `postman/SANAD-Phase-2B.postman_collection.json` and select a private local environment. It has a loopback-only pre-request guard and blank secret/code variables. Response scripts store attempt/proof/token values only in that selected local environment and never print them. Mark these variables secret in Postman and never export a populated environment/collection.

Signup order: availability → registration preparation → signup start → send → enter the delivered code locally → verify with purpose signup → finalize → current user → logout. Login order: credential preparation (optional compatibility check) → login start → send → enter code → verify with purpose login → current user → logout.

Availability, preparation and start do not call Wexora. Send/verify require real server-side Wexora credentials for manual Postman use. Finalization requires a real server proof from the preceding signup verification. All automated flows use Laravel Http fakes, including successful authentication; there is no mock switch usable from Postman or a public endpoint. Do not run the full collection blindly: send really sends WhatsApp messages once configured. No real messages were sent during implementation or tests.

## Tests and changed files

All DB tests use guarded local sanad_test_db with actual migrations in private rollback schemas. Three concurrency cases use separate PHP processes and newly created test-only schemas: concurrent login verification, concurrent consumption of one signup proof, and two verified signup attempts racing for the same identity. Each case must leave exactly one user/token completion. Cleanup validates the created schema name and test database before removing only that namespace. No public/development schema is refreshed or dropped. Worker secrets travel only over stdin, not command-line arguments or output.

Created:

- app/Application/Auth/AuthFlowException.php
- app/Application/Auth/OtpProvider.php
- app/Application/Auth/ProviderChallenge.php
- app/Application/Auth/OtpAuthentication.php
- app/Infrastructure/Auth/WexoraOtpProvider.php
- app/Models/AuthAttempt.php
- app/Http/Requests/StartSignupRequest.php
- app/Http/Requests/StartLoginRequest.php
- app/Http/Requests/OtpAttemptRequest.php
- app/Http/Requests/VerifyOtpRequest.php
- app/Http/Requests/FinalizeSignupRequest.php
- app/Http/Controllers/OtpAuthenticationController.php
- config/auth_otp.php
- database/migrations/2026_11_07_000001_create_auth_attempts_table.php
- tests/Feature/OtpAuthenticationTest.php
- tests/Feature/WexoraProviderTest.php
- tests/Feature/OtpConcurrencyTest.php
- tests/otp-race-worker.php
- docs/authentication-phase-2b.md
- docs/superpowers/plans/2026-11-07-secure-otp.md
- postman/SANAD-Phase-2B.postman_collection.json

Modified:

- app/Application/Auth/VerifyLoginCredentials.php (reusable validated user lookup, preparation unchanged)
- app/Providers/AppServiceProvider.php
- bootstrap/app.php
- config/database.php
- config/services.php
- config/sanctum.php
- .env.example
- routes/api.php
- routes/console.php
- phpunit.xml
- tests/TestCase.php
- tests/Support/UserTable.php
- tests/Support/PostgresTestDatabase.php
- tests/Feature/AuthPreparationTest.php (new routes now reject missing proofs/authentication instead of 404)
- docs/authentication-phase-2a.md

Next phase: apply the reviewed additive migration/configuration locally, perform a deliberate real-provider smoke test with a controlled account, then implement Flutter integration while retaining Firebase until the new flow is verified end to end.

## Verification results (2026-11-07)

- Complete backend suite: **142 passed, 1489 assertions, zero failures/errors/skips**, including all previous Phase 1/2A tests and all 10 original PostgreSQL constraint tests.
- Database-backed tests: only local sanad_test_db on PostgreSQL 18.6 as sanad_app, UTC sessions. Zero leftover private test schemas after the suite.
- Actual parallel-process cases: same login verification, same signup proof consumption, and different verified signup attempts for one identity. Each left exactly one token/user completion; provider calls were mocked in every process.
- PHP syntax checks passed for all 30 touched PHP files. Pint formatting and final --test passed.
- Postman collection: 12 requests, JSON valid; six script blocks compiled/exercised in Node. Local hosts accepted; remote and deceptive hosts rejected; secret defaults empty; capture and logout clearing passed. No network request was made by this artifact check.
- Static security review found one Important rolling-window issue. A failing time-travel regression reproduced it; last_verify_at accounting fixed it and the targeted reviewer confirmed resolution. No remaining concrete material findings were reported. Review was static; real provider behavior remains unverified.
- Read-only development checks: original user fingerprint/count (zero users), Phase 1 schema/indexes/role constraint and batch3 history unchanged. GET /api/health returned unchanged 200 local JSON through Laravel's HTTP kernel. New auth_attempts migration remains Pending; only its additive SQL was previewed.
- Flutter/Firebase/Wexora client files, development .env secrets, existing migrations and development user records were not modified. No real WhatsApp messages were sent.
