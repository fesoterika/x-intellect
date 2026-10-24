<?php

namespace App\Support;

/** Один и тот же читаемый текст для поиска и отрывков, без HTML-атрибутов. */
class SearchText
{
    public static function plain(?string $html): string
    {
        $text = preg_replace('#<(script|style|iframe)\b[^>]*>.*?</\1\s*>#is', ' ', (string) $html);
        $text = preg_replace('/\[\[audio:\d+\]\]/', ' ', $text);
        $text = preg_replace('#</?(?:p|div|li|td|th|tr|h[1-6]|blockquote|br|hr|section|article|figure|figcaption)\b[^>]*>#i', ' ', $text);

        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public static function normalize(string $text): string
    {
        return str_replace('ё', 'е', mb_strtolower(trim(preg_replace('/[\s\p{Z}]+/u', ' ', $text)), 'UTF-8'));
    }

    public static function index(?string $title, ?string $excerpt, ?string $body): string
    {
        return self::normalize(self::plain($title).' '.self::plain($excerpt).' '.self::plain($body));
    }
}
