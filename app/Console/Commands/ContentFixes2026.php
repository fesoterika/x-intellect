<?php

namespace App\Console\Commands;

use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Правки контента (июль 2026), идемпотентны — можно повторять на проде
 * после переимпорта архива:
 *
 *  1. Из таблиц вики-страниц удаляются пустые служебные строки
 *     «Аудио Запись» / «Аудио запись, часть N» (наследие MediaWiki —
 *     аудио на новом сайте живёт в разделе «Аудиозаписи» страницы).
 *  2. Страница «Техники» получает ссылки на вики-страницы техник;
 *     отсутствующие страницы создаются черновиками.
 *  3. Чинятся редиректы: удаляются 301, перекрывающие живые адреса разделов
 *     (/hello, /library, /rules — наследие импорта плоских статей);
 *     цели «/glossary?term=…» с несуществующим термином перевешиваются на
 *     страницу с тем же slug (кейс «Внеземные Цивилизации (ВЦ)»).
 *  4. Вики-страницы, состоящие только из нескольких однотипных таблиц
 *     «ключ-значение» (карточки встреч), получают одну сводную таблицу:
 *     шапка из ключей + строка на каждую исходную таблицу.
 *  5. Абсолютные ссылки на localhost в контенте (страницы, описания
 *     разделов, определения глоссария) → относительные (App\Services\LocalLinks;
 *     новые сохранения чистятся автоматически).
 *  6. Странице «Картины Учителей Ноосферы» возвращаются сами картины: из
 *     веб-архива они не тянутся, файлы берутся из офлайн-слепка (--snapshot).
 *
 *   php artisan site:content-fixes-2026 [--dry] [--snapshot=…/www.x-intellect.org]
 */
class ContentFixes2026 extends Command
{
    protected $signature = 'site:content-fixes-2026
        {--dry : Показать изменения без записи}
        {--snapshot= : Путь к офлайн-слепку (…/www.x-intellect.org) — для картин Учителей Ноосферы}';

    protected $description = 'Правки контента: строки «Аудио Запись» из таблиц вики + ссылки на странице «Техники»';

    protected const KARTINY_SLUG = 'kartiny-ucitelei-noosfery';

    /**
     * Картины проекта «Картины Учителей Ноосферы» — раскладка снимка вики
     * (web.archive.org/web/20200926172215):
     *
     *   якорь в теле => [before|after, [путь в wiki/images => обтекание]]
     *
     * KN_2M.PNG в вики стояла «thumb tright» перед карточкой проекта — отсюда
     * float, из которого TableImagePairer соберёт пару «картинка + таблица».
     * Остальные шли рядами миниатюр (их собирает ImageGallery).
     */
    protected const KARTINY_GALLERIES = [
        '<table>' => ['before', ['a/a4/KN_2M.PNG' => 'right']],

        '<div><strong>Структура банка Шамбалы</strong>' => ['before', ['d/df/SH555.jpg' => null]],

        'более высокой ступени развития.</li></ul>' => ['after', [
            '2/2f/SH22.jpg' => null,
            '1/10/SH23.jpg' => null,
            '1/14/SH24.jpg' => null,
        ]],

        '<strong>Шамбала (12 картин)</strong>' => ['after', [
            '6/6e/SH1.gif' => null,
            'a/a2/SH7.gif' => null,
            '0/0c/SH8.gif' => null,
            'a/a6/SH9.gif' => null,
        ]],

        '<strong>Параллельные миры (8 картин)</strong>' => ['after', [
            '0/0a/PM30.gif' => null,
            'e/ea/PM31.gif' => null,
            '6/65/PM32.gif' => null,
            'a/ab/PM33.gif' => null,
            'a/af/34.gif' => null,
            '7/7b/PM63.gif' => null,
        ]],

        '<strong>Гармония (6 картин)</strong>' => ['after', [
            '4/4f/G24.gif' => null,
            'd/d2/G25.gif' => null,
            '5/55/G49.gif' => null,
            'e/ef/G50.gif' => null,
        ]],

        '<strong>Создание пятой расы на Земле (9 картин)</strong>' => ['after', [
            '6/6e/SH1.gif' => null,
            '0/00/37.gif' => null,
            '8/81/36.gif' => null,
            '4/40/PM62.gif' => null,
        ]],
    ];

