<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            // Шапка — контентные разделы
            ['label' => 'Вики', 'url' => '/wiki', 'location' => 'header', 'position' => 1],
            ['label' => 'Глоссарий', 'url' => '/glossarij', 'location' => 'header', 'position' => 2],
            ['label' => 'Библиотека', 'url' => '/library', 'location' => 'header', 'position' => 3],
            ['label' => 'Журнал', 'url' => '/mag', 'location' => 'header', 'position' => 4],
            ['label' => 'Курсы', 'url' => '/courses', 'location' => 'header', 'position' => 5],
            ['label' => 'История', 'url' => '/history', 'location' => 'header', 'position' => 6],
            ['label' => 'О проекте', 'url' => '/about', 'location' => 'header', 'position' => 7],

            // Футер — служебные ссылки; /fesoterika в футере, не в основном
            // меню (стандартное место для «об авторе/разработчике», Этап 1 плана)
            ['label' => 'Приветствие', 'url' => '/hello', 'location' => 'footer', 'position' => 1],
            ['label' => 'Правила', 'url' => '/rules', 'location' => 'footer', 'position' => 2],
            ['label' => 'Об авторе сайта', 'url' => '/fesoterika', 'location' => 'footer', 'position' => 3],
            ['label' => 'Группа ВКонтакте', 'url' => 'https://vk.com/xintellect', 'location' => 'footer', 'position' => 4],
        ];

        foreach ($items as $item) {
            MenuItem::updateOrCreate(
                ['url' => $item['url'], 'location' => $item['location']],
                $item,
            );
        }
    }
}
