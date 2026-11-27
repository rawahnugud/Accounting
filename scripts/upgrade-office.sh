#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
umask 077
backup_dir=${ACCOUNTING_BACKUPS:-"$HOME/accounting-backups"}
mkdir -p "$backup_dir"
backup=$(mktemp "$backup_dir/pre-office-$(date -u +%Y%m%d-%H%M%S)-XXXXXXXX.sqlite")
php -r '
require "bootstrap/runtime.php";
$db = App\Database::open();
$db->exec("VACUUM INTO ".$db->quote($argv[1]));
$backup = new PDO("sqlite:".$argv[1]);
if ($backup->query("PRAGMA integrity_check")->fetchColumn() !== "ok") {
    throw new RuntimeException("Backup integrity check failed.");
}
echo "Verified database backup: ".$argv[1].PHP_EOL;
' "$backup"
php artisan migrate --force
php -r '
require "bootstrap/runtime.php";
$db=App\Database::open();
if ($db->query("PRAGMA foreign_key_check")->fetch() || $db->query("PRAGMA integrity_check")->fetchColumn()!=="ok") {
    throw new RuntimeException("Database verification failed.");
}
$kernel=new App\Kernel($_SERVER["APP_ENV"] ?? "production",false);
$request=Symfony\Component\HttpFoundation\Request::create("/login");
$response=$kernel->handle($request);
if ($response->getStatusCode()!==200 || !str_contains($response->getContent(),"Staff sign in")) {
    throw new RuntimeException("Login check failed.");
}
$kernel->terminate($request,$response);
echo "Accounting upgrade verified.".PHP_EOL;
'