    /** Страницы техник, на которые ссылается вики-страница «Техники» */
    protected const TECHNIQUE_TITLES = [
        'Техника астральной сборки оболочечного двойника',
        'Развитие энергоинформационного восприятия',
        'Лечение с помощью психо-биоэнергетического воздействия на биоактивные точки',
        'Активация и гармонизация чакр',
        'Защита от Деструктивных Сил (ДС)',
        'Подготовка Посредников',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        $this->removeAudioRows($dry);
        $this->linkTechniques($dry);
        $this->fixRedirects($dry);
        $this->mergeTableOnlyPages($dry);
        $this->relativizeLocalLinks($dry);
        $this->restoreKartinyImages($dry);

        return self::SUCCESS;
    }

    /** Ссылки на localhost в существующем контенте → относительные. */
    protected function relativizeLocalLinks(bool $dry): void
    {
        $links = app(\App\Services\LocalLinks::class);
        $like = fn ($q, $column) => $q->where($column, 'like', '%localhost%')
            ->orWhere($column, 'like', '%127.0.0.1%');

        $fixed = 0;

        // Страницы: чистим body; если localhost остался только в рендере —
        // сбрасываем body_rendered, наблюдатель перерендерит из чистого body
        $pages = Page::where(fn ($q) => $like($q, 'body'))
            ->orWhere(fn ($q) => $like($q, 'body_rendered'))
            ->orderBy('id')->get();
        foreach ($pages as $page) {
            $page->body = $links->relativize($page->body);
            if (! $page->isDirty('body')) {
                $page->body_rendered = null;
            }
            $fixed++;
            $this->line("ссылки → относительные: [{$page->id}] {$page->title}");
            if (! $dry) {
                $page->save();
            }
        }

        foreach (Section::where(fn ($q) => $like($q, 'description'))->get() as $section) {
            $section->description = $links->relativize($section->description);
            $fixed++;
            $this->line("ссылки → относительные (раздел): {$section->title}");
            if (! $dry) {
                $section->save();
            }
        }

        foreach (GlossaryTerm::where(fn ($q) => $like($q, 'definition'))->get() as $term) {
            $term->definition = $links->relativize($term->definition);
            $fixed++;
            $this->line("ссылки → относительные (термин): {$term->term}");
            if (! $dry) {
                $term->save();
            }
        }

        $this->info("Локальные ссылки: записей исправлено — {$fixed}.");
    }

    /**
     * Страница из одних таблиц (кроме картинок-фигур) с одинаковым набором
     * ключей в первом столбце → одна сводная таблица: шапка из ключей,
     * строка на каждую исходную таблицу.
     */
    protected function mergeTableOnlyPages(bool $dry): void
    {
        $merged = 0;

        foreach (Page::where('body', 'like', '%<table%')->orderBy('id')->get() as $page) {
            if (! preg_match_all('#<table\b.*?</table>#su', $page->body, $m) || count($m[0]) < 2) {
                continue;
            }
            $tables = $m[0];

            // Вне таблиц не должно оставаться контента (картинки-фигуры не в счёт)
            $rest = preg_replace('#<table\b.*?</table>#su', '', $page->body);
            $rest = preg_replace('#<figure\b.*?</figure>#su', '', $rest);
            if (mb_strlen(trim(strip_tags($rest))) > 40) {
                continue;
            }

            $parsed = array_map(fn ($t) => $this->parseKeyValueTable($t), $tables);
            if (in_array(null, $parsed, true)) {
                continue;
            }

            // Все таблицы должны иметь одинаковый набор ключей
            $keys = array_keys($parsed[0]);
            foreach ($parsed as $rows) {
                if (array_keys($rows) !== $keys) {
                    continue 2;
                }
            }

            $header = '<tr>'.implode('', array_map(
                fn ($k) => '<td><strong>'.e($k).'</strong></td>', $keys,
            )).'</tr>';
            $rows = implode('', array_map(
                fn ($cells) => '<tr>'.implode('', array_map(
                    fn ($k) => '<td>'.$cells[$k].'</td>', $keys,
                )).'</tr>',
                $parsed,
            ));
            $mergedTable = '<table>'.$header.$rows.'</table>';

            // Фигуры (картинки) сохраняем перед сводной таблицей
            preg_match_all('#<figure\b.*?</figure>#su', $page->body, $figures);

            $merged++;
            $this->line(sprintf('объединяю %d таблиц — [%d] %s', count($tables), $page->id, $page->title));

            if (! $dry) {
                $page->body = implode('', $figures[0]).$mergedTable;
                $page->save();
            }
        }

        $this->info("Сводные таблицы: страниц объединено — {$merged}.");
    }

