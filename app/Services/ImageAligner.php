<?php

namespace App\Services;

use DOMDocument;
use DOMElement;

/**
 * Выравнивание картинок в контенте. Trix хранит выбор пользователя (лево/
 * право/центр/во всю ширину) в атрибуте attachment `alignment` — он попадает
 * в JSON `data-trix-attachment` фигуры. Здесь по этому JSON вешаем на фигуру
 * класс xi-float-left/-right/xi-align-center/-wide, который стилизует
 * публичная часть. Сырое тело (body) не трогаем — там формат Trix должен
 * сохраняться для повторного открытия в редакторе; правим только рендер.
 */
class ImageAligner
{
    /** alignment из Trix → CSS-класс на фигуре. */
    private const MAP = [
        'left' => 'xi-float-left',
        'right' => 'xi-float-right',
        'center' => 'xi-align-center',
        'wide' => 'xi-align-wide',
    ];

    public function process(?string $html): ?string
    {
        if (blank($html) || ! str_contains($html, 'data-trix-attachment')) {
            return $html;
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="__a">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $root = $doc->getElementById('__a');
        if (! $root) {
            return $html;
        }

        foreach (iterator_to_array($doc->getElementsByTagName('figure')) as $figure) {
            /** @var DOMElement $figure */
            $json = $figure->getAttribute('data-trix-attachment');
            if ($json === '') {
                continue;
            }
            $data = json_decode(html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            $alignment = is_array($data) ? ($data['alignment'] ?? null) : null;

            // сбросить возможные прежние классы выравнивания, затем проставить актуальный
            $classes = array_values(array_diff(
                preg_split('/\s+/', trim($figure->getAttribute('class'))) ?: [],
                self::MAP,
            ));
            if ($alignment && isset(self::MAP[$alignment])) {
                $classes[] = self::MAP[$alignment];
            }
            $figure->setAttribute('class', implode(' ', array_filter($classes)));
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $c) {
            $out .= $doc->saveHTML($c);
        }

        return $out;
    }
}
