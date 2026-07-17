<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Раскладка структуры сайта по согласованной схеме (июль 2026):
 * подразделы «Дайджесты» (Статьи) и «Общий раздел»/«Проекты»/«Сеансы» (Вики),
 * перенос страниц в подразделы, скрытие стенограмм из списков.
 *
 *   php artisan site:structure-2026 [--pre] [--dry]
 *
 * --pre — только подготовка к пере-импорту: у страницы «Внеземные Цивилизации (ВЦ)»
 * выправляется source_type (была archive_xintellect — вики-импортёр не находил её
 * и создал бы дубликат). Запускать ДО import:offline-wiki --refresh.
 *
 * Команда идемпотентна: повторный запуск ничего не ломает.
 */
class ApplySiteStructure2026 extends Command
{
    protected $signature = 'site:structure-2026 {--pre} {--dry}';

    protected $description = 'Подразделы (Дайджесты, Общий раздел, Проекты, Сеансы) и раскладка страниц по ним';

    /** Меню вики: slug подраздела → [позиция подраздела, заголовки страниц по порядку]. */
    private array $wikiMenu = [
        'obshhii-razdel' => ['title' => 'Общий раздел', 'position' => 1, 'pages' => [
            'Правила Википедии',
            'Библиотека',
            'Техники',
        ]],
        'proekty' => ['title' => 'Проекты', 'position' => 2, 'pages' => [
            'Биоэкран',
            'Душа',
            'Картины Учителей Ноосферы: 2012',
            'Проекты 2005 - 2012',
        ]],
        'seansy' => ['title' => 'Сеансы', 'position' => 3, 'pages' => [
            'Сеансы 1991 - 2008',
            'Сеансы 2009 - 2010',
            'Сеансы 2011',
            'Сеансы 2012',
            'Сеансы 2013',
            'Сеансы 2014',
            'Сеансы 2015',
            'Сеансы 2016',
            'Сеансы 2017',
        ]],
    ];

    /**
     * Подраздел «Памяти А. Глаза» (Статьи, /articles/mag): точные заголовки
     * страниц памяти основателя проекта. Заголовок может повторяться, поэтому
     * переносятся ВСЕ совпадения, а не первое.
     *
     * «День Рождения Александра Глаза» (две статьи) сюда намеренно не входит —
     * их оставили в корне «Статей» по решению редактора.
     */
    private array $memorialPages = [
        'Безвременно ушел от нас Александр Георгиевич Глаз',
        'Исполнилось 40 дней со дня перехода Александра Георгиевича Глаза в иные реальности безграничного Дома Вселенной',
        'СВЕТЛАЯ ПАМЯТЬ АЛЕКСАНДРУ ГЛАЗУ',
        'Сегодня Александру Глазу исполнилось бы 53 года…но Творцу было угодно, чтобы душа его устремилась к небесам…',
    ];

