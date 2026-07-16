<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Services\ArchiveHtmlCleaner;
use App\Services\SferaRazumaArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Дополнение страниц-заглушек сеансов описанием из архива «Сферы Разума».
 *
 *   php artisan sessions:enrich-sfera {--dry}
 *
 * Страницы вида «Стенограмма сеанса не сохранилась…» (созданы командой
 * sessions:restore-missing из одного аудио) дополняются, если в веб-архиве вики
 * «Сферы Разума» нашлась стенограмма той же даты: добавляются метаданные и текст,
 * ставится пометка об источнике. Аудио и заголовок не трогаем; только черновики.
 */
class EnrichSessionsFromSfera extends Command
{
    protected $signature = 'sessions:enrich-sfera {--dry}';

    protected $description = 'Дополняет заглушки сеансов стенограммами из архива «Сферы Разума»';

    private const NOTE = 'Текстовая стенограмма восстановлена из архива проекта «Сфера Разума», '
        .'который вёл А. Г. Глаз до 2012 года.';

    public function handle(SferaRazumaArchive $sfera, ArchiveHtmlCleaner $cleaner): int
    {
        $catalogue = $sfera->transcriptPages();
        $dry = (bool) $this->option('dry');

        $stubs = Page::where('status', 'draft')
            ->where('body', 'like', '%не сохранилась%')
            ->where('source_type', 'archive_wiki')
            ->orderBy('title')->get();

        $enriched = 0;
        $noSource = [];

        foreach ($stubs as $page) {
            $key = preg_match('/((?:19|20)\d{6}[a-zA-Z]?)/', $page->title, $m) ? $m[1] : null;
            $catKey = $key ? $sfera->normalizeKey($key) : null;
            if (! $catKey || ! isset($catalogue[$catKey])) {
                $noSource[] = $page->title;

                continue;
            }

            $parsed = $sfera->parsePage($catalogue[$catKey], $cleaner);
            if (! $parsed || Str::length(strip_tags($parsed['body'])) < 40) {
                $noSource[] = $page->title;

                continue;
            }

            $body = $this->compose($page->title, $key, $parsed);
            $this->line(sprintf('~ %s ← стенограмма из Сферы (%d зн.)',
                Str::limit($page->title, 42), Str::length(strip_tags($parsed['body']))));
            $enriched++;

            if (! $dry) {
                $page->body = $body;
                $page->save();
            }
        }

        $this->newLine();
        $this->info(($dry ? '[dry] ' : '')."Дополнено: {$enriched}.");
        if ($noSource) {
            $this->comment('Без стенограммы в Сфере (оставлены как есть): '.count($noSource).' — '
                .implode('; ', array_map(fn ($t) => Str::limit($t, 30), array_slice($noSource, 0, 8)))
                .(count($noSource) > 8 ? '…' : ''));
        }

        return self::SUCCESS;
    }

    /** Таблица метаданных (из Сферы) + пометка + текст стенограммы. */
    private function compose(string $title, string $key, array $parsed): string
    {
        $rows = $parsed['meta'];
        // дату оставляем первой строкой
        $date = preg_match('/^((?:19|20)\d{2})(\d{2})(\d{2})/', $key, $m)
            ? sprintf('%d.%02d.%s', (int) $m[3], (int) $m[2], $m[1]) : null;
        $table = '<table><tbody>';
        if ($date) {
            $table .= '<tr><td><strong>Дата:</strong></td><td>'.$date.'</td></tr>';
        }
        foreach ($rows as $k => $v) {
            $table .= '<tr><td><strong>'.e($k).':</strong></td><td>'.e($v).'</td></tr>';
        }
        $table .= '</tbody></table>';

        return $table
            .'<p><em>'.self::NOTE.'</em></p>'
            .$parsed['body'];
    }
}
