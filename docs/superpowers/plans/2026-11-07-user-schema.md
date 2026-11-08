# SANAD Phase 1 Implementation Plan

> **For agentic workers:** Use executing-plans to implement these tasks inline; use test-driven-development and a final read-only review.

**Goal:** Add the SANAD user schema, model and registration validation without implementing authentication endpoints or changing Flutter.

**Architecture:** Preserve the existing users table and records. Stage new profile columns as nullable; require complete input in an unmounted registration Form Request. PostgreSQL expression indexes and a role check enforce identity/role integrity independently of application validation.

**Tech Stack:** Laravel 13.40, Sanctum 4, PHP 8.5, PostgreSQL 18; isolated SQLite for portable validation/model tests.

**Spec:** User's Phase 1 request, attached as `75c6d141-21d1-4a62-bd86-1e2d1445349e/Pasted text.txt` in this conversation.

## Global Constraints

- All changes inside sanad_backend; Flutter is read-only.
- No authentication endpoints, OTP changes, token issuance, infrastructure or dependency additions.
- No development database reset, destructive tests, record rewriting or secret changes.
- Do not apply the migration to sanad_db in this phase; provide the command for deliberate local application.
- No Git repository exists at the backend root; implement in place, without initializing one or creating a worktree.
- Existing AGENTS.md tooling-install boilerplate is superseded by the user's strict scope: use installed dependencies rather than adding Boost or replacing PHP.

## Review Focus

- Legacy rows: migration leaves new fields null, preserves names/emails/passwords, and never fabricates roles or personal data.
- Existing normalized email duplicates: migration fails with an actionable non-PII diagnostic; no automatic cleanup.
- Raw SQL writes: case/space variants collide at database indexes; invalid non-null roles fail.
- Tests: default tests use SQLite :memory:; PostgreSQL tests require an explicit separate local test database and run DDL inside a rolled-back transaction.
- Serialization/mass assignment: password, remember token and token relation stay hidden; role/verification/IDs/timestamps cannot be mass assigned.

## Tasks

### 1. Registration contract and model

Files: tests/Feature/RegistrationValidationTest.php, tests/Feature/UserModelTest.php, app/Enums/UserRole.php, app/Rules/SignupPassword.php, app/Rules/UniqueUserIdentity.php, app/Support/UserInput.php, app/Http/Requests/RegisterUserRequest.php, app/Models/User.php.

- [x] Write behavioral tests for required fields, normalization, both roles, invalid roles, duplicate identities, password/confirmation, phone, Unicode names and field boundaries, hashing, serialization and guarded fields.
- [x] Run the tests and observe missing Phase 1 behavior.
- [x] Implement enum, fixed-column case-insensitive uniqueness rule, request normalization/rules and guarded Sanctum-compatible model.
- [x] Run portable tests on in-memory SQLite; no production schema claims based on SQLite.

### 2. Additive PostgreSQL schema

Files: database/migrations/2026_11_07_000000_add_sanad_profile_fields_to_users_table.php, tests/Feature/PostgresUserSchemaTest.php.

- [x] Add opt-in PostgreSQL tests: legacy row preservation, duplicate email/username rejection, invalid roles, nullable staging, shared phone numbers and reversible schema.
- [x] Before any PostgreSQL write, verify explicit test database name, local host and actual current_database(); skip if not configured.
- [x] Add nullable username(15), names(80), phone(16), country/region(80), address(250), role(16); no role default.
- [x] Add unique lower(trim(email)) and lower(trim(username)) indexes and role check; preserve existing email unique constraint and all auth fields/tables.
- [x] Reject conflicting existing emails without changing data; migration runs transactionally on PostgreSQL. Rollback removes only this phase's indexes/check/columns.

### 3. Verification and documentation

Files: tests/TestCase.php, tests/Feature/HealthTest.php, phpunit.xml, docs/authentication-phase-1.md.

- [x] Enforce safe test defaults, add health contract test, document staged nullability, exact validation discrepancies, safe migration commands and isolated PostgreSQL test setup.
- [x] Run PHP syntax checks, Pint on changed PHP files, complete PHPUnit suite, migration SQL preview when safe, and health/route checks.
- [x] Obtain final read-only code review and address material findings.
- [x] Report actual results and any unperformed PostgreSQL verification in docs/authentication-phase-1.md.

## Execution ledger

- Inspection complete: both roots verified, current users/Sanctum/auth configuration and Flutter model/validators read. No isolated PostgreSQL test database configured.
- Execution authorized by the user; no additional approval checkpoint needed for scoped source changes.
- Task 1 complete: initial request/model regressions failed as expected, then passed after implementation.
- Task 2 complete: additive SQL preview generated; 10 PostgreSQL integration cases safely skipped because no isolated database is configured. No development migration applied.
- Task 3 complete: 58 tests passed / 10 skipped, 401 assertions; syntax and Pint checks passed; health contract and two existing API routes preserved.
- Review: no Critical/Important findings. Unicode whitespace issue reproduced, fixed and covered by regressions using the installed Dart SDK's documented trim character set.
- Ruling: retain nullable staging regardless of row count to keep one predictable schema; a later data-remediation phase must tighten NOT NULL. No fake profile defaults or legacy record edits.
- Ruling: no repository exists for backend, so worktree/commit workflows are inapplicable. User's scoped implementation instruction takes precedence over boilerplate tooling installation and plan approval pauses.
