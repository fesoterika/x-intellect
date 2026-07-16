<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use SplObjectStorage;

/**
 * Ряды миниатюр на публичной странице: идущие подряд картинки без обтекания
 * (в вики они стояли в строку — «Шамбала (12 картин)» и т.п., а фигура —
 * блочный элемент, поэтому в столбик) собираются во flex-контейнер:
 *
 *   <div class="xi-gallery">фигура фигура фигура</div>
 *
 * Ряд переносится по ширине экрана (см. .xi-gallery в app.css). Группируются
 * только соседние фигуры без классов выравнивания: обтекающие картинки и пары
 * «картинка + таблица» (TableImagePairer) остаются как есть. Применяется
 * ТОЛЬКО к рендеру (body_rendered) — сырое тело для Trix не трогаем.
 * Вызывается ПОСЛЕ TableImagePairer: обёртка разорвала бы соседство фигуры
 * с таблицей, и пара перестала бы собираться.
 */
class ImageGallery
{
    /** Фигуры с выравниванием в ряд не собираем — у них своя раскладка. */
    private const ALIGN_CLASSES = [
        'xi-float-left', 'xi-float-right', 'xi-align-center', 'xi-align-wide',
    ];

    public function process(?string $html): ?string
    {
        if (blank($html) || ! str_contains($html, '<figure')) {
            return $html;
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="__g">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $root = $doc->getElementById('__g');
        if (! $root) {
            return $html;
        }

        $done = new SplObjectStorage;

        foreach (iterator_to_array($doc->getElementsByTagName('figure')) as $figure) {
            /** @var DOMElement $figure */
            if ($done->contains($figure) || ! $this->isPlain($figure)) {
                continue;
            }

            // набираем цепочку соседних фигур
            $run = [$figure];
            for ($n = $this->nextFigure($figure); $n !== null; $n = $this->nextFigure($n)) {
                $run[] = $n;
            }

            if (count($run) < 2) {
                continue;
            }

            $wrap = $doc->createElement('div');
            $wrap->setAttribute('class', 'xi-gallery');
            $figure->parentNode?->insertBefore($wrap, $figure);

            foreach ($run as $item) {
                $done->attach($item);
                $wrap->appendChild($item); // appendChild переносит узел из прежнего места
            }
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $c) {
            $out .= $doc->saveHTML($c);
        }

        return $out;
    }

    /** Следующая соседняя фигура; пробельные текстовые узлы пропускаются. */
    private function nextFigure(DOMElement $el): ?DOMElement
    {
        for ($n = $el->nextSibling; $n !== null; $n = $n->nextSibling) {
            if ($n instanceof DOMElement) {
                return strtolower($n->tagName) === 'figure' && $this->isPlain($n) ? $n : null;
            }
            if (trim($n->textContent) !== '') {
                return null; // между картинками есть текст — это не ряд
            }
        }

        return null;
    }

    /** Фигура-картинка без выравнивания. */
    private function isPlain(DOMElement $figure): bool
    {
        if ($figure->getElementsByTagName('img')->length === 0) {
            return false;
        }

        $class = ' '.$figure->getAttribute('class').' ';
        foreach (self::ALIGN_CLASSES as $align) {
            if (str_contains($class, ' '.$align.' ')) {
                return false;
            }
        }

        return true;
    }
}
