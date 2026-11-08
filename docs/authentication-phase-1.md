# SANAD authentication foundation — Phase 1

Phase 2A update: the current test setup now runs all database-backed tests on guarded schemas in sanad_test_db, superseding the historical SQLite fixture setup below. See authentication-phase-2a.md for current API/testing instructions. The Phase 1 development migration was applied successfully after the verification recorded here; no additional migration is required by Phase 2A.

This phase adds schema, model and an unmounted registration validation contract. It does not register accounts, authenticate users, verify OTP, issue tokens or change Flutter. The existing API routes remain unchanged.

## Users schema after applying the migration

| Column | PostgreSQL type | Nullability / source |
| --- | --- | --- |
| id | bigint identity (existing Laravel bigserial) | Primary key; database generated |
| name | varchar(255) | Required; existing column. Model derives trimmed first + last name when both exist; incomplete legacy accounts retain their name. |
| email | varchar(255) | Required; existing column. Request/model trim and lowercase new assignments; existing values are not rewritten. Registration maximum is 254. |
| password | varchar(255) | Required; existing hashed cast retained. |
| username | varchar(15) | Nullable in stage 1; preserve display casing, trim new assignments. |
| first_name, last_name | varchar(80) | Nullable in stage 1. |
| phone_number | varchar(16) | Nullable in stage 1; not unique. |
| country, region | varchar(80) | Nullable in stage 1; preserve current localized/display values. |
| address | varchar(250) | Nullable in stage 1. |
| role | varchar(16) | Nullable in stage 1, no default; non-null values exactly patient/caregiver. |
| email_verified_at | timestamp without time zone | Existing nullable authentication metadata. |
| remember_token | varchar(100) | Existing nullable authentication metadata. |
| created_at, updated_at | timestamp without time zone | Existing nullable columns; Eloquent generates timestamps on the server when saving. |

Existing primary key, `users_email_unique`, password-reset/session tables and Sanctum token table are preserved. Added indexes are `users_email_normalized_unique` on `lower(trim(email))` and `users_username_normalized_unique` on `lower(trim(username))`. `users_role_check` restricts non-null roles. PostgreSQL unique indexes allow multiple null usernames during staging.

The database is the final authority for concurrent uniqueness. The request's parameterized identity lookup uses the same expression. Callers bypassing the model still cannot insert case/space-equivalent identities. SQL trim removes surrounding ASCII spaces; request/model normalize Unicode White_Space and BOM like Dart before persistence. Raw SQL writers must supply canonical input; the database indexes do not strip arbitrary Unicode/control whitespace. No reservation tables are needed.

## Safe staging and rollback

New profile columns deliberately remain nullable, including on an empty database, so schema does not depend on how many users happened to exist at migration time. Existing accounts receive no fabricated personal data or default role. Required new-registration fields are enforced in `RegisterUserRequest`; this is not a database NOT NULL guarantee for legacy or direct SQL writes. A later authentication implementation must reject or remediate incomplete accounts. After actual data remediation, a separately reviewed migration can add NOT NULL constraints.

Before changes, the migration locks the users table against concurrent writes and checks normalized email conflicts. It aborts without changing records if conflicts exist; resolve them explicitly without deleting records automatically. The complete migration is transactional on PostgreSQL. Index creation temporarily blocks writes; plan around that if the table becomes large.

The down migration removes only this phase's columns, indexes and check. Original rows/authentication fields remain, but any newly populated profile values would be lost on rollback. Do not use rollback as a routine repair after collecting profile data. No rollback/reset/fresh command was run against development.

## Registration validation contract

`app/Http/Requests/RegisterUserRequest.php` is reusable but is not connected to a route. `authorize()` permits the future public registration request; it does not confer identity, OTP verification or account access.

| Request field | Rule |
| --- | --- |
| username | Required string, 4–15 ASCII letters/digits/dot/underscore; case-insensitive unique; trim but retain casing. |
| email | Required string, maximum 254, same simple non-whitespace `local@domain.suffix` pattern as Flutter; case-insensitive unique; trim/lowercase. |
| password | Required string; at least 9 Dart UTF-16 code units; ASCII uppercase, lowercase and digit, plus a character from the Flutter punctuation set. Never trim. |
| password_confirmation | Required string, exact password match; request-only. |
| first_name, last_name | Required nonblank strings, maximum 80 each. |
| phone_number | Required normalized string: plus sign followed by 7–15 ASCII digits. |
| country, region | Required nonblank strings, maximum 80 each. |
| address | Required nonblank string, maximum 250. |
| role | Required, exactly patient or caregiver through UserRole enum. |

