# SANAD Phase 2A Implementation Plan

> **For agentic workers:** Execute inline with test-driven-development, verification-before-completion and a final read-only review.

**Goal:** Stateless authentication preparation and availability, without OTP, account/session creation or token issuance.

**Architecture:** Literal preparation endpoints reuse Phase 1 validation, query canonical identities, and validate credentials through Laravel's user provider without a guard login. Named rate limiters and a shared JSON/error contract protect the API. Every database-backed test runs in an isolated, rolled-back schema in sanad_test_db.

**Tech Stack:** Existing Laravel 13.40, PHP 8.5, PostgreSQL 18.6 and Sanctum; no additional dependencies or infrastructure.

**Spec:** User's SANAD Backend — Phase 2A: Authentication Core request in this conversation.

## Constraints and decisions

- Backend changes only. No Flutter/Firebase/Wexora/hardware changes, development user writes, new database migrations, OTP calls or authentication tokens.
- Registration preparation: POST /api/auth/register/prepare, HTTP 200 with explicit otp_required=true, otp_started=false, authenticated=false; no challenge/proof to replay.
- Login: POST /api/auth/login validates credentials only, same preparation flags; generic 401 for invalid credentials. No Auth::attempt/login/once, session middleware or password rehash writes.
- Availability: POST /api/auth/availability/email and /username, normalized input, boolean only, no reservation writes.
- GET /api/user stays auth:sanctum, returns a defined profile envelope; anonymous API requests return stable 401 JSON.
- Logout is a documented future POST /api/auth/logout, not registered in Phase 2A. Future authenticated bearer logout revokes only the caller's token and returns 204.
- Existing Phase 1 rules remain unchanged. Reuse RegisterUserRequest for preparation.
- No backend Git repository exists; implement in place. Strict scope supersedes AGENTS tooling-install boilerplate. User explicitly requested implementation; proceed without an additional plan approval pause.

## Review focus

- Valid credentials, forged OTP flags and preparation results must never grant protected access or produce cookies, sessions or tokens.
- Unknown account and wrong password must have identical generic failure responses; credential checks use a minimum timebox.
- Availability checks and races never replace PostgreSQL uniqueness.
- Rate-limit keys hash normalized identities; persistent configured cache in local development, isolated array cache in tests.
- Test guards must verify actual local sanad_test_db before any DDL, with all schemas rolled back.

## Tasks and ledger

- [x] Move the guarded test default to PostgreSQL and isolate each feature test in a transactional random schema. Replace portable table fixture with actual Phase 1 migrations. Run existing 88 tests.
- [x] Write failing API tests for availability/normalization/validation, throttling, valid/invalid login, preparation non-persistence, forged OTP flags, protected routes and absent finalize/logout routes.
- [x] Implement requests, controller, credential verifier, response/error contract and named rate limiting. Run targeted tests and full suite.
- [x] Add API documentation and a secret-free Postman collection, distinguishing implemented preparation from Phase 2B.
- [x] Run syntax/Pint, full suite, test-schema cleanup preflight, health verification and read-only review. Document exact changes/results.

Inspection complete: both roots verified, current models/request/rules/routes/test guards read. No OTP provider or secure challenge infrastructure exists, so preparation responses deliberately claim no started challenge.

Execution complete: existing 88 tests passed on the PostgreSQL-only harness. New API regressions initially failed on missing routes/error codes, then passed. An unusable stored-hash regression reproduced a 500 account-state signal and was fixed to generic 401 without modifying stored data. Final suite: 114 passed, 1044 assertions, zero failures/errors/skips. PHP syntax checks passed for 16 touched PHP files; Pint formatter and subsequent --test passed. Postman JSON parsed with six requests. Read-only reviewer found no Critical/Important issues; its profile serialization coverage note was addressed directly without authenticated test bypasses or token creation. Test schema count is zero after the suite. Read-only development snapshot confirmed unchanged original user rows, environment, Phase 1 schema/history and health JSON.
