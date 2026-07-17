<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use App\Services\ArchiveHtmlCleaner;
use App\Services\ExcerptMaker;
use App\Services\WordPressArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Доимпорт записей основного сайта из Wayback Machine — того, что не попало
 * в офлайн-слепок 2015 года (серия «Человек, Земля, Космос», поздние выпуски
 * «Ноосферы», прогнозы, годовщины, новогодние обращения).
 *
 *   php artisan import:wayback-posts [--snapshot=/путь/www.x-intellect.org]
 *                                    [--only=slug] [--limit=0] [--dry] [--dates-from=2012]
 *
 * Список адресов — константа SLUGS: он выверен по старому сайту вручную,
 * перечисление через CDX сюда затянуло бы служебные страницы (контакты,
 * формы связи, отзывы, профили участников), которые решено не переносить.
 *
 * Страницы создаются черновиками с галочкой «Показывать в списках»:
 * публикует их пользователь сам после вычитки. Уже существующее (в том числе
 * опубликованное) команда не трогает — прогон идемпотентен.
 */
class ImportWaybackPosts extends Command
{
    protected $signature = 'import:wayback-posts {--snapshot=} {--only=} {--limit=0} {--dry} {--dates-from=2012} {--dates-to=2017}';

    protected $description = 'Доимпорт записей основного сайта из Wayback Machine (черновики)';

    /**
     * Записи старого сайта, которых нет на новом. Сверено с полным перечнем
     * адресов x-intellect.org; служебное и личное (формы связи, отзывы,
     * консультации, профили участников, поддержка, карта сайта, тестовые
     * страницы WordPress) в список намеренно не входит.
     */
    private const SLUGS = [
        // Проект «Человек, Земля, Космос»
        'proekt-chelovek-zemlya-kosmos-2014',
        'proekt-chelovek-zemlya-kosmos-2014-ch-2',
        'proekt-chelovek-zemlya-kosmos-2014-ch-3',
        'proekt-chelovek-zemlya-kosmos-2014-ch-4',
        'proekt-chelovek-zemlya-kosmos-2014-ch-5',
        'proekt-chelovek-zemlya-kosmos-2014-upravlenie-sobstvennoj-e-nergetikoj-ch-1',
        'proekt-chelovek-zemlya-kosmos-2014-upravlenie-sobstvennoj-e-nergetikoj-ch-2',
        'proekt-chelovek-zemlya-kosmos-upravlenie-sobstvennoj-e-nergetikoj-ch-3',
        'proekt-chelovek-zemlya-kosmos-upravlenie-sobstvennoj-e-nergetikoj-ch-4',
        'proekt-chelovek-zemlya-kosmos-upravlenie-sobstvennoj-e-nergetikoj-ch-5',
        'proekt-chelovek-zemlya-kosmos-upravlenie-sobstvennoj-e-nergetikoj-ch-6',
        'proekt-chelovek-zemlya-kosmos-upravlenie-sobstvennoj-e-nergetikoj-ch-7',
        'proekt-chelovek-zemlya-kosmos-upravlenie-sobstvennoj-e-nergetikoj-otvety-sil-na-prakticheskie-voprosy',
        // Проект «Ноосфера», поздние выпуски
        'proekt-noosfera-7-kto-takie-uchitelya',
        'proekt-noosfera-8-kto-takie-uchitelya',
        'proekt-noosfera-9-vzaimodejstvie-noosfery-s-lyud-mi',
        'proekt-noosfera-10-struktury-angelov-hranitelej-arhangelov',
        'proekt-noosfera-11-vzaimodejstvie-noosfery-s-lyud-mi',
        'noosfera-12-sistema-angelov-hranitelej',
        // Памяти А. Г. Глаза
        'godovshhina-so-dnya-uhoda-aleksandra-georgievicha-glaza-osnovatelya-soobshhestva-i-sajta-h-intellekt',
        'ispolnilos-2-goda-so-dnya-uhoda-iz-zemnogo-voploshheniya-aleksandra-georgievicha-glaza-osnovatelya-soobshhestva-i-sajta-h-intellekt',
        'svetlaya-pamyat-aleksandru-glazu',
        'vnimanie',
        // Прогнозы, конференции, обращения
        'prognozy-na-2015-god',
        'prognozy-na-2017-god',
        'novogodnyaya-konferentsiya-s-uchastiem-sil-blizhnego-i-dal-nego-kosmosa-2015',
        'vnimanie-konferentsii-s-uchastiem-sil',
        'ny-2012-2013',
        's-nastupayushhim-novy-m-2014-godom',
        's-novy-m-2015-godom',
        's-nastupayushhim-novy-m-2016-godom',
        // Дайджест
        'dajdzhest-h-intellect-oktyabr-2013',
    ];

