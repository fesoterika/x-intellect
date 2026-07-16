<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\Page;
use App\Services\AudioLibrary;
use App\Services\OfflineSnapshotIndex;
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
    protected $signature = 'import:offline-audio {archive} {--dry} {--audio-dir=* : Доп. папки с mp3 в порядке приоритета} {--no-date-match : Не искать mp3 по дате в заголовке}';

    protected $description = 'Импорт аудио: привязка mp3 к вики-страницам сеансов и «Приветствию»';

    private AudioLibrary $library;

    public function handle(OfflineSnapshotIndex $index): int
    {
        $base = rtrim($this->argument('archive'), '/');
        if (! File::isDirectory($base)) {
            $this->error("Не найдено: {$base}");

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry');

        $this->library = (new AudioLibrary($this->audioRoots($base)))->build();
        $this->info('mp3 в библиотеке (слепок + доп. папки): '.$this->library->count());

        $entries = $index->build($base);
        $this->info('Статей в слепке: '.count($entries));

        $attached = 0;
        $tracks = 0;
        $missingFile = 0;
        $noPage = 0;
        $seenPages = [];

        foreach ($entries as $entry) {
            $html = @File::get($entry['path']);
            if (! $html) {
                continue;
            }

            // Ссылка может вести на другой домен архива (OE переписал его в
            // относительный путь: ../../www.sferarazuma.org/files/audio/x.mp3),
            // поэтому не привязываемся к началу пути — ищем сегмент files/audio/.
            if (! preg_match_all('#[^\s"\'<>()]*files/audio/[^\s"\'<>()]+?\.mp3#i', $html, $mm)) {
                continue;
            }
            $refs = array_values(array_unique($mm[0]));

            $title = $entry['title'];
            $page = $this->pageFor($title);
            if (! $page) {
                $noPage++;
                $this->line("нет страницы для: {$title}");

                continue;
            }

            foreach ($refs as $ref) {
                $real = realpath($base.'/'.$ref);
                if ($real === false || ! is_file($real)) {
                    // Файл лежит не там, куда указывает ссылка (чужой домен,
                    // переезд папок) — ищем по имени во всех источниках.
                    $found = $this->library->byName(basename($ref));
                    if ($found === null) {
                        $missingFile++;

                        continue;
                    }
                    $real = $found['path'];
                }

                if ($this->attachTrack($page, $title, $real, $dry)) {
                    $tracks++;
                }
            }

            if (! isset($seenPages[$page->id])) {
                $seenPages[$page->id] = true;
                $attached++;
            }
        }

        // Приветственные аудио → страница «Приветствие» (раздел hello).
        // На старом сайте они играли во flash-плеере (без прямых mp3-ссылок в HTML),
        // поэтому берём их из папки files/audio/privetstvie напрямую.
        $tracks += $this->attachGreetings($base, $dry);

        $byDate = $this->option('no-date-match') ? 0 : $this->attachByDate($dry);

        $this->newLine();
        $this->info("Готово. Страниц с аудио (по ссылкам): {$attached}, дорожек: {$tracks}.");
        if ($byDate) {
            $this->info("Добавлено по дате в заголовке: {$byDate} дорожек.");
        }
        if ($noPage || $missingFile) {
            $this->comment("Без страницы в БД: {$noPage}; отсутствующих файлов: {$missingFile}.");
        }

        return self::SUCCESS;
    }

    /** Папки с mp3 в порядке приоритета: слепок → --audio-dir → config. */
    private function audioRoots(string $wikiDir): array
    {
        $roots = [dirname($wikiDir)];
        $extra = (array) $this->option('audio-dir');
        if (! $extra) {
            $extra = (array) config('archive.audio_dirs', []);
        }

        return array_values(array_filter(array_merge($roots, $extra), 'is_dir'));
    }

    /**
     * Страницы с датой в заголовке, у которых аудио нет: ищем mp3 по дате
     * во всех источниках. Тело страницы не трогаем — только связь Media.
     */
    private function attachByDate(bool $dry): int
    {
        $added = 0;
        $pages = Page::whereIn('source_type', ['archive_wiki', 'archive_xintellect'])
            ->whereDoesntHave('media', fn ($q) => $q->where('type', 'audio'))
            ->get();

        foreach ($pages as $page) {
            $key = AudioLibrary::dateKeyOf($page->title);
            if ($key === null) {
                continue;
            }
            foreach ($this->library->byDateKey($key) as $file) {
                if ($this->attachTrack($page, $page->title, $file['path'], $dry)) {
                    $added++;
                    $this->line(sprintf('[по дате] %s ← %s (%s)',
                        Str::limit($page->title, 45), $file['name'], $file['source']));
                }
            }
        }

        return $added;
    }

    /** Копирует mp3 в storage и заводит Media. Идемпотентно (по page_id+file_path). */
    private function attachTrack(Page $page, string $title, string $real, bool $dry): bool
    {
        $name = basename($real);
        if ($dry) {
            $this->line(sprintf('[%s] ← %s', Str::limit($title, 45), $name));

            return true;
        }

        $dest = 'media/audio/archive/'.$name;
        // Разные файлы с одинаковым именем в разных папках не должны затирать
        // друг друга: при коллизии имени с другим размером — суффикс по хэшу пути.
        if (Storage::disk('public')->exists($dest)
            && Storage::disk('public')->size($dest) !== filesize($real)) {
            $dest = 'media/audio/archive/'.pathinfo($name, PATHINFO_FILENAME)
                .'-'.substr(sha1($real), 0, 8).'.mp3';
        }

        if (! Storage::disk('public')->exists($dest)) {
            // потоковое копирование — аудио бывает крупным, не грузим целиком в память
            $stream = @fopen($real, 'rb');
            if ($stream === false) {
                return false;
            }
            Storage::disk('public')->writeStream($dest, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $media = Media::firstOrCreate(
            ['page_id' => $page->id, 'file_path' => $dest],
            ['type' => 'audio', 'title' => $this->trackTitle($title, $name), 'disk' => 'public',
                'mime' => 'audio/mpeg', 'size' => filesize($real)],
        );

        return $media->wasRecentlyCreated;
    }

    /** Привязывает files/audio/privetstvie/*.mp3 к странице «Приветствие». */
    private function attachGreetings(string $wikiDir, bool $dry): int
    {
        $dir = dirname($wikiDir).'/files/audio/privetstvie';
        if (! is_dir($dir)) {
            return 0;
        }
        $hello = Page::whereHas('section', fn ($q) => $q->where('slug', 'hello'))
            ->where('title', 'like', '%риветствие%')->first();
        if (! $hello) {
            return 0;
        }

        $files = glob($dir.'/*.mp3');
        sort($files);
        $n = 0;
        $pos = 0;
        foreach ($files as $f) {
            $name = basename($f);
            if ($dry) {
                $this->line(sprintf('[Приветствие] ← %s', $name));
                $n++;
                $pos++;

                continue;
            }
            $dest = 'media/audio/archive/'.$name;
            if (! Storage::disk('public')->exists($dest)) {
                $stream = @fopen($f, 'rb');
                if ($stream === false) {
                    continue;
                }
                Storage::disk('public')->writeStream($dest, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $m = Media::firstOrCreate(
                ['page_id' => $hello->id, 'file_path' => $dest],
                ['type' => 'audio', 'title' => 'Приветствие — '.pathinfo($name, PATHINFO_FILENAME), 'disk' => 'public', 'position' => $pos],
            );
            if ($m->wasRecentlyCreated) {
                $n++;
            }
            $pos++;
        }

        return $n;
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
