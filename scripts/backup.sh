#!/usr/bin/env bash
# Verified SQLite snapshot outside the web root. Run daily from cPanel Cron Jobs (see README).
# An optional first argument names the snapshot, for example "pre-office" before an upgrade.
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
prefix=${1:-daily}
backup_dir=${ACCOUNTING_BACKUPS:-"$HOME/accounting-backups"}
mkdir -p "$backup_dir"
backup=$(mktemp "$backup_dir/$prefix-$(date -u +%Y%m%d-%H%M%S)-XXXXXXXX.sqlite")
php -r '
require "bootstrap/runtime.php";
$db = App\Database::open();
$db->exec("VACUUM INTO ".$db->quote($argv[1]));
$backup = new PDO("sqlite:".$argv[1]);
if ($backup->query("PRAGMA integrity_check")->fetchColumn() !== "ok") {
    throw new RuntimeException("Backup integrity check failed.");
}
echo "Verified database backup: ".$argv[1].PHP_EOL;
// Every document must balance in the reporting currency. Posting checks this; the snapshot proves nothing slipped past.
$bad = $backup->query("SELECT 1 FROM sqlite_master WHERE name=\x27entries\x27")->fetchColumn()
    ? $backup->query("SELECT document_id FROM entries GROUP BY document_id HAVING SUM(base_minor)<>0 LIMIT 5")->fetchAll(PDO::FETCH_COLUMN) : [];
if ($bad) { fwrite(STDERR, "WARNING: unbalanced documents D-".implode(", D-", $bad).". Tell the developer.".PHP_EOL); }
' "$backup"
# Daily snapshots older than ACCOUNTING_BACKUP_DAYS (default 30) are removed; named snapshots are kept.
find "$backup_dir" -maxdepth 1 -name 'daily-*.sqlite' -mtime +"${ACCOUNTING_BACKUP_DAYS:-30}" -delete
