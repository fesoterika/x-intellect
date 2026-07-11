<?php

namespace App\Console\Commands;

use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use App\Services\ArchiveHtmlCleaner;
use DOMDocument;
use DOMElement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Импорт вики (MediaWiki) из офлайн-слепка x-intellect.org — Фаза B.
 *
 *   php artisan import:offline-wiki {wiki-dir} [--limit=N] [--dry]
 *
 * wiki-dir — папка …/www.x-intellect.org/www.x-intellect.org/wiki.
 *
 * Логика (согласована с пользователем):
 *  - «Глоссарий» — это индекс-список ссылок на страницы-термины. Всё, что в нём
 *    перечислено → в glossary_terms (краткое определение), БЕЗ отдельной вики-страницы
 *    (работают тултипы).
 *  - Содержательные статьи, которых нет в индексе глоссария (сеансы, «Проект Душа»,
 *    коллекции сеансов и т.п.) → страницы раздела «Вики» черновиками.
 *  - Служебные страницы MediaWiki (namespace, история, исходник, участники, боты…) — скип.
 *  - Слаги — транслит; со старого кириллического wiki-URL пишем 301.
 *  - Дубли оставляем как есть (не дедуплицируем между разделами).
 */
class ImportOfflineWiki extends Command
{
    protected $signature = 'import:offline-wiki {archive} {--limit=0} {--dry} {--refresh}';

    protected $description = 'Импорт вики из офлайн-слепка: страницы Вики + термины в Глоссарий (черновики)';

    /** Точные заголовки, которые не импортируем (вики-мета/навигация). */
    private array $skipTitles = [
        'администраторы', 'боты', 'бюрократы', 'права групп', 'участники',
        'правила wiki', 'правила википедии', 'поддержка', 'описание',
        'заглавная страница', 'глоссарий', 'термины и понятия', 'книжная полка',
        'библиотека', 'x - интеллект', 'сфера разума',
        'техническая поддержка', 'настройка программы для видеоконференций team talk',
        'личные консультации', 'галерея новых файлов',
    ];

    /** Префиксы пространств имён MediaWiki — скип. */
    private array $skipNamespaces = [
        'x intellect:', 'mediawiki:', 'служебная:', 'файл:', 'участник:',
        'обсуждение:', 'категория:', 'шаблон:', 'справка:', 'изображение:',
        'special:', 'file:', 'user:', 'template:', 'help:', 'talk:', 'category:',
    ];

    /** Подстроки заголовков «экшн-страниц» MediaWiki — скип. */
    private array $skipContains = [
        'история изменений', 'исходного текста', 'редактирование',
        'различия между', 'просмотр исходного', 'личная консультаци',
    ];

