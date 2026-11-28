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

Once a company and branch are provisioned, run `php artisan staff:assign staff@example.test 1 cashier` with the actual email and branch ID. New staff are prompted for a name and password. The viewer role grants read access. Use the administrator control panel and branch posting screen for further setup.

## Verification and scope

`php tests/treasury.php` checks posting, amount precision, tenancy and retries. `python3 tests/staff_http.py` exercises authentication, branch authorization, transfers, CSRF and revocation against temporary data. The three migration names and existing SQLite records are compatible with the Laravel build. The office upgrade below adds accounting workflows using a fourth, additive migration.

## Accounting office upgrade on cPanel

In the active PHP 8.1 release run the following. The upgrade creates a consistent, integrity-checked SQLite snapshot outside the web root before applying pending migrations. Supply the email of the existing owner login explicitly. The script prompts for the chosen password with terminal echo disabled and invalidates old login sessions when they next refresh.

```bash
cd "$HOME/apps/accounting-php81" &&
export PATH="/opt/alt/php81/usr/bin:$PATH" &&
bash scripts/update.sh &&
bash scripts/promote-admin.sh rawah@midnighttravel.net
```

`scripts/update.sh` pulls and runs `scripts/upgrade-office.sh`. It keeps the PHP handler block that cPanel adds to `public/.htaccess`, so the pull no longer conflicts on that file, and it stops if the file has any other local change. The upgrade clears the compiled Symfony cache. The promotion step is only needed once.

For a daily verified snapshot, add a cPanel cron job such as `0 2 * * * export PATH="/opt/alt/php81/usr/bin:$PATH"; bash "$HOME/apps/accounting-php81/scripts/backup.sh"`, using the same PHP path as above. Snapshots go to `~/accounting-backups` (or ACCOUNTING_BACKUPS), daily ones older than 30 days (ACCOUNTING_BACKUP_DAYS) are removed, and a warning is printed if any document does not balance. Copy snapshots off the server from time to time.

To promote an existing staff login with its current password, run `php artisan staff:promote EMAIL --keep-password` from the private hosting terminal.

Admin can open the control panel to configure companies, branches, currency precision, dated rates, financial accounts, credit limits, staff logins, branch assignments, employee salary terms and leave. Create a bank or safe before posting cash movements. Set foreign rates as **source currency units per one reporting currency unit**. Company reporting currency is shared by its branches. Record verified opening balances and their dates through the posting form.

Open a branch to enter transactions, preview the signed journal movements and confirm. Use a related document number for partial settlement at historic carrying value. Unlinked receipts and payments are account-level credits. Reissues take incremental selling price and supplier cost. Refunds create independent credit documents; settle them with customer/supplier refund payments. Deleting an entry posts a reversing entry. Reversing a linked chain starts with the most recent dependent document. Debits are positive and credits negative in ledgers. Sales and supplier costs are posted separately. Posting retains the rates used. Payroll takes gross salary plus allowances less deductions. Recovering an advance debits accrued salary and credits staff advances.

The dashboard and group overview show cumulative posted amounts. Management reports use the chosen date range for profit and cash movements and an end date for balances. Filter the ledger by account for a printable statement, or open a document for a receipt. Print to PDF, export CSV, or open the XML workbook in Excel. Accounts with foreign currency exposure are reported at carrying value. Group totals are split by reporting currency.

Users with the viewer role can read their assigned branch. Cashiers can record routine branch transactions. Administrator-only operations, such as opening balances, payroll and creditor obligations, show “Administrator access required” to cashiers and are recorded in the audit log. Admin can manage access, approve requests, reverse documents, close periods, and access all branches. Only the private terminal command grants global admin. Financial documents and journal lines are append-only in the app. The control panel shows the latest audit events.

To test restore, restore the private snapshot to a **new private file**, point a separate installation's DB_DATABASE at it, and run integrity and ledger checks. For rollback, record the pre-update commit before pulling. Roll the code back to that revision and retain the upgraded database for investigation. The new tables are additive. Restoring an older database discards later entries and requires a maintenance window and explicit approval. Keep private copies of the environment file, application key and database backups together under controlled access.

Run `php tests/books.php`, `php tests/treasury.php`, and `python3 tests/staff_http.py` using the target PHP CLI. Acceptance data is synthetic. Confirm local payroll rules, reporting periods, balances and the preferred refund workflow with the accountant before entering live financial records.

Matching open items and bank, supplier and statement reconciliation are retired. Deleting a document that still has an old allocation removes the allocation and reverses its FX document. Each branch can set a unique printable document prefix and sequence. The internal D-number remains a stable link for allocations and reversals.

Fees and creditor obligations take an optional expense category, and the profit and loss splits other expenses by category. Record a creditor loan as **Receive a loan from a creditor**: it debits the bank or safe instead of an expense, and repayments can link to it. Management reports add balance sheet totals with a balance check and an aging table of open customer and supplier items by days past due. The dashboard cards show this month's sales and gross profit. The general ledger screen shows the latest 500 lines, while CSV and Excel exports include every line. Dates follow Sudan time (Africa/Khartoum). The books are dual currency: sales, supplier costs, fees and owner equity are posted in the currency of the operation, each account keeps its own currency, and every line also stores its USD value. The dashboard and management reports show USD and SDG side by side with a USD total, and general accounts have a statement per currency. Staff may enter the agreed exchange rate on any cross-currency operation; the document records who set it. Staff roles are Administrator (everything, including the control panel), Sales (every operation, edits, deletions, matching and reports, but no control panel) and Viewer (read only); administrators assign them under Manage branch access. Sales staff and administrators can edit or delete any entry from the ledger, recent sales, customer and supplier statements or the document page; both keep the original and post a reversal behind the scenes. The screens say Delete rather than Void, because void means cancelling a ticket in travel. A deleted entry and its reversal drop out of a statement when both fall in its period. To re-rate past entries, use Re-rate past entries in the control panel with a currency, date range, new rate and optional customer or supplier; invoices with linked payments are skipped and listed. The sign-in page has Forgot password, which emails a one-hour link through the host's PHP mail(); administrators can also use Set staff password. New staff logins no longer ask for a currency. When a branch has no rate on or before a date, posting uses the latest company rate on or before that date, then the first later rate. Operation forms no longer ask for a due date or notes: each item is due on its document date, so the aging table counts days since the document. Installment schedules keep their own dates. Deleting a document in a closed period posts the reversal on today's date. Staff can discard an unfinished entry from the dashboard. Addresses are short: /dashboard, /sale (or any operation, such as /receipt or /supplier-payment), /d/5 for a document, /edit/5, /ledger, /statement, /reports and /settings; a branch other than the user's main one adds ?branch=N, and old /branches/N/office/... and /control links redirect. Operation forms hide the exchange rate until the chosen accounts use different currencies, pick the only bank or safe (or the one last used on that device), and fill the customer and amount when an open invoice is chosen; the document page offers to record another of the same operation. The statements page lists customer, supplier, bank and safe balances when no account is chosen; picking an account opens its statement, document numbers open the document, a search box filters the lines, and customer and supplier statements offer Record receipt or Pay supplier with the account filled in. Scheduled installments leave the dashboard once repayments cover them, and the aging table applies unmatched payments and credits to the oldest amounts first.

The control panel supports customer and supplier credit limits, scheduled creditor installments, salary terms and leave decisions. Group overview aggregates only like reporting currencies. Scheduled rates are manual. Ticket entry is manual. Live GDS importing and dedicated BSP settlement are outside the agreed v5 brief. Run `python3 tests/upgrade.py` for a synthetic legacy upgrade, verified backup restore, promotion, and concurrent retry check.
