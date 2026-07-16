<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Пароль задаётся через .env (SEED_ADMIN_PASSWORD) - сменить сразу
        // после первого входа; при отсутствии переменной используется
        // временный пароль для локальной разработки
        $admin = User::updateOrCreate(
            ['email' => env('SEED_ADMIN_EMAIL', 'admin@x-intellect.org')],
            [
                'name' => 'Fesoterika',
                'password' => env('SEED_ADMIN_PASSWORD', 'change-me-now'),
                'email_verified_at' => now(),
            ],
        );

        // role вне Fillable (см. App\Models\User) — присваиваем явно, иначе
        // сработал бы дефолт миграции ('editor') и админка осталась бы закрытой
        $admin->forceFill(['role' => 'admin'])->save();
    }
}
