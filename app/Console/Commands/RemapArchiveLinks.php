<?php

namespace App\Console\Commands;

use App\Models\GlossaryTerm;
use App\Models\Page;
use DOMDocument;
use DOMElement;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Перелинковка внутренних ссылок в импортированных материалах (2-й проход).
 *
 *   php artisan remap:archive-links [--dry]
 *
 * В телах архивных страниц (главный сайт + вики) ссылки ведут на старые
 * локальные пути слепка:
 *   - главный сайт:  «slug/default.htm», «../slug/default.htm»;
 *   - вики:          «index.php@title=<двойная кодировка>», «../wiki/index.php@title=…».
 * Команда переписывает их на актуальные адреса нового сайта по карте
 * (редиректы → страницы, заголовки вики → страницы, термины → /glossary#slug).
 * Ссылки, которые некуда вести (служебные Файл:/Служебная:, неимпортированные
 * страницы, форум, битые относительные) — разворачиваются: текст остаётся,
 * мёртвый <a> убирается. Внешние http(s)/mailto — не трогаем.
 */
class RemapArchiveLinks extends Command
{
    protected $signature = 'remap:archive-links {--dry}';

    protected $description = 'Перелинковка внутренних ссылок архивных материалов на актуальные адреса';

    private array $slugMap = [];   // старый плоский slug → новый url
    private array $wikiMap = [];   // заголовок вики (lower) → /wiki/slug
    private array $glossMap = [];  // термин (lower) → /glossary#slug

    public function handle(): int
    {
        $this->buildMaps();
        $dry = (bool) $this->option('dry');

        $pages = Page::whereIn('source_type', ['archive_xintellect', 'archive_wiki'])->get();
        $touched = 0;
        $relinked = 0;
        $unwrapped = 0;

        foreach ($pages as $page) {
            if (blank($page->body) || ! str_contains($page->body, '<a ')) {
                continue;
            }

            [$body, $r, $u] = $this->rewrite($page->body);
            if ($r === 0 && $u === 0) {
                continue;
            }
            $relinked += $r;
            $unwrapped += $u;
            $touched++;

            if (! $dry) {
                $page->body = $body;
                $page->save(); // перерендерит body_rendered (в т.ч. тултипы новых терминов)
            }
        }

        $this->info(($dry ? '[dry] ' : '')."Страниц изменено: {$touched}. Ссылок перенаправлено: {$relinked}, развёрнуто (битых): {$unwrapped}.");

        return self::SUCCESS;
    }

    private function buildMaps(): void
    {
        // главный сайт: из редиректов вида /slug → to_url (только одноуровневые плоские)
        foreach (\App\Models\Redirect::where('status_code', 301)->get() as $r) {
            if (preg_match('#^/([a-z0-9_\-]+)$#i', $r->from_path, $m)) {
                $this->slugMap[mb_strtolower($m[1])] = $r->to_url;
            }
        }
        foreach (Page::where('source_type', 'archive_wiki')->get(['title', 'slug']) as $p) {
            $this->wikiMap[mb_strtolower(str_replace('_', ' ', $p->title))] = '/wiki/'.$p->slug;
        }
        foreach (GlossaryTerm::all(['term', 'slug']) as $t) {
            $this->glossMap[mb_strtolower($t->term)] = '/glossary#'.$t->slug;
        }

        $this->line(sprintf('Карта: главный %d, вики %d, глоссарий %d.', count($this->slugMap), count($this->wikiMap), count($this->glossMap)));
    }

    /** @return array{0:string,1:int,2:int} [body, relinked, unwrapped] */
    private function rewrite(string $html): array
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><div id="__r">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $root = $doc->getElementById('__r');
        if (! $root) {
            return [$html, 0, 0];
        }

        $relinked = 0;
        $unwrapped = 0;

        foreach (iterator_to_array($doc->getElementsByTagName('a')) as $a) {
            /** @var DOMElement $a */
            if (! $a->hasAttribute('href')) {
                continue;
            }
            $href = html_entity_decode($a->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $target = $this->resolve($href);

            if ($target === null) {
                continue; // внешняя/оставляем как есть
            }
            if ($target === '') {
                $this->unwrap($a);
                $unwrapped++;

                continue;
            }
            // внутренняя ссылка нового сайта — чистим target/rel
            $a->setAttribute('href', $target);
            $a->removeAttribute('target');
            $a->removeAttribute('rel');
            $relinked++;
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $c) {
            $out .= $doc->saveHTML($c);
        }

        return [trim($out), $relinked, $unwrapped];
    }

    /**
     * Решение по ссылке:
     *  - строка URL  → переписать на неё;
     *  - ''          → развернуть (битая внутренняя/служебная);
     *  - null        → не трогать (внешняя).
     */
    private function resolve(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }
        // Внешние — не трогаем
        if (preg_match('#^(https?:)?//#i', $href) || str_starts_with($href, 'mailto:')) {
            return null;
        }

        // Вики-ссылка: index.php@title=<код> (в т.ч. ../wiki/…)
        if (preg_match('#index\.php@title=([^"&?]+)#', $href, $m)) {
            $title = $this->decodeWikiTitle($m[1]);
            if ($title === '' || str_contains($title, ':')) {
                return ''; // Файл:/Служебная:/Категория: и пр. — развернуть
            }
            $key = mb_strtolower($title);

            return $this->wikiMap[$key] ?? $this->glossMap[$key] ?? '';
        }

        // Главный сайт: …/slug/default.htm
        if (preg_match('#([a-z0-9_\-]+)/default\.htm#i', $href, $m)) {
            $slug = mb_strtolower($m[1]);

            return $this->slugMap[$slug] ?? '';
        }

        // Прочие относительные (forum, wp-content, ../../внешние-как-относительные) — развернуть
        return '';
    }

    /** «_25D0_259A…» → «Карма» (двойная процентная кодировка Offline Explorer). */
    private function decodeWikiTitle(string $enc): string
    {
        $pct = str_replace('_25', '%', $enc);
        $decoded = rawurldecode($pct);

        return trim(str_replace('_', ' ', $decoded));
    }

    private function unwrap(DOMElement $a): void
    {
        $parent = $a->parentNode;
        while ($a->firstChild) {
            $parent->insertBefore($a->firstChild, $a);
        }
        $parent->removeChild($a);
    }
}
