<?php

namespace App\Jobs;

use App\Services\IndexNow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Подача адресов в IndexNow через очередь: сохранение страницы в админке
 * не должно ждать ответа поисковика и не должно падать, если тот недоступен.
 *
 * Очередь database, разбирается по cron (queue:work --stop-when-empty) —
 * постоянного воркера на shared-хостинге нет.
 */
class SubmitToIndexNow implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $urls
     */
    public function __construct(public array $urls) {}

    public function handle(IndexNow $indexNow): void
    {
        $indexNow->submit($this->urls);
    }
}
