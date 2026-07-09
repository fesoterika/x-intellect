<?php

namespace App\Services;

/**
 * Помечает список-хронологию классом "timeline" при сохранении страницы.
 * Rich-text редактор (Trix) вырезает произвольные классы, поэтому класс
 * восстанавливается автоматически: если первый <li> списка начинается с
 * <strong> и года (4 цифры), список считается таймлайном.
 * Прогоняется в App\Observers\PageObserver перед рендером тела.
 */
class TimelineTagger
{
    public function process(?string $html): ?string
    {
        if (blank($html) || ! str_contains($html, '<ul')) {
            return $html;
        }

        return preg_replace_callback('/<ul\b([^>]*)>(.*?)<\/ul>/is', function ($m) {
            $attrs = $m[1];
            $inner = $m[2];

            // Уже есть класс — не трогаем (редактор мог задать своё оформление)
            if (preg_match('/\bclass\s*=/i', $attrs)) {
                return $m[0];
            }

            // Первый пункт начинается с <strong>ГОД… — это хронология
            if (preg_match('/^\s*<li>\s*<strong>\s*\d{4}/iu', $inner)) {
                return '<ul class="timeline"'.$attrs.'>'.$inner.'</ul>';
            }

            return $m[0];
        }, $html);
    }
}
