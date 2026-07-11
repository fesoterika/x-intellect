<?php

namespace App\Services;

/**
 * «Открыть в новом окне» для ссылок из Trix-редактора. Модель Trix хранит
 * у ссылки только href, поэтому чекбокс в диалоге ссылки (admin.js) кодирует
 * выбор суффиксом `#_blank` в самом адресе. Здесь, при рендере, маркер
 * снимается и превращается в target="_blank" rel="noopener noreferrer".
 * Сырое тело (body) сохраняет маркер — редактор видит его при повторном
 * открытии и восстанавливает галочку.
 */
class LinkTargets
{
    public const MARKER = '#_blank';

    public function process(?string $html): ?string
    {
        if (blank($html) || ! str_contains($html, self::MARKER)) {
            return $html;
        }

        return preg_replace_callback(
            '/<a\b([^>]*?)href="([^"]*?)#_blank"([^>]*)>/i',
            function ($m) {
                $rest = $m[1].$m[3];
                // не дублировать target/rel, если они уже есть в теге
                $attrs = ' href="'.$m[2].'"';
                if (! preg_match('/\btarget\s*=/i', $rest)) {
                    $attrs .= ' target="_blank"';
                }
                if (! preg_match('/\brel\s*=/i', $rest)) {
                    $attrs .= ' rel="noopener noreferrer"';
                }

                return '<a'.rtrim($m[1]).$attrs.$m[3].'>';
            },
            $html,
        );
    }
}