    public function handle(WordPressArchive $wp, ArchiveHtmlCleaner $cleaner, ExcerptMaker $excerpts): int
    {
        $dry = (bool) $this->option('dry');
        $limit = (int) $this->option('limit');
        $cleaner->dryRun = $dry;

        $snapshotDir = null;
        if ($snapshot = trim((string) $this->option('snapshot'))) {
            $snapshotDir = realpath($snapshot) ?: null;
            if ($snapshotDir === null || ! is_dir($snapshotDir.'/wp-content')) {
                $this->error('Нет каталога слепка с wp-content: '.$snapshot);

                return self::FAILURE;
            }
        } else {
            $this->warn('Без --snapshot картинки 2012–2013 будут качаться из веб-архива (их там меньше).');
        }

        $sections = Section::pluck('id', 'slug');
        $todo = collect(self::SLUGS)
            ->when($only = trim((string) $this->option('only')), fn ($c) => $c->filter(fn ($s) => $s === $only))
            ->values();

        if ($todo->isEmpty()) {
            $this->error('Нечего импортировать'.($only ? ": в списке нет {$only}" : '.'));

            return self::FAILURE;
        }

        // Обе карты строим до первой записи в базу: если веб-архив не ответит,
        // лучше не начать вовсе, чем разложить материал без дат и картинок
        try {
            $this->info('Собираю даты записей из помесячных архивов веб-архива…');
            $dates = $wp->postDates((int) $this->option('dates-from'), (int) $this->option('dates-to'));
            $this->info('Дат собрано: '.count($dates).'.');

            $this->info('Картинок заснято веб-архивом: '.count($wp->uploadsIndex()).'.');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            $this->comment('Веб-архив отвечает с перебоями (429/503) — просто повторите прогон.');

            return self::FAILURE;
        }

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $noDate = [];
        $unanswered = [];

        foreach ($todo as $slug) {
            if ($limit && $created >= $limit) {
                break;
            }

            if ($this->alreadyOnSite($slug)) {
                $skipped++;

                continue;
            }

            try {
                $snap = $wp->bestSnapshot($slug);
            } catch (\RuntimeException $e) {
                // Не «материала нет», а «не смогли спросить» — прогон идемпотентен,
                // повтор доберёт пропущенное
                $this->error('Веб-архив не ответил, материал пропущен: '.$slug);
                $unanswered[] = $slug;
                $failed++;

                continue;
            }

            if ($snap === null) {
                $this->warn('Нет снимка в CDX: '.$slug);
                $failed++;

                continue;
            }

            $html = $wp->fetchSnapshot($snap['timestamp'], $snap['original']);
            if ($html === null) {
                $this->warn('Не скачалось: '.$slug);
                $failed++;

                continue;
            }

            $title = $wp->title($html);
            $entry = $wp->entryHtml($html);
            if ($title === null || $entry === null) {
                $this->warn('Не разобралось (нет заголовка или тела): '.$slug);
                $failed++;

                continue;
            }

            $sectionSlug = $wp->sectionFor($slug, $title);
            if ($sectionSlug === null) {
                $this->line('- служебная, пропуск: '.$title);
                $skipped++;

                continue;
            }

            // baseDir='/': localizeImages подставил абсолютные пути без ведущего
            // слэша, и realpath собирает их обратно — имя в storage остаётся
            // sha1(путь к исходному файлу), как при импорте из слепка
            $body = $cleaner->clean($wp->localizeImages($entry, $snapshotDir), '/');
            if (Str::length(strip_tags($body)) < 40) {
                $this->warn('Пусто после чистки: '.$slug);
                $skipped++;

                continue;
            }

            $date = $dates[$slug] ?? $this->dateFromPage($wp, $html, $snap['timestamp']);
            if ($date === null) {
                $noDate[] = $slug;
            }

            $sourceUrl = 'https://web.archive.org/web/'.$snap['timestamp'].'/'.$snap['original'];

            if ($dry) {
                $this->line(sprintf('+ [%s] %s — %s (%s)', $sectionSlug, Str::limit($title, 55), $date ?? 'без даты', $slug));
                $created++;

                continue;
            }

            $page = Page::create([
                'section_id' => $sections[$sectionSlug] ?? null,
                'title' => $title,
                'slug' => $this->uniqueSlug($slug),
                'body' => $body,
                'excerpt' => $excerpts->fromBody($body),
                'status' => 'draft',
                'is_listed' => true,
                'source_type' => 'archive_xintellect',
                'source_url' => $sourceUrl,
                'published_at' => $date ? Carbon::parse($date, config('app.timezone')) : null,
                'archived_at' => Carbon::createFromFormat('YmdHis', $snap['timestamp'], config('app.timezone')),
            ]);

            if (! Section::where('slug', $slug)->exists()) {
                Redirect::updateOrCreate(
                    ['from_path' => '/'.$slug],
                    ['to_url' => $page->url(), 'status_code' => 301, 'comment' => 'Архив: '.Str::limit($title, 60)],
                );
            }

            $this->line(sprintf('+ [%s] %s — %s', $sectionSlug, Str::limit($title, 55), $date ?? 'без даты'));
            $created++;
        }

        $this->newLine();
        $this->info(($dry ? '[dry] ' : '')."Создано: {$created}, пропущено: {$skipped}, не вышло: {$failed}.");
        $this->info(($dry ? '[dry] ' : '')."Картинок найдено: {$wp->imagesFetched}, нет в архиве: {$wp->imagesMissing}"
            .', скопировано в storage: '.$cleaner->imagesCopied.'.');

        if ($noDate) {
            $this->warn('Без точной даты со старого сайта: '.implode(', ', $noDate));
        }
        if ($unanswered) {
            $this->error('Веб-архив не ответил по '.count($unanswered).' адресам — ПОВТОРИТЕ ПРОГОН, '
                .'материал не потерян: '.implode(', ', $unanswered));
        }
        if (! $dry && $created > 0) {
            $this->comment('Дальше: remap:archive-links → site:structure-2026 → content:backfill → links:restore.');
        }

        return self::SUCCESS;
    }

    /**
     * Уже перенесённое: страница с тем же старым адресом в source_url.
     * Сверка по адресу, а не по заголовку — заголовки серий повторяются
     * («Проект Ноосфера. Кто такие Учителя» у ч. 7 и ч. 8).
     */
    private function alreadyOnSite(string $slug): bool
    {
        return Page::where('source_url', 'like', '%/'.$slug.'/')
            ->orWhere('source_url', 'like', '%/'.$slug)
            ->exists();
    }

    /**
     * Запасной источник даты: плашка на самой странице даёт день и месяц,
     * год берём из метки снимка. Снимок всегда позже публикации, поэтому
     * если плашка «декабрь», а снят январь — год предыдущий.
     */
    private function dateFromPage(WordPressArchive $wp, string $html, string $timestamp): ?string
    {
        $box = $wp->dateBox($html);
        if ($box === null) {
            return null;
        }
        [$month, $day] = $box;

        $snapYear = (int) substr($timestamp, 0, 4);
        $snapMonth = (int) substr($timestamp, 4, 2);
        $year = $month > $snapMonth ? $snapYear - 1 : $snapYear;

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function uniqueSlug(string $slug): string
    {
        $base = $slug;
        $i = 2;
        while (Page::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