    /**
     * Таблица «ключ-значение» (2 колонки, ключи в первой) → [ключ => HTML
     * значения]; null, если структура другая.
     */
    protected function parseKeyValueTable(string $table): ?array
    {
        if (! preg_match_all('#<tr>(.*?)</tr>#su', $table, $m)) {
            return null;
        }

        $rows = [];
        foreach ($m[1] as $tr) {
            // Режем по открывающим <td>: в архивной вики закрывающий </td>
            // у последней ячейки может отсутствовать перед </tr>
            $parts = preg_split('#<td\b[^>]*>#', $tr);
            array_shift($parts);
            $parts = array_map(fn ($c) => trim(preg_replace('#</td>\s*$#', '', trim($c))), $parts);

            if (count($parts) !== 2) {
                return null;
            }

            $key = trim(trim(strip_tags($parts[0])), ": \u{A0}");
            if ($key === '' || isset($rows[$key])) {
                return null;
            }
            $rows[$key] = $parts[1];
        }

        return count($rows) >= 2 ? $rows : null;
    }

    /**
     * Редиректы, мешающие живому сайту: 301 со старых плоских адресов,
     * совпадающих с URL разделов (перекрывают листинги — middleware
     * срабатывает до маршрутизации), и цели на несуществующие термины.
     */
    protected function fixRedirects(bool $dry): void
    {
        // 1) from_path совпадает с адресом раздела или фиксированным маршрутом
        $live = collect(['/', '/search', '/glossary', '/forum', '/fesoterika'])
            ->merge(Section::with('parent')->get()->map->url());

        foreach (Redirect::whereIn('from_path', $live)->get() as $redirect) {
            $this->line("удаляю редирект, перекрывающий живой адрес: {$redirect->from_path} → {$redirect->to_url}");
            if (! $dry) {
                $redirect->delete();
            }
        }

        // 2) Цель /glossary?term=<slug> без такого термина — если есть
        //    страница с тем же slug, редирект переезжает на неё
        $prefix = '/glossary?term=';
        foreach (Redirect::where('to_url', 'like', $prefix.'%')->get() as $redirect) {
            $slug = substr($redirect->to_url, strlen($prefix));
            if (GlossaryTerm::where('slug', $slug)->exists()) {
                continue;
            }

            // Страницу ищем по slug цели, затем по заголовку из старого
            // wiki-адреса (?title=…, подчёркивания = пробелы)
            $page = Page::where('slug', $slug)->first();
            if (! $page && preg_match('/[?&]title=([^&]+)/u', $redirect->from_path, $m)) {
                $title = str_replace('_', ' ', rawurldecode($m[1]));
                $page = Page::where('title', $title)->first();
            }

            if ($page) {
                $this->line("цель-термин «{$slug}» не существует: {$redirect->from_path} → {$page->url()}");
                if (! $dry) {
                    $redirect->update(['to_url' => $page->url()]);
                }

                continue;
            }

            // Термин мог быть переименован со сменой slug — принимаем
            // единственное однозначное совпадение по префиксу
            $renamed = GlossaryTerm::where('slug', 'like', $slug.'%')->get();
            if ($renamed->count() === 1) {
                $term = $renamed->first();
                $this->line("термин переехал: {$redirect->from_path} → {$term->url()}");
                if (! $dry) {
                    $redirect->update(['to_url' => $term->url()]);
                }
            } else {
                $this->warn("битый редирект (ни термина, ни страницы «{$slug}»): {$redirect->from_path}");
            }
        }

        $this->info('Редиректы проверены.');
    }

