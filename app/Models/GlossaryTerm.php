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

    /**
     * Определение без разметки — для meta description, JSON-LD, тултипов,
     * копирования и клиентского фильтра глоссария.
     */
    public function definitionPlain(): string
    {
        return trim(html_entity_decode(strip_tags((string) $this->definition), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Определение для вывода как HTML. Термины импорта хранят чистый текст —
     * его экранируем и переводим переносы в <br>; новые определения из
     * Trix-редактора уже содержат разметку и отдаются как есть.
     */
    public function definitionHtml(): string
    {
        $definition = (string) $this->definition;

        return str_contains($definition, '<')
            ? $definition
            : nl2br(e($definition));
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
