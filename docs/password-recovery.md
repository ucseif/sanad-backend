# Password recovery — local backend

Recovery is independent of signup/login and never creates a session, cookie or Sanctum token. Flutter uses its own recovery phone screen/state, a four-step recovery header, the existing shared OTP screen and the existing reset form. Signup no longer contains recovery-specific branching. Login preparation's previously added masked phone response remains unchanged.

## Database and worker prerequisites

New migration: `2026_11_07_000002_add_password_recovery_support.php`. **Not applied to sanad_db by this implementation.** Review and apply separately before running the updated application. It widens the attempt purpose, adds `password_reset` to the checks, adds a delivery-generation counter and nullable `users.phone_verified_at` / `phone_verified_hash`. It does not backfill or update accounts. Rollback refuses to erase recovery attempts.

Successful server OTP signup/login records verified-phone evidence. Recovery requires that evidence to match the current stored phone. A retained, completed, server-verified login attempt matching the user and current phone also qualifies. Legacy accounts without either form of evidence fail the eligibility check; no status or remediation metadata is returned. Merely storing a phone, client assertions, or historical signup payloads do not prove ownership. No automatic verification backfill is performed.

Delivery uses the existing database queue, explicitly `database`, queue `auth-recovery`. Its connection must equal the application default connection or sending fails closed. Run locally after the separately approved migration:

```powershell
php artisan queue:work database --queue=auth-recovery --tries=1 --timeout=60
```

Queue messages contain only an attempt UUID and delivery generation. Queue insertion and send reservation share the database transaction. Duplicate/stale generations are ignored. Provider failures consume a reservation; the client can retry after cooldown. No public delivery-status API reveals account eligibility or provider failures. Monitor worker health through local operational tooling; do not log credentials or codes. Existing `auth:prune-attempts` retention applies.

## API / Postman

Import `postman/SANAD-password-recovery.postman_collection.json`. Set `base_url` to your local Laravel origin (default `http://127.0.0.1:8000`). Use JSON `Accept` and `Content-Type` headers; no Bearer token is required. The collection rejects non-loopback destinations. Enter the actual code received on the account's verified WhatsApp phone manually into the temporary `otp_code` variable. Never commit populated secrets or export them.

All successes use `{ "code": "...", "data": { ... } }` and no-store response headers. Start/send/verify/reset responses include `authenticated: false`; eligibility exposes only its boolean result. Input errors use the existing `validation-failed`, `errors` and `error_codes` contract. Password and confirmation are never trimmed. Identity normalization matches the PostgreSQL lower/trim unique index and existing Unicode input trimming.

0. `POST /api/auth/password/eligibility`

   Request: `{ "email": "amina@example.com" }`. Response: `{ "code": "recovery-eligibility-checked", "data": { "eligible": true } }` (or `false`). This is an explicit recovery eligibility check, not registration availability. It creates no attempt, job or session and sends no message. It returns no phone, role, account ID or verification metadata. It uses the same `auth-recovery` limiter as `/forgot`: 20/min/IP and 5/15min/normalized email+IP across both endpoints together, plus the 200 ms floor. Flutter must check the response code and actual boolean, not HTTP success alone.

1. `POST /api/auth/password/forgot`

   Request: `{ "email": "amina@example.com", "phone_number": "+201012345678", "language": "en" }` (`language` optional `ar`/`en`). Phone is required and trimmed consistently with registration, then validated in canonical `+` plus 7–15 digits format. Flutter constructs it using the existing country picker and phone normalization.

   Only a matching eligible pair returns 202: `{ "code": "password-recovery-started", "data": { "attempt_id": "<uuid>", "attempt_token": "<64-hex-secret>", "purpose": "password_reset", "expires_at": "<ISO-8601>", "authenticated": false, "recovery_confirmed": true } }`. Unknown, unverified or mismatched pairs return generic 422 `recovery-not-eligible` before creating an attempt or job. New decoy attempts are no longer created. No stored phone/profile information is returned. Flutter requires the explicit confirmation and purpose, then requests the first send before opening OTP. Minimum processing time 200 ms; recovery throttle shared with eligibility.

2. `POST /api/auth/password/otp/send`

   Request: `{ "attempt_id": "<uuid>", "attempt_token": "<secret>" }`.

   202: `{ "code": "recovery-request-accepted", "data": { "attempt_id": "<uuid>", "authenticated": false, "recovery_confirmed": true, "resend_at": "<ISO-8601>" } }`. Account eligibility and binding are rechecked before reservation. This accepts queued processing, not confirmed delivery. Any supplied phone/email is ignored; delivery can only use the server-bound stored verified phone. Limit 10/min/IP, 3 sends/attempt and 60-second cooldown. The worker also enforces shared phone limits (3/15min and cooldown) across signup/login/recovery under PostgreSQL advisory locks. Flutter passes the acknowledged resend time into OTP to suppress another send on entry; uncertain network retries retain the same attempt and are subject to server cooldown.

3. `POST /api/auth/password/otp/verify`

   Request: `{ "attempt_id": "<uuid>", "attempt_token": "<secret>", "code": "<six-digits>" }`.

   200 only after positive server-provider verification of a live, eligible, bound recovery challenge: `{ "code": "password-recovery-verified", "data": { "reset_proof": "<64-hex-secret>", "expires_at": "<ISO-8601>", "authenticated": false } }`.

   Incorrect, unsent, unavailable, provider-expired, decoy or no-longer-bound cases return generic 422 `invalid-code`. Five verification attempts per challenge (then 429); 20/min/IP; shared phone budget 10/15min. Counter reservations survive provider failure. Decoy/unsent verification uses a random unresolvable provider lookup on the existing verify endpoint plus a 200 ms floor. Even a provider `true` for that lookup cannot grant a proof. This reduces timing differences; vendor response latency is not mathematically constant. No SMS/WhatsApp message is sent for this lookup.

