<?php

namespace App\Console\Commands;

use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\Redirect;
use App\Services\PhpbbParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Импорт архива форума phpBB — только чтение.
 *
 *   php artisan import:offline-forum {archive} [--wayback] [--report] [--dry]
 *
 * archive — папка …/x-intellect.org/forum (офлайн-слепок Offline Explorer).
 *
 * Источники контента:
 *  - папка: ВСЕ файлы viewtopic* (канонические, пагинация, пермалинки @p=,
 *    print-версии) — посты дедуплицируются по якорю pN и собираются в темы;
 *  - --wayback: недостающие темы и страницы пагинации докачиваются из
 *    web.archive.org (CDX + сырые id_-снимки), сливаются с папкой по pN.
 *
 * Всё системное phpBB (регистрация/логин/профили/личка) отбрасывается,
 * автор сообщения — строка-ник. --report пишет docs/forum-archive-report.md
 * (что восстановлено и что нет). После импорта создаются 301-редиректы со
 * старых URL тем/разделов на новые адреса.
 */
class ImportOfflineForum extends Command
{
    protected $signature = 'import:offline-forum {archive} {--wayback} {--report} {--dry}';

    protected $description = 'Импорт архива форума phpBB (папка + Wayback, только чтение)';

    private string $base = '';

    /** @var array<int, array{forum_id:int, title:string, posts:array<int,array>, sources:array<string,bool>}> */
    private array $topics = [];

    /** @var array<int, array{title:string, group:?string, position:int}> */
    private array $forums = [];

    /** @var array<int, array{forum_id:int, title:string, replies:?int}> топики из листингов (для отчёта) */
    private array $listing = [];

    public function handle(PhpbbParser $parser): int
    {
        $this->base = rtrim($this->argument('archive'), '/');
        if (! File::isDirectory($this->base)) {
            $this->error("Не найдено: {$this->base}");

            return self::FAILURE;
        }

        $this->parseForumsFromFolder();
        $this->info('Разделов форума (папка): '.count($this->forums));

        $this->collectFolder($parser);
        $this->info(sprintf('Из папки: %d тем / %d сообщений.', count($this->topics), $this->postCount()));

        if ($this->option('wayback')) {
            $this->collectWayback($parser);
            $this->info(sprintf('После Wayback: %d тем / %d сообщений.', count($this->topics), $this->postCount()));
        }

        if ($this->option('dry')) {
            $this->line('[dry] Запись в БД пропущена.');
        } else {
            $this->upsert();
            $this->makeRedirects();
            $this->info('Редиректов со старых URL: '.Redirect::where('comment', 'like', 'Архив форума:%')->count());
        }

        if ($this->option('report')) {
            $path = $this->writeReport();
            $this->info('Отчёт: '.$path);
        }

        $this->newLine();
        $this->info(sprintf('Готово. Тем: %d, сообщений: %d.', count($this->topics), $this->postCount()));

        return self::SUCCESS;
    }

    private function postCount(): int
    {
        return array_sum(array_map(fn ($t) => count($t['posts']), $this->topics));
    }

    // ── Сбор из папки ────────────────────────────────────────────────────

    private function collectFolder(PhpbbParser $parser): void
    {
        foreach (File::glob($this->base.'/viewtopic*') as $file) {
            // print-версии дублируют контент обычной страницы — пропускаем
            if (str_contains($file, 'view=print')) {
                continue;
            }
            $html = @File::get($file);
            if (! $html) {
                continue;
            }
            // подсказка id из имени файла (viewtopic.php@f=X&t=Y…, @t=Y)
            preg_match('/@f=(\d+)/', $file, $fm);
            preg_match('/[@&]t=(\d+)/', $file, $tm);
            $page = $parser->parsePage($html, $this->base, isset($fm[1]) ? (int) $fm[1] : null, isset($tm[1]) ? (int) $tm[1] : null);
            if ($page && $page['posts']) {
                $this->mergeTopic($page, 'folder');
            }
        }
    }

    // ── Сбор из Wayback ──────────────────────────────────────────────────

