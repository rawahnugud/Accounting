# Midnight Travel Accounting
Staff sign in with a provisioned account and land on their branch (a branch list appears only if someone is assigned to several). Viewers can inspect bank and safe accounts. Cashiers can post same-branch transfers in the company's reporting currency. The signed-in user ID is recorded on each transfer. Branch permission is checked again inside the SQLite write transaction.

## Local setup
This development runtime uses Laravel 12 and requires PHP 8.2 or later, Composer and PDO SQLite. Use an isolated accounting directory with its own database. From a fresh checkout run:

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve
```

A fresh database has no users or financial setup. Once company, branch and account records are provisioned, run `php artisan staff:assign staff@example.test cashier` with the intended email. The branch is picked automatically while only one branch exists; otherwise add its ID as a third argument. A new user is prompted for a name and password. Use `viewer` for read-only access. Company/branch configuration screens and opening balances are pending.

Run `php tests/treasury.php` for the posting checks and `python3 tests/staff_http.py` for the local HTTP integration test with synthetic accounts and a temporary database. Python is only used by the test.

## Verification and decisions
Invoice main's composer.json requests PHP ^8.1 and Laravel ^10.0. Its .cpanel.yml calls scripts/update-cpanel.sh, which checks installed Laravel 10, PHP 8.1+, and PDO SQLite. Live PHP versions, hosting limits, backups and SQLite contention remain unverified. The historical PHP 8.1 host configuration cannot run this development build. Confirm availability of PHP 8.2+ for an isolated accounting subdomain before any staging or deployment decision. Never run this migration against Invoice.

Currency precision is explicit. This increment accepts exact decimal strings and rejects excess fractional digits. Choose the reporting currency, approval thresholds, exchange-rate direction, rounding, and overdraft policy before exposing posting to users. Test fixture currency and branch choices are examples. This module records movements only. Opening balances, overdraft enforcement, reversal workflows and FX journals are pending.

## Project skills
Project-local skill files were read directly for this session. Ponytail revision dec89d9040ad863decb0207003dcd500cb00aa03 and Caveman revision a192894723a8f47e7f504c7b5041967ff1f18e55 are under .agents/skills with upstream licenses. AGENTS.md enables automatic use in this project.
