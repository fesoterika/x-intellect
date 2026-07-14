<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;

/**
 * Общая логика разбора страниц MediaWiki старого сайта — для импортёров
 * из офлайн-слепка (import:offline-wiki) и из Wayback Machine (import:wayback-wiki):
 * фильтры служебных страниц, ns-0 гейт, извлечение контента, слаги, старые URL.
 */
class MediaWikiArchive
{
    /** Точные заголовки, которые не импортируем (вики-мета/навигация). */
    public array $skipTitles = [
        'администраторы', 'боты', 'бюрократы', 'права групп', 'участники',
        'правила wiki', 'поддержка', 'описание',
        'заглавная страница', 'глоссарий', 'термины и понятия', 'книжная полка',
        'x - интеллект', 'сфера разума',
        'техническая поддержка', 'настройка программы для видеоконференций team talk',
        'личные консультации', 'галерея новых файлов',
    ];

    /** Префиксы пространств имён MediaWiki — скип. */
    public array $skipNamespaces = [
        'x intellect:', 'mediawiki:', 'служебная:', 'файл:', 'участник:',
        'обсуждение:', 'категория:', 'шаблон:', 'справка:', 'изображение:',
        'special:', 'file:', 'user:', 'template:', 'help:', 'talk:', 'category:',
    ];

    /** Подстроки заголовков «экшн-страниц» MediaWiki — скип. */
    public array $skipContains = [
        'история изменений', 'исходного текста', 'редактирование',
        'различия между', 'просмотр исходного', 'личная консультаци',
    ];

    /**
     * Заголовки из индекса глоссария, которым нужна полноценная вики-страница
     * (большие статьи, пункты меню «Проектов»). Термин в глоссарии остаётся.
     */
    public array $forceAsPages = ['биоэкран', 'душа', 'внеземные цивилизации (вц)'];

    public function isSkippable(string $title): bool
    {
        $t = mb_strtolower(trim($title));
        if (in_array($t, $this->skipTitles, true)) {
            return true;
        }
        foreach ($this->skipNamespaces as $ns) {
            if (str_starts_with($t, $ns)) {
                return true;
            }
        }
        foreach ($this->skipContains as $needle) {
            if (str_contains($t, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Разбирает HTML страницы MediaWiki: только основное пространство имён (ns-0).
     *
     * @return array{0: ?string, 1: ?DOMElement, 2: ?DOMDocument} [title, content, doc]
     */
    public function parse(?string $html): array
    {
        if (blank($html)) {
            return [null, null, null];
        }
        // Только статьи (ns-0): одним махом отсекаются Служебная/Участник/
        // Обсуждение/MediaWiki/Категория/Файл/Шаблон.
        if (preg_match('/"wgNamespaceNumber":(-?\d+)/', $html, $m) && $m[1] !== '0') {
            return [null, null, null];
        }
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $heading = $doc->getElementById('firstHeading');
        $content = $doc->getElementById('mw-content-text');
        if (! $heading || ! $content) {
            return [null, null, null];
        }
        $title = trim(preg_replace('/\s+/u', ' ', $heading->textContent));

        return [$title ?: null, $content instanceof DOMElement ? $content : null, $doc];
    }

    public function innerHtml(DOMElement $node, DOMDocument $doc): string
    {
        $html = '';
        foreach ($node->childNodes as $c) {
            $html .= $doc->saveHTML($c);
        }

        // Срезаем NewPP-комментарии и парсер-кэш
        return preg_replace('/<!--.*?-->/s', '', $html);
    }

    /** Краткое определение термина как plain text (для тултипа/глоссария). */
    public function definitionText(DOMElement $node): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
        $text = preg_replace('/\[править[^\]]*\]/u', '', $text);
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) <= 600) {
            return $text;
        }
        // обрезаем по границе предложения
        $cut = mb_substr($text, 0, 600);
        $lastDot = max(mb_strrpos($cut, '. '), mb_strrpos($cut, '! '), mb_strrpos($cut, '? '));

        return ($lastDot !== false && $lastDot > 200 ? mb_substr($cut, 0, $lastDot + 1) : $cut).' …';
    }

    /** Старые URL MediaWiki для 301 (с подчёркиваниями и с пробелами). */
    public function oldWikiPaths(string $title): array
    {
        $underscore = str_replace(' ', '_', $title);

        return array_unique([
            '/wiki/index.php?title='.$underscore,
            '/wiki/index.php?title='.$title,
        ]);
    }

    /** Уникальный транслит-слаг для указанной модели (Page или GlossaryTerm). */
    public function uniqueSlug(string $title, string $model): string
    {
        $base = Str::slug($title) ?: 'page';
        $slug = $base;
        $i = 2;
        while ($model::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
