<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Чистит HTML статей архива до форматирования, доступного в редакторе
 * (Trix): b/strong, i/em, s/del, a, h2-h5, ul/ol/li, blockquote, pre/code,
 * img. Разметку цвета текста и любые инлайновые стили/классы игнорируем
 * (по требованию). Неизвестные обёртки (span/font/div/table…) разворачиваем,
 * сохраняя текст. Локальные картинки копируются в storage.
 */
class ArchiveHtmlCleaner
{
    /** Теги, которые оставляем (h1 приводим к h2). */
    private array $allowed = [
        'p', 'br', 'strong', 'em', 's', 'del', 'a',
        'h2', 'h3', 'h4', 'h5', 'ul', 'ol', 'li',
        'blockquote', 'pre', 'code', 'img',
    ];

    /** Синонимы приводим к каноническим тегам редактора. */
    private array $rename = [
        'b' => 'strong', 'i' => 'em', 'strike' => 's', 'h1' => 'h2', 'h6' => 'h5',
    ];

    public int $imagesCopied = 0;
    public int $imagesDropped = 0;

    public function clean(?string $html, string $baseDir): string
    {
        if (blank($html)) {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="__root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $xp = new DOMXPath($doc);
        foreach ($xp->query('//script|//style|//noscript|//iframe|//comment()') as $n) {
            $n->parentNode?->removeChild($n);
        }

        // Удаляем блоки «Поделиться»/соцсети/похожие записи целиком (не разворачивая),
        // чтобы их подписи не протекли в контент
        $bad = ['buttons_share', 'share', 'social', 'pluso', 'sharedaddy', 'addthis',
            'uptolike', 'yashare', 'ya-share', 'jp-relatedposts', 'sd-block', 'sd-content',
            'relatedposts', 'related-posts', 'robokassa', 'commentlist', 'respond'];
        $cond = implode(' or ', array_map(
            fn ($c) => "contains(concat(' ', normalize-space(@class), ' '), ' {$c} ') or contains(@class, '{$c}')",
            $bad
        ));
        foreach (iterator_to_array($xp->query("//*[{$cond}]")) as $n) {
            $n->parentNode?->removeChild($n);
        }
        // Абзацы, состоящие только из «Опубликовать в …/Поделиться»
        foreach (iterator_to_array($xp->query('//p|//div')) as $n) {
            $txt = trim(preg_replace('/\s+/u', ' ', $n->textContent));
            if (preg_match('/^(Опубликовать в|Поделиться|Share this|Нравится:?)/u', $txt) && mb_strlen($txt) < 120) {
                $n->parentNode?->removeChild($n);
            }
        }

        $root = $doc->getElementById('__root');
        if (! $root) {
            return trim(strip_tags($html));
        }

        $this->processChildren($root, $doc, $baseDir);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $c) {
            $out .= $doc->saveHTML($c);
        }

        // схлопнуть пустые абзацы и лишние переводы
        $out = preg_replace('#<p>(\s|&nbsp;|<br\s*/?>)*</p>#iu', '', $out);
        $out = preg_replace('#(\s*<br\s*/?>\s*){3,}#i', '<br><br>', $out);

        return trim($out);
    }

    private function processChildren(DOMElement $parent, DOMDocument $doc, string $baseDir): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (! $node instanceof DOMElement) {
                continue; // текстовые узлы оставляем как есть
            }

            $tag = strtolower($node->tagName);
            $tag = $this->rename[$tag] ?? $tag;

            if ($tag === 'img') {
                $this->handleImage($node, $baseDir);

                continue;
            }

            if (in_array($tag, $this->allowed, true)) {
                // переименовать при необходимости
                if (strtolower($node->tagName) !== $tag) {
                    $node = $this->renameElement($node, $tag, $doc);
                }
                $this->stripAttributes($node, $tag);
                $this->processChildren($node, $doc, $baseDir);

                continue;
            }

            // неизвестный тег (span/font/div/table/tr/td…) — развернуть,
            // подняв детей на место элемента, затем обработать их
            $this->unwrap($node, $doc, $baseDir);
        }
    }

    private function unwrap(DOMElement $node, DOMDocument $doc, string $baseDir): void
    {
        $parent = $node->parentNode;
        // блочные обёртки заменяем на абзац, чтобы не слиплось
        $blocky = in_array(strtolower($node->tagName), ['div', 'section', 'article', 'table', 'tbody', 'tr', 'p'], true);

        $children = iterator_to_array($node->childNodes);
        foreach ($children as $child) {
            $parent->insertBefore($child, $node);
        }
        $parent->removeChild($node);

        // обработать перенесённых детей
        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $t = $this->rename[strtolower($child->tagName)] ?? strtolower($child->tagName);
                if ($t === 'img') {
                    $this->handleImage($child, $baseDir);
                } elseif (in_array($t, $this->allowed, true)) {
                    if (strtolower($child->tagName) !== $t) {
                        $child = $this->renameElement($child, $t, $doc);
                    }
                    $this->stripAttributes($child, $t);
                    $this->processChildren($child, $doc, $baseDir);
                } else {
                    $this->unwrap($child, $doc, $baseDir);
                }
            }
        }
    }

    private function renameElement(DOMElement $node, string $newTag, DOMDocument $doc): DOMElement
    {
        $new = $doc->createElement($newTag);
        while ($node->firstChild) {
            $new->appendChild($node->firstChild);
        }
        $node->parentNode->replaceChild($new, $node);

        return $new;
    }

    private function stripAttributes(DOMElement $node, string $tag): void
    {
        $keep = match ($tag) {
            'a' => ['href'],
            default => [],
        };

        foreach (iterator_to_array($node->attributes ?? []) as $attr) {
            if (! in_array(strtolower($attr->name), $keep, true)) {
                $node->removeAttribute($attr->name);
            }
        }

        if ($tag === 'a' && $node->hasAttribute('href')) {
            $node->setAttribute('target', '_blank');
            $node->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function handleImage(DOMElement $img, string $baseDir): void
    {
        $src = $img->getAttribute('src');
        $alt = $img->getAttribute('alt');
        $parent = $img->parentNode;

        // внешние картинки (радикал и т.п.) не тянем — убираем
        if ($src === '' || Str::startsWith($src, ['http://', 'https://', 'data:'])) {
            $this->imagesDropped++;
            $parent?->removeChild($img);

            return;
        }

        // локальный путь относительно файла статьи
        $path = realpath($baseDir.'/'.$src);
        if ($path === false || ! is_file($path) || filesize($path) > 8 * 1024 * 1024) {
            $this->imagesDropped++;
            $parent?->removeChild($img);

            return;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'jpg';
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $this->imagesDropped++;
            $parent?->removeChild($img);

            return;
        }

        $dest = 'media/archive/'.Str::random(24).'.'.$ext;
        Storage::disk('public')->put($dest, File::get($path));
        $this->imagesCopied++;

        // сбросить все атрибуты, оставить src+alt. Путь корне-относительный
        // (/storage/…) — портабелен между хостами/портами, не зависит от APP_URL
        foreach (iterator_to_array($img->attributes) as $a) {
            $img->removeAttribute($a->name);
        }
        $img->setAttribute('src', '/storage/'.$dest);
        $img->setAttribute('alt', $alt !== '' ? $alt : 'Иллюстрация из архива');
    }
}
