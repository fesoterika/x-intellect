<?php

namespace App\Console\Commands;

use App\Models\Page;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Точечное восстановление /rules/opgry по снимку Wayback 20.06.2021.
 * Текст, 78 таблиц и объединения ячеек сохранены; исправлены вложенность
 * списков и заголовки, возвращены 85 ссылок оглавления и прежние якоря.
 * Исходная редакция проверяется по хешу и сохраняется PageObserver в истории.
 */
class RestoreGrammarLayout extends Command
{
    protected $signature = 'site:restore-grammar-layout {--dry : Проверить без сохранения}';

    protected $description = 'Восстановить вёрстку справочника грамматики и его оглавление';

    private const ORIGINAL_SHA256 = '800b4e01b33b102ffe7a20cc800ff0e3d7d31385d6e7f7c8f3d34de555f30a6d';

    public function handle(): int
    {
        return DB::transaction(function () {
            $page = Page::where('slug', 'opgry')->lockForUpdate()->sole();
            if ($page->url() !== '/rules/opgry') {
                $this->error('Страница находится по другому адресу; восстановление отменено.');

                return self::FAILURE;
            }

            $body = File::get(resource_path('content/opgry.html'));
            if ($page->body === $body) {
                $this->info('Вёрстка справочника уже восстановлена.');

                return self::SUCCESS;
            }

            if (hash('sha256', $page->body) !== self::ORIGINAL_SHA256) {
                $this->error('Текст изменился после аудита; восстановление отменено, чтобы сохранить новые правки.');

                return self::FAILURE;
            }

            $this->line('Страница: /rules/opgry. Оглавление: 85 пунктов. Таблицы: 78.');
            if ($this->option('dry')) {
                $this->info('Проверка пройдена, изменения не записаны.');

                return self::SUCCESS;
            }

            $page->revisionReason = 'Восстановлены оглавление, якоря, таблицы и разметка по архивной копии от 20.06.2021';
            $page->body = $body;
            $page->source_url = 'https://web.archive.org/web/20210620025203/https://x-intellect.org/opgry/';
            $page->archived_at = '2021-06-20';
            $page->save();
            $this->info('Вёрстка восстановлена, предыдущая редакция сохранена в истории.');

            return self::SUCCESS;
        });
    }
}
