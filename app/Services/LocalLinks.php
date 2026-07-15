<?php

namespace App\Services;

/**
 * Абсолютные ссылки на локальный хост в контенте (http://localhost:8753/…,
 * http://127.0.0.1/…) → относительные (/путь): контент не привязывается к
 * окружению разработки, и на проде такие ссылки не ломаются.
 *
 * Применяется при каждом сохранении тела страницы, описания раздела и
 * определения термина; исторический контент чистит site:content-fixes-2026.
 */
class LocalLinks
{
    public function relativize(?string $html): ?string
    {
        if (blank($html)) {
            return $html;
        }

        // Только внутри href/src — текст, где localhost упомянут словами,
        // не трогаем. Маркер #_blank и query-string сохраняются в пути.
        return preg_replace_callback(
            '#(href|src)="https?://(?:localhost|127\.0\.0\.1)(?::\d+)?(/[^"]*)?"#i',
            fn ($m) => $m[1].'="'.(($m[2] ?? '') !== '' ? $m[2] : '/').'"',
            $html,
        );
    }
}
