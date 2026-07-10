<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    protected $fillable = ['title', 'slug', 'description', 'position', 'is_visible', 'show_on_home'];

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

    public function url(): string
    {
        return '/'.$this->slug;
    }
}
