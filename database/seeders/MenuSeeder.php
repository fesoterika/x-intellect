<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        // Шапка - контентные разделы (корневые пункты)
        $rootItems = [
            ['label' => 'Вики', 'url' => '/wiki', 'location' => 'header', 'position' => 1],
            ['label' => 'Библиотека', 'url' => '/library', 'location' => 'header', 'position' => 3],
            ['label' => 'Статьи', 'url' => '/articles', 'location' => 'header', 'position' => 4],
            ['label' => 'Курсы', 'url' => '/courses', 'location' => 'header', 'position' => 5],
            ['label' => 'О проекте', 'url' => '/about', 'location' => 'header', 'position' => 7],

            // Футер - служебные ссылки; /fesoterika в футере, не в основном
            // меню (стандартное место для «об авторе/разработчике», Этап 1 плана)
            ['label' => 'Приветствие', 'url' => '/hello', 'location' => 'footer', 'position' => 1],
            ['label' => 'Правила', 'url' => '/rules', 'location' => 'footer', 'position' => 2],
            ['label' => 'Об авторе сайта', 'url' => '/fesoterika', 'location' => 'footer', 'position' => 3],
            ['label' => 'Политика конфиденциальности', 'url' => '/about/politika-konfidencialnosti', 'location' => 'footer', 'position' => 4],
            ['label' => 'Группа ВК', 'url' => 'https://vk.com/xintellect', 'location' => 'footer', 'position' => 6],
        ];

        foreach ($rootItems as $item) {
            MenuItem::updateOrCreate(
                ['url' => $item['url'], 'location' => $item['location']],
                $item + ['parent_id' => null],
            );
        }

        // Подменю: Глоссарий - раздел вики, живёт в выпадающем меню «Вики»
        $wiki = MenuItem::where('location', 'header')->where('url', '/wiki')->first();

        MenuItem::updateOrCreate(
            ['url' => '/glossary', 'location' => 'header'],
            ['label' => 'Глоссарий', 'position' => 1, 'parent_id' => $wiki->id],
        );

        // Прежний плоский пункт глоссария (если остался от старого сидера)
        MenuItem::where('location', 'header')->where('url', '/glossarij')->delete();
    }
}
