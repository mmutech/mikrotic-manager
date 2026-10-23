<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('mmutech.seed.admin_email');
        $password = (string) config('mmutech.seed.admin_password');

        if ($email === '') {
            throw new \RuntimeException('Set MMUTECH_ADMIN_EMAIL before seeding the administrator.');
        }

        $user = User::withTrashed()->firstOrNew(['email' => $email]);
        $isNew = ! $user->exists;

        $user->name = (string) config('mmutech.seed.admin_name', 'MMUTech Administrator');
        $user->status = UserStatus::Active;

        if ($isNew || blank($user->password)) {
            if (strlen($password) < 8) {
                throw new \RuntimeException(
                    'Set MMUTECH_ADMIN_PASSWORD to at least 12 characters before seeding the administrator.'
                );
            }

            $user->password = Hash::make($password);
            $user->password_changed_at = now();
        }

        if ($user->trashed()) {
            $user->deleted_at = null;
        }

        $user->save();
        $user->syncRoles(['super-admin']);
    }
}
