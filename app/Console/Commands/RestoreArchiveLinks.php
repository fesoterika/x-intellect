<?php

namespace App\Console\Commands;

use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Redirect;
use App\Services\ArchiveLinkRestorer;
use App\Services\MediaWikiArchive;
use App\Services\OfflineSnapshotIndex;
use App\Support\RussianText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Восстановление ссылок, потерянных при импорте.
 *
 *   php artisan links:restore {слепок} [--dry] [--published-only] [--report=файл]
 *
 * Сверяет каждый материал с его архивным исходником: если ссылка в источнике
 * есть, а в теле остался лишь голый текст — вставляет <a> точечно вокруг этого
 * текста (см. ArchiveLinkRestorer: тело вне вставки не меняется).
 *
 * Что НЕ трогаем (решения пользователя от 16.07.2026):
 *  - цели вне охвата: страницы старого сайта, которые решено не переносить,
 *    персональные страницы участников, контакты, заказ консультаций;
 *  - страницы, удалённые пользователем вручную;
 *  - места, где текст исходника в теле не найден: значит, материал вычитан
 *    вручную — молча оставляем как есть и пишем в отчёт.
 */
class RestoreArchiveLinks extends Command
{
    protected $signature = 'links:restore {archive} {--dry} {--published-only} {--report=}';

    protected $description = 'Точечно возвращает в материалы ссылки, срезанные при импорте';

    /**
     * Цели, ссылки на которые не восстанавливаем: страницы старого сайта вне
     * охвата + персональные страницы участников, контакты, заказ консультаций.
     */
    private const OUT_OF_SCOPE = [
        'alexavan', 'contact-form', 'contact-form-emmir', 'contact-form-kotox',
        'contact-form-magur7', 'contact-form-oleg', 'contact-form-svetlana',
        'emmir', 'galateia', 'kotox', 'magur7', 'map', 'ny-2012-2013',
        'otzivi-o-lichnih-konsultaciyah', 'otzy-vy-o-lichny-h-konsul-tatsiyah',
        'pravila-oformlenia-stenogramm-lichnih-konsultacii', 'projects', 'skype',
        'support', 'finansovie-rekviziti', 'svetlana', 'testomonial', 'thank_you',
        // контакты и персональные страницы участников
        'contacts', 'alexandrglaz', 'glaz', 'contact', 'feedback',
    ];

    /** Служебный хром MediaWiki/WordPress — не ссылки материала. */
    private const CHROME = '/^(версия |текущая версия|править$|просмотреть исходный код$|← предыдущая правка$|следующая правка →$|thumb$|перейти к:|навигация$|поиск$|обсуждение$|читать$|история$|личный инструментарий|представиться|заглавная страница$|сообщество$|текущие события$|свежие правки$|случайная статья$|справка$|инструменты$|ссылки сюда$|связанные правки|спецстраницы$|постоянная ссылка$|пожертвовать|войти$|регистрация$|карта сайта$|главная$|наверх|подробнее$|далее$|читать далее)/ui';

    private array $stats = ['inserted' => 0, 'out_of_scope' => 0, 'no_target' => 0, 'text_changed' => 0, 'already' => 0];

    private array $report = [];

    private OfflineSnapshotIndex $index;

    /** @var array<string, string> имя файла слепка → путь */
    private array $fileMap = [];

