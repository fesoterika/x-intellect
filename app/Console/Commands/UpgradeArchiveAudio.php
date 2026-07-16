<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\AudioLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Замена привязанных аудиозаписей более полными копиями из папок архива.
 *
 *   php artisan audio:upgrade {archive} [--dry] [--audio-dir=*] [--min-gain=0.5]
 *
 * Зачем отдельно от import:offline-audio: тот привязывает дорожку по ссылке из
 * самой статьи, то есть берёт копию из офлайн-слепка как есть. А слепок местами
 * обрезан — у 20081111 там 22 минуты против 74 в «Сайт и файлы + аудио». Здесь
 * каждая уже привязанная дорожка сверяется со всеми источниками, и если
 * где-то запись длиннее, файл заменяется.
 *
 * Тело страницы не трогаем — меняется только файл в media.
 */
class UpgradeArchiveAudio extends Command
{
    protected $signature = 'audio:upgrade {archive} {--dry} {--audio-dir=*} {--min-gain=0.5}';

    protected $description = 'Заменяет привязанные аудио более полными копиями из папок архива';

    public function handle(): int
    {
        $base = rtrim($this->argument('archive'), '/');
        $roots = array_values(array_filter(
            array_merge([$base], (array) ($this->option('audio-dir') ?: config('archive.audio_dirs', []))),
            'is_dir'
        ));
        if (! $roots) {
            $this->error("Нет ни одной папки-источника: {$base}");

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');
        $minGain = (float) $this->option('min-gain');
        $library = (new AudioLibrary($roots))->build();
        $this->info('mp3 в библиотеке: '.$library->count().' (папок: '.count($roots).')');

        $upgraded = 0;
        $gain = 0.0;
        $checked = 0;
        $noSource = 0;

        foreach (Media::with('page')->where('type', 'audio')->orderBy('id')->get() as $media) {
            $current = Storage::disk($media->disk)->path($media->file_path);
            if (! is_file($current)) {
                continue;
            }
            $checked++;

            $best = $this->bestFor($media, $library);
            if ($best === null) {
                $noSource++;

                continue;
            }

            $now = AudioLibrary::duration($current);
            if ($best['duration'] <= $now + $minGain) {
                continue;
            }

            $this->line(sprintf('%-46s %5.1f → %5.1f мин  (+%.1f) ← %s / %s',
                Str::limit($media->page?->title ?? $media->title, 44),
                $now, $best['duration'], $best['duration'] - $now, $best['source'], $best['name']));

            $gain += $best['duration'] - $now;
            $upgraded++;

            if ($dry) {
                continue;
            }

            // Имя по хэшу исходного пути: детерминированно, повторный прогон
            // не плодит копий, а старый файл остаётся у других дорожек, если он им нужен.
            $dest = 'media/audio/archive/'.pathinfo($best['name'], PATHINFO_FILENAME)
                .'-'.substr(sha1($best['path']), 0, 8).'.mp3';
            if (! Storage::disk('public')->exists($dest)) {
                $stream = @fopen($best['path'], 'rb');
                if ($stream === false) {
                    continue;
                }
                Storage::disk('public')->writeStream($dest, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $media->update(['file_path' => $dest, 'size' => filesize($best['path']), 'disk' => 'public']);
        }

        $this->newLine();
        $this->info(sprintf('Проверено дорожек: %d. Заменено: %d (прибавка %.0f мин). Без копии в папках: %d.',
            $checked, $upgraded, $gain, $noSource));
        if ($dry) {
            $this->comment('Это --dry: ничего не заменено.');
        }

        return self::SUCCESS;
    }

    /** Лучшая копия этой дорожки: по дате в заголовке, иначе по имени файла. */
    private function bestFor(Media $media, AudioLibrary $library): ?array
    {
        $name = basename($media->file_path);
        $key = AudioLibrary::dateKeyOf($media->page?->title ?? '') ?? AudioLibrary::dateKeyOf($name);

        if ($key !== null) {
            $variant = AudioLibrary::variantOf($name, $key);
            $tracks = $library->byDateKey($key);
            // Та же дорожка, а не соседняя запись той же даты (GOL против ZEL)
            foreach ($tracks as $track) {
                if ($track['variant'] === $variant) {
                    return $track;
                }
            }
            if (count($tracks) === 1 && $variant === '') {
                return $tracks[0];
            }

            return null;
        }

        return $library->byName($name);
    }
}
