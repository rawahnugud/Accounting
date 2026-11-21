<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("CREATE TABLE branch_staff (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            company_id INTEGER NOT NULL,
            branch_id INTEGER NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('viewer','cashier')),
            PRIMARY KEY(user_id, branch_id),
            FOREIGN KEY(company_id, branch_id) REFERENCES branches(company_id, id)
        )");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE branch_staff');
    }
};
