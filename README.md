# Midnight Travel Accounting
First Phase 1 increment from the external developer handoff v5. A trusted PHP adapter posts a same-branch transfer between two bank or safe accounts in the company's reporting currency. An immutable transfer produces equal debit and credit journal lines. This module has no public endpoint.

## Run
Use 64-bit PHP 8.1+ with PDO SQLite. Run `php tests/treasury.php` from the project root. The test uses an in-memory database and synthetic records. For a local integration, use the included Laravel migration in a dedicated accounting application, enable SQLite foreign keys, and inject its PDO connection into App\Accounting\Treasury. The service owns the transaction. Supply the authorized actor from the authenticated session and reuse one request key for all retries of a transfer.

## Verification and decisions
Invoice main's composer.json requests PHP ^8.1 and Laravel ^10.0. Its .cpanel.yml calls scripts/update-cpanel.sh, which checks installed Laravel 10, PHP 8.1+, and PDO SQLite. Live PHP versions, hosting limits, backups and SQLite contention remain unverified. Review supported Laravel versions against the host before choosing the accounting runtime. Never run this migration against Invoice.

Currency precision is explicit. This increment accepts exact decimal strings and rejects excess fractional digits. Choose the reporting currency, approval thresholds, exchange-rate direction, rounding, and overdraft policy before exposing posting to users. Test fixture currency and branch choices are examples. This module records movements only. Opening balances, overdraft enforcement, login, role checks, reversal workflows and FX journals are pending.

## Project skills
Project-local skill files were read directly for this session. Ponytail revision dec89d9040ad863decb0207003dcd500cb00aa03 and Caveman revision a192894723a8f47e7f504c7b5041967ff1f18e55 are under .agents/skills with upstream licenses. AGENTS.md enables automatic use in this project.
