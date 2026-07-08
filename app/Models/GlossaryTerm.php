<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlossaryTerm extends Model
{
    protected $fillable = ['term', 'slug', 'definition', 'page_id'];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
