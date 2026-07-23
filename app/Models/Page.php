<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    public const SOURCE_TYPES = [
        'archive_sferarazuma' => 'Сфера Разума (до 2012)',
        'archive_xintellect'  => 'Архив X-Intellect',
        'archive_wiki'        => 'Архив вики',
        'new'                 => 'Новый материал',
    ];

    /**
     * Причина изменения из формы правки: PageObserver кладёт её в создаваемую
     * ревизию. Объявлена настоящим свойством, иначе Eloquent примет её за
     * атрибут и попытается сохранить в несуществующую колонку pages.
     */
    public ?string $revisionReason = null;

    protected $fillable = [
        'section_id', 'title', 'slug', 'excerpt', 'disclaimer', 'body', 'body_rendered',
        'page_type', 'status', 'is_listed', 'in_wiki_menu', 'is_pinned', 'source_type', 'source_url', 'seo',
        'position', 'published_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'seo' => 'array',
            'is_listed' => 'boolean',
            'in_wiki_menu' => 'boolean',
            'is_pinned' => 'boolean',
            'published_at' => 'datetime',
            'archived_at' => 'date',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class)->orderBy('position');
    }

    public function audio(): HasMany
    {
        return $this->media()->where('type', 'audio');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevision::class)->latest();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /** Страницы, видимые в списках (разделы, последние материалы, поиск). */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('is_listed', true);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function sourceLabel(): string
    {
        return self::SOURCE_TYPES[$this->source_type] ?? $this->source_type;
    }

    public function url(): string
    {
        if ($this->page_type === 'author') {
            return '/'.$this->slug;
        }

        // Страницы подразделов живут под URL корневого раздела:
        // перенос страницы в подраздел не меняет её адрес.
        return $this->section
            ? '/'.$this->section->rootAncestor()->slug.'/'.$this->slug
            : '/'.$this->slug;
    }

    /**
     * Адрес до текущего сохранения — по нему PageObserver ставит 301, когда
     * страница переезжает. Считается из getOriginal(), поэтому вызывать
     * только внутри saving/saved: после syncOriginal() вернёт новый адрес.
     */
    public function urlBeforeSave(): ?string
    {
        $slug = $this->getOriginal('slug');
        if (blank($slug)) {
            return null;
        }
        if ($this->getOriginal('page_type') === 'author') {
            return '/'.$slug;
        }

        $sectionId = $this->getOriginal('section_id');
        if (! $sectionId) {
            return '/'.$slug;
        }

        $section = Section::find($sectionId);

        return $section ? '/'.$section->rootAncestor()->slug.'/'.$slug : null;
    }

    public function seoValue(string $key, ?string $default = null): ?string
    {
        return $this->seo[$key] ?? $default;
    }

    /**
     * Обложка плеера для системного UI ОС (Media Session API — экран
     * блокировки/шторка на iOS и Android при прослушивании): og-картинка
     * страницы, иначе первая картинка в тексте, иначе стандартная
     * OG-заглушка сайта. Всегда абсолютный URL — MediaMetadata.artwork
     * относительных путей не принимает.
     */
    public function coverImageUrl(): string
    {
        $absolute = function (string $url): string {
            return str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
                ? $url
                : rtrim(config('app.url'), '/').'/'.ltrim($url, '/');
        };

        if ($og = $this->seoValue('og_image')) {
            return $absolute($og);
        }

        if ($this->body_rendered && preg_match('/<img[^>]+src="([^"]+)"/', $this->body_rendered, $m)) {
            return $absolute($m[1]);
        }

        return $absolute('/images/x-intellect_logo.webp');
    }
}