The password punctuation set is: ``! @ # $ % ^ & * ( ) _ + - = [ ] { } ; : " \ | , . < > / ? ` ~``. Apostrophe alone does not satisfy it. `SignupPassword` mirrors Flutter's ASCII rules and UTF-16 length rather than Laravel's broader Unicode mixed-case rules. It imposes no new maximum. Existing bcrypt hashing behavior remains unchanged; long-password/hash-driver compatibility must be decided before a registration endpoint is released.

First/last names, phone, country, region and address are trimmed. Unknown fields such as IDs, timestamps, name and verification flags are not returned by `validated()`. Validation is not proof that OTP succeeded. A future handler must consume only validated data, derive name on the server and assign `UserRole::from($validated['role'])` explicitly; role is deliberately not fillable. It must also map unique-index races to field errors.

Model fillable profile fields exclude role, IDs, timestamps, verification metadata and remember token. Password uses Laravel's existing hashed cast. Password, remember token and loaded tokens relation are hidden from JSON. `HasApiTokens` is enabled, but this phase never calls `createToken`.

## Flutter differences to preserve explicitly

- Active signup UI names/address only check nonblank; the reusable name validator has an 80-character limit but is not called by those form fields. Backend limits match the existing Firestore create rules (80/250). No Flutter changes were made.
- Flutter email UI has no length bound; repository/create rules cap it at 254. The Firestore rule also has minimum 6, while Flutter's actual regex accepts five-character addresses such as a@b.c. This contract follows the Flutter regex, without adding the Firestore-only minimum.
- Signup password checks include lowercase even though the UI displays four indicators. Recovery's eight-character/four-digit forms are previews and do not define this contract.
- The phone UI enforces configured national lengths; shared phone validation is broader. The backend accepts the stored normalized format used by Firestore. It neither validates phone ownership nor imposes phone uniqueness. It does not silently strengthen the format to a stricter country-code rule.
- Country is an English picker key; region can be Arabic or English. No new enums, geographic tables or relationship logic are introduced.
- New email assignments are lowercased consistently with identity checks; existing stored email casing is preserved by the migration. Username display casing is retained.

## Verification and applying locally

