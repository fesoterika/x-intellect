<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Media extends Model
{
    /**
     * Разрешённые к загрузке MIME-типы по значению колонки type — единый
     * список для раздела «Медиа» и редактора Trix (правила валидации держим
     * на модели, как Page::SOURCE_TYPES).
     *
     * Файл уходит на public-диск и отдаётся с /storage/… — того же
     * происхождения, что и сайт, а имя в хранилище получает расширение по
     * настоящему MIME. Поэтому список — белый: image/svg+xml и text/html
     * исполняют скрипты в контексте домена, то есть дают XSS.
     */
    public const MIMETYPES = [
        'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'audio' => [
            'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/aac',
            'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/flac',
        ],
        'pdf' => ['application/pdf'],
    ];

    protected $table = 'media';

    protected $fillable = [
        'page_id', 'type', 'title', 'file_path', 'disk',
        'mime', 'size', 'duration', 'position',
    ];

    /**
     * Правило mimetypes: для конкретного типа медиа либо (если тип не задан
     * или неизвестен) для всех разрешённых — неизвестный тип отсеет
     * отдельное правило Rule::in.
     */
    public static function mimetypesRule(?string $type = null): string
    {
        $allowed = self::MIMETYPES[$type] ?? array_merge(...array_values(self::MIMETYPES));

        return 'mimetypes:'.implode(',', $allowed);
    }

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

        // Локальный public-диск отдаём корне-относительно (/storage/…): портабельно
        // между хостами/портами, не зависит от APP_URL (как и картинки архива).
        if ($this->disk === 'public') {
            return '/storage/'.ltrim($this->file_path, '/');
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
