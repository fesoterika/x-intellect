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
 * img, плюс таблицы из тела материала (table/tr/td…) — они сохраняются
 * как есть и рендерятся на публичной странице (Trix их не редактирует).
 * Разметку цвета текста и любые инлайновые стили/классы игнорируем
 * (по требованию). Неизвестные обёртки (span/font/div…) разворачиваем,
 * сохраняя текст. Локальные картинки копируются в storage.
 */
class ArchiveHtmlCleaner
{
    /** Теги, которые оставляем (h1 приводим к h2). */
    private array $allowed = [
        'p', 'br', 'strong', 'em', 's', 'del', 'a',
        'h2', 'h3', 'h4', 'h5', 'ul', 'ol', 'li',
        'blockquote', 'pre', 'code', 'img',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption',
    ];

    /**
     * Разворачивать blockquote, поднимая содержимое (для вики: MediaWiki-статьи
     * целиком обёрнуты в декоративный blockquote — на новом сайте весь контент
     * выглядел бы цитатой).
     */
    private bool $keepBlockquote = true;

    /** Синонимы приводим к каноническим тегам редактора. */
    private array $rename = [
        'b' => 'strong', 'i' => 'em', 'strike' => 's', 'h1' => 'h2', 'h6' => 'h5',
    ];

    public int $imagesCopied = 0;
    public int $imagesDropped = 0;

    public function clean(?string $html, string $baseDir, bool $keepBlockquote = true): string
    {
        if (blank($html)) {
            return '';
        }
        $this->keepBlockquote = $keepBlockquote;

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
            'relatedposts', 'related-posts', 'robokassa', 'commentlist', 'respond',
            // MediaWiki-служебное (импорт вики)
            'mw-editsection', 'toc', 'printfooter', 'catlinks', 'navbox', 'noprint',
            'mw-jump-link', 'mw-redirectedfrom', 'magnify', 'mw-empty-elt'];
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

        // Пред-проход: помечаем картинки с обтеканием (float) из исходника —
        // WordPress alignleft/alignright, MediaWiki thumb tleft/tright, floatleft
        // и инлайновый float. Метку кладём на сам <img> ДО разворачивания обёрток.
        foreach (iterator_to_array($xp->query('//img')) as $img) {
            if ($float = $this->detectFloat($img)) {
                $img->setAttribute('data-xi-float', $float);
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

            if ($tag === 'blockquote' && ! $this->keepBlockquote) {
                $this->unwrap($node, $doc, $baseDir);

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

        // Якорь секции MediaWiki живёт на <span class="mw-headline" id=…>
        // внутри заголовка — перед разворачиванием поднимаем id на заголовок.
        if ($node->hasAttribute('id') && $parent instanceof DOMElement
            && in_array($this->rename[strtolower($parent->tagName)] ?? strtolower($parent->tagName), ['h2', 'h3', 'h4', 'h5'], true)
            && ! $parent->hasAttribute('id')) {
            $parent->setAttribute('id', $node->getAttribute('id'));
        }

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
                } elseif ($t === 'blockquote' && ! $this->keepBlockquote) {
                    $this->unwrap($child, $doc, $baseDir);
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
            // id — это якоря (<a name=…> в старом WordPress, id заголовков
            // MediaWiki): без них внутристраничные ссылки #… ведут в никуда
            'a' => ['href', 'id'],
            'h2', 'h3', 'h4', 'h5' => ['id'],
            // структурные атрибуты объединения ячеек (стили/классы не тянем)
            'td', 'th' => ['colspan', 'rowspan'],
            default => [],
        };

        // старый синтаксис якоря <a name="0001"> → <a id="0001">
        if ($tag === 'a' && ! $node->hasAttribute('id') && $node->hasAttribute('name')) {
            $node->setAttribute('id', $node->getAttribute('name'));
        }
        if (in_array('id', $keep, true) && $node->hasAttribute('id')) {
            $id = preg_replace('/[^\w.\-:]/u', '', $node->getAttribute('id'));
            $id === '' ? $node->removeAttribute('id') : $node->setAttribute('id', $id);
        }

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

    /**
     * Определяет обтекание картинки по исходной разметке: класс/стиль самой
     * картинки или её обёрток (WordPress alignleft/right, MediaWiki thumb
     * tleft/tright, floatleft/right, инлайновый float). Возвращает 'left'/'right'/''.
     */
    private function detectFloat(DOMElement $img): string
    {
        $node = $img;
        for ($depth = 0; $node instanceof DOMElement && $depth < 4; $depth++) {
            $class = mb_strtolower($node->getAttribute('class'));
            $style = mb_strtolower($node->getAttribute('style'));
            $align = mb_strtolower($node->getAttribute('align'));

            if (str_contains($class, 'alignleft') || str_contains($class, 'floatleft')
                || str_contains($class, 'tleft') || $align === 'left'
                || preg_match('/float\s*:\s*left/', $style)) {
                return 'left';
            }
            if (str_contains($class, 'alignright') || str_contains($class, 'floatright')
                || str_contains($class, 'tright') || $align === 'right'
                || preg_match('/float\s*:\s*right/', $style)) {
                return 'right';
            }
            $node = $node->parentNode;
        }

        return '';
    }

    private function handleImage(DOMElement $img, string $baseDir): void
    {
        $src = $img->getAttribute('src');
        $alt = $img->getAttribute('alt');
        $float = $img->getAttribute('data-xi-float');
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

        // Имя по хэшу исходного пути — детерминированно: повторный прогон
        // (--refresh) не плодит дубли в storage и не меняет src в теле.
        $dest = 'media/archive/'.substr(sha1($path), 0, 24).'.'.$ext;
        if (! Storage::disk('public')->exists($dest)) {
            Storage::disk('public')->put($dest, File::get($path));
        }
        $this->imagesCopied++;

        // сбросить все атрибуты, оставить src+alt (+класс обтекания, если был).
        // Путь корне-относительный (/storage/…) — портабелен между хостами/портами
        foreach (iterator_to_array($img->attributes) as $a) {
            $img->removeAttribute($a->name);
        }
        $img->setAttribute('src', '/storage/'.$dest);
        $img->setAttribute('alt', $alt !== '' ? $alt : 'Иллюстрация из архива');

        // Формат Trix: figure.attachment--preview, обтекание — на фигуре
        $figureClasses = ['attachment', 'attachment--preview'];
        if ($float === 'left' || $float === 'right') {
            $figureClasses[] = 'xi-float-'.$float;
        }

        $figure = $img->ownerDocument->createElement('figure');
        $figure->setAttribute('class', implode(' ', $figureClasses));

        if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'p'
            && $this->paragraphOnlyContainsImage($parent, $img)) {
            $parent->parentNode?->replaceChild($figure, $parent);
        } else {
            $parent?->replaceChild($figure, $img);
        }
        $figure->appendChild($img);
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
}
