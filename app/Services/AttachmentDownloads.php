<?php

namespace App\Services;

use DOMDocument;
use DOMElement;

/**
 * Файлы-вложения (PDF и другие не-картинки, прикреплённые из Trix-редактора)
 * на публичной странице: вместо бледной фигуры Trix с именем и размером
 * рендерится явная кнопка скачивания —
 *
 *   <a class="xi-download" href download><svg/>Скачать <span>имя · размер</span></a>
 *
 * Картинки и таблицы (content-вложения) не трогаются. Применяется только
 * к рендеру (body_rendered), сырое тело остаётся в формате Trix.
 */
class AttachmentDownloads
{
    private const DOWNLOAD_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        .'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        .'<path d="M12 3v11"/><path d="M7.5 10.5L12 15l4.5-4.5"/><path d="M4 19.5h16"/></svg>';

    public function process(?string $html): ?string
    {
        if (blank($html) || ! str_contains($html, 'data-trix-attachment')) {
            return $html;
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="__d">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $root = $doc->getElementById('__d');
        if (! $root) {
            return $html;
        }

        foreach (iterator_to_array($doc->getElementsByTagName('figure')) as $figure) {
            /** @var DOMElement $figure */
            $attrs = json_decode(
                html_entity_decode($figure->getAttribute('data-trix-attachment'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                true,
            );
            if (! is_array($attrs)) {
                continue;
            }

            $type = (string) ($attrs['contentType'] ?? '');
            // картинки остаются фигурами, таблицы разворачивает TrixTables
            if ($type === '' || str_starts_with($type, 'image') || $type === TrixTables::CONTENT_TYPE) {
                continue;
            }

            $href = (string) ($attrs['href'] ?? $attrs['url'] ?? '');
            if ($href === '') {
                continue;
            }

            $button = $this->buildButton($doc, $href, (string) ($attrs['filename'] ?? ''), $attrs['filesize'] ?? null);

            // Trix оборачивает файл-фигуру в <a href> — заменяем ссылку целиком
            $target = ($figure->parentNode instanceof DOMElement && strtolower($figure->parentNode->tagName) === 'a')
                ? $figure->parentNode
                : $figure;
            $target->parentNode->replaceChild($button, $target);
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $c) {
            $out .= $doc->saveHTML($c);
        }

        return $out;
    }

    private function buildButton(DOMDocument $doc, string $href, string $filename, mixed $filesize): DOMElement
    {
        $a = $doc->createElement('a');
        $a->setAttribute('class', 'xi-download');
        $a->setAttribute('href', $href);
        $a->setAttribute('target', '_blank');
        $a->setAttribute('rel', 'noopener noreferrer');
        $a->setAttribute('download', $filename !== '' ? $filename : '');

        $icon = $doc->createDocumentFragment();
        $icon->appendXML(self::DOWNLOAD_ICON);
        $a->appendChild($icon);

        $label = $doc->createElement('span');
        $label->setAttribute('class', 'xi-download__label');
        $label->appendChild($doc->createTextNode('Скачать'));
        $a->appendChild($label);

        $meta = trim($filename.($filesize ? ' · '.$this->humanSize((int) $filesize) : ''), ' ·');
        if ($meta !== '') {
            $metaEl = $doc->createElement('span');
            $metaEl->setAttribute('class', 'xi-download__meta');
            $metaEl->appendChild($doc->createTextNode($meta));
            $a->appendChild($metaEl);
        }

        return $a;
    }

    private function humanSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => str_replace('.', ',', (string) round($bytes / 1048576, 1)).' МБ',
            $bytes >= 1024 => (string) round($bytes / 1024).' КБ',
            default => $bytes.' Б',
        };
    }
}
