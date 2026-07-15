<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Services\MediaWikiArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Сверка дат материалов со старым сайтом: published_at = дата добавления
 * материала на старый сайт. Идемпотентно, можно повторять на проде.
 *
 *   php artisan content:sync-dates {archive} [--dry] [--cdx]
 *
 * archive — путь к слепку …/www.x-intellect.org/www.x-intellect.org.
 *
 * Источники дат (по убыванию точности):
 *  1. Помесячные/погодовые архивы WordPress в слепке (/2012/…, /2013/02/…):
 *     плашка-дата у записи + год из пути → точная дата публикации записи.
 *  2. Вики: подвал MediaWiki «Последнее изменение этой страницы: …»
 *     в снимке страницы (сопоставление по заголовку).
 *  3. Вики-страницы с датой в заголовке («Сеанс с Силами 20131031»,
 *     «Аудиозапись 20131125») — дата ГГГГММДД из заголовка: страниц нет
 *     в слепке 2015 года, а первый снимок Wayback бывает на годы позже.
 *  4. --cdx: для оставшихся — дата ПЕРВОГО снимка старого адреса в Wayback
 *     Machine (CDX API, ответы кешируются в storage/app/wayback-cdx-dates.json;
 *     API ограничивает частоту запросов — при 429 просто повторите команду,
 *     недостающие ответы дозапросятся).
 *
 * Страницы source_type=new не трогаются (их даты — настоящие даты публикации
 * на новом сайте). Найденные даты ПЕРЕЗАПИСЫВАЮТ published_at.
 */
class SyncArchiveDates extends Command
{
    protected $signature = 'content:sync-dates {archive : Путь к слепку основного сайта}
        {--dry : Показать изменения без записи}
        {--cdx : Для ненайденных — дата первого снимка в Wayback Machine (сеть)}';

    protected $description = 'Сверить published_at с датами добавления материалов на старом сайте';

    /** Плашки-даты WordPress-темы: сокращённый месяц → номер. */
    protected const MONTHS_SHORT = [
        'янв' => 1, 'фев' => 2, 'мар' => 3, 'апр' => 4, 'май' => 5, 'июн' => 6,
        'июл' => 7, 'авг' => 8, 'сен' => 9, 'окт' => 10, 'ноя' => 11, 'дек' => 12,
    ];

    /** Подвал MediaWiki: месяц в родительном падеже → номер. */
    protected const MONTHS_GENITIVE = [
        'января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4, 'мая' => 5, 'июня' => 6,
        'июля' => 7, 'августа' => 8, 'сентября' => 9, 'октября' => 10, 'ноября' => 11, 'декабря' => 12,
    ];

