<?php

namespace App\Services;

/**
 * Индекс вики-страниц офлайн-слепка Offline Explorer.
 *
 * Зачем: OE раскладывает файлы не только в корень `wiki/`, но и по переполненным
 * подпапкам `%&Ovr0`…`%&Ovr119`, а длинные имена обрезает и заменяет хвост хэшем.
 * Поэтому ни `File::glob($base.'/index.php@title=*')`, ни фильтр «в имени нет &»
 * не годятся: в выборку не попадает часть статей (Сеанс с силами 20070730b лежал
 * в `%&Ovr3/`), зато попадают просмотры diff и старых ревизий с обрезанными именами.
 *
 * Здесь вид страницы определяется по СОДЕРЖИМОМУ (mw.config + разметка):
 * у одной статьи бывают сотни файлов-вариантов, из которых канонический — один.
 */
class OfflineSnapshotIndex
{
    public const CANONICAL = 'canonical';

    public const OLD_REVISION = 'oldrev';

    public const DIFF = 'diff';

    public const REDIRECT = 'redirect';

    /** @var array<string, array<string, string>> Кеш карт имён по папке слепка. */
    private array $fileMaps = [];

    /** Служебные пространства имён по заголовку (ns-0 гейт ловит их и сам). */
    private const NS_PREFIXES = [
        'Служебная:', 'Обсуждение:', 'Участник:', 'Шаблон:', 'Категория:',
        'Файл:', 'Изображение:', 'MediaWiki:', 'Справка:', 'Медиа:',
    ];

    /**
     * Строит индекс: заголовок → лучший файл страницы.
     *
     * @return array<string, array{title: string, path: string, kind: string, body_len: int, variants: int, mp3: array<int, string>}>
     *                                                                                                                                Ключ — нормализованный заголовок (нижний регистр, ё→е).
     */
    public function build(string $wikiDir): array
    {
        $pages = [];
        foreach ($this->files($wikiDir) as $path) {
            $html = @file_get_contents($path);
            if ($html === false) {
                continue;
            }

            $title = $this->titleOf($html);
            if ($title === null || ! $this->isArticleView($html)) {
                continue;
            }
            foreach (self::NS_PREFIXES as $ns) {
                if (str_starts_with($title, $ns)) {
                    continue 2;
                }
            }
            // Страницы-файлы (20070730b.mp3, Chakra.jpg) — не статьи
            if (preg_match('/\.(mp3|jpe?g|png|gif|pdf|docx?)$/i', $title)) {
                continue;
            }

            $kind = $this->classify($html);
            if ($kind === self::DIFF || $kind === self::REDIRECT) {
                continue;
            }

            $body = $this->contentHtml($html);
            if ($body === null) {
                continue;
            }
            $len = mb_strlen($this->plainText($body));
            if ($len < 25 || $this->isStub($body)) {
                continue;
            }

            $key = $this->normalize($title);
            $entry = [
                'title' => $title,
                'path' => $path,
                'kind' => $kind,
                'body_len' => $len,
                'variants' => 1,
                'mp3' => $this->mp3Links($body),
            ];

            if (! isset($pages[$key])) {
                $pages[$key] = $entry;

                continue;
            }

            $pages[$key]['variants']++;
            // mp3 собираем со ВСЕХ вариантов: ссылка на запись могла быть добавлена
            // в одной ревизии и убрана в другой
            $pages[$key]['mp3'] = array_values(array_unique(
                array_merge($pages[$key]['mp3'], $entry['mp3'])
            ));

            if ($this->rank($entry) < $this->rank($pages[$key])) {
                $entry['variants'] = $pages[$key]['variants'];
                $entry['mp3'] = $pages[$key]['mp3'];
                $pages[$key] = $entry;
            }
        }

        return $pages;
    }

