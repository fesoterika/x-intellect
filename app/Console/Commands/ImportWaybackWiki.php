<?php

namespace App\Console\Commands;

use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use App\Services\ArchiveHtmlCleaner;
use App\Services\MediaWikiArchive;
use App\Services\OfflineSnapshotIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Импорт вики-страниц из Wayback Machine — материалов, которых нет в офлайн-слепке
 * 2015 года (Сеансы 2014–2017, поздние стенограммы и статьи).
 *
 *   php artisan import:wayback-wiki [--from=2013] [--to=2020] [--limit=0] [--dry]
 *                                  [--sleep=1500] [--refresh] [--snapshot=/путь/www.x-intellect.org]
 *
 * Перечисление заголовков — через CDX API веб-архива; скачиваются только те,
 * которых ещё нет в БД (страницы archive_wiki и термины глоссария) — идемпотентно.
 * Все новые страницы — черновики; source_url ведёт на конкретный снимок Wayback.
 *
 * Картинки. В снимках с модификатором id_ MediaWiki отдаёт их корне-относительными
 * путями (/wiki/images/a/a4/KN_2M.PNG), и те же пути 1:1 лежат в офлайн-слепке
 * 2015 года. Поэтому качать их из веб-архива не нужно: --snapshot=<корень слепка>
 * отдаётся чистильщику как baseDir, и картинки берутся из слепка. Без --snapshot
 * baseDir не существует и картинки отбрасываются (прежнее поведение).
 */
class ImportWaybackWiki extends Command
{
    protected $signature = 'import:wayback-wiki {--from=2013} {--to=2020} {--limit=0} {--dry} {--sleep=1500} {--refresh} {--snapshot=}';

    protected $description = 'Импорт недостающих вики-страниц из Wayback Machine (CDX)';

