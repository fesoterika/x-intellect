<?php

namespace App\Console\Commands;

use App\Services\IndexNow;
use Illuminate\Console\Command;

/**
 * Создаёт файл-подтверждение ключа IndexNow в public/.
 *
 * Без него поисковик отвечает 403: файл доказывает, что адреса подаёт
 * владелец сайта. Имя файла — сам ключ, содержимое — тоже ключ.
 */
class IndexNowKey extends Command
{
    protected $signature = 'indexnow:key {--show : Только показать состояние, ничего не создавая}';

    protected $description = 'Создать файл-подтверждение ключа IndexNow в public/';

    public function handle(IndexNow $indexNow): int
    {
        if (! $indexNow->enabled()) {
            $this->error('INDEXNOW_KEY не задан в .env — отправка выключена.');
            $this->line('Сгенерировать ключ: '.bin2hex(random_bytes(16)));

            return self::FAILURE;
        }

        $path = $indexNow->keyFilePath();

        if ($this->option('show')) {
            $this->line('Ключ:  '.$indexNow->key());
            $this->line('Хост:  '.$indexNow->host());
            $this->line('Файл:  '.$path.(is_file($path) ? '  (есть)' : '  (НЕТ)'));

            return self::SUCCESS;
        }

        file_put_contents($path, $indexNow->key());

        $this->info('Файл создан: '.$path);
        $this->line('Проверьте доступность: '.$indexNow->keyLocation());

        return self::SUCCESS;
    }
}