    /**
     * Строка таблицы, чья первая ячейка начинается с «Аудио» («Аудио Запись»,
     * «Аудио запись, часть1»…) — служебная метка старой вики, всегда без
     * файла. Удаляем строку целиком вместе со второй (пустой) ячейкой.
     */
    protected function removeAudioRows(bool $dry): void
    {
        $pattern = '#<tr>\s*<td>\s*(?:<strong>)?\s*Аудио[^<]*.*?</tr>\s*#su';

        $pages = Page::where('body', 'like', '%Аудио%')->orderBy('id')->get();
        $changed = 0;

        foreach ($pages as $page) {
            $body = preg_replace($pattern, '', $page->body, -1, $count);
            if (! $count || $body === null) {
                continue;
            }

            $changed++;
            $this->line(sprintf('строк аудио: %d — [%d] %s', $count, $page->id, $page->title));

            if (! $dry) {
                $page->body = $body;
                $page->save(); // PageObserver перерендерит body_rendered
            }
        }

        $this->info("Таблицы вики: страниц изменено — {$changed}.");
        $this->refreshStaleExcerpts($dry);
    }

    /**
     * Анонс и meta_description собираются из тела один раз и больше не
     * пересчитываются. Поэтому после удаления строки «Аудио Запись» она годами
     * живёт в анонсе — текста в теле уже нет, а в выдаче и в списках он есть.
     * Пересобираем ровно те анонсы, где остался этот след.
     */
    protected function refreshStaleExcerpts(bool $dry): void
    {
        $maker = app(\App\Services\ExcerptMaker::class);
        $refreshed = 0;
        $published = [];

        $pages = Page::where('excerpt', 'like', '%Аудио Запись%')
            ->orWhere('excerpt', 'like', '%Аудио запись%')
            ->orderBy('id')->get();

        foreach ($pages as $page) {
            if (str_contains((string) $page->body, 'Аудио Запись')) {
                continue; // строка ещё в теле — анонс верен
            }
            // Опубликованное вычитано вручную: анонс мог быть переписан руками
            if ($page->status === 'published') {
                $published[] = "[{$page->id}] {$page->title}";

                continue;
            }

            $fresh = $maker->fromBody($page->body);
            if ($fresh === '' || $fresh === $page->excerpt) {
                continue;
            }

            $seo = $page->seo ?? [];
            unset($seo['meta_description']); // SeoService пересоберёт из нового анонса

            $refreshed++;
            $this->line(sprintf('анонс пересобран: [%d] %s', $page->id, $page->title));

            if (! $dry) {
                $page->excerpt = $fresh;
                $page->seo = $seo;
                $page->save();
            }
        }

        $this->info("Анонсы после чистки таблиц: обновлено — {$refreshed}.");
        if ($published) {
            $this->warn('Опубликованные со следом «Аудио Запись» в анонсе (не тронуты): '.implode('; ', $published));
        }
    }

    /** Список техник на странице «Техники» превращается в ссылки на страницы. */
    protected function linkTechniques(bool $dry): void
    {
        $wiki = Section::root()->where('slug', 'wiki')->first();
        if (! $wiki) {
            $this->error('Раздел wiki не найден — ссылки техник пропущены.');

            return;
        }

        $index = Page::where('slug', 'texniki')->first();
        if (! $index) {
            $this->error('Страница «Техники» (slug=texniki) не найдена.');

            return;
        }

        $items = [];
        foreach (self::TECHNIQUE_TITLES as $title) {
            $page = Page::where('title', $title)->first();

            if (! $page) {
                $this->line("создаю черновик: {$title}");
                if ($dry) {
                    $items[] = '<li><strong>'.e($title).'</strong></li>';

                    continue;
                }
                $page = Page::create([
                    'section_id' => $wiki->id,
                    'title' => $title,
                    'status' => 'draft',
                    'source_type' => 'new',
                    'body' => '',
                ]);
            }

            $items[] = '<li><strong><a href="'.e($page->url()).'">'.e($page->title).'</a></strong></li>';
        }

        $body = '<ul>'.implode('', $items).'</ul>';

        if ($index->body === $body) {
            $this->info('Страница «Техники»: ссылки уже проставлены.');

            return;
        }

        if (! $dry) {
            $index->body = $body;
            $index->save();
        }

        $this->info('Страница «Техники»: список заменён на ссылки ('.count($items).').');
    }

