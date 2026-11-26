# Midnight Travel Accounting

The Accounting web application runs on Symfony 6.4, PHP 8.1+, Composer and PDO SQLite. Staff can sign in, view assigned branches and bank/safe accounts, and record same-currency transfers as a cashier. Each posting records the staff member and creates balanced journal lines. A duplicate request returns the original transfer.

## Existing cPanel staging installation

Keep the existing installation at `~/apps/accounting`. Prepare the compatibility release in a separate directory, using the PHP binary already verified on the Accounts host:

```sh
export PATH="/opt/alt/php81/usr/bin:$PATH"
git clone --branch compat/php81 --single-branch https://github.com/rawahnugud/Accounting.git "$HOME/apps/accounting-php81" &&
sh "$HOME/apps/accounting-php81/scripts/prepare-cpanel.sh"
```

If preparation was interrupted after cloning, rerun the script from the same release. It verifies Composer's requirements, links the existing private environment file and database, checks the migration ledger, and renders the login screen locally. The original directory remains necessary for the linked files. Existing staff sign in again after the switch.

After READY, change the Accounts document root to `apps/accounting-php81/public`. With the verified older CageFS deployment, switch off isolation for **accounts.midnighttravel.net only** to inherit PHP 8.1 and access the private application directory. This also makes account-level permissions the boundary between this site and sibling domains. Keep all private files outside the web root. Check HTTPS `/login` for “Staff sign in” and test a staging staff login. To roll back the web change, restore the original document root and per-domain settings. Both releases use the same SQLite database.

## Fresh local installation

Install the locked dependencies with `composer install`. Copy `.env.example` to `.env` and set a private random APP_KEY, or reuse an existing Accounting key. Generate a fresh value with `php -r 'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;'`. Set DB_DATABASE to the absolute path to the Accounting SQLite file, and create that empty file. Run `php artisan migrate`. For local development only, start `php -S 127.0.0.1:8000 -t public public/index.php`.

Once a company and branch are provisioned, run `php artisan staff:assign staff@example.test 1 cashier` with the actual email and branch ID. New staff are prompted for a name and password. The viewer role grants read access. Branch, account and opening balance setup screens remain pending.

## Verification and scope

`php tests/treasury.php` checks posting, amount precision, tenancy and retries. `python3 tests/staff_http.py` exercises authentication, branch authorization, transfers, CSRF and revocation against temporary data. The three migration names and existing SQLite records are compatible with the Laravel build. Cross-currency journals, customer/supplier ledgers, ticket operations and other full-system features remain pending.
