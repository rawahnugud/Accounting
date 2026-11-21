<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

Artisan::command('staff:assign {email} {branch} {role}', function () {
    $email = $this->argument('email');
    $role = $this->argument('role');
    $branch = DB::table('branches')->find($this->argument('branch'));
    if (!$branch || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['viewer','cashier'], true)) {
        $this->error('Enter a valid email, existing branch ID, and viewer or cashier.');
        return 1;
    }
    $user = User::firstOrNew(['email' => $email]);
    if (!$user->exists) {
        $user->name = $this->ask('Staff name');
        $password = $this->secret('Password (at least 12 characters)');
        Validator::make(['name' => $user->name, 'password' => $password],
            ['name' => 'required|string', 'password' => 'required|string|min:12|max:72'])->validate();
        $user->password = $password;
    }
    DB::transaction(function () use ($user, $role, $branch) {
        $user->save();
        DB::table('branch_staff')->updateOrInsert(['user_id' => $user->id, 'branch_id' => $branch->id],
            ['company_id' => $branch->company_id, 'role' => $role]);
    });
    $this->info('Branch access saved.');
})->purpose('Create or update a staff branch assignment');
