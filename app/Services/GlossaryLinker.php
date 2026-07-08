<?php

namespace App\Services;

use App\Models\GlossaryTerm;

/**
 * Авто-простановка терминов глоссария в тексте статьи (Этап 1 плана).
 * Прогоняется при сохранении страницы: первое вхождение каждого термина
 * оборачивается в <span class="glossary-term"> с определением для
 * Alpine.js-тултипа и ссылкой на страницу глоссария.
 */
class GlossaryLinker
{
    public function process(?string $html): ?string
    {
        if (blank($html)) {
            return $html;
        }

        // Длинные термины первыми, чтобы «Силы Дальнего Космоса»
        // не разбивалось на вхождение более короткого термина «Силы»
        $terms = GlossaryTerm::query()
            ->orderByRaw('LENGTH(term) DESC')
            ->get();

        foreach ($terms as $term) {
            $escaped = preg_quote($term->term, '/');
            $definition = e($term->definition);

            // Одно (первое) вхождение вне HTML-тегов и вне уже проставленных ссылок
            $pattern = '/(?<![\p{L}\p{N}>-])('.$escaped.')(?![\p{L}\p{N}])(?![^<]*>)/ui';

            $html = preg_replace_callback($pattern, function ($m) use ($definition, $term) {
                return '<span class="glossary-term" data-glossary-term="'.e($term->term).'"'
                    .' data-glossary-definition="'.$definition.'"'
                    .' tabindex="0">'.$m[1].'</span>';
            }, $html, 1);
        }

        return $html;
    }
}
