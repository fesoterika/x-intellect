<?php

namespace App\Console\Commands;

use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use DOMDocument;
use DOMElement;
use Illuminate\Console\Command;

/**
 * Перелинковка внутренних ссылок в импортированных материалах (2-й проход).
 *
 *   php artisan remap:archive-links [--dry] [--report]
 *
 * В телах архивных страниц (главный сайт + вики) ссылки ведут на старые
 * локальные пути слепка:
 *   - главный сайт:  «slug/default.htm», «../slug/default.htm»;
 *   - вики:          «index.php@title=<двойная кодировка>», «../wiki/index.php@title=…».
 * Команда переписывает их на актуальные адреса нового сайта по картам
 * (таблица redirects → страницы, заголовки вики → страницы, термины → /glossary?term=slug).
 *
 * КОМАНДА ИДЕМПОТЕНТНА: ссылки, уже ведущие на новый сайт (/wiki/slug,
 * /storage/…, /glossary?term=…), распознаются и не трогаются. Это не деталь
 * реализации, а защита от потери данных: разворот пишется в body, поэтому
 * повторный прогон, не узнавший собственный результат, стирал ссылки
 * безвозвратно (так было потеряно 353 ссылки — см. тест на идемпотентность).
 *
 * Разворачиваются только заведомо мёртвые архивные ссылки (служебные Файл:/
 * Служебная:, неимпортированные вики-страницы, битые slug/default.htm): текст
 * остаётся, мёртвый <a> убирается. Внешние http(s)/mailto и любые неопознанные
 * пути остаются как есть и попадают в отчёт — молча ничего не удаляем.
 */
class RemapArchiveLinks extends Command
{
    protected $signature = 'remap:archive-links {--dry} {--report}';

    protected $description = 'Перелинковка внутренних ссылок архивных материалов на актуальные адреса';

    private array $slugMap = [];      // старый плоский slug → новый url
    private array $wikiMap = [];      // заголовок вики (lower) → /wiki/slug
    private array $glossMap = [];     // термин (lower) → /glossary?term=slug
    private array $redirectMap = [];  // from_path (lower) → to_url
    private array $newSiteRoots = []; // корневые разделы + фиксированные маршруты

    /** Причина разворота последней ссылки — заполняется resolve(). */
    private string $reason = '';

    /** Ссылок, спасённых по тексту (href покорёжен слепком). */
    private int $recoveredByText = 0;

    /** @var array<string, list<string>> причина → «страница: текст → href» */
    private array $unwrapLog = [];

    /** @var array<string, list<string>> неопознанные пути, оставленные как есть */
    private array $unknownLog = [];

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

            [$body, $r, $u] = $this->rewrite($page->body, $page->title);
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

        $this->newLine();
        $this->info(($dry ? '[dry] ' : '')."Страниц изменено: {$touched}. Ссылок перенаправлено: {$relinked}, развёрнуто (битых): {$unwrapped}.");
        if ($this->recoveredByText > 0) {
            $this->line("Из них спасено по тексту ссылки (href покорёжен слепком): {$this->recoveredByText}.");
        }
        $this->printReport();

