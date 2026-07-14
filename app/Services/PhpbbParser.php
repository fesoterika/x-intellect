<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Разбор одной страницы темы phpBB (тема subsilver2) — общий для офлайн-слепка
 * и снимков Wayback. На вход сырой HTML страницы, на выходе — идентичность темы
 * (id раздела/темы, заголовок) и посты страницы, ключ — якорь pN.
 *
 * Пермалинки (viewtopic.php@p=…) и страницы пагинации разрешаются к своей теме
 * по ссылке заголовка <h2><a class="titles" href="viewtopic.php…f=F&t=T…">.
 * Всё системное phpBB (профили, личка, ответить, служебная графика) убирается,
 * цитаты приводятся к blockquote, смайлы — к alt-тексту. Автор — строка-ник.
 */
class PhpbbParser
{
    /** RU-месяцы phpBB → номер месяца. */
    private const MONTHS = [
        'янв' => 1, 'фев' => 2, 'мар' => 3, 'апр' => 4, 'май' => 5, 'июн' => 6,
        'июл' => 7, 'авг' => 8, 'сен' => 9, 'окт' => 10, 'ноя' => 11, 'дек' => 12,
    ];

    public function __construct(private ArchiveHtmlCleaner $cleaner) {}

    /**
     * Разобрать страницу темы. $hintForum/$hintTopic — запасной источник id
     * (из имени файла слепка или URL Wayback), если в разметке их нет.
     *
     * @return null|array{forum_id: int, topic_id: int, title: string, posts: array<int, array{old_id: int, author: string, posted_at: ?Carbon, body: string}>}
     */
    public function parsePage(string $html, string $baseDir = '', ?int $hintForum = null, ?int $hintTopic = null): ?array
    {
        [$forumId, $topicId, $title] = $this->identify($html, $hintForum, $hintTopic);
        if ($topicId === null || $title === null) {
            return null;
        }

        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $posts = [];
        foreach ($xp->query('//a[starts-with(@name, "p")]') as $anchor) {
            /** @var DOMElement $anchor */
            if (! preg_match('/^p(\d+)$/', $anchor->getAttribute('name'), $pm)) {
                continue;
            }
            $pid = (int) $pm[1];
            if (isset($posts[$pid])) {
                continue; // дубль в пределах страницы
            }

            $author = trim($xp->query('following::b[@class="postauthor"][1]', $anchor)->item(0)?->textContent ?? '');
            $bodyEl = $xp->query('following::div[@class="postbody"][1]', $anchor)->item(0);
            if ($author === '' || ! $bodyEl instanceof DOMElement) {
                continue;
            }

            $body = $this->cleaner->clean($this->prepareBody($bodyEl, $doc), $baseDir);
            if (trim(strip_tags($body)) === '') {
                continue;
            }

            $posts[$pid] = [
                'old_id' => $pid,
                'author' => Str::limit($author, 90, ''),
                'posted_at' => $this->postDate($xp, $anchor),
                'body' => $this->dropForumLinks($body),
            ];
        }

        return ['forum_id' => (int) $forumId, 'topic_id' => $topicId, 'title' => $title, 'posts' => $posts];
    }

    /**
     * Идентичность темы страницы: id раздела/темы и заголовок. Берём из ссылки
     * заголовка <h2><a class="titles" href="viewtopic.php…f=F&t=T…">Заголовок,
     * что верно и для пермалинков/страниц пагинации. Запасной вариант —
     * подсказки из имени файла/URL и текст h2.
     *
     * @return array{0: ?int, 1: ?int, 2: ?string}
     */
    private function identify(string $html, ?int $hintForum, ?int $hintTopic): array
    {
        $forumId = $hintForum;
        $topicId = $hintTopic;
        $title = null;

        // Ссылка заголовка темы (и @f=…&t=…, и ?f=…&t=… / &amp;)
        if (preg_match('#<h2><a class="titles" href="viewtopic\.php[@?]f=(\d+)&(?:amp;)?t=(\d+)[^"]*">(.*?)</a>#is', $html, $m)) {
            $forumId = (int) $m[1];
            $topicId = (int) $m[2];
            $title = $this->text($m[3]);
        } elseif (preg_match('#<h2[^>]*>(?:<a[^>]*>)?(.*?)</#is', $html, $m)) {
            $title = $this->text($m[1]);
        }

        // Запасной id раздела/темы — первая ссылка viewtopic на странице
        if ($topicId === null && preg_match('#viewtopic\.php[@?]f=(\d+)&(?:amp;)?t=(\d+)#i', $html, $m)) {
            $forumId ??= (int) $m[1];
            $topicId = (int) $m[2];
        }

        return [$forumId, $topicId, ($title === '' ? null : $title)];
    }

    private function text(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** Дата поста: «Добавлено:</b> 11 авг 2012, 03:26» рядом с якорем. */
    private function postDate(DOMXPath $xp, DOMElement $anchor): ?Carbon
    {
        $holder = $xp->query('following::td[contains(@class, "gensmall")][1]', $anchor)->item(0);
        if ($holder && preg_match('/Добавлено:\s*(\d{1,2})\s+([а-я]+)\s+(\d{4}),\s*(\d{1,2}):(\d{2})/u', $holder->textContent, $dm)) {
            $month = self::MONTHS[mb_substr($dm[2], 0, 3)] ?? null;
            if ($month) {
                return Carbon::create((int) $dm[3], $month, (int) $dm[1], (int) $dm[4], (int) $dm[5]);
            }
        }

        return null;
    }

    /**
     * Пред-обработка тела поста ДО чистильщика:
     *  - цитаты phpBB (quotetitle/quotecontent) → blockquote с подписью;
     *  - смайлы → alt-текст, служебная графика imageset/styles — долой.
     */
    private function prepareBody(DOMElement $bodyEl, DOMDocument $doc): string
    {
        foreach (iterator_to_array($bodyEl->getElementsByTagName('img')) as $img) {
            /** @var DOMElement $img */
            $src = $img->getAttribute('src');
            if (str_contains($src, 'smilies')) {
                $img->parentNode?->replaceChild($doc->createTextNode(' '.$img->getAttribute('alt').' '), $img);
            } elseif (str_contains($src, 'imageset') || str_contains($src, 'styles/')) {
                $img->parentNode?->removeChild($img);
            }
        }

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
     * Ссылки: внешние (не x-intellect.org) остаются рабочими; внутреннее —
     * системные страницы phpBB (профили, личка, ответы), относительные пути
     * слепка, mailto — разворачивается: текст остаётся, мёртвая ссылка исчезает.
     */
    private function dropForumLinks(string $html): string
    {
        return preg_replace_callback('/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', function ($m) {
            $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            // Автоссылка phpBB: текст и есть исходный URL — надёжный источник
            // (Offline Explorer переписал href внешних ссылок в локальные пути).
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
                $href = $scheme.'://'.$host.preg_replace('/@/', '?', $mm[2] ?? '', 1);
            }

            // Wayback-обёртка вокруг внешней ссылки → распаковываем оригинал
            if (preg_match('#^https?://web\.archive\.org/web/[^/]+/(https?://.+)$#i', $href, $wb)) {
                $href = $wb[1];
            }

            if (preg_match('#^https?://#i', $href) && ! preg_match('#^https?://(www\.)?x-intellect\.org#i', $href)) {
                return '<a href="'.e($href).'" target="_blank" rel="noopener noreferrer">'.$m[2].'</a>';
            }

            return $m[2];
        }, $html);
    }
}
