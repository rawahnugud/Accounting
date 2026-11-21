<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Treasury requires the isolated accounting SQLite connection.');
        }
        $db->unprepared(file_get_contents(__DIR__.'/../001_treasury.sql'));
    }

    public function down(): void
    {
        throw new RuntimeException('Restore the pre-migration accounting backup to roll back.');
    }
};