        return self::SUCCESS;
    }

    private function buildMaps(): void
    {
        // главный сайт: из редиректов вида /slug → to_url (только одноуровневые плоские)
        foreach (Redirect::all() as $r) {
            $this->redirectMap[mb_strtolower($r->from_path)] = $r->to_url;

            if ($r->status_code === 301 && preg_match('#^/([a-z0-9_\-]+)$#i', $r->from_path, $m)) {
                $this->slugMap[mb_strtolower($m[1])] = $r->to_url;
            }
        }
        foreach (Page::where('source_type', 'archive_wiki')->get(['title', 'slug']) as $p) {
            $this->wikiMap[mb_strtolower(str_replace('_', ' ', $p->title))] = '/wiki/'.$p->slug;
        }
        foreach (GlossaryTerm::all(['term', 'slug']) as $t) {
            $this->glossMap[mb_strtolower($t->term)] = $t->url();
        }

        // Корни адресного пространства нового сайта. Разделы берём из БД —
        // структура меняется (site:structure-2026), хардкод разошёлся бы с ней.
        $this->newSiteRoots = array_merge(
            Section::whereNull('parent_id')->pluck('slug')->map(fn ($s) => mb_strtolower($s))->all(),
            ['search', 'glossary', 'fesoterika', 'storage', 'go'],
        );

        $this->line(sprintf(
            'Карта: главный %d, вики %d, глоссарий %d, редиректы %d, корневые разделы %d.',
            count($this->slugMap), count($this->wikiMap), count($this->glossMap),
            count($this->redirectMap), count($this->newSiteRoots),
        ));
    }

    /** @return array{0:string,1:int,2:int} [body, relinked, unwrapped] */
    private function rewrite(string $html, string $pageTitle): array
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
            $this->reason = '';
            $target = $this->resolve($href, trim(preg_replace('/\s+/u', ' ', $a->textContent)));

            if ($target === null) {
                if ($this->reason !== '') {
                    // не опознали — ссылку сохраняем, но показываем в отчёте
                    $this->unknownLog[$this->reason][] = sprintf('%s: %s', $pageTitle, $href);
                }

                continue; // внешняя/новая/неопознанная — оставляем как есть
            }
            if ($target === '') {
                $text = trim(preg_replace('/\s+/u', ' ', $a->textContent));
                $this->unwrapLog[$this->reason][] = sprintf('%s: «%s» → %s', $pageTitle, mb_strimwidth($text, 0, 40, '…'), $href);

                // Битая ссылка с якорем-id: текст и якорь сохраняем, href убираем
                if ($a->hasAttribute('id')) {
                    $a->removeAttribute('href');
                    $a->removeAttribute('target');
                    $a->removeAttribute('rel');
                } else {
                    $this->unwrap($a);
                }
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
     *  - ''          → развернуть (заведомо мёртвая архивная), причина в $this->reason;
     *  - null        → не трогать (внешняя, уже мигрированная или неопознанная).
     */
    private function resolve(string $href, string $text = ''): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#')) {
            return null; // внутристраничный якорь — не трогаем
        }

        // Якорную часть отделяем до сопоставления и возвращаем на новый адрес
        // страницы (термины глоссария — без фрагмента: у них ?term=slug).
        [$path, $frag] = array_pad(explode('#', $href, 2), 2, null);
        $frag = $frag !== null && $frag !== '' ? '#'.$frag : '';

        // Снимки Wayback Machine (тела, скачанные из веб-архива): разворачиваем
        // обёртку /web/<ts>/<url>; ссылки на старый x-intellect.org — внутренние.
        if (preg_match('#^(?:(?:https?:)?//web\.archive\.org)?/web/[0-9a-z_*]+/(.+)$#i', $path, $m)) {
            $inner = preg_match('#^(?:https?:)?//#', $m[1]) ? $m[1] : 'http://'.$m[1];
            $parts = parse_url($inner);
            $host = strtolower($parts['host'] ?? '');
            if (! in_array($host, ['x-intellect.org', 'www.x-intellect.org'], true)) {
                return null; // чужой сайт в веб-архиве — оставляем как есть
            }
            $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        } elseif (preg_match('#^(?:https?:)?//(?:www\.)?x-intellect\.org(/.*)?$#i', $path, $m)) {
            // Абсолютные ссылки на старый сайт — внутренние
            $path = $m[1] ?? '/';
        } elseif (preg_match('#^(https?:)?//#i', $path) || str_starts_with($path, 'mailto:')) {
            return null; // внешние — не трогаем
        }

        // Ссылка уже ведёт на новый сайт — не трогаем. Ключевая проверка:
        // без неё повторный прогон разворачивал собственный результат.
        if ($this->isNewSitePath($path)) {
            return null;
        }

        // Таблица redirects — готовая карта «старый адрес → новый» (её же
        // наполняют импортёры), покрывает и пути с query-string.
        if ($to = $this->redirectLookup($path)) {
            return $to.(str_contains($to, '?') ? '' : $frag);
        }

        // Живой URL MediaWiki: index.php?title=<одинарная кодировка>
        // (сырые снимки id_ из Wayback Machine и абсолютные старые ссылки)
        if (preg_match('#index\.php\?title=([^&\#"]+)#', $path, $m)) {
            return $this->resolveWikiTitle(trim(str_replace('_', ' ', rawurldecode($m[1]))), $frag, $text);
        }

        // Вики-ссылка: index.php@title=<код> (в т.ч. ../wiki/…)
        if (preg_match('#index\.php@title=([^"&?\#]+)#', $path, $m)) {
            return $this->resolveWikiTitle($this->decodeWikiTitle($m[1]), $frag, $text);
        }

        // Главный сайт: …/slug/default.htm
        if (preg_match('#([a-z0-9_\-]+)/default\.htm#i', $path, $m)) {
            $to = $this->slugMap[mb_strtolower($m[1])] ?? '';
            if ($to !== '') {
                return $to.$frag;
            }
            $this->reason = 'главный сайт: страница не импортирована';

            return '';
        }

        // Главный сайт, живой корне-относительный путь: /slug/ (сырые снимки Wayback)
        if (preg_match('#^/([a-z0-9_\-]+)/?$#i', $path, $m)) {
            $to = $this->slugMap[mb_strtolower($m[1])] ?? '';
            if ($to !== '') {
                return $to.$frag;
            }
            $this->reason = 'главный сайт: страница не импортирована';

            return '';
        }

        // Всё остальное (forum, wp-content, ../../внешние-как-относительные).
        // Раньше здесь стоял `return ''` и молча удалял ссылку — в том числе
        // рабочие ссылки нового сайта. Теперь оставляем и показываем в отчёте.
        $this->reason = 'не опознано (оставлено как есть)';

        return null;
    }

    /**
     * Заголовок вики → страница или термин глоссария; '' — разворачиваем.
     *
     * Запасной путь по тексту ссылки: Offline Explorer обрезал длинные имена
     * файлов и приписал хеш, поэтому часть href декодируется в мусор
     * («Метагалакр97092D453»), тогда как текст ссылки — целый заголовок
     * («Метагалактический домен»). Срабатывает только когда href не опознан,
     * то есть выбор всегда между текстовым совпадением и удалением ссылки.
     */
    private function resolveWikiTitle(string $title, string $frag, string $text = ''): string
    {
        if ($title === '') {
            $this->reason = 'вики: пустой заголовок';

            return '';
        }
        if (str_contains($title, ':')) {
            $this->reason = 'вики: служебная страница (Файл:/Служебная:/Категория:)';

            return '';
        }

        foreach ([$title, $text] as $i => $candidate) {
            $key = mb_strtolower(trim($candidate));
            if ($key === '' || str_contains($key, ':')) {
                continue;
            }
            $to = $this->wikiMap[$key] ?? null;
            if ($to !== null) {
                $this->recoveredByText += $i;

                return $to.$frag;
            }
            $to = $this->glossMap[$key] ?? null;
            if ($to !== null) {
                $this->recoveredByText += $i;

                return $to;
            }
        }

        $this->reason = 'вики: страница не импортирована';

        return '';
    }

    /** Путь ведёт на новый сайт (корневой раздел, глоссарий, /storage, …)? */
    private function isNewSitePath(string $path): bool
    {
        if ($path === '' || ! str_starts_with($path, '/')) {
            return false;
        }
        // Старые адреса, живущие под /wiki/: это НЕ адреса нового сайта
        if (str_contains($path, 'index.php') || str_contains($path, 'default.htm')) {
            return false;
        }

        $first = mb_strtolower(explode('/', ltrim(explode('?', $path)[0], '/'))[0] ?? '');

        return $first === '' || in_array($first, $this->newSiteRoots, true);
    }

    /** Точное совпадение с таблицей redirects (путь и путь+query). */
    private function redirectLookup(string $path): ?string
    {
        foreach (array_unique([$path, rawurldecode($path), rtrim($path, '/')]) as $candidate) {
            $to = $this->redirectMap[mb_strtolower($candidate)] ?? null;
            if ($to !== null) {
                return $to;
            }
        }

        return null;
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

    /**
     * Сводка всегда, поимённый список — по --report. Молчаливое удаление
     * ссылок однажды уже прошло незамеченным, поэтому счётчики по причинам
     * печатаются в любом случае.
     */
    private function printReport(): void
    {
        $verbose = (bool) $this->option('report');

        foreach ([
            ['Развёрнуто ссылок (мёртвые архивные)', $this->unwrapLog],
            ['Оставлено как есть (не опознано)', $this->unknownLog],
        ] as [$caption, $log]) {
            if ($log === []) {
                continue;
            }

            $this->newLine();
            $this->comment($caption.':');
            foreach ($log as $reason => $items) {
                $this->line(sprintf('  %-52s %d', $reason, count($items)));
                if (! $verbose) {
                    continue;
                }
                foreach ($items as $item) {
                    $this->line('      '.$item);
                }
            }
        }

        if (! $verbose && ($this->unwrapLog !== [] || $this->unknownLog !== [])) {
            $this->newLine();
            $this->comment('Поимённый список: --report');
        }
    }
}
