<?php

namespace App\Console\Commands;

use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use Illuminate\Console\Command;

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
 *
 *   php artisan site:content-fixes-2026 [--dry]
 */
class ContentFixes2026 extends Command
{
    protected $signature = 'site:content-fixes-2026 {--dry : Показать изменения без записи}';

    protected $description = 'Правки контента: строки «Аудио Запись» из таблиц вики + ссылки на странице «Техники»';

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
}
