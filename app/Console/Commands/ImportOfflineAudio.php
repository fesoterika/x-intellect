<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\Page;
use DOMDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Импорт аудио (Фаза C): привязка mp3 из files/audio/** к вики-страницам
 * сеансов и к «Приветствию».
 *
 *   php artisan import:offline-audio {wiki-dir} [--dry]
 *
 * Источник привязки — сама архивная страница: в её HTML есть ссылка вида
 * «../files/audio/…​.mp3». По заголовку страницы находим уже импортированную
 * страницу в БД (раздел Вики или hello), копируем файл в storage и заводим
 * Media(type=audio). Идемпотентно.
 */
class ImportOfflineAudio extends Command
{
    protected $signature = 'import:offline-audio {archive} {--dry}';

    protected $description = 'Импорт аудио: привязка mp3 к вики-страницам сеансов и «Приветствию»';

    public function handle(): int
    {
        $base = rtrim($this->argument('archive'), '/');
        if (! File::isDirectory($base)) {
            $this->error("Не найдено: {$base}");

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry');

        $files = collect(File::glob($base.'/index.php@title=*'))
            ->reject(fn ($f) => str_contains($f, '&'))
            ->reject(fn ($f) => (bool) preg_match('/\.(png|jpe?g|gif|svg|mp3|pdf|css|js|tmp|ico|webp|bmp)$/i', $f));

        $attached = 0;
        $tracks = 0;
        $missingFile = 0;
        $noPage = 0;
        $seenPages = [];

        foreach ($files as $file) {
            $html = @File::get($file);
            if (! $html || (preg_match('/"wgNamespaceNumber":(-?\d+)/', $html, $m) && $m[1] !== '0')) {
                continue;
            }

            if (! preg_match_all('#(?:\.\./)*files/audio/[^\s"\'<>]+?\.mp3#i', $html, $mm)) {
                continue;
            }
            $refs = array_values(array_unique($mm[0]));

            $title = $this->titleOf($html);
            if ($title === null) {
                continue;
            }

            $page = $this->pageFor($title);
            if (! $page) {
                $noPage++;
                $this->line("нет страницы для: {$title}");

                continue;
            }

            foreach ($refs as $ref) {
                $real = realpath($base.'/'.$ref);
                if ($real === false || ! is_file($real)) {
                    $missingFile++;

                    continue;
                }

                $name = basename($real);
                if ($dry) {
                    $this->line(sprintf('[%s] ← %s', Str::limit($title, 45), $name));
                    $tracks++;

                    continue;
                }

                $dest = 'media/audio/archive/'.$name;
                if (! Storage::disk('public')->exists($dest)) {
                    // потоковое копирование — аудио бывает крупным, не грузим целиком в память
                    $stream = @fopen($real, 'rb');
                    if ($stream === false) {
                        $missingFile++;

                        continue;
                    }
                    Storage::disk('public')->writeStream($dest, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                $media = Media::firstOrCreate(
                    ['page_id' => $page->id, 'file_path' => $dest],
                    ['type' => 'audio', 'title' => $this->trackTitle($title, $name), 'disk' => 'public'],
                );
                if ($media->wasRecentlyCreated) {
                    $tracks++;
                }
            }

            if (! isset($seenPages[$page->id])) {
                $seenPages[$page->id] = true;
                $attached++;
            }
        }

        $this->newLine();
        $this->info("Готово. Страниц с аудио: {$attached}, дорожек: {$tracks}.");
        if ($noPage || $missingFile) {
            $this->comment("Без страницы в БД: {$noPage}; отсутствующих файлов: {$missingFile}.");
        }

        return self::SUCCESS;
    }

    private function titleOf(string $html): ?string
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        $h = $doc->getElementById('firstHeading');

        return $h ? (trim(preg_replace('/\s+/u', ' ', $h->textContent)) ?: null) : null;
    }

    /** Ищем импортированную страницу: сначала вики по заголовку, затем «Приветствие». */
    private function pageFor(string $title): ?Page
    {
        $page = Page::where('source_type', 'archive_wiki')->where('title', $title)->first();
        if ($page) {
            return $page;
        }

        // приветственные сеансы дополнительно можно было бы вешать на hello —
        // но у каждого сеанса своя вики-страница, поэтому вручную только явное «Приветствие»
        if (Str::contains(mb_strtolower($title), 'приветствие')) {
            return Page::whereHas('section', fn ($q) => $q->where('slug', 'hello'))->first();
        }

        return null;
    }

    private function trackTitle(string $pageTitle, string $file): string
    {
        // человекочитаемое имя дорожки
        $stem = pathinfo($file, PATHINFO_FILENAME);

        return Str::limit($pageTitle, 60, '').' — '.$stem;
    }
}
