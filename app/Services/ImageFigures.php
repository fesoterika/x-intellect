<?php

namespace App\Services;

use DOMDocument;
use DOMElement;

/**
 * Картинки в теле материала на публичной странице:
 * — голые img из архивного импорта оборачиваются в figure.attachment--preview
 *   (как вложения Trix), классы обтекания переносятся на фигуру;
 * — пустая подпись Trix (имя и размер файла) убирается;
 * — клик по картинке открывает её в новой вкладке.
 *
 * Применяется только к рендеру (body_rendered), сырое тело не трогается.
 */
class ImageFigures
{
    /** Классы выравнивания: на img из архива → на figure (как у ImageAligner для Trix). */
    private const ALIGN_CLASSES = [
        'xi-float-left', 'xi-float-right', 'xi-align-center', 'xi-align-wide',
    ];

    public function process(?string $html): ?string
    {
        if (blank($html) || ! str_contains($html, '<img')) {
            return $html;
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="__f">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $root = $doc->getElementById('__f');
        if (! $root) {
            return $html;
        }

        foreach (iterator_to_array($doc->getElementsByTagName('img')) as $img) {
            /** @var DOMElement $img */
            $this->wrapArchiveImage($doc, $img);
        }

        foreach (iterator_to_array($doc->getElementsByTagName('figcaption')) as $figcaption) {
            /** @var DOMElement $figcaption */
            $this->cleanCaption($figcaption);
        }

        foreach (iterator_to_array($doc->getElementsByTagName('img')) as $img) {
            /** @var DOMElement $img */
            if ($this->isDecorative($img)) {
                continue;
            }
            $this->linkImage($doc, $img);
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $c) {
            $out .= $doc->saveHTML($c);
        }

        return $out;
    }

    private function cleanCaption(DOMElement $figcaption): void
    {
        foreach (iterator_to_array($figcaption->getElementsByTagName('span')) as $span) {
            /** @var DOMElement $span */
            $class = ' '.$span->getAttribute('class').' ';
            if (str_contains($class, ' attachment__name ') || str_contains($class, ' attachment__size ')) {
                $span->parentNode->removeChild($span);
            }
        }

        if (trim($figcaption->textContent) === '') {
            $figcaption->parentNode?->removeChild($figcaption);
        }
    }

    private function isDecorative(DOMElement $img): bool
    {
        for ($node = $img->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (str_contains(' '.$node->getAttribute('class').' ', ' xi-download ')) {
                return true;
            }
        }

        return false;
    }

    /** Голый img из /media/archive/ → figure.attachment--preview (формат Trix). */
    private function wrapArchiveImage(DOMDocument $doc, DOMElement $img): void
    {
        if ($this->isDecorative($img) || $this->isInsideFigure($img)) {
            return;
        }

        $src = $img->getAttribute('src');
        if ($src === '' || ! str_contains($src, '/media/archive/')) {
            return;
        }

        $figureClasses = ['attachment', 'attachment--preview'];
        $imgClasses = preg_split('/\s+/', trim($img->getAttribute('class'))) ?: [];
        foreach (self::ALIGN_CLASSES as $align) {
            if (in_array($align, $imgClasses, true)) {
                $figureClasses[] = $align;
                $imgClasses = array_values(array_diff($imgClasses, [$align]));
            }
        }

        $figure = $doc->createElement('figure');
        $figure->setAttribute('class', implode(' ', $figureClasses));
        if ($imgClasses) {
            $img->setAttribute('class', implode(' ', $imgClasses));
        } else {
            $img->removeAttribute('class');
        }

        $parent = $img->parentNode;
        if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'p'
            && $this->paragraphOnlyContainsImage($parent, $img)) {
            $parent->parentNode?->replaceChild($figure, $parent);
        } elseif ($parent instanceof DOMElement) {
            $parent->replaceChild($figure, $img);
        } else {
            return;
        }

        $figure->appendChild($img);
    }

    private function isInsideFigure(DOMElement $img): bool
    {
        for ($node = $img->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (strtolower($node->tagName) === 'figure') {
                return true;
            }
        }

        return false;
    }

    /** Абзац содержит только img (и пробелы/br) — заменяем целиком на figure. */
    private function paragraphOnlyContainsImage(DOMElement $p, DOMElement $img): bool
    {
        foreach (iterator_to_array($p->childNodes) as $child) {
            if ($child === $img) {
                continue;
            }
            if ($child instanceof DOMElement) {
                return false;
            }
            if (trim($child->textContent) !== '') {
                return false;
            }
        }

        return true;
    }

    private function linkImage(DOMDocument $doc, DOMElement $img): void
    {
        $parent = $img->parentNode;
        if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'a') {
            $parent->setAttribute('target', '_blank');
            $parent->setAttribute('rel', 'noopener noreferrer');

            return;
        }

        $src = $img->getAttribute('src');
        if ($src === '') {
            return;
        }

        $a = $doc->createElement('a');
        $a->setAttribute('href', $src);
        $a->setAttribute('target', '_blank');
        $a->setAttribute('rel', 'noopener noreferrer');
        $parent?->replaceChild($a, $img);
        $a->appendChild($img);
    }
}
