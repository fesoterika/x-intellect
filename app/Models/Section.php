<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    protected $fillable = ['parent_id', 'title', 'slug', 'description', 'position', 'is_visible', 'show_on_home'];

    protected function casts(): array
    {
        return ['is_visible' => 'boolean', 'show_on_home' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    public function publishedPages(): HasMany
    {
        return $this->pages()
            ->where('status', 'published')
            ->orderBy('position')
            ->orderByDesc('published_at');
    }

    /** Корневые разделы (глубина иерархии — 1: раздел → подразделы). */
    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /** Корневой предок; страницы подразделов живут под URL корня. */
    public function rootAncestor(): self
    {
        return $this->parent ?? $this;
    }

    public function url(): string
    {
        return $this->isRoot()
            ? '/'.$this->slug
            : '/'.$this->parent->slug.'/'.$this->slug;
    }
}
