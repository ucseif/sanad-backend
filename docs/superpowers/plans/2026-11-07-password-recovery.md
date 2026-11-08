# SANAD password recovery implementation plan

> Execute inline with TDD and focused verification. Run the full backend suite once after focused tests/review are green.

**Goal:** OTP-gated password reset only, using existing provider/lifecycle/policy. **Spec:** user's Password Recovery (WhatsApp OTP) request. Backend only; no development migration/record changes, dependencies, Flutter or provider-client edits.

## Decisions

- Add password_reset purpose via a new widening/check-constraint migration; decoy recovery attempts may have no user. Add nullable per-user server-verified phone timestamp/hash, never backfill trust. Signup/login populate it only after verified OTP.
- forgot returns a neutral 202 attempt for eligible, unverified and unknown accounts. No phone/profile/existence result is returned. A 200ms minimum timebox reduces lookup signals.
- send uses the existing database queue for every valid real/decoy attempt, with no synchronous provider request. Payload contains attempt ID/generation only. No new queue infrastructure; local worker required.
- Recovery API enforces identical per-attempt cooldown/counts; worker applies shared phone budgets and rechecks account/phone binding. Jobs are one-shot and generation-guarded. Provider errors remain neutral to prevent enumeration.
- Verify always gives generic invalid-code for failures, including missing/ineligible/provider failures. A dummy challenge verification lookup (never a send, never authoritative) avoids a simple network/no-network distinction; only a genuine current provider challenge and eligible bound account can produce a proof. Timing is not mathematically constant across vendor responses.
- Reset proof is server-issued/hash-stored, purpose/account/credential/phone-bound, <=5min and one-use. Reset transaction locks attempt/user, updates hash, revokes all tokens and consumes proof. Never mint a token.
- Reuse OtpDelivery extracted from existing send; expose existing attempt-lock/binding/phone-lock helpers internally. Original signup/login contracts remain unchanged.

## Tasks / ledger

- [x] Focused recovery regressions RED: neutral existence, verified phone, valid/invalid/expired/reused proof, cross-account/purpose, password rules, revocation, queues/provider failures and limits.
- [x] Add/test migration, shared delivery, recovery service/job/requests/controller and verified-phone metadata.
- [x] Focused tests and static security review; database exception sanitization and queue connection guard added. Final focused checks: 22 passed, 432 assertions, including actual concurrent reset completion.
- [x] Document contracts, worker/migration/privacy behavior and Postman flow.
- [x] Syntax/Pint passed; full suite run once: 159 passed, 1818 assertions, no failures/errors/skips. No migration application to sanad_db.

Existing AGENTS install boilerplate is outside user scope. No backend Git repo; work in place. User explicitly authorized implementation, so no extra approval checkpoint.
