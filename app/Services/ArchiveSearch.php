<?php

namespace App\Services;

use App\Support\RussianText;
use App\Support\SearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ArchiveSearch
{
    public readonly string $phrase;
    public readonly array $terms;

    public function __construct(string $query)
    {
        $this->phrase = SearchText::normalize($query);
        $this->terms = array_values(array_unique(array_filter(
            preg_split('/[\s\p{Z},;:!?()\[\]{}«»"“”]+/u', $this->phrase),
            fn ($term) => $term !== '',
        )));
    }

    public function apply(Builder $builder): void
    {
        // Все части запроса обязательны, даже если разнесены по заголовку и телу.
        // Никакого OR FULLTEXT: его natural-language режим возвращал одно слово
        // вместо всей фразы и искал в HTML-атрибутах.
        foreach ($this->terms as $term) {
            RussianText::contains($builder, 'search_text', $term);
        }

        $title = "REPLACE(LOWER(title), 'ё', 'е')";
        if (DB::connection()->getDriverName() === 'sqlite') {
            RussianText::registerLower();
            $title = "REPLACE(xi_lower(title), 'ё', 'е')";
        }
        $like = fn ($value) => '%'.addcslashes($value, '%_\\').'%';
        $escape = DB::connection()->getDriverName() === 'sqlite' ? " ESCAPE '\\'" : '';
        $builder->orderByRaw("CASE WHEN {$title} LIKE ?{$escape} THEN 0 ELSE 1 END", [$like($this->phrase)]);
        $parts = array_fill(0, count($this->terms), "CASE WHEN {$title} LIKE ?{$escape} THEN 1 ELSE 0 END");
        if ($parts) {
            $builder->orderByRaw('('.implode(' + ', $parts).') DESC', array_map($like, $this->terms));
        }
        RussianText::containsFirstOrder($builder, 'search_text', $this->phrase);
    }

    public function matches(string $text): bool
    {
        $text = SearchText::normalize($text);

        return $this->terms !== [] && collect($this->terms)->every(fn ($term) => str_contains($text, $term));
    }

    /** Экранируем каждый фрагмент ДО добавления единственной разрешённой разметки. */
    public function highlight(string $text): HtmlString
    {
        $needles = array_unique([$this->phrase, ...$this->terms]);
        usort($needles, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $patterns = array_map(fn ($term) => preg_replace('/[её]/u', '[её]', preg_quote($term, '~')), array_filter($needles));
        if (! $patterns) {
            return new HtmlString(e($text));
        }
        $parts = preg_split('~('.implode('|', $patterns).')~iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $html = '';
        foreach ($parts as $i => $part) {
            $html .= $i % 2 ? '<mark class="search-match">'.e($part).'</mark>' : e($part);
        }

        return new HtmlString($html);
    }

    /** До двух отрывков, чтобы показать и разнесённые по тексту слова. */
    public function snippet(?string $body, ?string $excerpt = null): HtmlString
    {
        $text = SearchText::plain($body);
        $preview = SearchText::plain($excerpt);
        $hasMatch = fn ($value) => collect($this->terms)->contains(fn ($term) => str_contains(SearchText::normalize($value), $term));
        if (! $hasMatch($text) && $hasMatch($preview)) {
            $text = $preview;
        }
        if (! $hasMatch($text)) {
            return $this->highlight(Str::limit($preview ?: $text, 240));
        }

        $normalized = SearchText::normalize($text);
        $phraseAt = mb_strpos($normalized, $this->phrase);
        $positions = [];
        foreach ($this->terms as $term) {
            if (($at = mb_strpos($normalized, $term)) !== false) {
                $positions[] = $at;
            }
        }
        sort($positions);
        $anchor = $phraseAt !== false ? $phraseAt : $positions[0];
        // Выбираем окно с наибольшим числом разных частей запроса.
        if ($phraseAt === false) {
            $best = -1;
            foreach ($positions as $at) {
                $window = mb_substr($normalized, max(0, $at - 55), 240);
                $score = collect($this->terms)->filter(fn ($term) => str_contains($window, $term))->count();
                if ($score > $best) {
                    $best = $score;
                    $anchor = $at;
                }
            }
        }
        $ranges = [[$anchor, 240]];
        $first = mb_substr($normalized, max(0, $anchor - 55), 240);
        foreach ($this->terms as $term) {
            if (! str_contains($first, $term) && ($at = mb_strpos($normalized, $term)) !== false) {
                $ranges = [[$anchor, 150], [$at, 150]];
                break;
            }
        }
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
        $windows = [];
        foreach ($ranges as [$at, $length]) {
            $start = max($at - 55, 0);
            // Сохраняем целые слова на границах окна, включая длинный запрос.
            if ($start > 0 && ($space = mb_strpos($text, ' ', $start)) !== false && $space < $at) {
                $start = $space + 1;
            }
            $end = min(mb_strlen($text), max($start + $length, $at + mb_strlen($this->phrase)));
            if ($end < mb_strlen($text) && ($space = mb_strpos($text, ' ', $end)) !== false) {
                $end = $space;
            }
            $last = count($windows) - 1;
            if ($last >= 0 && $start <= $windows[$last][1]) {
                $windows[$last][1] = max($windows[$last][1], $end);
            } else {
                $windows[] = [$start, $end];
            }
        }
        $fragments = array_map(fn ($range) => trim(mb_substr($text, $range[0], $range[1] - $range[0])), $windows);

        return $this->highlight(($windows[0][0] > 0 ? '… ' : '').implode(' … ', $fragments)
            .($windows[count($windows) - 1][1] < mb_strlen($text) ? ' …' : ''));
    }
}
