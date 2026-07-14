<?php

namespace App\Console\Commands;

use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Services\ArchiveHtmlCleaner;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Импорт архива форума phpBB из офлайн-слепка 2015 года — только чтение.
 *
 *   php artisan import:offline-forum {forum-dir} [--dry]
 *
 * forum-dir — папка …/x-intellect.org/forum.
 *
 * Берём ТОЛЬКО контент: разделы (index.php + viewforum), темы и сообщения
 * (viewtopic со страницами пагинации, дедупликация постов по якорям p=).
 * Всё системное phpBB отбрасывается: регистрация/логин/профили/личка,
 * ссылки на memberlist/ucp/posting разворачиваются в текст, смайлы
 * заменяются alt-текстом, служебная графика удаляется. Автор сообщения —
 * просто строка-ник, никаких страниц пользователей.
 */
class ImportOfflineForum extends Command
{
    protected $signature = 'import:offline-forum {archive} {--dry}';

    protected $description = 'Импорт архива форума phpBB (темы и сообщения, только чтение)';

    /** RU-месяцы phpBB → номер месяца. */
    private const MONTHS = [
        'янв' => 1, 'фев' => 2, 'мар' => 3, 'апр' => 4, 'май' => 5, 'июн' => 6,
        'июл' => 7, 'авг' => 8, 'сен' => 9, 'окт' => 10, 'ноя' => 11, 'дек' => 12,
    ];

    private string $base = '';

