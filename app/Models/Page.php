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

    protected $fillable = [
        'section_id', 'title', 'slug', 'excerpt', 'body', 'body_rendered',
        'page_type', 'status', 'is_listed', 'source_type', 'source_url', 'seo',
        'position', 'published_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'seo' => 'array',
            'is_listed' => 'boolean',
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

    public function seoValue(string $key, ?string $default = null): ?string
    {
        return $this->seo[$key] ?? $default;
    }
}