    public function handle(MediaWikiArchive $mw): int
    {
        $base = rtrim($this->argument('archive'), '/');
        if (! File::isDirectory($base)) {
            $this->error("Не найдено: {$base}");

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');

        $postDates = $this->collectPostDates($base);
        $wikiDates = $this->collectWikiDates($base, $mw);
        $this->info('Дат из архивов WordPress: '.count($postDates).', из подвалов вики: '.count($wikiDates).'.');

        $changed = 0;
        $confirmed = 0;
        $unmatched = [];

        $pages = Page::where('source_type', '!=', 'new')->orderBy('id')->get();

        foreach ($pages as $page) {
            $date = $page->source_type === 'archive_wiki'
                ? ($wikiDates[mb_strtolower(trim($page->title))] ?? $this->titleDate($page->title))
                : ($postDates[$this->oldSlug($page)] ?? null);

            if ($date === null && $this->option('cdx')) {
                $date = $this->firstWaybackCapture($page);
            }

            if ($date === null) {
                $unmatched[] = $page;

                continue;
            }

            if ($page->published_at?->toDateString() === $date) {
                $confirmed++;

                continue;
            }

            $this->line(sprintf(
                '%s: %s → %s  (%s)',
                $page->slug,
                $page->published_at?->format('Y-m-d') ?? '—',
                $date,
                Str::limit($page->title, 50),
            ));

            if (! $dry) {
                $page->published_at = Carbon::parse($date, config('app.timezone'));
                $page->saveQuietly(); // без конвейера рендера и sitemap — меняется только дата
            }
            $changed++;
        }

        $this->newLine();
        $this->info("Готово. Перезаписано дат: {$changed}, уже совпадало: {$confirmed}, не найдено: ".count($unmatched).'.');

        if ($unmatched) {
            $this->comment('Без даты на старом сайте (published_at не тронут):');
            foreach ($unmatched as $page) {
                $this->line(sprintf('  %s  (%s, %s)', $page->slug, $page->source_type, Str::limit($page->title, 50)));
            }
            if (! $this->option('cdx')) {
                $this->comment('Подсказка: --cdx возьмёт для них дату первого снимка в Wayback Machine.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Даты записей WordPress из погодовых и помесячных архивов слепка:
     * /<год>[/<месяц>][/page/N]/default.htm, у записи — плашка «Фев 04».
     *
     * @return array<string, string> старый slug → Y-m-d
     */
    protected function collectPostDates(string $base): array
    {
        $files = collect([
            ...File::glob($base.'/20[0-9][0-9]/default.htm'),
            ...File::glob($base.'/20[0-9][0-9]/page/*/default.htm'),
            ...File::glob($base.'/20[0-9][0-9]/[0-9][0-9]/default.htm'),
            ...File::glob($base.'/20[0-9][0-9]/[0-9][0-9]/page/*/default.htm'),
        ]);

        $dates = [];

        foreach ($files as $file) {
            if (! preg_match('~/(20\d{2})(?:/|$)~', str_replace($base, '', $file), $ym)) {
                continue;
            }
            $year = (int) $ym[1];

            $html = File::get($file);
            preg_match_all(
                "~datebox'>\s*<span class='month'>([^<]+)</span>\s*<span class='date'>(\d{1,2})</span>.*?<h2><a href=\"([^\"]+)\"~su",
                $html,
                $posts,
                PREG_SET_ORDER,
            );

            foreach ($posts as [, $monthName, $day, $href]) {
                $month = self::MONTHS_SHORT[mb_strtolower(trim($monthName))] ?? null;
                $slug = mb_strtolower(basename(dirname($href)));
                if ($month === null || $slug === '' || $slug === '.') {
                    continue;
                }
                // первая встреченная дата — архивы согласованы между собой
                $dates[$slug] ??= sprintf('%04d-%02d-%02d', $year, $month, (int) $day);
            }
        }

        return $dates;
    }

    /**
     * Даты вики-страниц из подвала MediaWiki в снимках слепка.
     *
     * @return array<string, string> заголовок в нижнем регистре → Y-m-d
     */
    protected function collectWikiDates(string $base, MediaWikiArchive $mw): array
    {
        $files = collect(File::glob($base.'/wiki/index.php@title=*'))
            ->reject(fn ($f) => str_contains($f, '&'))
            ->reject(fn ($f) => (bool) preg_match('/\.(png|jpe?g|gif|svg|mp3|pdf|css|js|tmp|ico|webp|bmp)$/i', $f));

        $dates = [];

        foreach ($files as $file) {
            $html = @File::get($file);
            if (! $html) {
                continue;
            }

            if (! preg_match(
                '~footer-info-lastmod">\s*Последнее изменение этой страницы:\s*\d{1,2}:\d{2},\s*(\d{1,2})\s+([а-яё]+)\s+(\d{4})~u',
                $html,
                $m,
            )) {
                continue;
            }
            $month = self::MONTHS_GENITIVE[mb_strtolower($m[2])] ?? null;
            if ($month === null) {
                continue;
            }

            [$title] = $mw->parse($html);
            if ($title === null) {
                continue;
            }

            $dates[mb_strtolower(trim($title))] ??= sprintf('%04d-%02d-%02d', (int) $m[3], $month, (int) $m[1]);
        }

        return $dates;
    }

    /** Дата ГГГГММДД из заголовка вики-страницы (сеансы, аудиозаписи). */
    protected function titleDate(string $title): ?string
    {
        if (! preg_match('/(?<!\d)((?:19|20)\d{2})(\d{2})(\d{2})(?!\d)/', $title, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            ? sprintf('%s-%s-%s', $m[1], $m[2], $m[3])
            : null;
    }

    /** Старый slug страницы из source_url (…x-intellect.org/<slug>/). */
    protected function oldSlug(Page $page): string
    {
        if (preg_match('~x-intellect\.org/([^/?#]+)/?$~', (string) $page->source_url, $m)) {
            return mb_strtolower($m[1]);
        }

        return '';
    }

    /** Дата первого снимка старого адреса в Wayback Machine (CDX, с кешем на диске). */
    protected function firstWaybackCapture(Page $page): ?string
    {
        // старый адрес — источник за префиксом Wayback в source_url
        // (в снимках Wayback у хоста бывает порт :80 — убираем)
        if (! preg_match('~https?://(?:www\.)?x-intellect\.org(?::\d+)?(/.+)$~', (string) $page->source_url, $m)) {
            return null;
        }
        $oldUrl = 'http://www.x-intellect.org'.$m[1];

        $cachePath = storage_path('app/wayback-cdx-dates.json');
        $cache = File::exists($cachePath) ? (json_decode(File::get($cachePath), true) ?: []) : [];

        if (! array_key_exists($oldUrl, $cache)) {
            usleep(700_000); // бережём CDX API от 429 Too Many Requests

            try {
                $response = Http::timeout(20)->retry(3, 3000)->get('http://web.archive.org/cdx/search/cdx', [
                    'url' => $oldUrl,
                    'output' => 'json',
                    'filter' => 'statuscode:200',
                    'limit' => 1,
                    'fl' => 'timestamp',
                ]);
                $rows = $response->json();
                // [["timestamp"],["20120704…"]] — заголовок + первая (самая ранняя) метка
                $cache[$oldUrl] = $rows[1][0] ?? null;
            } catch (\Throwable $e) {
                $this->warn("CDX недоступен для {$oldUrl}: {$e->getMessage()}");

                return null;
            }

            File::put($cachePath, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $stamp = $cache[$oldUrl];

        return $stamp ? Carbon::createFromFormat('YmdHis', $stamp)->toDateString() : null;
    }
}
