<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

final class Database
{
    public static function open(): PDO
    {
        $root = dirname(__DIR__);
        $path = $_SERVER['DB_DATABASE'] ?? $root.'/database/database.sqlite';
        if (!str_starts_with($path, '/')) {
            $path = $root.'/'.$path;
        }
        if (($_SERVER['DB_CONNECTION'] ?? 'sqlite') !== 'sqlite' || !is_file($path)) {
            throw new RuntimeException('Configure an existing Accounting SQLite database.');
        }
        $db = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA foreign_keys = ON');
        $db->exec('PRAGMA busy_timeout = 5000');
        return $db;
    }
}
