<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use App\Services\ArchiveHtmlCleaner;
use App\Services\AudioLibrary;
use App\Services\MediaWikiArchive;
use App\Services\SferaRazumaArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Импорт аудиозаписей проекта «Сфера Разума» (до 2012, вёл А. Г. Глаз).
 *
 *   php artisan import:sferarazuma {--dry} {--audio-dir=*} {--only=}
 *
 * Аудио — из папок архива Дмитрия Морозова («…/Аудиозаписи контактов/Новое» и
 * «…/Аудио контакты 93-94»). Описания и метаданные — из вики «Сферы Разума» в
 * веб-архиве (снимок до конца 2012). Если описания в архиве нет — страница-
 * заглушка с максимумом данных из имени файла. Все страницы получают
 * source_type=archive_sferarazuma → бейдж «Сфера Разума (до 2012)».
 *
 * Идемпотентна: запись, уже представленная на сайте (по дате), пропускается.
 */
class ImportSferaRazuma extends Command
{
    protected $signature = 'import:sferarazuma {--dry} {--audio-dir=*} {--only=}';

    protected $description = 'Аудио «Сферы Разума» (до 2012) с описаниями из веб-архива';

    private const NOTE = 'Материал из архива проекта «Сфера Разума», который вёл А. Г. Глаз до 2012 года. '
        .'Аудиозапись — из архива проекта Николая Морозова; описание, если есть, взято из веб-архива вики «Сферы Разума».';

    private const MONTHS = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля', 5 => 'мая', 6 => 'июня',
        7 => 'июля', 8 => 'августа', 9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    public function handle(SferaRazumaArchive $sfera, ArchiveHtmlCleaner $cleaner, MediaWikiArchive $mw): int
    {
        $roots = (array) ($this->option('audio-dir') ?: config('archive.sfera_audio_dirs', []));
        $roots = array_values(array_filter($roots, 'is_dir'));
        if (! $roots) {
            $this->error('Нет папок с аудио «Сферы Разума». Задайте --audio-dir или archive.sfera_audio_dirs.');

            return self::FAILURE;
        }

        $library = (new AudioLibrary($roots))->build();
        $records = $this->folderRecords($roots);
        $this->info('Аудиозаписей в папках: '.count($records));

        $catalogue = $sfera->transcriptPages();
        $this->info('Страниц-стенограмм в архиве «Сферы Разума»: '.count($catalogue));

        $section = Section::where('slug', 'seansy')->first() ?? Section::where('slug', 'wiki')->first();
        $dry = (bool) $this->option('dry');
        $only = trim((string) $this->option('only'));

        $created = 0;
        $withDesc = 0;
        $stubs = 0;
        $skipped = 0;

        foreach ($records as $rec) {
            if ($only !== '' && $rec['key'] !== $only) {
                continue;
            }
            // Запись уже на сайте как обычный сеанс/материал — не дублируем
            if ($this->alreadyOnSite($rec['key'])) {
                $skipped++;

                continue;
            }
            // Уже импортирована этой командой — идемпотентность (иначе повторный
            // прогон плодит дубли: alreadyOnSite намеренно исключает свой тип)
            $existing = Page::where('source_type', 'archive_sferarazuma')
                ->where('title', 'like', '%'.$rec['key'].'%')->first();
            if ($existing) {
                $this->attachAudio($existing, $rec, $library); // добьём аудио, если чего-то не было
                $skipped++;

                continue;
            }

            $catKey = $sfera->normalizeKey($rec['key']);
            $page = isset($catalogue[$catKey])
                ? $sfera->parsePage($catalogue[$catKey], $cleaner)
                : null;

            [$title, $body, $hasDesc] = $this->composePage($rec, $page);

            $this->line(sprintf('+ %-40s %s ← %s', Str::limit($title, 40),
                $hasDesc ? '[описание]' : '[заглушка]', $rec['file']));
            $hasDesc ? $withDesc++ : $stubs++;
            $created++;

            if ($dry) {
                continue;
            }

            $model = Page::create([
                'section_id' => $section?->id,
                'title' => $title,
                'slug' => $mw->uniqueSlug($title, Page::class),
                'body' => $body,
                'status' => 'draft',
                'is_listed' => false,
                'source_type' => 'archive_sferarazuma',
                'source_url' => isset($catalogue[$catKey])
                    ? 'https://web.archive.org/web/2012/http://sferarazuma.ru/wiki/index.php/'.rawurlencode($catalogue[$catKey])
                    : null,
                'archived_at' => $this->recordDate($rec['key']),
                'published_at' => $this->recordDate($rec['key']),
            ]);

            $this->attachAudio($model, $rec, $library);

            // 301 со старого адреса SphereWiki, если страница там была
            if (isset($catalogue[$catKey])) {
                foreach ([$catalogue[$catKey], str_replace(' ', '_', $catalogue[$catKey])] as $old) {
                    Redirect::updateOrCreate(
                        ['from_path' => '/wiki/index.php/'.$old],
                        ['to_url' => $model->url(), 'status_code' => 301, 'comment' => 'Сфера Разума: '.Str::limit($title, 40)],
                    );
                }
            }
        }

        $this->newLine();
        $this->info(($dry ? '[dry] ' : '')."Создано: {$created} (с описанием {$withDesc}, заглушек {$stubs}). Уже на сайте: {$skipped}.");
        $this->comment('Страницы — черновики, бейдж «Сфера Разума (до 2012)». Публикация вручную.');

        return self::SUCCESS;
    }