Run commands from sanad_backend using the existing PHP CLI (Herd's PHP executable can be used if php is not on PATH):

```powershell
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
php vendor/laravel/pint/builds/pint --test app/Enums app/Rules app/Support app/Http/Requests app/Models/User.php tests database/migrations/2026_11_07_000000_add_sanad_profile_fields_to_users_table.php
php artisan migrate:status
php artisan migrate --pretend --path=database/migrations/2026_11_07_000000_add_sanad_profile_fields_to_users_table.php
```

Review the target configuration and migration SQL first. Once ready, apply only the additive migration:

```powershell
php artisan migrate --path=database/migrations/2026_11_07_000000_add_sanad_profile_fields_to_users_table.php
```

No development migration is automatically applied by this implementation. Do not run migrate:fresh, migrate:reset or db:wipe. Preserve a backup before deliberate schema changes.

Portable tests force SQLite :memory: and exercise real request/model behavior using a small fixture. PostgreSQL integration tests use only `sanad_test_db`. They now fail closed rather than silently skip when their test configuration or permissions are missing. A SQLite pass is not PostgreSQL verification.

The local `.env.testing` is ignored by Git, names only `sanad_test_db`, and uses the existing `sanad_app` credentials. Development `.env` is unchanged. `.env.testing.example` contains the non-secret template. Supply a valid test APP_KEY and the existing account password locally; never commit the filled file. Its normal DB_CONNECTION is pgsql for standalone test-environment commands; PHPUnit deliberately overrides the portable-test default to SQLite memory while pinning named PostgreSQL connections separately.

The safety guard requires exactly `sanad_test_db`, account `sanad_app`, a loopback host, testing environment, no cached configuration, and no URL/read/write overrides. A read-only query verifies the actual database, account, server address, and CONNECT/CREATE privileges before beginning a transaction or creating schemas. TestCase configuration guards run inside createApplication before Laravel database setup traits. An additional current_schema check precedes migration execution. No schema operation may run against `sanad_db`.

Read-only preflight and test commands:

```powershell
php tests/verify-postgres.php
# Run tests only after preflight succeeds (exit code 0).
php vendor/phpunit/phpunit/phpunit --filter PostgresUserSchemaTest --do-not-cache-result
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
```

The standalone preflight prints only non-secret target/privilege information and exits nonzero on refusal. Each integration test creates a random schema inside a transaction, runs the actual migrations there, and rolls everything back. Existing schemas/tables are never refreshed or dropped. The preflight also reports the number of leftover `sanad_phase1_` schemas for cleanup verification.

The first live permission check confirmed `sanad_test_db` on local PostgreSQL 18.6 as `sanad_app`: CONNECT allowed, CREATE denied. The account cannot create isolated schemas yet, so the guards refused every PostgreSQL test before any DDL. The database owner must grant only the required test-database permission before retrying:

```sql
GRANT CREATE ON DATABASE sanad_test_db TO sanad_app;
```

No grants or database ownership changes are performed by the test suite. This permission is scoped to the test database; do not grant it on `sanad_db` for testing.

Next phase: registration service/controller and feature tests using this contract, with an explicit plan for OTP enforcement before token issuance. Login/logout/OTP/Flutter remain outside Phase 1.

## Change inventory and observed results

Modified:

- app/Models/User.php
- tests/TestCase.php
- phpunit.xml

Created:

- database/migrations/2026_11_07_000000_add_sanad_profile_fields_to_users_table.php
- app/Enums/UserRole.php
- app/Rules/SignupPassword.php
- app/Rules/UniqueUserIdentity.php
- app/Support/UserInput.php
- app/Http/Requests/RegisterUserRequest.php
- tests/Support/UserTable.php
- tests/Feature/RegistrationValidationTest.php
- tests/Feature/UserModelTest.php
- tests/Feature/PostgresUserSchemaTest.php
- tests/Feature/HealthTest.php
- docs/authentication-phase-1.md
- docs/superpowers/plans/2026-11-07-user-schema.md

Initial Phase 1 verification on 2026-11-07: full PHPUnit suite 58 passed, 10 PostgreSQL cases skipped, 401 assertions; Pint --test and PHP syntax checks passed. The API route list contained only GET/HEAD api/health and api/user, with health contract passing. The development migration remained pending after SQL preview. This initial run did not verify PostgreSQL constraints.

Follow-up verification setup: added ignored .env.testing, .env.testing.example, tests/Support/PostgresTestDatabase.php, tests/Unit/PostgresTestDatabaseTest.php, tests/verify-postgres.php; modified .gitignore, tests/TestCase.php, tests/Feature/PostgresUserSchemaTest.php and this document. The new guard unit tests passed. The first full configured run reported 78 passed, 10 PostgreSQL setup errors, 0 assertion failures and 0 skipped (88 total). All 10 errors were permission-guard refusals because sanad_app initially lacked CREATE on sanad_test_db, not executed constraint failures. Pint --test passed.

After the user granted CREATE on the test database, the read-only preflight verified local PostgreSQL 18.6, actual database sanad_test_db, account sanad_app, and CONNECT/CREATE permissions. All 10 PostgreSQL migration/constraint cases passed (126 assertions). The complete backend suite then passed: 88 tests, 604 assertions, 0 failures, 0 errors and 0 skipped. Post-run preflight reported zero leftover sanad_phase1_ schemas. No schema or test changes were needed after the permission grant; only this verification record was updated.

Phase 1's tested schema behavior is ready for deliberate development migration, subject to the existing preflight conflict check and normal backup/review. This does not certify existing development data or implement authentication APIs. No migration, reset, grant or write was performed against sanad_db during verification, and Flutter was untouched. The additive development migration remains unapplied by this work.

Final read-only review found no Critical/Important issues. Its Unicode-whitespace compatibility finding was reproduced with failing tests and fixed using the Dart SDK's documented whitespace list; the complete suite passed afterward. Flutter's pre-existing Git status remained unchanged. No secrets, dependencies, original migrations, routes or development user records were modified.
