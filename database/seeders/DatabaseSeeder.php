<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Порядок важен: глоссарий сеется до страниц, чтобы Observer
     * при сохранении страниц проставил ссылки-тултипы терминов.
     * Model events намеренно НЕ отключаются.
     */
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            SectionSeeder::class,
            GlossarySeeder::class,
            PageSeeder::class,
            RedirectSeeder::class,
            MenuSeeder::class,
        ]);
    }
}
