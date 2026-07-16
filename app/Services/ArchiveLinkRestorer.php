<?php

namespace App\Services;

/**
 * Точечная вставка потерянной ссылки в тело материала.
 *
 * При первом импорте часть ссылок была срезана: якорный текст в теле остался, а
 * <a> исчез (страницы-указатели вроде «Сеансы 2009 - 2010» превратились в
 * простой список слов). remap:archive-links тут бессилен — он переписывает
 * существующие ссылки, а не воскрешает удалённые.
 *
 * Правка обязана быть хирургической: пользователь вычитывает страницы вручную,
 * и опубликованное трогать нельзя сверх самой вставки. Поэтому работаем не через
 * DOM (пересборка меняет форматирование и сущности всего документа), а
 * сплайсингом строки: за пределами вставленного <a> тело остаётся байт-в-байт
 * прежним.
 */
class ArchiveLinkRestorer
{
    /** Мягкое сопоставление: регистр, ё/е, кавычки, неразрывные пробелы. */
    private const SKIP_CHARS = ['«', '»', '"', '„', '“', '”', '\'', '’', '‘'];

    /**
     * Оборачивает первое вхождение $anchor в <a href="$href">.
     *
     * @return string|null Новое тело или null, если текст не найден либо уже в ссылке.
     */
    public function insert(string $body, string $anchor, string $href): ?string
    {
        $needle = $this->normalize($anchor)['norm'];
        if ($needle === '') {
            return null;
        }

        // Токены: теги и текст между ними. Правим ровно один текстовый токен,
        // остальные возвращаем как есть.
        preg_match_all('/<[^>]*>|[^<]+/su', $body, $m);
        $tokens = $m[0];
        if (! $tokens) {
            return null;
        }

        $inAnchor = 0;
        $skip = 0; // script/style/figure — внутрь не лезем
        foreach ($tokens as $i => $token) {
            if ($token[0] === '<') {
                $tag = strtolower((string) (preg_match('/^<\/?\s*([a-z0-9]+)/i', $token, $t) ? $t[1] : ''));
                $closing = str_starts_with($token, '</');
                if ($tag === 'a') {
                    $inAnchor += $closing ? -1 : 1;
                    $inAnchor = max(0, $inAnchor);
                } elseif (in_array($tag, ['script', 'style', 'figure', 'figcaption'], true)) {
                    $skip += $closing ? -1 : 1;
                    $skip = max(0, $skip);
                }

                continue;
            }
            if ($inAnchor > 0 || $skip > 0) {
                continue;
            }

            $found = $this->find($token, $needle);
            if ($found === null) {
                continue;
            }
            [$start, $end] = $found;

            $chars = mb_str_split($token);
            $before = implode('', array_slice($chars, 0, $start));
            $match = implode('', array_slice($chars, $start, $end - $start));
            $after = implode('', array_slice($chars, $end));

            $tokens[$i] = $before
                .'<a href="'.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').'">'.$match.'</a>'
                .$after;

            return implode('', $tokens);
        }

        return null;
    }

    /** Текст уже обёрнут в ссылку где-либо в теле? */
    public function alreadyLinked(string $body, string $anchor): bool
    {
        $needle = $this->normalize($anchor)['norm'];
        if ($needle === '') {
            return true;
        }
        if (! preg_match_all('/<a\b[^>]*>(.*?)<\/a>/su', $body, $m)) {
            return false;
        }
        foreach ($m[1] as $inner) {
            if (str_contains($this->normalize(strip_tags($inner))['norm'], $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Текст есть в теле (вне тегов)? */
    public function containsText(string $body, string $anchor): bool
    {
        $needle = $this->normalize($anchor)['norm'];

        return $needle !== '' && str_contains($this->normalize(strip_tags($body))['norm'], $needle);
    }

    /**
     * Позиция $needle в $raw в символах исходной строки.
     *
     * @return array{0:int,1:int}|null [начало, конец) или null
     */
    private function find(string $raw, string $needle): ?array
    {
        $n = $this->normalize($raw);
        $pos = mb_strpos($n['norm'], $needle);
        if ($pos === false) {
            return null;
        }
        $end = $pos + mb_strlen($needle);

        return [$n['start'][$pos], $n['end'][$end - 1]];
    }

    /**
     * Нормализованная строка + карта смещений: для каждого символа результата —
     * границы породившего его фрагмента исходной строки (в символах).
     *
     * @return array{norm:string, start:array<int,int>, end:array<int,int>}
     */
    private function normalize(string $raw): array
    {
        $chars = mb_str_split($raw);
        $count = count($chars);
        $norm = '';
        $start = [];
        $end = [];

        for ($i = 0; $i < $count;) {
            $char = $chars[$i];
            $from = $i;

            // HTML-сущность разворачиваем целиком: &nbsp; &laquo; &amp; &#8212;
            if ($char === '&') {
                $rest = implode('', array_slice($chars, $i, 10));
                if (preg_match('/^&(#\d+|#x[0-9a-f]+|[a-z]+);/i', $rest, $m)) {
                    $decoded = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $i += mb_strlen($m[0]);
                    $char = $decoded;
                } else {
                    $i++;
                }
            } else {
                $i++;
            }

            // Пробельный ряд (включая &nbsp;) — один пробел
            if (preg_match('/^\s+$/u', $char) || $char === "\u{00A0}") {
                while ($i < $count && (preg_match('/^\s$/u', $chars[$i]) || $chars[$i] === "\u{00A0}")) {
                    $i++;
                }
                if ($norm !== '' && ! str_ends_with($norm, ' ')) {
                    $norm .= ' ';
                    $start[] = $from;
                    $end[] = $i;
                }

                continue;
            }

            foreach (mb_str_split($char) as $c) {
                if (in_array($c, self::SKIP_CHARS, true)) {
                    continue;
                }
                $norm .= mb_strtolower(str_replace(['ё', 'Ё'], ['е', 'е'], $c));
                $start[] = $from;
                $end[] = $i;
            }
        }

        return ['norm' => rtrim($norm), 'start' => $start, 'end' => $end];
    }
}
