<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Laravel Scheduler — единственная cron-строка в панели Timeweb:
| * * * * * php /путь/до/сайта/artisan schedule:run
|--------------------------------------------------------------------------
*/

// Обработка database-очереди без постоянного воркера: раз в минуту
// разобрать накопившиеся задачи и завершиться (ограничение shared-хостинга)
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();

// Страховочная пересборка sitemap раз в час (плюс пересборка по событию
// публикации страницы через очередь — см. App\Observers\PageObserver)
Schedule::command('sitemap:generate')->hourly();
