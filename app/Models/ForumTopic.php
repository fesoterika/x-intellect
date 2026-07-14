<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ForumTopic extends Model
{
    protected $fillable = [
        'old_id', 'forum_old_id', 'forum_title', 'forum_group', 'forum_position',
        'slug', 'title', 'posts_count', 'started_at', 'last_posted_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_posted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function posts(): HasMany
    {
        return $this->hasMany(ForumPost::class, 'topic_id')->orderBy('position');
    }

    public function url(): string
    {
        return '/forum/'.$this->slug;
    }
}
