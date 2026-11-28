#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
bash scripts/backup.sh pre-office
php artisan migrate --force
# Production never checks the compiled container for staleness, so drop it and let the next request rebuild it with the new routes and services.
rm -rf "$(php -r 'require "bootstrap/runtime.php"; echo (($_SERVER["LARAVEL_STORAGE_PATH"] ?? "") ?: "storage")."/framework/cache/symfony";')"
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