    public function handle(ArchiveHtmlCleaner $cleaner, MediaWikiArchive $mw, OfflineSnapshotIndex $index): int
    {
        $wikiSectionId = Section::where('slug', 'wiki')->value('id');
        if (! $wikiSectionId) {
            $this->error('Нет раздела wiki.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');
        $limit = (int) $this->option('limit');
        $sleepMs = max(0, (int) $this->option('sleep'));

        // Корень офлайн-слепка: картинки снимков резолвятся относительно него.
        // Несуществующий путь = картинок не будет — лучше упасть сразу.
        $baseDir = '/nonexistent';
        if ($snapshot = trim((string) $this->option('snapshot'))) {
            $baseDir = realpath($snapshot) ?: '';
            if ($baseDir === '' || ! is_dir($baseDir)) {
                $this->error('Нет каталога слепка: '.$snapshot);

                return self::FAILURE;
            }
            if (! is_dir($baseDir.'/wiki/images')) {
                $this->error('В слепке нет wiki/images: '.$baseDir.' — нужен корень …/www.x-intellect.org.');

                return self::FAILURE;
            }
        } else {
            $this->warn('Без --snapshot картинки из снимков не восстанавливаются.');
        }
        $cleaner->dryRun = $dry;

        $this->info('Опрашиваю CDX API веб-архива…');
        $candidates = $this->enumerateTitles($mw);
        $this->info('Заголовков-кандидатов в CDX: '.count($candidates));

        $refresh = (bool) $this->option('refresh');

        // Что уже есть в БД (без учёта регистра)
        $known = [];
        foreach (Page::where('source_type', 'archive_wiki')->pluck('title') as $t) {
            $known[mb_strtolower(trim($t))] = true;
        }
        foreach (GlossaryTerm::pluck('term') as $t) {
            $known[mb_strtolower(trim($t))] = true;
        }

        // --refresh: страницы, уже импортированные ИЗ WAYBACK (source_url не
        // /web/2015/), перечищаются заново; слепковые и термины не трогаем
        $refreshable = [];
        if ($refresh) {
            foreach (Page::where('source_type', 'archive_wiki')
                ->where('source_url', 'like', 'https://web.archive.org/web/%')
                ->where('source_url', 'not like', '%/web/2015/%')
                ->get() as $p) {
                $refreshable[mb_strtolower(trim($p->title))] = $p;
            }
        }

        $todo = collect($candidates)
            ->reject(fn ($c, $key) => isset($known[$key]) && ! isset($refreshable[$key]))
            ->values();
        $this->info('Будут скачаны: '.$todo->count().($refresh ? ' (включая обновляемые)' : ''));

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $withImages = 0;
        $imagesTotal = 0;

        foreach ($todo as $c) {
            if ($limit && $created >= $limit) {
                break;
            }

            $html = $this->fetchSnapshot($c['timestamp'], $c['original']);
            if ($html === null) {
                $this->warn('Не скачалось: '.$c['title']);
                $failed++;

                continue;
            }

            [$title, $node, $doc] = $mw->parse($html);
            if ($title === null || $node === null || $mw->isSkippable($title)) {
                $skipped++;

                continue;
            }

            $norm = mb_strtolower(trim($title));

            // Заголовок из снимка может отличаться от URL-кандидата — перепроверяем
            if (isset($known[$norm]) && ! isset($refreshable[$norm])) {
                $skipped++;

                continue;
            }

            // Картинки берутся из офлайн-слепка по корне-относительному пути
            // снимка; без --snapshot чистильщик их по-прежнему отбрасывает
            $before = $cleaner->imagesCopied;
            $body = $cleaner->clean($mw->innerHtml($node, $doc), $baseDir, keepBlockquote: false);
            $images = $cleaner->imagesCopied - $before;
            if (Str::length(strip_tags($body)) < 25 || $index->isStub($body)) {
                $skipped++;

                // Пусто, редирект или «красная ссылка»: страница-заглушка
                // MediaWiki («В настоящее время на этой странице нет текста»)
                // длиннее 25 знаков и раньше проходила фильтр — так в базу
                // попали пустые Программы/УФО/Стабилизирующие оси.
                continue;
            }

            $known[$norm] = true;
            $imagesTotal += $images;
            if ($images > 0) {
                $withImages++;
            }
            $note = $images > 0 ? ', картинок: '.$images : '';

            if (isset($refreshable[$norm])) {
                $existing = $refreshable[$norm];
                // Опубликованное вычитано вручную и перезаписи не подлежит — даже
                // если ревизий с пометкой о ручной правке ещё нет (страницу могли
                // вычитать до того, как появилась пометка, или прямо в редакторе).
                $protected = $existing->status === 'published'
                    || $existing->revisions()->where('note', 'like', 'Отредактирована вручную%')->exists();
                if ($protected) {
                    $this->warn(sprintf('Пропуск (%s): %s',
                        $existing->status === 'published' ? 'опубликована' : 'ручные правки', $existing->title));
                    $skipped++;
                    $imagesTotal -= $images;
                    if ($images > 0) {
                        $withImages--;
                    }
                } else {
                    if (! $dry) {
                        $existing->body = $body;
                        $existing->source_url = 'https://web.archive.org/web/'.$c['timestamp'].'/'.$c['original'];
                        $existing->save();
                    }
                    $this->line('~ '.$title.' (/wiki/'.$existing->slug.') обновлена'.$note);
                    $created++;
                }

                if ($sleepMs) {
                    usleep($sleepMs * 1000);
                }

                continue;
            }

            if ($dry) {
                $this->line(sprintf('+ [wayback %s] %s%s', substr($c['timestamp'], 0, 8), $title, $note));
                $created++;

                if ($sleepMs) {
                    usleep($sleepMs * 1000);
                }

                continue;
            }

            $slug = $mw->uniqueSlug($title, Page::class);
            $page = Page::create([
                'section_id' => $wikiSectionId,
                'title' => $title,
                'slug' => $slug,
                'body' => $body,
                'status' => 'draft',
                'source_type' => 'archive_wiki',
                'source_url' => 'https://web.archive.org/web/'.$c['timestamp'].'/'.$c['original'],
            ]);

            foreach ($mw->oldWikiPaths($title) as $from) {
                Redirect::updateOrCreate(
                    ['from_path' => $from],
                    ['to_url' => $page->url(), 'status_code' => 301, 'comment' => 'Wayback вики: '.Str::limit($title, 50)],
                );
            }

            $this->line('+ '.$title.' (/wiki/'.$slug.')');
            $created++;

            if ($sleepMs) {
                usleep($sleepMs * 1000);
            }
        }

        $this->newLine();
        $this->info(($dry ? '[dry] ' : '')."Создано: {$created}, пропущено: {$skipped}, не скачалось: {$failed}.");
        $this->info(($dry ? '[dry] ' : '')."Страниц с картинками: {$withImages}, картинок всего: {$imagesTotal}"
            .($cleaner->imagesDropped ? ', отброшено: '.$cleaner->imagesDropped : '').'.');
        if (! $dry && $created > 0) {
            $this->comment('Дальше: php artisan remap:archive-links (перелинковка тел из Wayback).');
        }

        return self::SUCCESS;
    }

    /**
     * Заголовки вики из CDX: последний удачный снимок каждой страницы
     * ?title=… без action/oldid/printable.
     *
     * @return array<string, array{title: string, timestamp: string, original: string}> ключ — заголовок (lower)
     */
    private function enumerateTitles(MediaWikiArchive $mw): array
    {
        $out = [];

        foreach (['www.x-intellect.org', 'x-intellect.org'] as $host) {
            $rows = $this->cdxQuery($host);
            foreach ($rows as $row) {
                [$original, $timestamp] = $row;
                $query = parse_url($original, PHP_URL_QUERY) ?: '';
                parse_str($query, $params);

                // только чистые просмотры статей: единственный параметр title
                if (! isset($params['title']) || count($params) > 1) {
                    continue;
                }

                $title = trim(str_replace('_', ' ', rawurldecode($params['title'])));
                if ($title === '' || str_contains($title, ':') || $mw->isSkippable($title)) {
                    continue;
                }

                $key = mb_strtolower($title);
                if (! isset($out[$key]) || strcmp($timestamp, $out[$key]['timestamp']) > 0) {
                    $out[$key] = ['title' => $title, 'timestamp' => $timestamp, 'original' => $original];
                }
            }
        }

        return $out;
    }

    /** @return array<int, array{0: string, 1: string}> [original, timestamp] */
    private function cdxQuery(string $host): array
    {
        $resp = Http::retry(3, 3000)->timeout(120)->get('https://web.archive.org/cdx/search/cdx', [
            'url' => $host.'/wiki/index.php',
            'matchType' => 'prefix',
            'output' => 'json',
            'fl' => 'original,timestamp,statuscode',
            'filter' => 'statuscode:200',
            'from' => (string) $this->option('from'),
            'to' => (string) $this->option('to'),
            'limit' => '100000',
        ]);

        if (! $resp->ok()) {
            $this->warn("CDX {$host}: HTTP {$resp->status()}");

            return [];
        }

        $rows = $resp->json() ?: [];
        array_shift($rows); // заголовок ["original","timestamp","statuscode"]

        return $rows;
    }

    /** Скачивает исходный HTML снимка (модификатор id_ — без тулбара Wayback). */
    private function fetchSnapshot(string $timestamp, string $original): ?string
    {
        try {
            $resp = Http::retry(2, 5000)->timeout(60)
                ->get("https://web.archive.org/web/{$timestamp}id_/{$original}");

            if ($resp->status() === 429) {
                $this->warn('429 от веб-архива — пауза 30 с…');
                sleep(30);
                $resp = Http::timeout(60)->get("https://web.archive.org/web/{$timestamp}id_/{$original}");
            }

            return $resp->ok() ? $resp->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
