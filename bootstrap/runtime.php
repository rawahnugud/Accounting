<?php
declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';
// Staff work on Sudan time, so "today" must not lag two hours behind after midnight.
date_default_timezone_set('Africa/Khartoum');
foreach (['DB_CONNECTION', 'DB_DATABASE', 'APP_ENV', 'APP_KEY', 'LARAVEL_STORAGE_PATH'] as $name) {
    $value = getenv($name);
    if ($value !== false) {
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}
$env = dirname(__DIR__).'/.env';
if (is_file($env)) {
    (new Dotenv())->load($env);
}
