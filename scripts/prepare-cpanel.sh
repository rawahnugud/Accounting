#!/bin/sh
# Run from a separate clone/worktree. The existing installation remains the rollback target.
set -eu
cd "$(dirname "$0")/.."
release=$(pwd -P)
original=$(cd "${1:-$HOME/apps/accounting}" && pwd -P)
[ "$release" != "$original" ] || { echo 'Run this in a separate Accounting release directory.'; exit 1; }
[ -s "$original/.env" ] && [ -s "$original/database/database.sqlite" ] || {
    echo 'Expected the existing Accounting .env and SQLite database.'; exit 1;
}
umask 077
for item in .env database/database.sqlite; do
    if [ -L "$item" ] && [ "$(readlink "$item")" = "$original/$item" ]; then
        continue
    fi
    if [ -e "$item" ] || [ -L "$item" ]; then
        echo "Existing release file needs review: $item"; exit 1
    fi
    ln -s "$original/$item" "$item"
done
php -r 'exit(PHP_VERSION_ID >= 80100 && extension_loaded("pdo_sqlite") ? 0 : 1);' || {
    echo 'Select PHP 8.1+ with PDO SQLite in Terminal.'; exit 1;
}
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
php -r '
    require "bootstrap/runtime.php";
    if (empty($_SERVER["APP_KEY"])) { throw new RuntimeException("Missing existing APP_KEY."); }
    $db = App\Database::open();
    $actual = $db->query("PRAGMA database_list")->fetch()["file"];
    if (realpath($actual) !== realpath($argv[1])) { throw new RuntimeException("Unexpected DB_DATABASE."); }
    $names = $db->query("SELECT migration FROM migrations")->fetchAll(PDO::FETCH_COLUMN);
    foreach (["0001_01_01_000000_create_users_table", "2026_11_22_000001_create_treasury",
              "2026_11_22_000002_create_branch_staff"] as $name) {
        if (!in_array($name, $names, true)) { throw new RuntimeException("Missing migration: ".$name); }
    }
    $kernel = new App\Kernel($_SERVER["APP_ENV"] ?? "production", false);
    $request = Symfony\Component\HttpFoundation\Request::create("/login");
    $response = $kernel->handle($request);
    if ($response->getStatusCode() !== 200 || !str_contains($response->getContent(), "Staff sign in")) {
        throw new RuntimeException("Login preflight failed.");
    }
    $kernel->terminate($request, $response);
' "$original/database/database.sqlite"
echo 'READY: existing database recognized and staff login rendered.'
