<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\AudioLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Заполнение длительностей аудиодорожек (подпись в плейлисте плеера).
 *
 *   php artisan media:durations [--force] [--dry]
 *
 * Импортёры поле duration не писали — у всех дорожек подпись длительности
 * в плейлисте пустовала. Считает честным обходом кадров mp3 / заголовком WAV
 * (AudioLibrary::duration) и пишет СЕКУНДЫ: durationLabel() форматирует их
 * через gmdate. Идемпотентна: заполненные пропускает (--force — пересчитать).
 */
class FillMediaDurations extends Command
{
    protected $signature = 'media:durations {--force} {--dry}';

    protected $description = 'Посчитать и записать длительности аудио (media.duration, в секундах)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $filled = 0;
        $skipped = 0;
        $missing = 0;
        $failed = 0;

        foreach (Media::where('type', 'audio')->orderBy('id')->get() as $media) {
            if ($media->duration > 0 && ! $this->option('force')) {
                $skipped++;

                continue;
            }

            $file = Storage::disk($media->disk)->path($media->file_path);
            if (! is_file($file)) {
                $this->warn('Файла нет на диске: '.$media->file_path);
                $missing++;

                continue;
            }

            $seconds = (int) round(AudioLibrary::duration($file) * 60);
            if ($seconds <= 0) {
                $this->warn('Не посчиталось (0 сек): '.$media->file_path);
                $failed++;

                continue;
            }

            $this->line(sprintf('%s → %s', basename($media->file_path), gmdate($seconds >= 3600 ? 'G:i:s' : 'i:s', $seconds)));
            if (! $dry) {
                $media->duration = $seconds;
                $media->save();
            }
            $filled++;
        }

        $this->newLine();
        $this->info(($dry ? '[dry] ' : '')."Заполнено: {$filled}, уже было: {$skipped}, без файла: {$missing}, не посчиталось: {$failed}.");

        return self::SUCCESS;
    }
}