    private function collectWayback(PhpbbParser $parser): void
    {
        $cacheDir = storage_path('app/forum-wayback');
        File::ensureDirectoryExists($cacheDir);

        // дополним карту разделов из wayback-снимка главной форума
        $this->parseForumsFromWayback();

        // CDX: все снимки страниц тем, только 200; берём канонические f&t-страницы
        $lines = $this->cdx('x-intellect.org/forum/viewtopic.php');
        $pages = []; // "f-t-start" => [ts, url, f, t, start]
        foreach ($lines as $ln) {
            [$url, $ts] = array_pad(explode(' ', trim($ln)), 2, '');
            if ($url === '' || preg_match('/[?&]p=\d+/', $url) || str_contains($url, 'view=')) {
                continue; // пермалинки/print/next — в wayback не нужны, есть start-страницы
            }
            if (! preg_match('/[?&]f=(\d+)/', $url, $fm) || ! preg_match('/[?&]t=(\d+)/', $url, $tm)) {
                continue;
            }
            preg_match('/[?&]start=(\d+)/', $url, $sm);
            $key = $fm[1].'-'.$tm[1].'-'.($sm[1] ?? '0');
            // лучший снимок страницы — с самым поздним таймстампом (полнее)
            if (! isset($pages[$key]) || $ts > $pages[$key]['ts']) {
                $pages[$key] = ['ts' => $ts, 'url' => $url, 'f' => (int) $fm[1], 't' => (int) $tm[1]];
            }
        }
        $this->info('Wayback: страниц тем к загрузке: '.count($pages));

        $bar = $this->output->createProgressBar(count($pages));
        $bar->start();
        foreach ($pages as $key => $p) {
            $html = $this->fetchWayback($p['ts'], $p['url'], $cacheDir.'/'.$key.'.html');
            if ($html) {
                $page = $parser->parsePage($html, '', $p['f'], $p['t']);
                if ($page && $page['posts']) {
                    $this->mergeTopic($page, 'wayback');
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }

    /** Слить разобранную страницу в тему: посты объединяются по old_id (pN). */
    private function mergeTopic(array $page, string $source): void
    {
        $tid = $page['topic_id'];
        if (! isset($this->topics[$tid])) {
            $this->topics[$tid] = [
                'forum_id' => $page['forum_id'],
                'title' => $page['title'],
                'posts' => [],
                'sources' => [],
            ];
        }
        $t = &$this->topics[$tid];
        $t['sources'][$source] = true;
        if ($page['forum_id'] > 0) {
            $t['forum_id'] = $page['forum_id'];
        }
        if (($t['title'] === '' || $t['title'] === null) && $page['title']) {
            $t['title'] = $page['title'];
        }
        foreach ($page['posts'] as $pid => $post) {
            $t['posts'][$pid] ??= $post; // первое вхождение поста выигрывает
        }
    }

    private function cdx(string $prefix): array
    {
        $resp = Http::timeout(90)->retry(3, 3000)->get('http://web.archive.org/cdx/search/cdx', [
            'url' => $prefix,
            'matchType' => 'prefix',
            'output' => 'text',
            'fl' => 'original,timestamp',
            'collapse' => 'urlkey',
            'filter' => 'statuscode:200',
        ]);

        return $resp->successful() ? array_filter(explode("\n", $resp->body())) : [];
    }

    private function fetchWayback(string $ts, string $originalUrl, string $cacheFile): ?string
    {
        if (File::exists($cacheFile)) {
            return File::get($cacheFile);
        }
        // сырой снимок без тулбара Wayback (суффикс id_)
        $url = "https://web.archive.org/web/{$ts}id_/{$originalUrl}";
        try {
            $resp = Http::timeout(60)->retry(3, 2000)->get($url);
        } catch (\Throwable) {
            return null;
        }
        if (! $resp->successful()) {
            return null;
        }
        usleep(300_000); // вежливая пауза к архиву
        File::put($cacheFile, $resp->body());

        return $resp->body();
    }

    // ── Разделы и листинги ───────────────────────────────────────────────

    private function parseForumsFromFolder(): void
    {
        $index = @File::get($this->base.'/index.php') ?: @File::get($this->base.'/default.htm') ?: '';
        $this->parseForumIndex($index);

        foreach (File::glob($this->base.'/viewforum.php@f=*') as $file) {
            if (! preg_match('/@f=(\d+)$/', $file, $m)) {
                continue;
            }
            $this->parseForumListing((int) $m[1], @File::get($file) ?: '');
        }
    }

    private function parseForumsFromWayback(): void
    {
        $lines = $this->cdx('x-intellect.org/forum/index.php');
        foreach ($lines as $ln) {
            [$url, $ts] = array_pad(explode(' ', trim($ln)), 2, '');
            if ($url === '') {
                continue;
            }
            $html = $this->fetchWayback($ts, $url, storage_path('app/forum-wayback/index-'.$ts.'.html'));
            if ($html) {
                $this->parseForumIndex($html);
            }
            break; // одного снимка главной достаточно для карты разделов
        }
    }

    /** Категории (cat h4) и разделы (forumlink) с главной форума. */
    private function parseForumIndex(string $html): void
    {
        $position = count($this->forums);
        $group = null;
        if (preg_match_all(
            '#class="cat"[^>]*><h4><a href="viewforum\.php[@?]f=\d+">([^<]+)</a></h4>|class="forumlink" href="viewforum\.php[@?]f=(\d+)">([^<]+)</a>#u',
            $html,
            $rows,
            PREG_SET_ORDER,
        )) {
            foreach ($rows as $row) {
                if (($row[1] ?? '') !== '') {
                    $group = html_entity_decode($row[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    continue;
                }
                $fid = (int) $row[2];
                $this->forums[$fid] ??= [
                    'title' => html_entity_decode($row[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'group' => $group,
                    'position' => $position++,
                ];
            }
        }
    }

    /** Один раздел: заголовок + темы (заголовок, число ответов) для отчёта. */
    private function parseForumListing(int $fid, string $html): void
    {
        if (! isset($this->forums[$fid]) && preg_match('/<h2[^>]*>(?:<a[^>]*>)?([^<]+)/u', $html, $h)) {
            $this->forums[$fid] = [
                'title' => trim(html_entity_decode($h[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'group' => null,
                'position' => 500 + $fid,
            ];
        }

        // строки тем: заголовок + число ответов (первая ячейка width="50"
        // с topicdetails после заголовка — «Ответы»; следующая — «Просмотры»)
        $re = '#viewtopic\.php[@?]f=\d+&(?:amp;)?t=(\d+)[^"]*"[^>]*class="topictitle"[^>]*>([^<]+)</a>'
            .'.*?width="50" align="center"><p class="topicdetails">(\d+)</p>#us';
        if (preg_match_all($re, $html, $rows, PREG_SET_ORDER)) {
            foreach ($rows as $row) {
                $tid = (int) $row[1];
                $this->listing[$tid] ??= [
                    'forum_id' => $fid,
                    'title' => trim(html_entity_decode($row[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                    'replies' => (int) $row[3],
                ];
            }
        }
    }

    private function forumMeta(int $fid): array
    {
        $meta = $this->forums[$fid] ?? ['title' => 'Форум', 'group' => null, 'position' => 999];
        // подфорумы без категории в слепке — исследовательские ветки
        $meta['group'] ??= 'Исследования';

        return $meta;
    }

    // ── Запись в БД ──────────────────────────────────────────────────────

    private function upsert(): void
    {
        foreach ($this->topics as $tid => $t) {
            if (! $t['posts']) {
                continue;
            }
            ksort($t['posts']); // pN монотонны по времени
            $ordered = array_values($t['posts']);
            $forum = $this->forumMeta($t['forum_id']);

            $topic = ForumTopic::updateOrCreate(
                ['old_id' => $tid],
                [
                    'forum_old_id' => $t['forum_id'],
                    'forum_title' => $forum['title'],
                    'forum_group' => $forum['group'],
                    'forum_position' => $forum['position'],
                    'slug' => $this->topicSlug($t['title'], $tid),
                    'title' => $t['title'],
                    'posts_count' => count($ordered),
                    'started_at' => $ordered[0]['posted_at'],
                    'last_posted_at' => end($ordered)['posted_at'],
                ],
            );

            foreach ($ordered as $i => $post) {
                ForumPost::updateOrCreate(
                    ['topic_id' => $topic->id, 'old_id' => $post['old_id']],
                    [
                        'author' => $post['author'],
                        'posted_at' => $post['posted_at'],
                        'body' => $post['body'],
                        'position' => $i,
                    ],
                );
            }
        }
    }

    /** 301 со старых URL форума на новые адреса (тема → slug, раздел → /forum). */
    private function makeRedirects(): void
    {
        foreach (ForumTopic::all() as $topic) {
            $to = $topic->url();
            $label = 'Архив форума: '.Str::limit($topic->title, 50);
            foreach ([
                '/forum/viewtopic.php?f='.$topic->forum_old_id.'&t='.$topic->old_id,
                '/forum/viewtopic.php?t='.$topic->old_id,
            ] as $from) {
                Redirect::updateOrCreate(
                    ['from_path' => $from],
                    ['to_url' => $to, 'status_code' => 301, 'comment' => $label],
                );
            }
        }

        // разделы и главная форума → общий список
        foreach (array_keys($this->forums) as $fid) {
            Redirect::updateOrCreate(
                ['from_path' => '/forum/viewforum.php?f='.$fid],
                ['to_url' => '/forum', 'status_code' => 301, 'comment' => 'Архив форума: раздел'],
            );
        }
        Redirect::updateOrCreate(
            ['from_path' => '/forum/index.php'],
            ['to_url' => '/forum', 'status_code' => 301, 'comment' => 'Архив форума: главная'],
        );
    }

    private function topicSlug(string $title, int $oldId): string
    {
        $existing = ForumTopic::where('old_id', $oldId)->value('slug');
        if ($existing) {
            return $existing;
        }
        $slug = Str::slug(Str::limit($title, 80, '')) ?: 'tema';
        if (ForumTopic::where('slug', $slug)->exists()) {
            $slug .= '-t'.$oldId;
        }

        return $slug;
    }

    // ── Отчёт ────────────────────────────────────────────────────────────

    private function writeReport(): string
    {
        // полный перечень тем, известных форуму: листинги ∪ импортированные
        $known = $this->listing;
        foreach ($this->topics as $tid => $t) {
            $known[$tid] ??= ['forum_id' => $t['forum_id'], 'title' => $t['title'], 'replies' => null];
        }

        $imported = 0;
        $missing = [];
        $rows = [];
        foreach ($known as $tid => $info) {
            $have = isset($this->topics[$tid]) ? count($this->topics[$tid]['posts']) : 0;
            $src = isset($this->topics[$tid]) ? implode('+', array_keys($this->topics[$tid]['sources'])) : '—';
            $forumTitle = $this->forumMeta($info['forum_id'])['title'];
            $expected = $info['replies'] !== null ? (string) ($info['replies'] + 1) : '?';
            $title = $this->topics[$tid]['title'] ?? $info['title'];
            $rows[] = sprintf('| %d | %s | %s | %s | %d | %s |', $tid, $this->md($title), $this->md($forumTitle), $expected, $have, $src);
            if ($have > 0) {
                $imported++;
            } else {
                $missing[] = sprintf('| %d | %s | %s |', $tid, $this->md($title), $this->md($forumTitle));
            }
        }

        // ожидаемые сообщения по листингам (там, где известно число ответов)
        $expectedPosts = 0;
        foreach ($known as $info) {
            if ($info['replies'] !== null) {
                $expectedPosts += $info['replies'] + 1;
            }
        }
        $lostEntirely2015 = max(0, 135 - $imported);
        $lostEntirely2016 = max(0, 148 - $imported);

        $lines = [
            '# Отчёт о переносе архива форума X-Intellect',
            '',
            '_Сгенерировано командой `import:offline-forum'.($this->option('wayback') ? ' --wayback' : '').'`. '
                .'Источники: офлайн-слепок Offline Explorer (2015) + снимки web.archive.org._',
            '',
            '## Итоги',
            '',
            '**По счётчику форума** (из его же интерфейса): 135 тем / 2903 сообщения (Wayback, 7 янв 2015), '
                .'148 тем / 3207 сообщений (8 апр 2016).',
            '',
            '**Восстановлено на новый сайт:**',
            '',
            '- Тем с ≥1 сообщением: **'.$imported.'**',
            '- Сообщений: **'.$this->postCount().'**',
            '',
            '**Пробелы:**',
            '',
            '- Тем известно по сохранившимся листингам форума: **'.count($known).'** '
                .'(из них без единой уцелевшей страницы — **'.count($missing).'**, см. ниже).',
            '- Тем утеряно полностью (нет ни листинга, ни страницы ни в папке, ни в Wayback): '
                .'ориентировочно **'.$lostEntirely2015.'** (к отметке 2015 г.) … **'.$lostEntirely2016.'** (к 2016 г.).',
            '- Сообщений недобрано: значительная часть — у многостраничных тем уцелели не все страницы '
                .'пагинации (ни в папке, ни в Wayback). Итог '.$this->postCount().' против ~2903 (2015).',
            '',
            'Причина: и краулер Offline Explorer (2015), и робот web.archive.org сохранили форум лишь частично — '
                .'многие страницы тем и списков разделов никогда не архивировались и физически недоступны.',
            '',
            '## По темам',
            '',
            'Колонка «Ожидалось» — число сообщений по листингу раздела (ответы + 1); «?» — листинг не сохранился.',
            '',
            '| t= | Тема | Раздел | Ожидалось | Импортировано | Источник |',
            '|----|------|--------|-----------|---------------|----------|',
            ...$rows,
        ];

        if ($missing) {
            $lines = array_merge($lines, [
                '',
                '## Не восстановлено (нет ни одной страницы ни в папке, ни в Wayback)',
                '',
                '| t= | Тема | Раздел |',
                '|----|------|--------|',
                ...$missing,
            ]);
        }

        $path = base_path('docs/forum-archive-report.md');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode("\n", $lines)."\n");

        return $path;
    }

    private function md(string $s): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], trim($s));
    }
}