    /**
     * Подразделы раздела «Проекты» (главный сайт, не вики): slug, заголовок,
     * позиция и regex по названию страницы. Порядок правил важен: «Картины
     * Учителей Ноосферы» раньше «Ноосферы», иначе перехват по подстроке.
     */
    private array $projectRules = [
        ['kartiny-uchitelei', 'Картины Учителей Ноосферы', 5, '/картины учител/ui'],
        ['izosfera', 'Изосфера и параллельные миры', 3, '/изосфера|параллельны[е\s]+миры/ui'],
        ['muzhchina-i-zhenshhina', 'Мужчина и Женщина', 4, '/мужчина и женщина/ui'],
        ['noosfera-proekt', 'Ноосфера', 2, '/ноосфера/ui'],
        ['dusha', 'Душа', 1, '/душа/ui'],
        ['celitelstvo-proekt', 'Целительство', 7, '/целительств/ui'],
        ['bioekran-proekt', 'Биоэкран', 6, '/биоэкран/ui'],
        ['etalonizaciia', 'Эталонизация', 8, '/эталониз/ui'],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        if ($this->option('pre')) {
            return $this->preImportFixes($dry);
        }

        $wiki = Section::root()->where('slug', 'wiki')->first();
        $articles = Section::root()->where('slug', 'articles')->first();
        if (! $wiki || ! $articles) {
            $this->error('Нет корневых разделов wiki/articles.');

            return self::FAILURE;
        }

        // 1. Подразделы
        $digests = $this->ensureChild($articles, 'daidzesty', 'Дайджесты', 1, $dry);
        $memorial = $this->ensureChild($articles, 'mag', 'Памяти А. Глаза', 2, $dry);
        $children = [];
        foreach ($this->wikiMenu as $slug => $def) {
            $children[$slug] = $this->ensureChild($wiki, $slug, $def['title'], $def['position'], $dry);
        }

        // 2. Дайджесты → подраздел «Дайджесты»
        $moved = 0;
        foreach (Page::where('title', 'like', '%айджест%')->get() as $page) {
            $moved += $this->movePage($page, $digests, null, $dry);
        }
        $this->info("Дайджестов в подразделе: {$moved}.");

        // 2а. Памяти А. Глаза → подраздел /articles/mag.
        // Публичный URL страниц не меняется (Page::url() строится от корневого
        // раздела), поэтому редиректы для самих статей не нужны.
        $memorialMoved = 0;
        $memorialMissing = [];
        foreach ($this->memorialPages as $title) {
            $pages = Page::where('title', $title)->get();
            if ($pages->isEmpty()) {
                $memorialMissing[] = $title;

                continue;
            }
            foreach ($pages as $page) {
                $memorialMoved += $this->movePage($page, $memorial, null, $dry);
            }
        }
        $this->info("Статей в подразделе «Памяти А. Глаза»: {$memorialMoved}.");
        if ($memorialMissing) {
            $this->warn('[Памяти А. Глаза] не найдены: '.implode('; ', $memorialMissing));
        }

        // 2б. Старый редирект /mag → /articles заменяем на актуальный адрес
        // подраздела (/articles/mag): слаг занят самим разделом.
        if (! $dry && $memorial->exists) {
            Redirect::updateOrCreate(
                ['from_path' => '/mag'],
                ['to_url' => $memorial->url(), 'status_code' => 301, 'comment' => 'Короткий адрес подраздела «Памяти А. Глаза»'],
            );
            $this->line('Редирект /mag → '.$memorial->url().' (301).');
        }

        // 3. Страницы меню вики → подразделы, с позициями
        foreach ($this->wikiMenu as $slug => $def) {
            $missing = [];
            foreach ($def['pages'] as $i => $title) {
                $page = Page::where('title', $title)->first();
                if (! $page) {
                    $missing[] = $title;

                    continue;
                }
                $this->movePage($page, $children[$slug], ($i + 1) * 10, $dry);
            }
            if ($missing) {
                $this->warn("[{$def['title']}] не найдены: ".implode('; ', $missing));
            }
        }

        // 4. Стенограммы сеансов → подраздел «Сеансы».
        // Видимость в списках не трогаем — это галочка редактора (см. movePage).
        $transcripts = Page::where('title', 'like', 'Сеанс с _илами%')->get()
            ->filter(fn ($p) => preg_match('/^Сеанс с [Сс]илами/u', $p->title));
        foreach ($transcripts as $page) {
            $this->movePage($page, $children['seansy'], null, $dry);
        }
        $this->info('Стенограмм в подразделе «Сеансы»: '.$transcripts->count().'.');

        // 4а. Ранние варианты страниц-указателей («Сеансы 1991», «Сеансы 2007»,
        // «Сеансы 1997 - 2008»…) — тот же перечень за другой период. Не удаляются
        // (адреса живые, на них ведут ссылки), просто складываются в «Сеансы».
        // Видимость — за редактором, команда её не трогает.
        $canonical = collect($this->wikiMenu['seansy']['pages'])
            ->map(fn ($t) => mb_strtolower($t))->all();
        $variants = Page::where('title', 'like', 'Сеансы%')->get()
            ->filter(fn ($p) => ! in_array(mb_strtolower($p->title), $canonical, true));
        foreach ($variants as $page) {
            $this->movePage($page, $children['seansy'], null, $dry);
        }
        $this->info('Ранних вариантов указателей сеансов: '.$variants->count().'.');

        // 5. Проекты (главный сайт): подразделы по названиям проектов.
        // Кандидаты — страницы корня «Проектов» и «Проект…» из «Статей»
        // (страницы курсов и вики не трогаем). Не-проектные (мемориальные
        // и пр., без «проект» в названии) остаются в корне.
        $projects = Section::root()->where('slug', 'projects')->first();
        if ($projects) {
            $candidates = Page::where('section_id', $projects->id)->get()
                ->concat(Page::where('section_id', $articles->id)->where('title', 'like', 'Проект%')->get());

            $byProject = 0;
            foreach ($candidates as $page) {
                if (! preg_match('/проект/ui', $page->title)) {
                    continue;
                }
                foreach ($this->projectRules as [$slug, $title, $position, $re]) {
                    if (preg_match($re, $page->title)) {
                        $child = $this->ensureChild($projects, $slug, $title, $position, $dry);
                        $byProject += $this->movePage($page, $child, null, $dry);

                        break;
                    }
                }
            }
            $this->info("Страниц проектов разложено по подразделам: {$byProject}.");
        }

        $this->info(($dry ? '[dry] ' : '').'Структура применена.');

        return self::SUCCESS;
    }

