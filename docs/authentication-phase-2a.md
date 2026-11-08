# SANAD Phase 2A — authentication preparation

Phase 2B update: the preparation endpoints below remain compatible. Secure completion is now implemented through separate start/send/verify/finalize routes, plus bearer logout. See authentication-phase-2b.md; the historical statements below about unregistered completion/logout routes describe Phase 2A only.

Phase 2A implements credential/input preparation only. It does not start WhatsApp delivery, create challenges or signup proofs, persist users, start sessions, set authentication cookies, or issue Sanctum tokens. All development remains local. Phase 1 schema and registration rules are unchanged.

## Implemented endpoints

Use base URL `http://127.0.0.1:8000`, `Accept: application/json` and `Content-Type: application/json`. Auth-related responses use `Cache-Control: no-store, private`. The existing health response is unchanged.

| Method / path | Successful response | Limits |
| --- | --- | --- |
| POST /api/auth/availability/email | 200 availability-checked | 30/minute/IP, shared with username checks |
| POST /api/auth/availability/username | 200 availability-checked | Same shared limit |
| POST /api/auth/login | 200 login-prepared, NOT authenticated | 5/minute/normalized-email+IP and 20/minute/IP |
| POST /api/auth/register/prepare | 200 registration-prepared, NOT registered | 5/minute/IP |
| GET /api/user | 200 user-retrieved for an existing authenticated principal; 401 otherwise | Existing auth:sanctum protection |
| GET /api/health | Existing 200 health JSON | Unchanged |

Availability requests:

```json
{"email":"amina@example.com"}
```

```json
{"username":"Amina_1"}
```

Response (no identity/profile is echoed):

```json
{"code":"availability-checked","data":{"available":true}}
```

Inputs are trimmed with Dart-compatible whitespace handling; email is lowercased and username display casing retained. SQL comparisons use the same lower(trim(column)) expression as Phase 1 indexes. Checks neither reserve values nor guarantee later registration success; PostgreSQL uniqueness remains authoritative.

Login request:

```json
{"email":"amina@example.com","password":"<existing account password>"}
```

Valid credentials return:

```json
{
  "code":"login-prepared",
  "data":{
    "status":"prepared",
    "otp_required":true,
    "otp_started":false,
    "authenticated":false,
    "next_step":"server-verified-otp"
  }
}
```

This is HTTP 200 preparation, deliberately not HTTP 202 challenge acceptance. There is no challenge ID, opaque authorization handle, token, proof, expiry or verified-phone claim. The response cannot be redeemed. Passwords are checked by Laravel's Eloquent user provider without calling guard attempt/login/once, firing login events or rehashing/saving credentials. Existing mixed-case/space-padded email rows are queried canonically. Missing users, wrong passwords and unusable/unsupported hashes return the same generic 401; a 200 ms minimum timebox reduces fast missing-user signals. It is not a guarantee of identical execution time for arbitrarily expensive existing hashes.

Registration preparation request:

```json
{
  "username":"Amina_1",
  "first_name":"أمينة",
  "last_name":"Hassan",
  "email":"amina@example.com",
  "password":"ExamplePass1!",
  "password_confirmation":"ExamplePass1!",
  "role":"patient",
  "phone_number":"+201012345678",
  "country":"Egypt",
  "region":"القاهرة",
  "address":"12 Nile Street"
}
```

Success returns the same preparation data with code `registration-prepared`. The original Phase 1 RegisterUserRequest rules, including the exact password punctuation/UTF-16 policy, are reused without modification. There is no account creation or persistence of registration passwords/profile input. Client fields such as otp_verified, verified, otp_verification_token and challenge_id grant no authority and are not consumed.

## Errors and Flutter message mapping

Generic invalid credentials, regardless of account existence:

```json
{"code":"invalid-credentials","message":"The email or password is incorrect."}
```

Validation returns HTTP 422:

```json
{
  "code":"validation-failed",
  "message":"The submitted data is invalid.",
  "errors":{"email":["The email has already been taken."]},
  "error_codes":{"email":["email-already-in-use"]}
}
```

Machine codes are stable; human-readable Laravel validation messages are fallback text, not an API for client branching.

| Condition | Code |
| --- | --- |
| Invalid credentials | invalid-credentials (401) |
| Missing/invalid bearer authentication | unauthenticated (401) |
| Request validation | validation-failed (422), with per-field codes below |
| Existing email / username | email-already-in-use / username-taken |
| Invalid identity / phone / role | invalid-email / invalid-username / invalid-phone-number / invalid-role |
| Password policy / confirmation | weak-password / password-mismatch |
| Other field feedback | required / too-short / too-long / invalid-data |
| Throttled request | too-many-requests (429), with Retry-After header |

These map to existing Flutter authentication/localized validation concepts without changing Flutter. Availability intentionally reveals only an availability boolean; limits reduce enumeration. Rate-limit keys hash normalized email; no request/password/profile logging is added. Development uses the existing configured persistent cache store; test rate limits use an isolated array store. Rate limits count preparation requests, including successful ones. No Redis/infrastructure was added.

## Current-user and logout contracts

The protected GET /api/user route remains inaccessible anonymously and rejects forged bearer strings. Its successful envelope is now explicit, replacing the previous raw Eloquent response:

```json
{
  "code":"user-retrieved",
  "data":{"user":{
    "id":"123","name":"Amina Hassan","email":"amina@example.com","username":"Amina_1",
    "first_name":"Amina","last_name":"Hassan","phone_number":"+201012345678",
    "country":"Egypt","region":"القاهرة","address":"12 Nile Street","role":"patient",
    "created_at":"2026-11-07T10:00:00.000000Z"
  }}
}
```