    /** Заголовок, тело и признак «описание из архива». */
    private function composePage(array $rec, ?array $page): array
    {
        $dateText = $this->humanDate($rec['key']);

        // Заголовок по приоритету: тема из заголовка архива → проект из его меты
        // → описание из скобок имени файла → общий вид. Всегда с датой в скобках.
        $name = $page['title'] ?? '';
        if ($name === '' && $page) {
            $name = $page['meta']['Проект'] ?? '';
        }
        if ($name === '') {
            $name = $rec['desc'];
        }
        $title = $name !== '' ? $name.' ('.$rec['key'].')' : 'Ченнелинг '.$rec['key'];
        $title = Str::limit(trim($title), 150, '');

        $note = '<p><em>'.self::NOTE.'</em></p>';

        if ($page && Str::length(strip_tags($page['body'])) >= 40) {
            // Метаданные из архива + дата; вводный список из тела уже вырезан
            $rows = array_merge(['Дата' => $dateText], $page['meta']);

            return [$title, $note.$this->metaTable($rows).$page['body'], true];
        }

        // Заглушка: максимум из имени файла
        $rows = ['Дата' => $dateText];
        if ($rec['desc'] !== '') {
            $rows['Тема'] = $rec['desc'];
        }
        $stub = $note.$this->metaTable($rows)
            .'<p><strong>Стенограмма</strong></p>'
            .'<p>Текстовая стенограмма не сохранилась. Сохранилась аудиозапись — она ниже.</p>';

        return [$title, $stub, false];
    }

    private function metaTable(array $rows): string
    {
        $html = '<table><tbody>';
        foreach ($rows as $k => $v) {
            $html .= '<tr><td><strong>'.e($k).':</strong></td><td>'.e($v).'</td></tr>';
        }

        return $html.'</tbody></table>';
    }

    private function attachAudio(Page $page, array $rec, AudioLibrary $library): void
    {
        // Сам файл записи (его путь уже известен) + при наличии другие дорожки той
        // же даты из библиотеки. byDateKey не годится единственным источником: он
        // индексирует только mp3 и матчит по «20…» в имени, а тут бывают .wav и
        // файлы «93-94» с именем 24-12-93 (ключ 19931224 в имени не встречается).
        $paths = [$rec['path'] => true];
        foreach ($library->byDateKey($rec['key']) as $track) {
            $paths[$track['path']] = true;
        }

        $pos = 0;
        foreach (array_keys($paths) as $path) {
            if (! is_file($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'mp3';
            $dest = 'media/audio/archive/'.pathinfo($path, PATHINFO_FILENAME)
                .'-'.substr(sha1($path), 0, 8).'.'.$ext;
            if (! Storage::disk('public')->exists($dest)) {
                $stream = @fopen($path, 'rb');
                if ($stream === false) {
                    continue;
                }
                Storage::disk('public')->writeStream($dest, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            Media::firstOrCreate(
                ['page_id' => $page->id, 'file_path' => $dest],
                ['type' => 'audio', 'title' => $page->title, 'disk' => 'public',
                    'mime' => $ext === 'wav' ? 'audio/wav' : 'audio/mpeg',
                    'size' => filesize($path), 'position' => $pos++],
            );
        }
    }

    /**
     * Записи из папок: дата-ключ, описание из скобок, путь.
     *
     * @return array<int, array{key: string, file: string, desc: string, path: string}>
     */
    private function folderRecords(array $roots): array
    {
        $out = [];
        $seen = [];
        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (! $f->isFile() || ! preg_match('/\.(mp3|wav)$/i', $f->getFilename())) {
                    continue;
                }
                $name = $f->getFilename();
                $key = $this->keyOf($name);
                if ($key === null || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                // Жадно до последней «)»: у имён бывают вложенные скобки
                // («20081115 (Сфера Разума. Глава 1 (начало)).mp3»)
                $desc = preg_match('/\((.+)\)/u', $name, $m) ? trim($m[1]) : '';
                $out[] = ['key' => $key, 'file' => $name, 'desc' => $desc, 'path' => $f->getPathname()];
            }
        }
        usort($out, fn ($a, $b) => $a['key'] <=> $b['key']);

        return $out;
    }

    /** Ключ-дата: 20070730a как есть; 24-12-93 → 19931224. */
    private function keyOf(string $name): ?string
    {
        if (preg_match('/((?:19|20)\d{6}[a-zA-Z]?)/', $name, $m)) {
            return $m[1];
        }
        if (preg_match('/(\d{2})-(\d{2})-(\d{2})/', $name, $m)) {
            $year = ((int) $m[3] >= 90 ? 1900 : 2000) + (int) $m[3];

            return sprintf('%04d%02d%02d', $year, (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    private function alreadyOnSite(string $key): bool
    {
        return Page::where('title', 'like', '%'.$key.'%')
            ->where('source_type', '!=', 'archive_sferarazuma')
            ->exists()
            || Media::whereRaw("file_path LIKE ?", ['%'.substr($key, 0, 8).'%'])
                ->whereHas('page', fn ($q) => $q->where('source_type', '!=', 'archive_sferarazuma'))
                ->exists();
    }

    private function recordDate(string $key): ?string
    {
        if (! preg_match('/^((?:19|20)\d{2})(\d{2})(\d{2})/', $key, $m)) {
            return null;
        }

        return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
    }

    private function humanDate(string $key): string
    {
        if (! preg_match('/^((?:19|20)\d{2})(\d{2})(\d{2})/', $key, $m)) {
            return $key;
        }

        return sprintf('%d %s %d года', (int) $m[3], self::MONTHS[(int) $m[2]] ?? '', (int) $m[1]);
    }
}