    /**
     * Карта «имя файла → путь» по всем папкам слепка.
     *
     * Нужна для ссылок: href в HTML остался от исходной структуры сайта, а OE
     * растащил файлы по %&OvrN — страница из %&Ovr15 ссылается на файл, который
     * лежит в %&Ovr3, и относительный путь не резолвится. Имя же уникально:
     * длинные имена OE обрезает и дописывает хэш пути.
     *
     * @return array<string, string>
     */
    public function fileMap(string $wikiDir): array
    {
        $key = rtrim($wikiDir, '/');
        if (isset($this->fileMaps[$key])) {
            return $this->fileMaps[$key];
        }
        $map = [];
        foreach ($this->files($wikiDir) as $path) {
            $map[basename($path)] ??= $path;
        }

        return $this->fileMaps[$key] = $map;
    }

    /** Все файлы страниц: корень wiki/ + подпапки %&OvrN. */
    public function files(string $wikiDir): array
    {
        $root = rtrim($wikiDir, '/');
        $dirs = [$root];
        foreach ((array) @scandir($root) as $name) {
            if (str_starts_with($name, '%&Ovr') && is_dir($root.'/'.$name)) {
                $dirs[] = $root.'/'.$name;
            }
        }

        $files = [];
        foreach ($dirs as $dir) {
            foreach ((array) @scandir($dir) as $name) {
                if (! str_starts_with($name, 'index.php@title=')) {
                    continue;
                }
                $path = $dir.'/'.$name;
                if (is_file($path)) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }

    /**
     * Канонический просмотр статьи или устаревший срез?
     *
     * Имя файла ненадёжно (OE обрезает), поэтому смотрим разметку: таблица diff,
     * баннер старой ревизии, сообщение о перенаправлении.
     */
    public function classify(string $html): string
    {
        if (preg_match('/<table class=[\'"]diff|mw-diff-otitle|mw-diff-ntitle/', $html)) {
            return self::DIFF;
        }
        if (str_contains($html, 'class="redirectMsg"')) {
            return self::REDIRECT;
        }
        if (preg_match('/id="mw-revision-info|class="mw-revision/', $html)) {
            return self::OLD_REVISION;
        }

        return self::CANONICAL;
    }

    /** Заголовок из mw.config (firstHeading у diff-страниц врёт). */
    public function titleOf(string $html): ?string
    {
        if (! preg_match('/"wgTitle":"((?:[^"\\\\]|\\\\.)*)"/', $html, $m)) {
            return null;
        }
        $title = json_decode('"'.$m[1].'"');
        if (! is_string($title)) {
            $title = str_replace(['\\"', '\\/'], ['"', '/'], $m[1]);
        }
        $title = trim($title);

        return $title !== '' ? $title : null;
    }

    /** Статья основного пространства имён в режиме просмотра? */
    public function isArticleView(string $html): bool
    {
        if (! preg_match('/"wgNamespaceNumber":(-?\d+)/', $html, $m) || $m[1] !== '0') {
            return false;
        }
        if (preg_match('/"wgAction":"(\w+)"/', $html, $a) && $a[1] !== 'view') {
            return false;
        }

        return true;
    }

    public function contentHtml(string $html): ?string
    {
        if (! preg_match('#<div id="mw-content-text"[^>]*>(.*?)<div class="printfooter">#s', $html, $m)) {
            return null;
        }

        return $m[1];
    }

    /** Красная ссылка / пустая страница MediaWiki. */
    public function isStub(string $bodyHtml): bool
    {
        $text = $this->plainText($bodyHtml);

        return str_contains($text, 'В настоящее время на этой странице нет текста')
            || str_contains($text, 'текст на данной странице отсутствует');
    }

    public function normalize(string $title): string
    {
        return str_replace(['ё', 'Ё'], ['е', 'е'], mb_strtolower(trim($title)));
    }

    private function mp3Links(string $bodyHtml): array
    {
        preg_match_all('/href="([^"]*\.mp3[^"]*)"/i', $bodyHtml, $m);

        return array_values(array_unique($m[1]));
    }

    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** Канонический просмотр важнее длины; при равенстве — длиннее тело. */
    private function rank(array $entry): array
    {
        return [$entry['kind'] === self::CANONICAL ? 0 : 1, -$entry['body_len']];
    }
}