Legacy nullable profile fields remain null; no fake values are produced. Password, remember token, tokens and verification metadata are never included by UserResource. User retains HasApiTokens support. Phase 2A supplies no way to acquire an authenticated principal or token, and tests do not mint tokens or use actingAs to bypass OTP.

**Logout is a Phase 2B contract, not an implemented endpoint:** future POST /api/auth/logout will require the caller's verified bearer session, revoke only the current token and return 204 without a body. Invalid/missing/revoked credentials return 401. No logout, register-finalization or OTP routes are registered in this phase; calls currently receive 404. No refresh-token or logout-all-device behavior is introduced.

## Postman instructions

Import `postman/SANAD-Phase-2A.postman_collection.json`. Its base_url defaults to loopback only and it contains no secrets/tokens. Use the already-running local backend, or start the existing local server with `php artisan serve --host=127.0.0.1 --port=8000`. No migration or seeding is needed for these preparation APIs.

1. Health should return the existing local success JSON.
2. Email/username checks return boolean availability; change casing/outer whitespace and verify the result stays consistent.
3. Set a nonempty dummy login_password to test a missing account/wrong password (generic 401); leaving the variable blank tests required-password validation (422). The valid path requires an already-existing local account; do not create development records just for Postman. It is covered automatically by isolated PostgreSQL fixtures.
4. Set an existing account password only in your local Postman variable if testing that path; never commit/export a populated secret. A valid response must show otp_started=false/authenticated=false and contain no token/challenge. Check that no Set-Cookie header appears and subsequent anonymous GET /api/user remains 401.
5. Registration preparation with valid unused identities returns registration-prepared; repeat it and confirm no account is created. Bad role/password/confirmation/phone produces 422 with field codes.
6. Trigger the documented per-minute limits and inspect 429 and Retry-After. Wait for expiry before continuing.
7. GET /api/user without a token, or with an invented token, returns unauthenticated. Finalization/logout routes remain unavailable.

Do not use Postman collection runs against a remote host. The collection has no OTP request and never acquires or saves tokens. Only real credentials for an already-existing local account can exercise the valid-login manual path.

## Testing and Phase 2B boundary

All database-backed tests now use sanad_test_db only, including previous model/validation fixtures. phpunit.xml forces pgsql and the exact database. Existing target/account/loopback/permission guards verify the actual connection before DDL. Every feature test uses its own random schema and rollback transaction; actual Phase 1/Sanctum migrations create fixture tables. No public/development tables are refreshed or reset. Pure unit safety tests do not connect to any database. Preflight command: `php tests/verify-postgres.php`; suite: `php vendor/phpunit/phpunit/phpunit --do-not-cache-result`.

Phase 2B must add server-owned expiring challenges, purpose/phone/credential-attempt binding, delivery/resend/verification attempt limits, replay prevention and atomic signup-proof consumption. It must require a complete valid profile before issuing an authenticated token. Preparation responses or client booleans must never be accepted as verification proof. OTP provider credentials stay server-side in that future implementation. Until then, neither new-account completion nor authenticated login/token issuance is available. Laravel's existing Sanctum expiry defaults are unchanged because this phase issues no tokens; define expiry/revocation before Phase 2B token issuance.

## Files changed

Created:

- app/Application/Auth/VerifyLoginCredentials.php
- app/Http/Controllers/AuthPreparationController.php
- app/Http/Requests/EmailInputRequest.php
- app/Http/Requests/UsernameInputRequest.php
- app/Http/Requests/LoginPreparationRequest.php
- app/Http/Resources/UserResource.php
- app/Support/AuthApiResponse.php
- tests/Feature/AuthPreparationTest.php
- postman/SANAD-Phase-2A.postman_collection.json
- docs/authentication-phase-2a.md
- docs/superpowers/plans/2026-11-07-authentication-core.md

Modified:

- routes/api.php
- bootstrap/app.php
- app/Providers/AppServiceProvider.php
- app/Http/Requests/RegisterUserRequest.php (scope comment only; rules unchanged)
- phpunit.xml
- tests/TestCase.php
- tests/Support/UserTable.php
- tests/Feature/PostgresUserSchemaTest.php
- tests/Feature/RegistrationValidationTest.php (complete fixture required by real schema)
- docs/authentication-phase-1.md (points to current test configuration)

## Observed verification results

On 2026-11-07, the full backend suite passed with 114 tests and 1044 assertions: zero failures, errors or skipped tests. All database-backed tests used sanad_test_db on local PostgreSQL 18.6 as sanad_app, including all 10 Phase 1 PostgreSQL constraint cases. Preflight after the suite reported zero leftover test schemas. PHP syntax checks passed for all 16 touched PHP files. Pint formatting and its final --test check passed. The six-request Postman collection parsed successfully as JSON.

Read-only review found no Critical/Important findings. Its minor successful-profile coverage note was addressed by testing the profile serializer directly, including sensitive-field exclusion and nullable legacy fields, without minting a token or using actingAs. Valid/invalid login and registration preparation tests assert no session persistence, Set-Cookie, token records or protected-route access.

The API route list contains exactly the four preparation/availability routes plus existing user and health routes. Read-only verification on local sanad_db confirmed its original user count remains zero and the original-row fingerprint is unchanged; the Phase 1 columns/indexes/role constraint and migration batch 3 remain intact. GET /api/health returned unchanged 200 local JSON through Laravel's HTTP kernel. No new migrations or development record writes were performed. Development .env, Phase 1 migration source and Flutter were unchanged.
