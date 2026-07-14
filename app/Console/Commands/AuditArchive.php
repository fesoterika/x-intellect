<?php

namespace App\Console\Commands;

use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use App\Services\MediaWikiArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Аудит контента нового сайта против офлайн-слепка (и, опционально, Wayback):
 * пишет Markdown-отчёты в docs/audit/. Ничего не меняет, кроме --fix-redirects
 * (досоздаёт недостающие 301 со старых адресов).
 *
 *   php artisan audit:archive {archive} [--wayback] [--fix-redirects] [--out=docs/audit]
 *
 * archive — путь к www.x-intellect.org/www.x-intellect.org (вики — в подпапке wiki/).
 *
 * Исключения (по согласованию): раздел сайта «Библиотека» (library) и форум
 * не сверяются; страницы source_type=new — только информационный список.
 */
class AuditArchive extends Command
{
    protected $signature = 'audit:archive {archive} {--wayback} {--fix-redirects} {--out=docs/audit}';

    protected $description = 'Сравнение материалов нового сайта с архивом; отчёты в docs/audit/';

    /** Слаги слепка, не подлежащие сверке (сервис/дубли; зеркало ImportOfflineExplorer). */
    private array $skipSlugs = [
        'www.x-intellect.org', 'feed', 'api', 'params', 'image', 'rt=j', '$xd',
        'files', 'map', 'support', 'svetlanaglaz', 'meteor-slides', 'comments',
        'soul-1', 'page', 'embed', 'category', 'glaz', '_3a', 'socialhost_3a',
        'wp-includes', 'tag', 'contacts', 'foto_on_slider', 'skype', 'test',
        'kotox', 'emmir', 'magur7', 'alexavan', 'galateia', 'projects',
        'courses', 'courses-arc', 'rules', '2012', '2013', 'thank_you',
        'ny-2012-2013', 'privet-mir', 'slidenoborders', 'metodologia', 'mission',
    ];

    /** Разделы сайта, исключённые из сверки по требованию пользователя. */
    private array $excludedSlugs = ['library', 'forum'];

    private MediaWikiArchive $mw;

    public function handle(MediaWikiArchive $mw): int
    {
        $this->mw = $mw;

        $base = rtrim($this->argument('archive'), '/');
        if (! File::isDirectory($base)) {
            $this->error("Не найдено: {$base}");

            return self::FAILURE;
        }

        $out = base_path($this->option('out'));
        File::ensureDirectoryExists($out);

        $this->info('1/4 Сверка основного сайта…');
        $main = $this->auditMainSite($base);

        $this->info('2/4 Сверка вики…');
        $wiki = $this->auditWiki($base.'/wiki');

        $wayback = null;
        if ($this->option('wayback')) {
            $this->info('3/4 Сверка с Wayback Machine (CDX)…');
            $wayback = $this->auditWayback($wiki['archiveTitles']);
        } else {
            $this->info('3/4 Wayback пропущен (нет --wayback).');
        }

        $this->info('4/4 Проверка редиректов…');
        $redirects = $this->auditRedirects();

        File::put($out.'/content-comparison.md', $this->renderContentReport($main, $wiki, $wayback, $redirects));
        File::put($out.'/glossary-comparison.md', $this->renderGlossaryReport($base.'/wiki'));
        File::put($out.'/extra-pages.md', $this->renderExtraPagesReport($main, $wiki));

        $this->info("Отчёты записаны в {$out}/: content-comparison.md, glossary-comparison.md, extra-pages.md");

        return self::SUCCESS;
    }

    // ------------------------------------------------------------ main site

