<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Пересборка sitemap.xml по событию публикации/обновления страницы.
 * Выполняется database-очередью, которую разбирает cron
 * (queue:work --stop-when-empty) — без постоянного воркер-процесса,
 * что укладывается в ограничения shared-хостинга.
 */
class RegenerateSitemap implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function handle(): void
    {
        Artisan::call('sitemap:generate');
    }
}
