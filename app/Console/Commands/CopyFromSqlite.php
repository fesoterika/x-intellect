<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Перелив данных из SQLite-файла в текущую базу (MySQL).
 *
 *   php artisan db:copy-from-sqlite {file} [--force]
 *
 * Сценарий переезда dev → MySQL (локально и при деплое):
 *   1. настроить .env на MySQL и прогнать `php artisan migrate` (схема);
 *   2. прогнать эту команду — она очистит таблицы и зальёт данные из SQLite.
 *
 * Схему НЕ создаёт (это делают миграции) и таблицу migrations не трогает.
 * Служебные эфемерные таблицы (сессии, кеш, очередь) пропускаются.
 */
class CopyFromSqlite extends Command
{
    protected $signature = 'db:copy-from-sqlite
        {file=database/database.sqlite : Путь к SQLite-файлу}
        {--force : Не спрашивать подтверждение на очистку целевых таблиц}';

    protected $description = 'Скопировать данные из SQLite-файла в текущую базу (после php artisan migrate)';

    /** Таблицы, которые не переносим: журнал миграций и эфемерные данные. */
    private const SKIP = [
        'migrations', 'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ];

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error("Файл не найден: {$file}");

            return self::FAILURE;
        }

        $target = DB::connection();
        if ($target->getDriverName() === 'sqlite') {
            $this->error('Целевое соединение — SQLite; переключите .env на MySQL и прогоните migrate.');

            return self::FAILURE;
        }

        // Источник — одноразовое соединение поверх переданного файла
        config(['database.connections.xi_sqlite_src' => [
            'driver' => 'sqlite',
            'database' => $file,
            'foreign_key_constraints' => false,
        ]]);
        $source = DB::connection('xi_sqlite_src');

        $tables = array_values(array_diff(
            array_column($source->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"), 'name'),
            self::SKIP,
        ));

        if (! $this->option('force') && ! $this->confirm('Целевые таблицы будут очищены и заполнены данными из '.$file.'. Продолжить?')) {
            return self::FAILURE;
        }

        $target->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    $this->warn("— {$table}: нет в целевой базе (миграции отстают?), пропущена");

                    continue;
                }

                $target->table($table)->truncate();

                $copied = 0;
                $buffer = [];
                foreach ($source->table($table)->orderBy('rowid')->cursor() as $row) {
                    $buffer[] = (array) $row;
                    if (count($buffer) >= 100) {
                        $target->table($table)->insert($buffer);
                        $copied += count($buffer);
                        $buffer = [];
                    }
                }
                if ($buffer) {
                    $target->table($table)->insert($buffer);
                    $copied += count($buffer);
                }

                $this->line(sprintf('%s: %d строк', $table, $copied));
            }
        } finally {
            $target->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->info('Готово. Проверьте данные и прогоните sitemap:generate при необходимости.');

        return self::SUCCESS;
    }
}