    public function handle(OfflineSnapshotIndex $index, ArchiveLinkRestorer $restorer): int
    {
        $base = rtrim($this->argument('archive'), '/');
        $wikiDir = is_dir($base.'/wiki') ? $base.'/wiki' : $base;
        $siteDir = dirname($wikiDir);
        if (! is_dir($wikiDir)) {
            $this->error("Не найдено: {$wikiDir}");

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');
        $entries = $index->build($wikiDir);
        $this->info('Статей в слепке: '.count($entries));

        $pages = Page::whereIn('source_type', ['archive_wiki', 'archive_xintellect', 'archive_sferarazuma'])
            ->when($this->option('published-only'), fn ($q) => $q->where('status', 'published'))
            ->orderBy('id')->get();

        $this->index = $index;
        $this->fileMap = $index->fileMap($wikiDir);

        foreach ($pages as $page) {
            $source = $page->source_type === 'archive_wiki'
                ? $this->wikiSource($entries, $index, $page)
                : $this->siteSource($siteDir, $page);

            // Страницы, импортированные из Wayback, в слепке 2015 отсутствуют —
            // их источник качаем из веб-архива (снимок указан в source_url).
            $source ??= $this->waybackSource($page);

            if ($source === null) {
                continue;
            }

            [$html, $dir] = $source;
            $this->restorePage($page, $html, $dir, $restorer, $dry);
        }

        $this->newLine();
        $this->info(sprintf(
            'Готово. Вставлено ссылок: %d. Пропущено: вне охвата %d, цель не найдена %d, текст правлен вручную %d.',
            $this->stats['inserted'], $this->stats['out_of_scope'],
            $this->stats['no_target'], $this->stats['text_changed']
        ));
        if ($dry) {
            $this->comment('Это --dry: ничего не сохранено.');
        }

        if ($path = $this->option('report')) {
            file_put_contents($path, $this->renderReport());
            $this->info("Отчёт: {$path}");
        }

        return self::SUCCESS;
    }

    private function restorePage(Page $page, string $sourceHtml, string $sourceDir, ArchiveLinkRestorer $restorer, bool $dry): void
    {
        $body = $page->body ?? '';
        $changed = false;
        $seen = [];

        foreach ($this->linksOf($sourceHtml) as [$text, $href]) {
            $key = mb_strtolower($text);
            if (isset($seen[$key]) || mb_strlen($text) < 3) {
                continue;
            }
            $seen[$key] = true;

            if (preg_match(self::CHROME, $text) || preg_match('/^[\d\s.,:;\-–—\[\]]+$/u', $text)) {
                continue;
            }
            if (mb_strtolower(trim($text)) === mb_strtolower(trim($page->title))) {
                continue; // самоссылка-заголовок
            }

            if ($this->isOutOfScope($href)) {
                $this->stats['out_of_scope']++;
                $this->report[] = [$page, $text, '—', 'вне охвата'];

                continue;
            }
            if (! $restorer->containsText($body, $text)) {
                $this->stats['text_changed']++;

                continue; // тело вычитано вручную — не трогаем
            }
            if ($restorer->alreadyLinked($body, $text)) {
                $this->stats['already']++;

                continue;
            }

            $url = $this->resolve($href, $text, $sourceDir);
            if ($url === null) {
                $this->stats['no_target']++;
                $this->report[] = [$page, $text, $href, 'цель не найдена'];

                continue;
            }

            $out = $restorer->insert($body, $text, $url);
            if ($out === null) {
                continue;
            }
            $body = $out;
            $changed = true;
            $this->stats['inserted']++;
            $this->report[] = [$page, $text, $url, 'вставлено'];
            $this->line(sprintf('[%s] %s → %s', $page->status === 'published' ? 'PUB' : 'чрн',
                Str::limit($page->title, 40), Str::limit($text, 40)));
        }

        if ($changed && ! $dry) {
            // PageObserver сам заведёт ревизию с пометкой «Обновлена командой»
            $page->body = $body;
            $page->save();
        }
    }

    /** Ссылки исходника: [текст, href], без красных и картиночных. */
    private function linksOf(string $html): array
    {
        if (! preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/su', $html, $m, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($m as $set) {
            [$all, $attrs, $inner] = $set;
            if (! preg_match('/href="([^"]*)"/', $attrs, $h)) {
                continue;
            }
            $href = $h[1];
            $class = preg_match('/class="([^"]*)"/', $attrs, $c) ? explode(' ', $c[1]) : [];
            if (in_array('image', $class, true) || str_starts_with($href, '#')) {
                continue; // обёртка картинки / внутристраничный якорь
            }
            // Красные ссылки (class="new", …&action=edit&redlink=1) не выкидываем:
            // на момент снимка страницы не было, но в архиве она может быть — её
            // сняли другим снимком или взяли из слепка. Решает наличие цели в БД,
            // а не цвет ссылки в конкретном снимке. Ссылку «править» отсеет CHROME.
            if (str_contains($href, 'action=edit') && ! in_array('new', $class, true)) {
                continue;
            }
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($text === '') {
                continue;
            }
            $out[] = [$text, $href];
        }

        return $out;
    }

    private function isOutOfScope(string $href): bool
    {
        if (! preg_match('~\.\./([^"?\#]+)/default\.htm~', $href, $m)) {
            return false;
        }
        $parts = array_values(array_filter(explode('/', $m[1])));
        foreach ($parts as $p) {
            if (in_array($p, self::OUT_OF_SCOPE, true)) {
                return true;
            }
        }

        return false;
    }

    /** Архивный href → адрес на новом сайте. */
    private function resolve(string $href, string $text, string $sourceDir): ?string
    {
        // Вики в снимке Wayback: обычный URL index.php?title=… (в отличие от
        // офлайн-слепка, где Offline Explorer заменил «?» на «@»)
        if (preg_match('~index\.php\?title=([^&"\#]+)~', $href, $m)) {
            $title = trim(str_replace('_', ' ', rawurldecode($m[1])));
            foreach (app(MediaWikiArchive::class)->skipNamespaces as $ns) {
                if (str_starts_with(mb_strtolower($title), $ns)) {
                    return null; // Файл:, Служебная:, Участник: — не статьи
                }
            }

            return $title === '' ? null : $this->urlForWikiTitle($title);
        }

        // Вики: index.php@title=…
        if (str_contains($href, 'index.php@title=')) {
            // Сначала идём по самому файлу: Offline Explorer обрезает длинные
            // href и дописывает хэш («План трени…A04AB161»), поэтому имя ссылки
            // разобрать нельзя — зато в файле-цели есть настоящий wgTitle.
            $title = $this->titleOfLinkedFile($href, $sourceDir)
                ?? $this->decodeTitle((string) (preg_match('/index\.php@title=([^&"#]+)/', $href, $m) ? $m[1] : ''));

            return $title === null ? null : $this->urlForWikiTitle($title);
        }

        // Корень старого сайта → главная нового
        if (preg_match('~^(\.\./)*default\.htm$~', $href)) {
            return '/';
        }

        // Внешняя ссылка: OE переписал чужой домен в путь «../../домен/…».
        // Текст автоссылки обычно и есть исходный URL — он вернее; иначе
        // собираем адрес обратно из пути (query у OE закодирован как path@query).
        if (preg_match('~^(?:\.\./){2,}([a-z0-9-]+(?:\.[a-z0-9-]+)+)(/.*)?$~i', $href, $m)) {
            if (preg_match('#^https?://#i', $text)) {
                return rtrim($text, '/');
            }
            $rest = $m[2] ?? '/';
            $rest = preg_replace('~/default\.htm$~', '/', $rest);
            $rest = str_replace('@', '?', $rest);

            return 'http://'.$m[1].$rest;
        }

        // Страница старого сайта: ../slug/default.htm
        if (preg_match('~\.\./([^"?\#]+)/default\.htm~', $href, $m)) {
            $slug = basename($m[1]);
            $page = Page::where('slug', $slug)->first()
                ?? Page::where('source_url', 'like', '%/'.$slug.'/%')->first();
            if ($page) {
                return $page->url();
            }
        }

        if (preg_match('#^https?://#i', $text)) {
            return rtrim($text, '/');
        }

        return null;
    }

    /** Заголовок страницы-цели из самого файла слепка (href ненадёжен). */
    private function titleOfLinkedFile(string $href, string $sourceDir): ?string
    {
        $path = realpath($sourceDir.'/'.$href);
        if ($path === false) {
            // Соседний путь не сошёлся: OE растащил файлы по %&OvrN, а href
            // остался от исходной структуры. Имя файла при этом уникально.
            $path = $this->fileMap[basename($href)] ?? null;
        }
        if ($path === null || $path === false || ! is_file($path)) {
            return null;
        }
        $html = @file_get_contents($path, false, null, 0, 65536);
        if ($html === false) {
            return null;
        }

        return $this->index->titleOf($html);
    }

    private function urlForWikiTitle(string $title): ?string
    {
        $norm = str_replace('_', ' ', $title);

        $page = Page::where(fn ($q) => RussianText::equals($q, 'title', $norm))->first();
        if ($page) {
            return $page->url();
        }

        $term = GlossaryTerm::where(fn ($q) => RussianText::equals($q, 'term', $norm))->first();
        if ($term) {
            return '/glossary?term='.$term->slug;
        }

        $redirect = Redirect::where('from_path', '/wiki/index.php?title='.str_replace(' ', '_', $norm))
            ->orWhere('from_path', '/wiki/index.php?title='.$norm)
            ->first();

        return $redirect?->to_url;
    }

    /** Имя файла Offline Explorer → заголовок: _25D0_25A1… → «Сеанс…». */
    private function decodeTitle(string $encoded): ?string
    {
        $s = str_replace('_25', '%25', $encoded);
        $s = rawurldecode(rawurldecode($s));
        $s = trim(str_replace('_', ' ', $s));
        if ($s === '') {
            return null;
        }

        // Двоеточие само по себе не признак служебной страницы: у статей оно
        // встречается сплошь («План тренинга: "КАРМИЧЕСКАЯ КОРРЕКЦИЯ"»).
        // Отсекаем только настоящие пространства имён.
        foreach (app(MediaWikiArchive::class)->skipNamespaces as $ns) {
            if (str_starts_with(mb_strtolower($s), $ns)) {
                return null;
            }
        }

        return $s;
    }

    /** @return array{0:string,1:string}|null [контент исходника, папка файла] */
    private function wikiSource(array $entries, OfflineSnapshotIndex $index, Page $page): ?array
    {
        $entry = $entries[$index->normalize($page->title)] ?? null;
        if ($entry === null) {
            return null;
        }
        $html = @file_get_contents($entry['path']);
        if ($html === false) {
            return null;
        }
        $content = $index->contentHtml($html);

        return $content === null ? null : [$content, dirname($entry['path'])];
    }

    /**
     * Исходник страницы из Wayback Machine (для импортированных оттуда).
     *
     * Ответы кешируются на диск: страниц десятки, а веб-архив легко отвечает
     * 429/503 — повторный прогон не должен ходить в сеть заново.
     *
     * @return array{0:string,1:string}|null
     */
    private function waybackSource(Page $page): ?array
    {
        $url = (string) $page->source_url;
        // /web/2015/… — метка импортёра слепка, а не настоящий снимок
        if (! preg_match('#//web\.archive\.org/web/(\d{8,14})/#', $url, $m)) {
            return null;
        }

        $cache = storage_path('app/wayback-pages/'.sha1($url).'.html');
        if (is_file($cache)) {
            $html = (string) file_get_contents($cache);
        } else {
            // id_ — сырой снимок без обвязки веб-архива
            $raw = preg_replace('#(/web/\d{8,14})/#', '$1id_/', $url, 1);
            $resp = Http::retry(2, 3000)->timeout(60)->get($raw);
            if (! $resp->ok()) {
                $this->warn('Wayback '.$resp->status().': '.Str::limit($page->title, 40));

                return null;
            }
            $html = $resp->body();
            File::ensureDirectoryExists(dirname($cache));
            File::put($cache, $html);
            usleep(700000); // веб-архив не любит частых запросов
        }

        $content = $this->index->contentHtml($html);

        return $content === null ? null : [$content, ''];
    }

    /** @return array{0:string,1:string}|null */
    private function siteSource(string $siteDir, Page $page): ?array
    {
        $candidates = [];
        if ($page->source_url && preg_match('~x-intellect\.org/([^?\#]*)$~', $page->source_url, $m)) {
            $candidates[] = trim($m[1], '/');
        }
        $candidates[] = $page->slug;

        foreach (array_filter($candidates) as $rel) {
            $path = $siteDir.'/'.$rel.'/default.htm';
            if (! is_file($path)) {
                continue;
            }
            $html = @file_get_contents($path);
            if ($html === false) {
                continue;
            }
            if (preg_match('#<div class="entry">(.*?)</div>\s*<#s', $html, $m)
                || preg_match('#<div class="post"[^>]*>(.*?)<div id="sidebar"#s', $html, $m)) {
                return [$m[1], dirname($path)];
            }
        }

        return null;
    }

    private function renderReport(): string
    {
        $lines = ['# Восстановление ссылок — '.now()->format('d.m.Y H:i'), ''];
        $lines[] = sprintf('Вставлено: %d. Вне охвата: %d. Цель не найдена: %d. Текст правлен вручную: %d. Уже со ссылкой: %d.',
            $this->stats['inserted'], $this->stats['out_of_scope'], $this->stats['no_target'],
            $this->stats['text_changed'], $this->stats['already']);
        $lines[] = '';
        $lines[] = '| Страница | Статус | Якорь | Цель | Итог |';
        $lines[] = '|---|---|---|---|---|';
        foreach ($this->report as [$page, $text, $url, $result]) {
            $lines[] = sprintf('| %s (id %d) | %s | %s | %s | %s |',
                str_replace('|', '\\|', Str::limit($page->title, 50)), $page->id, $page->status,
                str_replace('|', '\\|', Str::limit($text, 45)), str_replace('|', '\\|', Str::limit($url, 45)), $result);
        }

        return implode("\n", $lines)."\n";
    }
}
