# Phase 2B implementation plan

> Execute inline with TDD, systematic debugging, verification-before-completion and a final read-only security review.

**Goal:** Server-owned Wexora challenges and atomic OTP-gated authentication. **Spec:** user's Phase 2B attachment in this conversation. **Stack:** existing Laravel/PostgreSQL/Sanctum, no new infrastructure.

## Constraints and decisions

- Backend only; Flutter/provider Node code read-only. No real provider requests during tests; no development migration automatically applied. No dependency/tool installs or Git initialization.
- Preserve all Phase 2A preparation responses. Add signup/start, login/start, otp/send, otp/verify, register finalization and bearer logout.
- Provider contract is the existing Flutter/Node implementation: POST https://wexora.pro/api/v1/otp with Bearer, Idempotency-Key, session_id/to/purpose/reference/language; 202 plus id/expires_at. POST /otp/{id}/verify with code; authoritative 200 boolean verified, documented invalid 422. Existing provider purpose sanad_signup is reused for both flows; true signup/login binding is enforced locally. No invented provider purpose or delivery guarantee.
- auth_attempts stores UUID, SHA-256 attempt-secret/proof hashes, HMAC phone/credential binding, encrypted payload/provider ID, counters/state/expiry. No plaintext password or code is stored.
- Signup validates Phase 1 rules before hashing/encrypting pending payload. Finalization revalidates profile/uniqueness, consumes proof and creates user/token atomically. No registration changes accepted under an existing proof.
- Login start rechecks credentials and binds user/password-hash/phone. Verify rechecks binding under user lock; one completed attempt can mint one token only.
- Per-row FOR UPDATE serializes send/verify/finalize. Per-phone PostgreSQL advisory lock serializes cross-attempt send/guess budgets. Failed sends/verification attempts consume committed budgets; no retrying network side effects inside retried transactions.
- Server TTL 10 min, challenge TTL capped by provider/attempt, proof TTL 5 min, 3 sends/15min/phone, 60sec resend cooldown, 5 verification attempts per attempt and 10/15min/phone. Bearer token expiry 7 days; no cookie/session fallback.
- Missing credentials fail closed. Credentials referenced only through services/env; no secrets, codes, proofs or tokens logged. Provider failures sanitized.

## Tasks / ledger

- [x] Write provider-contract and end-to-end security regressions, observe RED.
- [x] Add additive migration, model, provider adapter/interface, requests, flow service, controllers and rate limits; preserve existing preparation routes.
- [x] Verify invalid/expired/wrong-purpose/cross-account/replay/resend/provider failure cases, duplicate signup, bearer-only profile/logout and token ordering.
- [x] Run real parallel verify/finalize processes against private test schemas; mocked provider only. Cleanup only newly created test schemas.
- [x] Add pruning, API/setup/migration/provider-contract docs and secret-free Postman collection.
- [x] Syntax/Pint/full PostgreSQL suite, cleanup/health/development preservation checks and read-only review.

No backend Git repository exists; work in place under the user's explicit implementation authorization. User scope supersedes bootstrap-tool installation/extra approval pauses.

Results: 142 tests passed / 1489 assertions, zero failures/errors/skips. All DB tests used local sanad_test_db; all provider calls mocked/stray requests forbidden. Three separate-process races passed, including different verified proofs competing for one identity. Syntax and Pint passed for 30 PHP files. Postman JSON has 12 requests; all six script blocks compiled, loopback hosts accepted, remote/deceptive hosts rejected, blank secrets and response/logout capture verified in Node without HTTP.

Debugging evidence: initial OTP flow RED on missing routes; timezone RED revealed PostgreSQL Africa/Cairo session interpreted UTC expiry bindings incorrectly, fixed by explicit UTC connection setting and regression. Review's Important rolling-window issue reproduced RED and fixed with last_verify_at conservative phone accounting; targeted reviewer confirmed resolution. Token storage-failure regression RED on unsafe 500, fixed to sanitized 503 after atomic rollback. No remaining concrete material review findings.

Development: new migration SQL previewed only and remains Pending; Phase 1 migration batch3/schema/user fingerprint and local health JSON unchanged. No actual Wexora credentials/messages used, no .env secrets changed, Flutter working-tree status unchanged.