    /**
     * @return array{rows: array, skipped: array, excluded: array, missing: array}
     */
    private function auditMainSite(string $base): array
    {
        $rows = [];
        $skipped = [];
        $excluded = [];

        foreach (File::glob($base.'/*/default.htm') as $file) {
            $slug = basename(dirname($file));
            $slugKey = mb_strtolower($slug);
            $oldUrl = 'http://www.x-intellect.org/'.$slug.'/';

            if (in_array($slugKey, $this->excludedSlugs, true)) {
                $excluded[] = ['slug' => $slug, 'url' => $oldUrl];

                continue;
            }

            $html = @File::get($file) ?: '';
            $title = $this->extractTitle($html);

            // сервисные страницы (правило импортёра sectionFor → null)
            $serviceTitle = $title !== null && preg_match('/связь с представителем|консультац|отзыв/ui', $title);

            if (in_array($slugKey, $this->skipSlugs, true) || Str::startsWith($slugKey, 'contact-form')
                || $title === null || $this->isJunkTitle($title) || $serviceTitle) {
                $skipped[] = ['slug' => $slug, 'title' => $title ?? '(без заголовка)', 'url' => $oldUrl];

                continue;
            }

            $archiveLen = $this->mainBodyTextLength($html);
            $page = Page::where('source_url', 'like', '%/'.$slug.'/')->first();
            if (! $page) {
                // фолбэк: по редиректу со старого пути
                $to = Redirect::where('from_path', '/'.$slug)->value('to_url');
                if ($to && preg_match('#/([a-z0-9\-]+)$#', $to, $m)) {
                    $page = Page::where('slug', $m[1])->first();
                }
            }

            $dbLen = $page ? mb_strlen(trim(strip_tags($page->body ?? ''))) : 0;
            $flag = '';
            if ($page && $archiveLen > 400 && $dbLen < $archiveLen * 0.75) {
                $flag = 'короче архива на '.round(100 - $dbLen / max(1, $archiveLen) * 100).'%';
            }

            $rows[] = [
                'slug' => $slug,
                'title' => $title,
                'page' => $page,
                'archiveLen' => $archiveLen,
                'dbLen' => $dbLen,
                'flag' => $flag,
            ];
        }

        $missing = array_values(array_filter($rows, fn ($r) => $r['page'] === null));

        return compact('rows', 'skipped', 'excluded', 'missing');
    }

    private function extractTitle(string $html): ?string
    {
        if (! preg_match('#<title>(.*?)</title>#su', $html, $m)) {
            return null;
        }
        $t = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/\s*[:\x{2014}\x{2013}|-]\s*X\s*[\x{2014}\x{2013}-]\s*ИНТЕЛЛЕКТ.*$/ui', '', $t);

        return trim(preg_replace('/\s+/u', ' ', $t)) ?: null;
    }

    private function isJunkTitle(string $title): bool
    {
        return (bool) preg_match('/Страница не найдена|File moved|IIS|403|Forbidden|Карта сайта/ui', $title);
    }

    private function mainBodyTextLength(string $html): int
    {
        try {
            $crawler = new Crawler($html);
            foreach (['.entry', '.post-content', '.entry-content', 'article', '#content'] as $sel) {
                $node = $crawler->filter($sel);
                if ($node->count()) {
                    return mb_strlen(trim(preg_replace('/\s+/u', ' ', $node->first()->text(''))));
                }
            }
        } catch (\Throwable) {
        }

        return 0;
    }

    // ------------------------------------------------------------ wiki

