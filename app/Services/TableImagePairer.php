<?php

namespace App\Services;

use DOMDocument;
use DOMElement;

/**
 * Пара «картинка с обтеканием + таблица» на публичной странице: если прямо
 * перед таблицей стоит картинка с выравниванием влево/вправо (img.xi-float-*,
 * та же картинка внутри <a>, либо Trix-фигура, уже размеченная ImageAligner),
 * оба элемента оборачиваются во flex-контейнер:
 *
 *   <div class="xi-imgtable xi-imgtable--left|right">картинка таблица</div>
 *
 * Картинка встаёт на свою сторону, таблица — на противоположную; ширина
 * таблицы в приоритете (текст не сжимается — сжимается картинка, см. CSS
 * .xi-imgtable в app.css), на смартфоне пара складывается в колонку
 * (картинка над таблицей). Применяется ТОЛЬКО к рендеру (body_rendered) —
 * сырое тело остаётся без обвязки. Вызывается после ImageAligner.
 */
class TableImagePairer
{
    public function process(?string $html): ?string
    {
        if (blank($html) || ! str_contains($html, '<table')) {
            return $html;
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="__p">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $root = $doc->getElementById('__p');
        if (! $root) {
            return $html;
        }

        foreach (iterator_to_array($doc->getElementsByTagName('table')) as $table) {
            /** @var DOMElement $table */
            $prev = $this->previousElement($table);
            if (! $prev || ! ($side = $this->floatSide($prev))) {
                continue;
            }

            $wrap = $doc->createElement('div');
            $wrap->setAttribute('class', 'xi-imgtable xi-imgtable--'.$side);
            $table->parentNode->insertBefore($wrap, $prev);
            $wrap->appendChild($prev);
            $wrap->appendChild($table);
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $c) {
            $out .= $doc->saveHTML($c);
        }

        return $out;
    }

    /** Предыдущий элемент-сосед, пробельные текстовые узлы пропускаются. */
    private function previousElement(DOMElement $el): ?DOMElement
    {
        for ($n = $el->previousSibling; $n !== null; $n = $n->previousSibling) {
            if ($n instanceof DOMElement) {
                return $n;
            }
            if (trim($n->textContent) !== '') {
                return null; // между картинкой и таблицей есть текст — не пара
            }
        }

        return null;
    }

    /**
     * Сторона обтекания элемента-картинки: 'left'/'right' или null.
     * Понимает img.xi-float-*, <a> с такой картинкой внутри и фигуру Trix
     * (класс на неё уже повешен ImageAligner).
     */
    private function floatSide(DOMElement $el): ?string
    {
        $tag = strtolower($el->tagName);

        if ($tag === 'a') {
            foreach ($el->getElementsByTagName('img') as $img) {
                if ($side = $this->classSide($img)) {
                    return $side;
                }
            }

            return null;
        }

        if ($tag === 'img' || $tag === 'figure') {
            return $this->classSide($el);
        }

        return null;
    }

    private function classSide(DOMElement $el): ?string
    {
        $class = ' '.$el->getAttribute('class').' ';

        if (str_contains($class, ' xi-float-left ')) {
            return 'left';
        }
        if (str_contains($class, ' xi-float-right ')) {
            return 'right';
        }

        return null;
    }
}