4. `POST /api/auth/password/reset`

   Request: `{ "attempt_id": "<uuid>", "attempt_token": "<secret>", "reset_proof": "<secret>", "password": "<new-password>", "password_confirmation": "<same-password>" }`.

   200: `{ "code": "password-reset", "data": { "authenticated": false, "login_required": true } }`. The user must complete the existing login + OTP flow next. All existing Sanctum tokens are revoked in the same transaction that changes the password and consumes the proof. No new token is returned. Limit 5/min/IP.

   Password policy is the existing SignupPassword rule: at least 9 Dart UTF-16 units, ASCII upper/lower/digit and an allowed punctuation character, plus exact confirmation. Bcrypt inputs over 72 bytes fail safely as `weak-password`. Hashing uses the configured Laravel hasher.

## Security and errors

- **Intentional enumeration tradeoff:** explicit eligibility and pair-rejection outcomes permit probing whether account details qualify for recovery. This replaces the previous neutral-decoy navigation contract at the user's request. Generic localized messages, no registered-phone/profile disclosure, shared throttles and timeboxing reduce exposure but do not eliminate account or phone-pair enumeration. Neither eligibility nor a `recovery_confirmed` acknowledgement authorizes reset; a live server-verified single-use proof is still mandatory.

- Attempts expire in 600 seconds. Proof expires after at most 300 seconds and never outlives the attempt. Only hashes of attempt secrets/proofs are stored; payload/provider ID use existing encrypted casts.
- Attempt and user row locks serialize completion. Proofs bind purpose, challenge, account and the password/phone snapshot. Changed credentials or phone invalidate pending recovery. Concurrent completions succeed once. Existing login attempts are invalidated by the changed password binding.
- 404 `invalid-challenge`: wrong token/proof or wrong purpose. 409 `challenge-used`: completed/verified step reused. 409 `account-changed`: account binding changed before reset. 410 `otp-expired`: attempt/proof expired. 429 `too-many-requests`: throttled or exhausted. 503 `otp-service-unavailable`: safe infrastructure/configuration error. Database exceptions are sanitized after rollback, including queued delivery failures.
- Reset request must use the original attempt ID/token and its own proof; client email, role, phone or verification flags cannot change the target. Signup/login challenges cannot enter recovery and recovery cannot enter signup/login finalization.
- No provider credentials, codes, raw tokens or personal data are added to application logs. Wexora adapter and provider-side API purpose remain unchanged; Laravel independently enforces the new purpose. Tests mock all provider HTTP; live delivery is not claimed verified by these tests.

## Verification

Tests use only guarded `sanad_test_db`, with per-test transaction schemas and isolated subprocess race schemas; development data is untouched. Focused tests cover neutral emails, stored verified phones, provider true/false/failure, expiry/reuse, cross-purpose/account use, password/confirmation/hasher boundaries, queue duplicate/configuration failures, token revocation and rollback. Existing signup/login tests verify new phone metadata. A two-process PostgreSQL test proves single-use reset completion.

Verified locally on 2026-11-07:

- Final focused filter `PasswordRecoveryTest|OtpConcurrencyTest|test_signup_requires_provider_verification_and_consumes_a_single_use_proof|test_login_is_bound_to_stored_phone_and_only_otp_can_issue_token`: **22 passed, 432 assertions**.
- PHP syntax checks: all 17 changed PHP files passed. `vendor/bin/pint --test` on those files: passed.
- `php -d opcache.enable_cli=0 vendor/phpunit/phpunit/phpunit --do-not-cache-result` (complete suite, run once): **159 passed, 1,818 assertions, 0 failures, 0 errors, 0 skipped**. Includes `/api/health` contract and existing PostgreSQL constraints/signup/login tests.
- Safety preflight: PostgreSQL 18.6, `127.0.0.1`, database exactly `sanad_test_db`, account `sanad_app`, UTC. Per-test schema creation guarded before DDL. No development migration or live OTP request is part of verification.
- Postman JSON parsed successfully: four requests, loopback guard, no populated secret defaults. Manual Postman/live provider delivery remains a separate local check after migration approval and worker startup.

## Exact implementation files

Created:

- `app/Application/Auth/PasswordRecovery.php`
- `app/Application/Auth/OtpDelivery.php`
- `app/Jobs/DeliverRecoveryOtp.php`
- `app/Http/Controllers/PasswordRecoveryController.php`
- `app/Http/Requests/ForgotPasswordRequest.php`
- `app/Http/Requests/VerifyRecoveryOtpRequest.php`
- `app/Http/Requests/ResetPasswordRequest.php`
- `database/migrations/2026_11_07_000002_add_password_recovery_support.php`
- `tests/Feature/PasswordRecoveryTest.php`
- `postman/SANAD-password-recovery.postman_collection.json`
- `docs/password-recovery.md`
- `docs/superpowers/plans/2026-11-07-password-recovery.md`

Modified:

- `app/Application/Auth/OtpAuthentication.php` — share delivery/hasher/locking helpers; persist verification evidence only after server OTP.
- `app/Models/User.php` — hide trusted metadata and cast timestamp; no mass-assignment permission added.
- `app/Providers/AppServiceProvider.php` — recovery rate limiter.
- `routes/api.php` — four recovery routes.
- `tests/Support/UserTable.php` — test real recovery migration and existing queue migration in isolated schemas.
- `tests/Feature/OtpAuthenticationTest.php` — assert verified-phone metadata after signup/login.
- `tests/Feature/OtpConcurrencyTest.php` — add actual concurrent reset completion.
- `tests/otp-race-worker.php` — allow only the additional recovery reset test path.