    /**
     * @return array{archiveTitles: array, rows: array, skipped: array, missing: array}
     */
    private function auditWiki(string $wikiDir): array
    {
        $files = collect(File::glob($wikiDir.'/index.php@title=*'))
            ->reject(fn ($f) => str_contains($f, '&'))
            ->reject(fn ($f) => (bool) preg_match('/\.(png|jpe?g|gif|svg|mp3|pdf|css|js|tmp|ico|webp|bmp)$/i', $f));

        $pagesByTitle = [];
        foreach (Page::where('source_type', 'archive_wiki')->get(['id', 'title', 'slug', 'status', 'body']) as $p) {
            $pagesByTitle[mb_strtolower(trim($p->title))] = $p;
        }
        $termsByTitle = [];
        foreach (GlossaryTerm::all(['term', 'slug', 'definition']) as $t) {
            $termsByTitle[mb_strtolower(trim($t->term))] = $t;
        }

        $archiveTitles = [];
        $rows = [];
        $skipped = [];
        $seen = [];

        foreach ($files as $file) {
            [$title, $node] = $this->mw->parse(@File::get($file) ?: null);
            if ($title === null || $node === null) {
                continue; // не ns-0 / служебное
            }
            $norm = mb_strtolower(trim($title));
            if (isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;

            $oldUrl = 'http://www.x-intellect.org/wiki/index.php?title='.rawurlencode(str_replace(' ', '_', $title));

            if ($this->mw->isSkippable($title)) {
                $skipped[] = ['title' => $title, 'url' => $oldUrl];

                continue;
            }

            $archiveTitles[$norm] = $title;
            $archiveLen = mb_strlen(trim(preg_replace('/\s+/u', ' ', $node->textContent)));

            $page = $pagesByTitle[$norm] ?? null;
            $term = $termsByTitle[$norm] ?? null;

            $dbLen = $page ? mb_strlen(trim(strip_tags($page->body ?? ''))) : 0;
            $flag = '';
            if ($page && $archiveLen > 400 && $dbLen < $archiveLen * 0.6) {
                $flag = 'короче архива на '.round(100 - $dbLen / max(1, $archiveLen) * 100).'%';
            }

            $rows[] = [
                'title' => $title,
                'page' => $page,
                'term' => $term,
                'archiveLen' => $archiveLen,
                'dbLen' => $dbLen,
                'flag' => $flag,
                'url' => $oldUrl,
            ];
        }

        $missing = array_values(array_filter($rows, fn ($r) => $r['page'] === null && $r['term'] === null));

        return compact('archiveTitles', 'rows', 'skipped', 'missing');
    }

    // ------------------------------------------------------------ wayback

    /** @return array{titles: array, onlyWayback: array, counts: array} */
    private function auditWayback(array $archiveTitles): array
    {
        $titles = [];

        foreach (['www.x-intellect.org', 'x-intellect.org'] as $host) {
            $resp = Http::retry(3, 3000)->timeout(120)->get('https://web.archive.org/cdx/search/cdx', [
                'url' => $host.'/wiki/index.php',
                'matchType' => 'prefix',
                'output' => 'json',
                'fl' => 'original,timestamp,statuscode',
                'filter' => 'statuscode:200',
                'from' => '2010',
                'to' => '2024',
                'limit' => '100000',
            ]);
            if (! $resp->ok()) {
                $this->warn("CDX {$host}: HTTP {$resp->status()}");

                continue;
            }
            $rowsJson = $resp->json() ?: [];
            array_shift($rowsJson);
            foreach ($rowsJson as $row) {
                [$original, $timestamp] = $row;
                parse_str(parse_url($original, PHP_URL_QUERY) ?: '', $params);
                if (! isset($params['title']) || count($params) > 1) {
                    continue;
                }
                $title = trim(str_replace('_', ' ', rawurldecode($params['title'])));
                if ($title === '' || str_contains($title, ':') || $this->mw->isSkippable($title)) {
                    continue;
                }
                $key = mb_strtolower($title);
                if (! isset($titles[$key]) || strcmp($timestamp, $titles[$key]['timestamp']) > 0) {
                    $titles[$key] = ['title' => $title, 'timestamp' => $timestamp, 'original' => $original];
                }
            }
        }

        $pagesByTitle = Page::where('source_type', 'archive_wiki')->pluck('slug', 'title')
            ->mapWithKeys(fn ($slug, $title) => [mb_strtolower(trim($title)) => $slug])->all();
        $termsByTitle = GlossaryTerm::pluck('slug', 'term')
            ->mapWithKeys(fn ($slug, $term) => [mb_strtolower(trim($term)) => $slug])->all();

        $onlyWayback = [];
        foreach ($titles as $key => $c) {
            if (! isset($archiveTitles[$key]) && ! isset($pagesByTitle[$key]) && ! isset($termsByTitle[$key])) {
                $onlyWayback[] = $c;
            }
        }

        return [
            'titles' => $titles,
            'onlyWayback' => $onlyWayback,
            'counts' => [
                'cdx' => count($titles),
                'slepok' => count($archiveTitles),
                'dbPages' => Page::where('source_type', 'archive_wiki')->count(),
                'dbTerms' => GlossaryTerm::count(),
            ],
        ];
    }

    // ------------------------------------------------------------ redirects

    /** @return array{missing: array, broken: array, fixed: int} */
    private function auditRedirects(): array
    {
        $missing = [];
        $fix = (bool) $this->option('fix-redirects');
        $fixed = 0;

        // архив главного сайта: /slug → страница
        foreach (Page::whereIn('source_type', ['archive_xintellect', 'archive_sferarazuma'])->get() as $page) {
            if (! $page->source_url || ! preg_match('#x-intellect\.org/([^/]+)/?$#', $page->source_url, $m)) {
                continue;
            }
            $from = '/'.rawurldecode($m[1]);
            if (! Redirect::where('from_path', $from)->exists()) {
                $missing[] = ['from' => $from, 'to' => $page->url(), 'title' => $page->title];
                if ($fix) {
                    Redirect::updateOrCreate(
                        ['from_path' => $from],
                        ['to_url' => $page->url(), 'status_code' => 301, 'comment' => 'Аудит: '.Str::limit($page->title, 50)],
                    );
                    $fixed++;
                }
            }
        }

        // вики: оба варианта старого URL (подчёркивания и пробелы)
        foreach (Page::where('source_type', 'archive_wiki')->get() as $page) {
            foreach ($this->mw->oldWikiPaths($page->title) as $from) {
                if (! Redirect::where('from_path', $from)->exists()) {
                    $missing[] = ['from' => $from, 'to' => $page->url(), 'title' => $page->title];
                    if ($fix) {
                        Redirect::updateOrCreate(
                            ['from_path' => $from],
                            ['to_url' => $page->url(), 'status_code' => 301, 'comment' => 'Аудит вики: '.Str::limit($page->title, 50)],
                        );
                        $fixed++;
                    }
                }
            }
        }

        // термины глоссария
        foreach (GlossaryTerm::all() as $term) {
            foreach ($this->mw->oldWikiPaths($term->term) as $from) {
                if (! Redirect::where('from_path', $from)->exists()) {
                    $missing[] = ['from' => $from, 'to' => $term->url(), 'title' => 'термин: '.$term->term];
                    if ($fix) {
                        Redirect::updateOrCreate(
                            ['from_path' => $from],
                            ['to_url' => $term->url(), 'status_code' => 301, 'comment' => 'Аудит термина: '.Str::limit($term->term, 50)],
                        );
                        $fixed++;
                    }
                }
            }
        }

        // битые цели внутренних редиректов
        $broken = [];
        $sectionUrls = Section::all()->map(fn ($s) => $s->url())->flip();
        foreach (Redirect::all() as $r) {
            $to = $r->to_url;
            if (! str_starts_with($to, '/')) {
                continue; // внешние не проверяем
            }
            $path = parse_url($to, PHP_URL_PATH) ?: $to;
            if (in_array($path, ['/', '/glossary', '/search', '/forum'], true) || isset($sectionUrls[$path])) {
                continue;
            }
            if (preg_match('#^/glossary$#', $path)) {
                continue;
            }
            $slug = basename($path);
            if (! Page::where('slug', $slug)->exists() && ! Section::where('slug', $slug)->exists()
                && ! str_starts_with($path, '/forum/') && ! str_starts_with($path, '/storage/') && ! str_starts_with($path, '/go/')) {
                $broken[] = ['from' => $r->from_path, 'to' => $to];
            }
        }

        return compact('missing', 'broken', 'fixed');
    }

    // ------------------------------------------------------------ reports

    private function renderContentReport(array $main, array $wiki, ?array $wayback, array $redirects): string
    {
        $base = rtrim(config('app.url'), '/');
        $now = now()->format('d.m.Y H:i');

        $md = "# Аудит контента: новый сайт vs архив\n\nСформировано: {$now}. Команда: `php artisan audit:archive`.\n\n";
        $md .= "Исключены из сверки (по требованию): раздел сайта «Библиотека» (library), форум. Страницы `source_type=new` — не сверяются с архивом (созданы для нового сайта).\n\n";

        // --- сводка
        $okMain = count(array_filter($main['rows'], fn ($r) => $r['page']));
        $okWiki = count(array_filter($wiki['rows'], fn ($r) => $r['page'] || $r['term']));
        $md .= "## Сводка\n\n";
        $md .= "| Что | В архиве (содержательное) | Есть на сайте | Отсутствует |\n|---|---|---|---|\n";
        $md .= '| Основной сайт (страницы WordPress) | '.count($main['rows'])." | {$okMain} | ".count($main['missing'])." |\n";
        $md .= '| Вики (статьи ns-0) | '.count($wiki['rows'])." | {$okWiki} | ".count($wiki['missing'])." |\n\n";

        if ($wayback) {
            $c = $wayback['counts'];
            $md .= "### Сверка количества с Wayback Machine\n\n";
            $md .= "| Источник | Заголовков вики |\n|---|---|\n";
            $md .= "| Офлайн-слепок 2015 (ns-0, содержательные) | {$c['slepok']} |\n";
            $md .= "| Wayback CDX (2010–2024, чистые title-URL) | {$c['cdx']} |\n";
            $md .= "| БД: страницы вики (archive_wiki) | {$c['dbPages']} |\n";
            $md .= "| БД: термины глоссария | {$c['dbTerms']} |\n\n";
            $md .= "Есть только в Wayback (нет ни в слепке, ни в БД): **".count($wayback['onlyWayback'])."**\n\n";
            if ($wayback['onlyWayback']) {
                $md .= "| Заголовок | Снимок |\n|---|---|\n";
                foreach ($wayback['onlyWayback'] as $c2) {
                    $md .= "| {$c2['title']} | https://web.archive.org/web/{$c2['timestamp']}/{$c2['original']} |\n";
                }
                $md .= "\n";
            }
        }

        // --- основной сайт
        $md .= "## Основной сайт\n\n### Отсутствующие на новом сайте\n\n";
        $md .= $main['missing']
            ? $this->mdTable(['Заголовок', 'Старый адрес'], array_map(fn ($r) => [$r['title'], 'http://www.x-intellect.org/'.$r['slug'].'/'], $main['missing']))
            : "Нет — все содержательные страницы слепка есть на сайте.\n\n";

        $flagged = array_filter($main['rows'], fn ($r) => $r['flag'] !== '');
        $md .= "### Подозрительно короткие (возможно, заполнены не полностью)\n\n";
        $md .= $flagged
            ? $this->mdTable(['Страница', 'Текст в архиве', 'Текст на сайте', 'Отметка'], array_map(fn ($r) => [
                '['.$r['title'].']('.$base.$r['page']->url().')', $r['archiveLen'], $r['dbLen'], $r['flag'],
            ], $flagged))
            : "Нет.\n\n";

        $md .= "### Полный список (архив → сайт)\n\n";
        $md .= $this->mdTable(['Архивная страница', 'На сайте', 'Статус'], array_map(function ($r) use ($base) {
            $target = $r['page'] ? '['.$r['page']->slug.']('.$base.$r['page']->url().')' : '—';
            $status = $r['page'] ? $r['page']->status : 'ОТСУТСТВУЕТ';

            return [$r['title'], $target, $status];
        }, $main['rows']));

        // --- вики
        $md .= "## Вики\n\n### Отсутствующие (нет ни страницы, ни термина)\n\n";
        $md .= $wiki['missing']
            ? $this->mdTable(['Заголовок', 'Старый адрес'], array_map(fn ($r) => [$r['title'], $r['url']], $wiki['missing']))
            : "Нет — все статьи ns-0 слепка представлены на сайте (страницей или термином глоссария).\n\n";

        $flaggedW = array_filter($wiki['rows'], fn ($r) => $r['flag'] !== '');
        $md .= "### Подозрительно короткие\n\n";
        $md .= $flaggedW
            ? $this->mdTable(['Страница', 'Текст в архиве', 'Текст на сайте', 'Отметка'], array_map(fn ($r) => [
                '['.$r['title'].']('.$base.$r['page']->url().')', $r['archiveLen'], $r['dbLen'], $r['flag'],
            ], $flaggedW))
            : "Нет.\n\n";

        $md .= "### Полный список (архив → сайт)\n\n";
        $md .= $this->mdTable(['Статья вики', 'На сайте', 'Как'], array_map(function ($r) use ($base) {
            if ($r['page']) {
                return [$r['title'], '['.$r['page']->slug.']('.$base.$r['page']->url().')', 'страница ('.$r['page']->status.')'];
            }
            if ($r['term']) {
                return [$r['title'], '[глоссарий]('.$base.'/glossary?term='.$r['term']->slug.')', 'термин глоссария'];
            }

            return [$r['title'], '—', 'ОТСУТСТВУЕТ'];
        }, $wiki['rows']));

        // --- source_type=new
        $md .= "## Страницы нового сайта (source_type=new) — не сверяются\n\n";
        $md .= $this->mdTable(['Страница', 'Раздел', 'Статус'], Page::where('source_type', 'new')->get()
            ->map(fn ($p) => ['['.$p->title.']('.$base.$p->url().')', $p->section?->title ?? '—', $p->status])->all());

        // --- редиректы
        $md .= "## Редиректы\n\n";
        $md .= 'Недостающих редиректов со старых адресов: **'.count($redirects['missing']).'**'
            .($redirects['fixed'] ? " (создано --fix-redirects: {$redirects['fixed']})" : '')."\n\n";
        if ($redirects['missing'] && ! $redirects['fixed']) {
            $md .= $this->mdTable(['Старый путь', 'Куда должен вести', 'Материал'], array_map(
                fn ($r) => ['`'.$r['from'].'`', $r['to'], $r['title']], array_slice($redirects['missing'], 0, 200)));
        }
        $md .= 'Редиректов с битой целью (внутренний адрес не существует): **'.count($redirects['broken'])."**\n\n";
        if ($redirects['broken']) {
            $md .= $this->mdTable(['Откуда', 'Куда (битое)'], array_map(fn ($r) => ['`'.$r['from'].'`', '`'.$r['to'].'`'], $redirects['broken']));
        }

        $md .= "## Известные ограничения\n\n";
        $md .= "- Страницы, импортированные из Wayback Machine, — без картинок (в снимках это внешние URL веб-архива; чистильщик их не тянет).\n";
        $md .= "- Ручное редактирование в Trix убирает `id`-якоря из тела (ограничение редактора); якоря сохраняются при импорте и программных правках.\n";
        $md .= "- «Личные консультации» намеренно не импортируются (правило импортёра) — перечислены в extra-pages.md.\n";

        return $md;
    }

    private function renderGlossaryReport(string $wikiDir): string
    {
        $base = rtrim(config('app.url'), '/');
        $now = now()->format('d.m.Y H:i');

        // термины из архивных индексов
        $archiveTerms = [];
        foreach (['Глоссарий', 'Термины и понятия'] as $indexTitle) {
            $enc = str_replace('%', '_25', rawurlencode(str_replace(' ', '_', $indexTitle)));
            $file = $wikiDir.'/index.php@title='.$enc;
            if (! is_file($file)) {
                continue;
            }
            [, $node] = $this->mw->parse(@File::get($file) ?: null);
            if ($node === null) {
                continue;
            }
            foreach ($node->getElementsByTagName('a') as $a) {
                $t = trim($a->getAttribute('title'));
                $red = (bool) preg_match('/\(страница отсутствует\)\s*$/u', $t);
                $t = preg_replace('/\s*\(страница отсутствует\)\s*$/u', '', $t);
                if ($t !== '' && ! str_contains($t, ':')) {
                    $key = mb_strtolower($t);
                    $archiveTerms[$key] = ['title' => $t, 'red' => ($archiveTerms[$key]['red'] ?? true) && $red];
                }
            }
        }

        $dbTerms = GlossaryTerm::with('page')->orderBy('term')->get();
        $dbByKey = $dbTerms->keyBy(fn ($t) => mb_strtolower(trim($t->term)));

        $md = "# Аудит глоссария: новый сайт vs архив\n\nСформировано: {$now}.\n\n";
        $md .= '| Источник | Терминов |'."\n|---|---|\n";
        $md .= '| Архивные индексы («Глоссарий» + «Термины и понятия») | '.count($archiveTerms)." |\n";
        $md .= '| БД glossary_terms | '.$dbTerms->count()." |\n\n";

        // отсутствующие в БД
        $missing = array_filter($archiveTerms, fn ($t, $k) => ! isset($dbByKey[$k]), ARRAY_FILTER_USE_BOTH);
        $md .= "## В архиве есть, в БД нет\n\n";
        $md .= $missing
            ? $this->mdTable(['Термин', 'Примечание'], array_map(fn ($t) => [$t['title'], $t['red'] ? 'красная ссылка (страницы не было и в архиве)' : 'страница была'], array_values($missing)))
            : "Нет.\n\n";

        // лишние в БД
        $extra = $dbTerms->filter(fn ($t) => ! isset($archiveTerms[mb_strtolower(trim($t->term))]));
        $md .= "## В БД есть, в архивных индексах нет\n\n";
        $md .= $extra->isNotEmpty()
            ? $this->mdTable(['Термин', 'Ссылка'], $extra->map(fn ($t) => [$t->term, $base.'/glossary?term='.$t->slug])->all())
            : "Нет.\n\n";

        // пустые определения
        $empty = $dbTerms->filter(fn ($t) => mb_strlen(trim($t->definition)) < 20);
        $md .= "## Слишком короткие определения (< 20 символов)\n\n";
        $md .= $empty->isNotEmpty()
            ? $this->mdTable(['Термин', 'Определение'], $empty->map(fn ($t) => [$t->term, Str::limit($t->definition, 80)])->all())
            : "Нет.\n\n";

        // термин + страница
        $withPage = $dbTerms->filter(fn ($t) => $t->page_id);
        $md .= "## Термины с привязанной страницей (page_id)\n\n";
        $md .= $withPage->isNotEmpty()
            ? $this->mdTable(['Термин', 'Страница'], $withPage->map(fn ($t) => [$t->term, $base.$t->page->url()])->all())
            : "Нет привязок page_id.\n\n";

        // термин и страница одновременно (forceAsPages)
        $md .= "## Термин и полная страница одновременно (намеренно)\n\n";
        $rows = [];
        foreach ($this->mw->forceAsPages as $key) {
            $page = Page::whereRaw('LOWER(title) = ?', [$key])->first()
                ?? Page::where('source_type', 'archive_wiki')->get()->first(fn ($p) => mb_strtolower($p->title) === $key);
            $term = $dbByKey[$key] ?? null;
            $rows[] = [
                $key,
                $page ? $base.$page->url() : '—',
                $term ? $base.'/glossary?term='.$term->slug : '—',
            ];
        }
        $md .= $this->mdTable(['Заголовок', 'Страница', 'Термин'], $rows);

        $md .= "\nПолный список терминов БД: {$base}/glossary\n";

        return $md;
    }

    private function renderExtraPagesReport(array $main, array $wiki): string
    {
        $base = rtrim(config('app.url'), '/');
        $now = now()->format('d.m.Y H:i');

        $md = "# Лишние и системные страницы — для ручной проверки\n\nСформировано: {$now}. Ничего из перечисленного не удалялось.\n\n";

        // страницы БД без архивного соответствия
        $wikiKeys = array_change_key_case($wiki['archiveTitles'], CASE_LOWER);
        $mainSlugs = [];
        foreach ($main['rows'] as $r) {
            $mainSlugs[mb_strtolower($r['slug'])] = true;
        }

        $orphans = [];
        foreach (Page::where('source_type', '!=', 'new')->with('section')->get() as $p) {
            $inWiki = isset($wikiKeys[mb_strtolower(trim($p->title))]);
            $inMain = $p->source_url && preg_match('#x-intellect\.org/([^/]+)/?$#', $p->source_url, $m)
                && isset($mainSlugs[mb_strtolower(rawurldecode($m[1]))]);
            $fromWayback = $p->source_url && preg_match('#web\.archive\.org/web/(?!2015/)#', $p->source_url);
            if (! $inWiki && ! $inMain && ! $fromWayback) {
                $orphans[] = $p;
            }
        }
        $md .= "## Страницы БД без соответствия в слепке 2015 (кроме Wayback-импорта)\n\n";
        $md .= $orphans
            ? $this->mdTable(['Страница', 'Раздел', 'Источник', 'Правка'], array_map(fn ($p) => [
                '['.$p->title.']('.$base.$p->url().')', $p->section?->title ?? '—', $p->source_type,
                $base.'/admin/pages/'.$p->slug.'/edit',
            ], $orphans))
            : "Нет.\n\n";

        // дубли по заголовку
        $dupes = Page::selectRaw('LOWER(TRIM(title)) as t, COUNT(*) as c')->groupBy('t')->having('c', '>', 1)->pluck('t');
        $md .= "## Дубли по заголовку\n\n";
        if ($dupes->isNotEmpty()) {
            $rows = [];
            foreach ($dupes as $t) {
                foreach (Page::whereRaw('LOWER(TRIM(title)) = ?', [$t])->with('section')->get() as $p) {
                    $rows[] = ['['.$p->title.']('.$base.$p->url().')', $p->section?->title ?? '—', $p->source_type, $p->status];
                }
            }
            $md .= $this->mdTable(['Страница', 'Раздел', 'Источник', 'Статус'], $rows);
        } else {
            $md .= "Нет.\n\n";
        }

        // пропущено по правилам: главный сайт
        $md .= "## Архив: пропущено по правилам импорта (системное/сервисное) — основной сайт\n\n";
        $md .= $this->mdTable(['Slug', 'Заголовок', 'Старый адрес'], array_map(
            fn ($r) => ['`'.$r['slug'].'`', $r['title'], $r['url']], $main['skipped']));

        // пропущено по правилам: вики
        $md .= "## Архив: пропущено по правилам импорта — вики (в т.ч. личные консультации)\n\n";
        $md .= $this->mdTable(['Заголовок', 'Старый адрес'], array_map(
            fn ($r) => [$r['title'], $r['url']], $wiki['skipped']));

        // исключено по требованию
        $md .= "## Исключено из аудита по требованию (library, forum)\n\n";
        $md .= $this->mdTable(['Slug', 'Старый адрес'], array_map(
            fn ($r) => ['`'.$r['slug'].'`', $r['url']], $main['excluded']));

        return $md;
    }

    /** Markdown-таблица. */
    private function mdTable(array $headers, array $rows): string
    {
        if ($rows === []) {
            return "Нет.\n\n";
        }
        $md = '| '.implode(' | ', $headers)." |\n|".str_repeat('---|', count($headers))."\n";
        foreach ($rows as $row) {
            $md .= '| '.implode(' | ', array_map(fn ($c) => str_replace(["\n", '|'], [' ', '\\|'], (string) $c), $row))." |\n";
        }

        return $md."\n";
    }
}
