<?php

namespace App\Observers;

use App\Models\Media;
use App\Services\AudioLibrary;
use Illuminate\Support\Facades\Storage;

/**
 * Длительность аудио заполняется сама при каждой загрузке файла: админ-форма
 * «Медиа», редактор Trix и консольные импортёры создают Media — наблюдатель
 * до записи в БД считает секунды честным разбором файла (AudioLibrary).
 *
 * Указанная вручную длительность не перетирается (поле в форме остаётся
 * переопределением). При замене файла у существующей записи (audio:upgrade)
 * длительность пересчитывается; если новый файл не разобрался — обнуляется,
 * чтобы не показывать цифру от старого файла.
 */
class MediaObserver
{
    public function saving(Media $media): void
    {
        if ($media->type !== 'audio' || str_starts_with((string) $media->file_path, 'http')) {
            return;
        }

        $fileReplaced = $media->exists && $media->isDirty('file_path');
        if ($media->duration && ! ($fileReplaced && ! $media->isDirty('duration'))) {
            return;
        }

        $file = Storage::disk($media->disk ?: 'public')->path($media->file_path);
        if (! is_file($file)) {
            return;
        }

        $seconds = (int) round(AudioLibrary::duration($file) * 60);
        if ($seconds > 0) {
            $media->duration = $seconds;
        } elseif ($fileReplaced) {
            $media->duration = null;
        }
    }
}