    public function handle(ArchiveHtmlCleaner $cleaner): int
    {
        $base = rtrim($this->argument('archive'), '/');
        if (! File::isDirectory($base)) {
            $this->error("Не найдено: {$base}");

            return self::FAILURE;
        }

        $wikiSectionId = Section::where('slug', 'wiki')->value('id');
        if (! $wikiSectionId) {
            $this->error('Нет раздела wiki.');

            return self::FAILURE;
        }

        $termSet = $this->parseGlossaryIndex($base);
        $this->info('Терминов в индексе глоссария: '.count($termSet));

        $files = collect(File::glob($base.'/index.php@title=*'))
            ->reject(fn ($f) => str_contains($f, '&'))
            ->reject(fn ($f) => (bool) preg_match('/\.(png|jpe?g|gif|svg|mp3|pdf|css|js|tmp|ico|webp|bmp)$/i', $f));

        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry');

        $pages = 0;
        $terms = 0;
        $skipped = 0;
        $seen = [];

        foreach ($files as $file) {
            [$title, $node, $doc] = $this->loadPage($file);
            if ($title === null || $node === null) {
                $skipped++;

                continue;
            }

            if ($this->isSkippable($title)) {
                $skipped++;

                continue;
            }

            $norm = mb_strtolower(trim($title));
            if (isset($seen[$norm])) {
                $skipped++;

                continue; // дубль-снимок той же страницы
            }

            $isTerm = isset($termSet[$norm]);

            if ($dry) {
                $seen[$norm] = true;
                $this->line(sprintf('[%s] %s', $isTerm ? 'термин' : 'вики', Str::limit($title, 70)));
                $isTerm ? $terms++ : $pages++;
                if ($limit && ($pages + $terms) >= $limit) {
                    break;
                }

                continue;
            }

            // keepBlockquote: false — MediaWiki-статьи целиком обёрнуты в
            // декоративный blockquote, на новом сайте выглядели бы цитатой
            $body = $cleaner->clean($this->innerHtml($node, $doc), $base, keepBlockquote: false);
            if (Str::length(strip_tags($body)) < 25) {
                $skipped++;

                continue; // пусто/заглушка/редирект
            }

            $seen[$norm] = true;

            if ($isTerm) {
                $this->createTerm($title, $node, $base);
                $terms++;
            } else {
                $this->createWikiPage($title, $body, $wikiSectionId);
                $pages++;
            }

            if ($limit && ($pages + $terms) >= $limit) {
                break;
            }
        }

        $this->newLine();
        $this->info("Готово. Страниц Вики: {$pages}, терминов в Глоссарий: {$terms}, пропущено: {$skipped}.");
        $this->info("Картинок скопировано: {$cleaner->imagesCopied}, убрано: {$cleaner->imagesDropped}.");
        $this->comment('Страницы Вики — черновики. Термины глоссария активны сразу (тултипы).');

        return self::SUCCESS;
    }

    /** Термин глоссария: краткое определение (plain text) в glossary_terms, без вики-страницы. */
    private function createTerm(string $title, DOMElement $node, string $base): void
    {
        $definition = $this->definitionText($node);
        if ($definition === '') {
            return;
        }

        $slug = $this->uniqueSlug($title, GlossaryTerm::class);
        $existing = GlossaryTerm::where('term', $title)->first();

        GlossaryTerm::updateOrCreate(
            ['term' => $title],
            ['slug' => $existing->slug ?? $slug, 'definition' => $definition],
        );

        // 301 со старого wiki-URL на страницу глоссария (по якорю слага)
        foreach ($this->oldWikiPaths($title) as $from) {
            Redirect::updateOrCreate(
                ['from_path' => $from],
                ['to_url' => '/glossary#'.($existing->slug ?? $slug), 'status_code' => 301, 'comment' => 'Вики-термин: '.Str::limit($title, 50)],
            );
        }
    }

    private function createWikiPage(string $title, string $body, int $wikiSectionId): void
    {
        // идемпотентность: не плодить дубли при повторном запуске.
        // --refresh — обновить тело существующего черновика (перечистка из исходника).
        $existing = Page::where('source_type', 'archive_wiki')->where('title', $title)->first();
        if ($existing) {
            if ($this->option('refresh')) {
                $existing->body = $body;
                $existing->save();
            }

            return;
        }

        $slug = $this->uniqueSlug($title, Page::class);
        $mwTitle = str_replace(' ', '_', $title);

        $page = Page::create([
            'section_id' => $wikiSectionId,
            'title' => $title,
            'slug' => $slug,
            'body' => $body,
            'status' => 'draft',
            'source_type' => 'archive_wiki',
            'source_url' => 'https://web.archive.org/web/2015/http://www.x-intellect.org/wiki/index.php?title='.rawurlencode($mwTitle),
        ]);

        foreach ($this->oldWikiPaths($title) as $from) {
            Redirect::updateOrCreate(
                ['from_path' => $from],
                ['to_url' => $page->url(), 'status_code' => 301, 'comment' => 'Архив вики: '.Str::limit($title, 50)],
            );
        }
    }

