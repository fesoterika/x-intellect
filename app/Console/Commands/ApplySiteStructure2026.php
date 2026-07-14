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

        // 4. Стенограммы сеансов → подраздел «Сеансы», скрыты из списков/меню
        $transcripts = Page::where('title', 'like', 'Сеанс с _илами%')->get()
            ->filter(fn ($p) => preg_match('/^Сеанс с [Сс]илами/u', $p->title));
        foreach ($transcripts as $page) {
            $this->movePage($page, $children['seansy'], null, $dry, listed: false);
        }
        $this->info('Стенограмм в подразделе «Сеансы» (скрыты из списков): '.$transcripts->count().'.');

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

    /** Переносит страницу в подраздел; при смене публичного URL пишет 301 и правит старые редиректы. */
    private function movePage(Page $page, Section $target, ?int $position, bool $dry, bool $listed = true): int
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
        if ($page->is_listed !== $listed) {
            $changes['is_listed'] = $listed;
        }
        if ($changes === []) {
            return 1;
        }

        if ($dry) {
            $this->line("[dry] «{$page->title}» → /{$target->slug}".($listed ? '' : ' (unlisted)'));

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
