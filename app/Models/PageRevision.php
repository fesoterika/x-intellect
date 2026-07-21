<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageRevision extends Model
{
    protected $fillable = [
        'page_id', 'title', 'body', 'source_type', 'source_url', 'archived_at', 'note', 'reason',
    ];

    protected function casts(): array
    {
        return ['archived_at' => 'date'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function sourceLabel(): string
    {
        return Page::SOURCE_TYPES[$this->source_type] ?? $this->source_type;
    }
}