    /**
     * Картины на странице «Картины Учителей Ноосферы».
     *
     * Страница пришла из веб-архива (import:wayback-wiki), а он отдаёт картинки
     * внешними ссылками — ArchiveHtmlCleaner их отбрасывает, и от галерей
     * остались одни заголовки («Шамбала (12 картин)» и т.п.). Сами файлы есть
     * в офлайн-слепке 2015 года по тем же путям MediaWiki, что и в снимке
     * (/wiki/images/x/xx/Имя) — берём их оттуда и вставляем фигуры по якорям
     * текста. Тело правится точечно (страница вычитана вручную — перезаливать
     * её из архива нельзя).
     */
    protected function restoreKartinyImages(bool $dry): void
    {
        $page = Page::where('slug', self::KARTINY_SLUG)->first();
        if (! $page) {
            $this->warn('Страница «Картины Учителей Ноосферы» не найдена — картины пропущены.');

            return;
        }

        if (str_contains((string) $page->body, 'media/archive/')) {
            $this->info('Картины Учителей Ноосферы: картины уже на месте.');

            return;
        }

        $snapshot = $this->option('snapshot');
        if (! $snapshot || ! is_dir($snapshot)) {
            $this->comment('Картины Учителей Ноосферы: нужен путь к слепку — '
                .'php artisan site:content-fixes-2026 --snapshot=/путь/к/www.x-intellect.org');

            return;
        }

        $body = (string) $page->body;
        $copied = 0;

        foreach (self::KARTINY_GALLERIES as $anchor => [$where, $files]) {
            if (substr_count($body, $anchor) !== 1) {
                $this->warn('Картины: якорь не найден (или неоднозначен) — '.strip_tags($anchor));

                return;
            }

            $figures = '';
            foreach ($files as $file => $float) {
                $src = $this->copyArchiveImage($snapshot, $file, $dry);
                if ($src === null) {
                    $this->error('Картины: нет файла в слепке — wiki/images/'.$file);

                    return;
                }
                $copied++;

                $classes = 'attachment attachment--preview'.($float ? ' xi-float-'.$float : '');
                $figures .= '<figure class="'.$classes.'">'
                    .'<img src="'.e($src).'" alt="'.e(basename($file)).'">'
                    .'</figure>';
            }

            $body = str_replace(
                $anchor,
                $where === 'before' ? $figures.$anchor : $anchor.$figures,
                $body,
            );
        }

        if (! $dry) {
            $page->body = $body;
            $page->save();
        }

        $this->info("Картины Учителей Ноосферы: вставлено картин — {$copied}.");
    }

    /**
     * Файл из слепка → storage/media/archive. Имя — по хэшу исходного пути,
     * как в ArchiveHtmlCleaner: тот же файл из слепка не задваивается, а
     * повторный прогон не меняет src в теле.
     */
    protected function copyArchiveImage(string $snapshot, string $file, bool $dry): ?string
    {
        $path = realpath(rtrim($snapshot, '/').'/wiki/images/'.$file);
        if ($path === false || ! is_file($path)) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $dest = 'media/archive/'.substr(sha1($path), 0, 24).'.'.$ext;

        if (! $dry && ! Storage::disk('public')->exists($dest)) {
            Storage::disk('public')->put($dest, File::get($path));
        }

        return '/storage/'.$dest;
    }
}