    /**
     * Подготовка к пере-импорту: «Внеземные Цивилизации (ВЦ)» должна опознаваться
     * вики-импортёром (--refresh ищет по source_type=archive_wiki + title).
     */
    private function preImportFixes(bool $dry): int
    {
        $vc = Page::where('slug', 'vnezemnye-tsivilizatsii')
            ->orWhere('title', 'Внеземные Цивилизации (ВЦ)')
            ->first();

        if (! $vc) {
            $this->warn('Страница «Внеземные Цивилизации (ВЦ)» не найдена — импортёр создаст новую.');

            return self::SUCCESS;
        }

        $sourceUrl = 'https://web.archive.org/web/2015/http://www.x-intellect.org/wiki/index.php?title='
            .rawurlencode(str_replace(' ', '_', 'Внеземные Цивилизации (ВЦ)'));

        $this->line("ВЦ: id {$vc->id}, source_type {$vc->source_type} → archive_wiki");
        if (! $dry) {
            $vc->forceFill(['source_type' => 'archive_wiki', 'source_url' => $sourceUrl])->saveQuietly();
        }

        return self::SUCCESS;
    }

    private function ensureChild(Section $root, string $slug, string $title, int $position, bool $dry): Section
    {
        $existing = Section::where('slug', $slug)->first();
        if ($existing) {
            if ($existing->parent_id !== $root->id) {
                $this->warn("Раздел /{$slug} существует вне {$root->slug} — не трогаю.");
            }

            return $existing;
        }

        if ($dry) {
            $this->line("[dry] создать подраздел {$root->slug} → {$title} (/{$slug})");

            return new Section(['slug' => $slug, 'title' => $title, 'parent_id' => $root->id]);
        }

        return Section::create([
            'parent_id' => $root->id,
            'title' => $title,
            'slug' => $slug,
            'position' => $position,
            'is_visible' => true,
            'show_on_home' => false,
        ]);
    }

    /**
     * Переносит страницу в подраздел; при смене публичного URL пишет 301 и правит старые редиректы.
     *
     * Команда раскладывает только структуру (раздел + позиция). Видимость
     * («Показывать в списках») — решение редактора в админке, команда её не
     * трогает: иначе каждый прогон молча перетирал бы ручные правки.
     */
    private function movePage(Page $page, Section $target, ?int $position, bool $dry): int
    {
        if (! $target->exists) {
            return 0; // dry-run: подраздел ещё не создан
        }

        $oldUrl = $page->url();
        $changes = [];
        if ($page->section_id !== $target->id) {
            $changes['section_id'] = $target->id;
        }
        if ($position !== null && $page->position !== $position) {
            $changes['position'] = $position;
        }
        if ($changes === []) {
            return 1;
        }

        if ($dry) {
            $this->line("[dry] «{$page->title}» → /{$target->slug}");

            return 1;
        }

        $page->forceFill($changes)->saveQuietly();
        $newUrl = $page->fresh()->url();

        if ($newUrl !== $oldUrl) {
            // страница сменила корневой раздел — сохранить старый адрес
            Redirect::updateOrCreate(
                ['from_path' => $oldUrl],
                ['to_url' => $newUrl, 'status_code' => 301, 'comment' => 'Перенос в подраздел: '.Str::limit($page->title, 50)],
            );
            Redirect::where('to_url', $oldUrl)->where('from_path', '!=', $oldUrl)
                ->update(['to_url' => $newUrl]);
            $this->line("URL изменился: {$oldUrl} → {$newUrl} (301 создан)");
        }

        return 1;
    }
}
