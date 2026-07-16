<?php

namespace App\Services;

/**
 * Анонс материала из его тела: первые предложения как plain text.
 *
 * Вынесен из BackfillContentMeta, чтобы им пользовались все обработчики: когда
 * команда меняет тело (например убирает служебную строку таблицы «Аудио Запись»),
 * она обязана пересобрать и анонс — иначе в анонсе и в meta_description навсегда
 * остаётся текст, которого в теле уже нет.
 */
class ExcerptMaker
{
    public function fromBody(?string $body): string
    {
        // short-коды аудио не тянем в текст анонса
        $text = preg_replace('/\[\[audio:\d+\]\]/', ' ', (string) $body);
        // блочные теги → пробел, чтобы абзацы/ячейки не слипались
        $text = preg_replace('#</(p|div|li|td|th|tr|h[1-6]|blockquote)>#i', ' ', $text);
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) <= 200) {
            return $text;
        }

        $cut = mb_substr($text, 0, 200);
        $lastEnd = max(mb_strrpos($cut, '. ') ?: 0, mb_strrpos($cut, '! ') ?: 0, mb_strrpos($cut, '? ') ?: 0);

        if ($lastEnd > 80) {
            return mb_substr($cut, 0, $lastEnd + 1);
        }

        // предложение длиннее лимита — режем по слову
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim(mb_substr($cut, 0, $lastSpace ?: 200), ' ,;:—-').'…';
    }
}
