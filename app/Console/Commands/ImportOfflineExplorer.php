<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use App\Services\ArchiveHtmlCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Импорт локального офлайн-слепка x-intellect.org (Offline Explorer).
 * Фаза A: основной сайт (WordPress, папки со страницами default.htm) → черновики.
 *
 *   php artisan import:offline-explorer {archive} [--limit=N] [--dry]
 *
 * archive — путь к www.x-intellect.org/www.x-intellect.org.
 * Создаёт раздел «Проекты», раскладывает статьи по разделам, чистит HTML
 * до форматирования редактора, транслитерирует slug, пишет 301 со старого
 * адреса. Публикация — вручную после вычитки.
 */
class ImportOfflineExplorer extends Command
{
    protected $signature = 'import:offline-explorer {archive} {--limit=0} {--dry}';

    protected $description = 'Импорт основного сайта из офлайн-слепка (черновики)';

    /** Слаги/темы, которые не импортируем (сервис, служебное, дубли лендингов). */
    private array $skipSlugs = [
        'www.x-intellect.org', 'feed', 'api', 'params', 'image', 'rt=j', '$xd',
        'files', 'map', 'support', 'svetlanaglaz', 'meteor-slides', 'comments',
        'soul-1', 'page', 'embed', 'category', 'glaz', '_3a', 'socialhost_3a',
        'wp-includes', 'tag', 'contacts', 'foto_on_slider', 'skype', 'test',
        'kotox', 'emmir', 'magur7', 'alexavan', 'galateia', 'projects', 'library',
        'courses', 'courses-arc', 'rules', 'forum', '2012', '2013', 'thank_you',
        'ny-2012-2013', 'privet-mir', 'slidenoborders', 'metodologia', 'mission',
    ];

    public function handle(ArchiveHtmlCleaner $cleaner): int
    {
        $base = rtrim($this->argument('archive'), '/');
        if (! File::isDirectory($base)) {
            $this->error("Не найдено: {$base}");

            return self::FAILURE;
        }

        $this->ensureProjectsSection();
        $sections = Section::pluck('id', 'slug');

        $files = collect(File::glob($base.'/*/default.htm'));
        $limit = (int) $this->option('limit');
        $dry = $this->option('dry');

        $created = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $slug = basename(dirname($file));
            $slugKey = mb_strtolower($slug);

            if (in_array($slugKey, $this->skipSlugs, true) || Str::startsWith($slugKey, 'contact-form')) {
                $skipped++;

                continue;
            }

            $html = File::get($file);
            $title = $this->extractTitle($html);
            if ($title === null || $this->isJunkTitle($title)) {
                $skipped++;

                continue;
            }

            $sectionSlug = $this->sectionFor($slugKey, $title);
            if ($sectionSlug === null) {
                $skipped++;

                continue;
            }

            $oldUrl = '/'.$slug;
            if (Page::where('source_url', 'like', '%'.$oldUrl.'%')->exists()) {
                $skipped++;

                continue; // уже импортировано
            }

            $this->line(sprintf('[%s] %s  (%s)', $sectionSlug, Str::limit($title, 60), $slug));

            if ($dry) {
                $created++;

                continue; // в сухом прогоне тело не извлекаем (не копируем картинки)
            }

            $body = $this->extractBody($html, dirname($file), $cleaner);
            if (Str::length(strip_tags($body)) < 40) {
                $skipped++;

                continue; // пусто/заглушка
            }

            $newSlug = $this->uniqueSlug($slug, $title);
            $page = Page::create([
                'section_id' => $sections[$sectionSlug] ?? null,
                'title' => $title,
                'slug' => $newSlug,
                'body' => $body,
                'status' => 'draft',
                'source_type' => 'archive_xintellect',
                // «Архивная копия» ведёт на снимок в Wayback Machine (не на мёртвый оригинал)
                'source_url' => 'https://web.archive.org/web/2015/http://www.x-intellect.org'.$oldUrl.'/',
            ]);

            // 301 со старого плоского адреса на новый
            Redirect::updateOrCreate(
                ['from_path' => $oldUrl],
                ['to_url' => $page->url(), 'status_code' => 301, 'comment' => 'Архив: '.Str::limit($title, 60)],
            );

            $created++;
        }

        $this->newLine();
        $this->info("Готово. Черновиков создано: {$created}, пропущено: {$skipped}.");
        $this->info("Картинок скопировано: {$cleaner->imagesCopied}, внешних/битых убрано: {$cleaner->imagesDropped}.");
        $this->comment('Все страницы — черновики. Публикация после вычитки в админке.');

        return self::SUCCESS;
    }

    private function ensureProjectsSection(): void
    {
        Section::firstOrCreate(
            ['slug' => 'projects'],
            [
                'title' => 'Проекты',
                'position' => 3,
                'description' => 'Развёрнутые проекты X-Intellect: серии «Ноосфера», «Душа», «Изосфера и параллельные миры», «Мужчина и Женщина», «Картины Учителей Ноосферы» и другие.',
            ],
        );
    }

    private function extractTitle(string $html): ?string
    {
        if (! preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
            return null;
        }
        $t = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/\s*:\s*X\s*[—-]\s*ИНТЕЛЛЕКТ\s*$/u', '', $t);

        return trim(preg_replace('/\s+/u', ' ', $t)) ?: null;
    }

    private function isJunkTitle(string $t): bool
    {
        return (bool) preg_match('/Страница не найдена|File moved|IIS |403|Forbidden|Карта сайта/iu', $t);
    }

    private function extractBody(string $html, string $baseDir, ArchiveHtmlCleaner $cleaner): string
    {
        $crawler = new Crawler($html);
        foreach (['.entry', '.post-content', '.entry-content', 'article', '#content'] as $sel) {
            $node = $crawler->filter($sel);
            if ($node->count() && filled(trim(strip_tags($node->first()->html(''))))) {
                return $cleaner->clean($node->first()->html(''), $baseDir);
            }
        }

        return '';
    }

    private function uniqueSlug(string $folderSlug, string $title): string
    {
        // имена папок WordPress уже латинские — используем их; иначе транслит заголовка
        $slug = preg_match('/^[a-z0-9\-]+$/', $folderSlug) ? $folderSlug : Str::slug($title);
        $slug = $slug ?: 'page';
        $base = $slug;
        $i = 2;
        while (Page::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** Раздел по слагу/заголовку (эвристика, согласована с пользователем). */
    private function sectionFor(string $slug, string $title): ?string
    {
        $t = mb_strtolower($title);

        $has = fn (string $re) => (bool) preg_match('/'.$re.'/u', $t);

        if ($slug === 'hello' || $has('приветствие от представителей')) {
            return 'hello';
        }
        if ($has('связь с представителем|консультац|отзыв')) {
            return null; // сервис — скип
        }
        if ($has('правил|граммат|стенограмм')) {
            return 'rules';
        }
        if ($has('монографи|библиотек')) {
            return 'library';
        }
        if ($has('дайджест')) {
            return 'mag';
        }
        if ($has('кольцо|беседа с силами|взаимодействие и манипул')) {
            return 'courses';
        }
        if ($has('проект|ноосфер|изосфер|душа|картины учител|мужчина и женщина|параллел|биоэкран|целительств|эталониз')) {
            return 'projects';
        }
        if ($has('лотос|талисман|тандем|камни|инкарнац|чистк|частот|деструктивн|целительств')) {
            return 'mag';
        }
        if ($has('глаз|уш[её]л|день рождения|53 года|40 дней|памят|миссия|мнение сил о сайте|ченнелинг')) {
            return 'about';
        }

        return 'mag'; // прочее содержательное → Статьи
    }
}