    public function handle(ArchiveHtmlCleaner $cleaner): int
    {
        $this->base = rtrim($this->argument('archive'), '/');
        if (! File::isDirectory($this->base)) {
            $this->error("Не найдено: {$this->base}");

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');

        // 1) карта разделов: категория, название, порядок
        $forums = $this->parseForums();
        $this->info('Разделов форума: '.count($forums));

        // 2) темы: канонические файлы viewtopic.php@f=X&t=Y (+ пагинация start=)
        $topicFiles = [];
        foreach (File::glob($this->base.'/viewtopic.php@f=*') as $file) {
            if (preg_match('/@f=(\d+)&t=(\d+)(?:&start=(\d+))?$/', $file, $m)) {
                $topicFiles[(int) $m[2]]['forum'] = (int) $m[1];
                $topicFiles[(int) $m[2]]['pages'][(int) ($m[3] ?? 0)] = $file;
            }
        }
        $this->info('Тем в слепке: '.count($topicFiles));

        $topics = 0;
        $posts = 0;

        foreach ($topicFiles as $oldId => $data) {
            ksort($data['pages']);
            $parsed = $this->parseTopic($oldId, $data['pages'], $cleaner);
            if ($parsed === null || $parsed['posts'] === []) {
                continue;
            }

            $forum = $forums[$data['forum']] ?? ['title' => 'Форум', 'group' => null, 'position' => 999];
            // подфорумы без категории в слепке — исследовательские ветки
            $forum['group'] = $forum['group'] ?? 'Исследования';

            if ($dry) {
                $this->line(sprintf('[%s] %s (%d сообщ.)', $forum['title'], Str::limit($parsed['title'], 60), count($parsed['posts'])));
                $topics++;
                $posts += count($parsed['posts']);

                continue;
            }

            $topic = ForumTopic::updateOrCreate(
                ['old_id' => $oldId],
                [
                    'forum_old_id' => $data['forum'],
                    'forum_title' => $forum['title'],
                    'forum_group' => $forum['group'],
                    'forum_position' => $forum['position'],
                    'slug' => $this->topicSlug($parsed['title'], $oldId),
                    'title' => $parsed['title'],
                    'posts_count' => count($parsed['posts']),
                    'started_at' => $parsed['posts'][0]['posted_at'],
                    'last_posted_at' => end($parsed['posts'])['posted_at'],
                ],
            );

            foreach ($parsed['posts'] as $i => $post) {
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

            $topics++;
            $posts += count($parsed['posts']);
        }

        $this->newLine();
        $this->info(($dry ? '[dry] ' : '')."Готово. Тем: {$topics}, сообщений: {$posts}.");

        return self::SUCCESS;
    }

    /**
     * Разделы форума: категории и порядок из index.php, названия — из
     * viewforum (там же ловятся подфорумы, которых нет на главной).
     *
     * @return array<int, array{title: string, group: ?string, position: int}>
     */
    private function parseForums(): array
    {
        $forums = [];
        $position = 0;

        // главная форума: категории (cat h4) и форумы верхнего уровня (forumlink)
        $index = @File::get($this->base.'/index.php') ?: @File::get($this->base.'/default.htm') ?: '';
        $group = null;
        if (preg_match_all(
            '#class="cat"[^>]*><h4><a href="viewforum\.php@f=\d+">([^<]+)</a></h4>|class="forumlink" href="viewforum\.php@f=(\d+)">([^<]+)</a>#u',
            $index,
            $rows,
            PREG_SET_ORDER,
        )) {
            foreach ($rows as $row) {
                if ($row[1] !== '') {
                    $group = html_entity_decode($row[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    continue;
                }
                $forums[(int) $row[2]] = [
                    'title' => html_entity_decode($row[3], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'group' => $group,
                    'position' => $position++,
                ];
            }
        }

        // viewforum-файлы: названия подфорумов + вложенность (forumlink внутри раздела)
        foreach (File::glob($this->base.'/viewforum.php@f=*') as $file) {
            if (! preg_match('/@f=(\d+)$/', $file, $m)) {
                continue;
            }
            $fid = (int) $m[1];
            $html = @File::get($file) ?: '';

            if (! isset($forums[$fid]) && preg_match('/<h2[^>]*>(?:<a[^>]*>)?([^<]+)/u', $html, $h)) {
                $forums[$fid] = [
                    'title' => trim(html_entity_decode($h[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                    'group' => null,
                    'position' => 500 + $fid,
                ];
            }

            // подфорумы наследуют категорию родителя и встают следом за ним
            if (isset($forums[$fid]) && preg_match_all('/class="forumlink" href="viewforum\.php@f=(\d+)">([^<]+)<\/a>/u', $html, $subs, PREG_SET_ORDER)) {
                foreach ($subs as $j => $sub) {
                    $sid = (int) $sub[1];
                    $forums[$sid] = [
                        'title' => html_entity_decode($sub[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        'group' => $forums[$fid]['group'],
                        'position' => $forums[$fid]['position'] * 10 + $j + 1,
                    ];
                    $forums[$fid]['position'] = $forums[$fid]['position'] * 10;
                }
            }
        }

        return $forums;
    }

    /**
     * Тема: заголовок + посты со всех страниц пагинации, дедупликация по p-якорям.
     *
     * @param  array<int, string>  $pages
     * @return null|array{title: string, posts: list<array{old_id: int, author: string, posted_at: ?Carbon, body: string}>}
     */
    private function parseTopic(int $oldId, array $pages, ArchiveHtmlCleaner $cleaner): ?array
    {
        $title = null;
        $posts = [];

        foreach ($pages as $file) {
            $html = @File::get($file);
            if (! $html) {
                continue;
            }

            $doc = new DOMDocument;
            libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
            libxml_clear_errors();
            $xp = new DOMXPath($doc);

            if ($title === null) {
                $h2 = $xp->query('//h2')->item(0);
                $title = $h2 ? trim(preg_replace('/\s+/u', ' ', $h2->textContent)) : null;
            }

            foreach ($xp->query('//a[starts-with(@name, "p")]') as $anchor) {
                /** @var DOMElement $anchor */
                if (! preg_match('/^p(\d+)$/', $anchor->getAttribute('name'), $pm)) {
                    continue;
                }
                $pid = (int) $pm[1];
                if (isset($posts[$pid])) {
                    continue; // дубль с другой страницы
                }

                $author = trim($xp->query('following::b[@class="postauthor"][1]', $anchor)->item(0)?->textContent ?? '');
                $bodyEl = $xp->query('following::div[@class="postbody"][1]', $anchor)->item(0);
                if ($author === '' || ! $bodyEl instanceof DOMElement) {
                    continue;
                }

                // дата: «Добавлено:</b> 11 авг 2012, 03:26»
                $postedAt = null;
                $dateHolder = $xp->query('following::td[contains(@class, "gensmall")][1]', $anchor)->item(0);
                if ($dateHolder && preg_match('/Добавлено:\s*(\d{1,2})\s+([а-я]+)\s+(\d{4}),\s*(\d{1,2}):(\d{2})/u', $dateHolder->textContent, $dm)) {
                    $month = self::MONTHS[mb_substr($dm[2], 0, 3)] ?? null;
                    if ($month) {
                        $postedAt = Carbon::create((int) $dm[3], $month, (int) $dm[1], (int) $dm[4], (int) $dm[5]);
                    }
                }

                $body = $cleaner->clean($this->prepareBody($bodyEl, $doc), $this->base);
                if (trim(strip_tags($body)) === '' ) {
                    continue;
                }

                $posts[$pid] = [
                    'old_id' => $pid,
                    'author' => Str::limit($author, 90, ''),
                    'posted_at' => $postedAt,
                    'body' => $this->dropForumLinks($body),
                ];
            }
        }

        if ($title === null) {
            return null;
        }

        ksort($posts); // p-идентификаторы монотонны по времени

        return ['title' => $title, 'posts' => array_values($posts)];
    }

    /**
     * Пред-обработка тела поста ДО чистильщика:
     *  - цитаты phpBB (quotetitle/quotecontent) → blockquote с подписью;
     *  - смайлы → их alt-текст, служебная графика imageset — долой.
     */
    private function prepareBody(DOMElement $bodyEl, DOMDocument $doc): string
    {
        // смайлы и служебные картинки
        foreach (iterator_to_array($bodyEl->getElementsByTagName('img')) as $img) {
            /** @var DOMElement $img */
            $src = $img->getAttribute('src');
            if (str_contains($src, 'smilies')) {
                $img->parentNode?->replaceChild($doc->createTextNode(' '.$img->getAttribute('alt').' '), $img);
            } elseif (str_contains($src, 'imageset') || str_contains($src, 'styles/')) {
                $img->parentNode?->removeChild($img);
            }
        }

        // цитаты: <div class="quotetitle">X писал(а):</div><div class="quotecontent">…</div>
        $xp = new DOMXPath($doc);
        foreach (iterator_to_array($xp->query('.//div[@class="quotecontent"]', $bodyEl)) as $quote) {
            /** @var DOMElement $quote */
            $block = $doc->createElement('blockquote');

            $titleEl = $xp->query('preceding-sibling::div[@class="quotetitle"][1]', $quote)->item(0);
            if ($titleEl) {
                $cite = $doc->createElement('p');
                $strong = $doc->createElement('strong');
                $strong->appendChild($doc->createTextNode(trim($titleEl->textContent)));
                $cite->appendChild($strong);
                $block->appendChild($cite);
                $titleEl->parentNode?->removeChild($titleEl);
            }

            while ($quote->firstChild) {
                $block->appendChild($quote->firstChild);
            }
            $quote->parentNode->replaceChild($block, $quote);
        }

        $out = '';
        foreach ($bodyEl->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    /**
     * Пост-обработка ссылок: внешние (не x-intellect.org) остаются рабочими,
     * всё внутреннее — системные страницы phpBB (профили, личка, ответы),
     * относительные пути слепка, mailto — разворачивается: текст остаётся,
     * мёртвая ссылка исчезает.
     */
    private function dropForumLinks(string $html): string
    {
        return preg_replace_callback('/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', function ($m) {
            $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            // Автоссылка phpBB: текст и есть исходный URL — самый надёжный источник
            // (Offline Explorer переписал href внешних ссылок в локальные пути слепка).
            // Длинные URL phpBB усекает в тексте (« ... ») — такие не годятся.
            if (preg_match('#^https?://#i', $text) && ! str_contains($text, ' ... ')) {
                $href = $text;
            } elseif (preg_match('#^(?:\.\./)+((?:https?@)?[a-z0-9.-]+\.[a-z]{2,})(/[^"]*)?$#i', $href, $mm)) {
                // именованная внешняя ссылка: раскодируем путь слепка обратно в URL
                $host = $mm[1];
                $scheme = 'http';
                if (str_starts_with($host, 'https@')) {
                    $host = substr($host, 6);
                    $scheme = 'https';
                }
                // '?' запроса Offline Explorer кодирует как '@' в имени файла
                $href = $scheme.'://'.$host.preg_replace('/@/', '?', $mm[2] ?? '', 1);
            }

            // внешняя ссылка на чужой домен — рабочая, оставляем
            if (preg_match('#^https?://#i', $href) && ! preg_match('#^https?://(www\.)?x-intellect\.org#i', $href)) {
                return '<a href="'.e($href).'" target="_blank" rel="noopener noreferrer">'.$m[2].'</a>';
            }

            // внутреннее/системное (профили, личка, мёртвые пути слепка) — только текст
            return $m[2];
        }, $html);
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
}
