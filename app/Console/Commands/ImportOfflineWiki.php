<?php

namespace App\Console\Commands;

use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use App\Services\ArchiveHtmlCleaner;
use App\Services\MediaWikiArchive;
use App\Services\OfflineSnapshotIndex;
use DOMDocument;
use DOMElement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Импорт вики (MediaWiki) из офлайн-слепка x-intellect.org — Фаза B.
 *
 *   php artisan import:offline-wiki {wiki-dir} [--limit=N] [--dry] [--refresh]
 *
 * wiki-dir — папка …/www.x-intellect.org/www.x-intellect.org/wiki.
 *
 * Логика (согласована с пользователем):
 *  - «Глоссарий» — это индекс-список ссылок на страницы-термины. Всё, что в нём
 *    перечислено → в glossary_terms (краткое определение), БЕЗ отдельной вики-страницы
 *    (работают тултипы). Исключение — MediaWikiArchive::$forceAsPages: большие
 *    статьи (Биоэкран, Душа, ВЦ), которым нужна и страница (пункты меню вики).
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

    /** Заголовки, пропущенные при --refresh из-за ручных правок (для сводки). */
    private array $refreshSkipped = [];

    private MediaWikiArchive $mw;

    public function handle(ArchiveHtmlCleaner $cleaner, MediaWikiArchive $mw, OfflineSnapshotIndex $index): int
    {
        $this->mw = $mw;

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

        // Индекс обходит и корень, и подпапки %&OvrN, и отсеивает diff/старые
        // ревизии по содержимому: имена файлов OE обрезает, полагаться на них нельзя.
        $entries = $index->build($base);
        $this->info('Статей в слепке (ns-0, канонических): '.count($entries));

        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry');

        $pages = 0;
        $terms = 0;
        $skipped = 0;

        foreach ($entries as $norm => $entry) {
            [$title, $node, $doc] = $this->mw->parse(@File::get($entry['path']) ?: null);
            if ($title === null || $node === null) {
                $skipped++;

                continue;
            }

            if ($this->mw->isSkippable($title)) {
                $skipped++;

                continue;
            }

            $isTerm = isset($termSet[$norm]) && ! in_array($norm, $this->mw->forceAsPages, true);

            if ($dry) {
                $this->line(sprintf('[%s] %s', $isTerm ? 'термин' : 'вики', Str::limit($title, 70)));
                $isTerm ? $terms++ : $pages++;
                if ($limit && ($pages + $terms) >= $limit) {
                    break;
                }

                continue;
            }

            // keepBlockquote: false — MediaWiki-статьи целиком обёрнуты в
            // декоративный blockquote, на новом сайте выглядели бы цитатой
            $body = $cleaner->clean($this->mw->innerHtml($node, $doc), $base, keepBlockquote: false);
            if (Str::length(strip_tags($body)) < 25) {
                $skipped++;

                continue; // пусто/заглушка/редирект
            }

            if ($isTerm) {
                $this->createTerm($title, $node);
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
        if ($this->refreshSkipped) {
            $this->warn('Не обновлены (ручные правки): '.implode('; ', array_unique($this->refreshSkipped)));
        }
        $this->info("Картинок скопировано: {$cleaner->imagesCopied}, убрано: {$cleaner->imagesDropped}.");
        $this->comment('Страницы Вики — черновики. Термины глоссария активны сразу (тултипы).');

        return self::SUCCESS;
    }

    /** Термин глоссария: краткое определение (plain text) в glossary_terms, без вики-страницы. */
    private function createTerm(string $title, DOMElement $node): void
    {
        $definition = $this->mw->definitionText($node);
        if ($definition === '') {
            return;
        }

        $slug = $this->mw->uniqueSlug($title, GlossaryTerm::class);
        $existing = GlossaryTerm::where('term', $title)->first();

        GlossaryTerm::updateOrCreate(
            ['term' => $title],
            ['slug' => $existing->slug ?? $slug, 'definition' => $definition],
        );

        // 301 со старого wiki-URL на адрес термина. Именно ?term=<slug>, а не
        // якорь #slug: фрагмент не доходит до сервера, и все термины склеивались
        // бы в индексе в один URL /glossary.
        foreach ($this->mw->oldWikiPaths($title) as $from) {
            Redirect::updateOrCreate(
                ['from_path' => $from],
                ['to_url' => '/glossary?term='.($existing->slug ?? $slug), 'status_code' => 301, 'comment' => 'Вики-термин: '.Str::limit($title, 50)],
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
                // Опубликованное вычитано вручную и перезаписи не подлежит — даже
                // если ревизии с пометкой о ручной правке ещё нет.
                if ($existing->status === 'published'
                    || $existing->revisions()->where('note', 'like', 'Отредактирована вручную%')->exists()) {
                    $this->refreshSkipped[] = $existing->title;
                } else {
                    $existing->body = $body;
                    $existing->save();
                }
            }

            return;
        }

        $slug = $this->mw->uniqueSlug($title, Page::class);
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

        foreach ($this->mw->oldWikiPaths($title) as $from) {
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
            [$title, $node] = $this->mw->parse(@File::get($file) ?: null);
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
}
