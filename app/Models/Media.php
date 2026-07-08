<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Media extends Model
{
    protected $table = 'media';

    protected $fillable = [
        'page_id', 'type', 'title', 'file_path', 'disk',
        'mime', 'size', 'duration', 'position',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Публичный URL файла: локальный диск public либо внешнее
     * S3-совместимое хранилище — код одинаков (Laravel Filesystem).
     */
    public function url(): string
    {
        if (Str::startsWith($this->file_path, ['http://', 'https://'])) {
            return $this->file_path;
        }

        return Storage::disk($this->disk)->url($this->file_path);
    }

    public function durationLabel(): ?string
    {
        if (! $this->duration) {
            return null;
        }

        return $this->duration >= 3600
            ? gmdate('G:i:s', $this->duration)
            : gmdate('i:s', $this->duration);
    }
}
