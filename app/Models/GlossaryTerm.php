<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlossaryTerm extends Model
{
    protected $fillable = ['term', 'slug', 'definition', 'page_id'];

    /**
     * Собственный индексируемый адрес термина: /glossary?term=<slug>.
     * Query-параметр, а не якорь #slug — фрагмент не доходит до сервера и
     * не индексируется как отдельный URL (сюда же ведут 301 со старых
     * вики-адресов /wiki/index.php?title=…).
     */
    public function url(): string
    {
        return '/glossary?term='.$this->slug;
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
