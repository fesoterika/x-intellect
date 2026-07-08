<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\Section;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Модуль импорта архива (Этап 2 плана): берёт сохранённые на Этапе 0
 * HTML-снапшоты Wayback Machine и создаёт черновики страниц.
 * Публикация — только вручную: автопарсинг архивного HTML почти
 * всегда требует вычитки редактором (Этап 7).
 *
 * Примеры:
 *   php artisan import:archive storage/archive/wiki --section=wiki
 *   php artisan import:archive storage/archive/sfera --section=wiki --source-type=archive_sferarazuma
 */
class ImportArchive extends Command
{
    protected $signature = 'import:archive
        {path : Папка с HTML-файлами архива}
        {--section=wiki : Slug раздела, куда складывать черновики}
        {--source-type=archive_xintellect : archive_xintellect | archive_sferarazuma}
        {--source-url-prefix= : Префикс архивного URL для поля source_url}';

    protected $description = 'Импортировать HTML-снапшоты архива как черновики страниц';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! File::isDirectory($path)) {
            $this->error("Папка не найдена: {$path}");

            return self::FAILURE;
        }

        $section = Section::where('slug', $this->option('section'))->first();

        if (! $section) {
            $this->error('Раздел не найден: '.$this->option('section'));

            return self::FAILURE;
        }

        $files = collect(File::allFiles($path))
            ->filter(fn ($f) => in_array(strtolower($f->getExtension()), ['html', 'htm']));

        $created = 0;

        foreach ($files as $file) {
            $html = File::get($file->getPathname());
            $crawler = new Crawler($html);

            $title = $this->extractTitle($crawler, $file->getFilenameWithoutExtension());
            $body = $this->extractBody($crawler);

            if (blank($body)) {
                $this->warn("Пропущен (пустое тело): {$file->getFilename()}");

                continue;
            }

            if (Page::where('title', $title)->where('section_id', $section->id)->exists()) {
                $this->line("Уже существует: {$title}");

                continue;
            }

            Page::create([
                'section_id' => $section->id,
                'title' => $title,
                'body' => $body,
                'status' => 'draft',
                'source_type' => $this->option('source-type'),
                'source_url' => $this->option('source-url-prefix')
                    ? rtrim($this->option('source-url-prefix'), '/').'/'.$file->getFilename()
                    : null,
            ]);

            $created++;
            $this->line("Черновик: {$title}");
        }

        $this->info("Создано черновиков: {$created} из {$files->count()} файлов. Публикация — вручную после вычитки.");

        return self::SUCCESS;
    }

    protected function extractTitle(Crawler $crawler, string $fallback): string
    {
        foreach (['#firstHeading', 'h1', 'title'] as $selector) {
            $node = $crawler->filter($selector);

            if ($node->count() && filled($node->first()->text(''))) {
                // Убираем хвосты вида « — X-Intellect Wiki»
                return trim(preg_replace('/\s*[—|-]\s*[^—|-]*(wiki|intellect|сфера).*$/ui', '', $node->first()->text()));
            }
        }

        return $fallback;
    }

    protected function extractBody(Crawler $crawler): ?string
    {
        // #mw-content-text / #bodyContent — контейнеры MediaWiki,
        // #content — типовой контейнер, body — крайний случай
        foreach (['#mw-content-text', '#bodyContent', '#content', 'article', 'body'] as $selector) {
            $node = $crawler->filter($selector);

            if (! $node->count()) {
                continue;
            }

            $inner = $node->first()->html('');

            // Чистка мусора Wayback Machine и служебной разметки
            $inner = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $inner);
            $inner = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $inner);
            $inner = preg_replace('/<!--.*?-->/s', '', $inner);

            if (filled(trim(strip_tags($inner)))) {
                return trim($inner);
            }
        }

        return null;
    }
}