    /** Индекс глоссария → множество заголовков-терминов (в нижнем регистре). */
    private function parseGlossaryIndex(string $base): array
    {
        $set = [];
        foreach (['Глоссарий', 'Термины и понятия'] as $indexTitle) {
            $file = $this->findFile($base, $indexTitle);
            if ($file === null) {
                continue;
            }
            [$title, $node] = $this->loadPage($file);
            if ($node === null) {
                continue;
            }
            foreach ($node->getElementsByTagName('a') as $a) {
                $t = trim($a->getAttribute('title'));
                // Пропускаем красные ссылки на несуществующие страницы (там « (страница отсутствует)»)
                $t = preg_replace('/\s*\(страница отсутствует\)\s*$/u', '', $t);
                if ($t !== '' && ! str_contains($t, ':')) {
                    $set[mb_strtolower($t)] = true;
                }
            }
        }

        return $set;
    }

    /** Находит файл страницы по человекочитаемому заголовку (прямое кодирование + фолбэк). */
    private function findFile(string $base, string $title): ?string
    {
        $enc = str_replace('%', '_25', rawurlencode(str_replace(' ', '_', $title)));
        $exact = $base.'/index.php@title='.$enc;
        if (is_file($exact)) {
            return $exact;
        }
        // фолбэк: усечённое имя + хэш OE
        $g = collect(File::glob($base.'/index.php@title='.substr($enc, 0, 50).'*'))
            ->reject(fn ($f) => str_contains($f, '&'))
            ->first();

        return $g ?: null;
    }

    /** @return array{0: ?string, 1: ?DOMElement, 2: ?DOMDocument} */
    private function loadPage(string $file): array
    {
        $html = @File::get($file);
        if (! $html) {
            return [null, null, null];
        }
        // Только основное пространство имён (статьи, ns-0). Так одним махом
        // отсекаются Служебная/Участник/Обсуждение/MediaWiki/Категория/Файл/Шаблон.
        if (preg_match('/"wgNamespaceNumber":(-?\d+)/', $html, $m) && $m[1] !== '0') {
            return [null, null, null];
        }
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $heading = $doc->getElementById('firstHeading');
        $content = $doc->getElementById('mw-content-text');
        if (! $heading || ! $content) {
            return [null, null, null];
        }
        $title = trim(preg_replace('/\s+/u', ' ', $heading->textContent));

        return [$title ?: null, $content instanceof DOMElement ? $content : null, $doc];
    }

    private function innerHtml(DOMElement $node, DOMDocument $doc): string
    {
        $html = '';
        foreach ($node->childNodes as $c) {
            $html .= $doc->saveHTML($c);
        }
        // Срезаем NewPP-комментарии и парсер-кэш
        return preg_replace('/<!--.*?-->/s', '', $html);
    }

    /** Краткое определение термина как plain text (для тултипа/глоссария). */
    private function definitionText(DOMElement $node): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
        // убрать ведущий «Термин:» дубль не трогаем — оставляем как есть, только чистим
        $text = preg_replace('/\[править[^\]]*\]/u', '', $text);
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) <= 600) {
            return $text;
        }
        // обрезаем по границе предложения
        $cut = mb_substr($text, 0, 600);
        $lastDot = max(mb_strrpos($cut, '. '), mb_strrpos($cut, '! '), mb_strrpos($cut, '? '));

        return ($lastDot !== false && $lastDot > 200 ? mb_substr($cut, 0, $lastDot + 1) : $cut).' …';
    }

    /** Старые URL MediaWiki для 301 (с подчёркиваниями и с пробелами). */
    private function oldWikiPaths(string $title): array
    {
        $underscore = str_replace(' ', '_', $title);

        return array_unique([
            '/wiki/index.php?title='.$underscore,
            '/wiki/index.php?title='.$title,
        ]);
    }

    private function isSkippable(string $title): bool
    {
        $t = mb_strtolower(trim($title));
        if (in_array($t, $this->skipTitles, true)) {
            return true;
        }
        foreach ($this->skipNamespaces as $ns) {
            if (str_starts_with($t, $ns)) {
                return true;
            }
        }
        foreach ($this->skipContains as $needle) {
            if (str_contains($t, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Уникальный транслит-слаг для указанной модели (Page или GlossaryTerm). */
    private function uniqueSlug(string $title, string $model): string
    {
        $base = Str::slug($title) ?: 'page';
        $slug = $base;
        $i = 2;
        while ($model::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
