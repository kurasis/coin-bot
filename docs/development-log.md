# Development evidence

All results below were obtained on PHP 8.5.12 / Laravel 13.38.0 on 2026-10-26. Production readiness is tracked separately from offline implementation.

## P01 - Complete

- Pinned Docker runtime and locked Composer dependencies; `bin/setup` initializes local configuration and SQLite without overwriting existing data.
- A clean export of the staged foundation installed all 104 packages from `composer.lock`, generated its local key, and migrated a new SQLite database.
- Clean-export `HealthTest`: 1 test / 4 assertions passed with warnings/risky tests treated as failures.
- Clean-export `composer validate --strict` and Pint: passed (22 PHP files).
- CI workflow is committed; execution on GitHub Actions remains to be observed after push. No production processes or recipient messages were started.

## P02 - Complete

- Added real SQLite domain schema with composite ownership foreign keys, exact uint256 amounts, transfer/outbox uniqueness, and scoped user access.
- `DomainPersistenceTest`: 11 tests / 22 assertions passed on the configured SQLite runtime.
- Verified rollback, same-transaction repeated logs, two-user shared-wallet isolation, foreign ownership rejection, destination authorization, and cross-user grant misuse.
- Database schema migrated successfully; no production users or credentials were created.

## P03 - Complete

- Implemented hashed 256-bit single-use keys, numeric Telegram identity binding, expiry, rate limits, generic denials, per-grant revocation, and fail-closed restored-state invalidation.
- Access feature/concurrency suites: 12 tests / 72 assertions passed; the concurrency case used two independent PHP processes against one real SQLite database and produced one activation and one denial.
- Pint passed all 13 access source/test files. Operator commands are local Artisan commands, not Telegram administration commands.
- No live user keys were issued or sent; test keys are synthetic and isolated.
