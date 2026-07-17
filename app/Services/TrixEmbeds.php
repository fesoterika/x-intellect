<?php

namespace App\Services;

/**
 * HTML-вставки со сторонних ресурсов (плейлисты, видео) в Trix-редакторе.
 * Trix вырезает iframe при разборе и санитизации, а править такой код в
 * WYSIWYG нечем — поэтому вставка живёт как content-вложение (по образцу
 * TrixTables):
 *
 *  - embed():   перед показом формы блок <div class="xi-embed">…</div>
 *               сворачивается в <figure data-trix-attachment="{json}"> с
 *               contentType-меткой; сам код лежит в атрибуте вложения `code`
 *               (внутри атрибута он экранирован, и санитайзер Trix до него не
 *               добирается), выравнивание — в атрибуте `alignment` (те же
 *               кнопки панели, что у картинок). В редакторе на месте блока —
 *               карточка с текстом кода, правка — диалогом (admin.js).
 *  - extract(): при сохранении страницы вложения с нашей меткой
 *               разворачиваются обратно в <div class="xi-embed">код</div>,
 *               выравнивание становится классом (как у картинок — см.
 *               ImageAligner), а код проходит белый список: только iframe.
 */
class TrixEmbeds
{
    /** Метка content-вложения вставки (синхронизирована с admin.js). */
    public const CONTENT_TYPE = 'application/vnd.xi-embed+html';

    /**
     * Замыкающая метка блока. Внутри вставки может быть что угодно, включая
     * свои <div>, поэтому границу блока по </div> не найти — её задаёт этот
     * комментарий (в браузере невидим).
     */
    private const END_MARKER = '<!--/xi-embed-->';

    /** alignment из Trix → CSS-класс блока (те же классы, что у ImageAligner). */
    private const ALIGN = [
        'left' => 'xi-float-left',
        'right' => 'xi-float-right',
        'center' => 'xi-align-center',
        'wide' => 'xi-align-wide',
    ];

    /**
     * Атрибуты iframe, которые переживают санитизацию. Всё остальное
     * вырезается — в том числе srcdoc и on*-обработчики: их содержимое
     * исполнилось бы в origin сайта.
     */
    private const IFRAME_ATTRS = [
        'src', 'width', 'height', 'style', 'class', 'title', 'allow', 'allowfullscreen',
        'allowtransparency', 'frameborder', 'scrolling', 'loading', 'referrerpolicy', 'sandbox',
    ];

    /** <div class="xi-embed"> → content-вложение Trix (для загрузки в редактор). */
    public function embed(?string $body): string
    {
        if (blank($body) || ! str_contains($body, self::END_MARKER)) {
            return (string) $body;
        }

        $pattern = '#<div[^>]*class="xi-embed([^"]*)"[^>]*>(.*?)</div>'.preg_quote(self::END_MARKER, '#').'#is';

        return preg_replace_callback($pattern, function ($m) {
            $attrs = [
                'content' => $this->card($m[2]),
                'contentType' => self::CONTENT_TYPE,
                'code' => $m[2],
            ];

            $alignment = array_search(trim($m[1]), self::ALIGN, true);
            if ($alignment !== false) {
                $attrs['alignment'] = $alignment;
            }

            $json = json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return '<figure data-trix-attachment="'.htmlspecialchars($json, ENT_QUOTES, 'UTF-8').'"></figure>';
        }, $body);
    }

    /** Content-вложение Trix с нашей меткой → блок с кодом (при сохранении). */
    public function extract(?string $body): string
    {
        if (blank($body) || ! str_contains($body, 'data-trix-attachment')) {
            return (string) $body;
        }

        return preg_replace_callback(
            '#<figure[^>]*data-trix-attachment="([^"]*)"[^>]*>.*?</figure>#is',
            function ($m) {
                $attrs = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                if (! is_array($attrs) || ($attrs['contentType'] ?? '') !== self::CONTENT_TYPE) {
                    return $m[0]; // чужое вложение (картинка, таблица) — не трогаем
                }

                return $this->wrap((string) ($attrs['code'] ?? ''), (string) ($attrs['alignment'] ?? ''));
            },
            $body,
        );
    }

    /** Код вставки → блок с границей для embed(). Пустой код блока не создаёт. */
    private function wrap(string $code, string $alignment): string
    {
        $code = $this->sanitize($code);
        if ($code === '') {
            return '';
        }

        $class = 'xi-embed'.(isset(self::ALIGN[$alignment]) ? ' '.self::ALIGN[$alignment] : '');

        return '<div class="'.$class.'">'.$code.'</div>'.self::END_MARKER;
    }

    /**
     * Белый список: из вставки на страницу попадают ТОЛЬКО iframe (плееры,
     * видео, плейлисты) — скрипты, стили и прочая разметка вырезаются. Метод
     * не чистит «плохое» из кода, а собирает блок заново из найденных iframe,
     * так что незнакомое не проходит по определению.
     */
    private function sanitize(string $code): string
    {
        // Замыкающая метка из чужого кода разорвала бы блок при следующей
        // загрузке в редактор
        $code = str_replace(self::END_MARKER, '', $code);

        if (! preg_match_all('#<iframe\b[^>]*>#i', $code, $tags)) {
            return '';
        }

        $frames = [];
        foreach ($tags[0] as $tag) {
            $attrs = $this->iframeAttributes($tag);
            if ($attrs !== null) {
                $frames[] = '<iframe'.$attrs.'></iframe>';
            }
        }

        return implode('', $frames);
    }

    /**
     * Известные атрибуты одного iframe строкой; null — если src отсутствует
     * или ведёт не на http(s)/свой сайт (javascript:, data: и пр.).
     */
    private function iframeAttributes(string $tag): ?string
    {
        $inner = preg_replace('#^<iframe#i', '', rtrim(rtrim($tag, '>'), '/'));
        preg_match_all(
            '/([a-z][a-z0-9-]*)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?/i',
            (string) $inner,
            $pairs,
            PREG_SET_ORDER,
        );

        $out = '';
        $hasSrc = false;
        foreach ($pairs as $pair) {
            $name = strtolower($pair[1]);
            if (! in_array($name, self::IFRAME_ATTRS, true)) {
                continue;
            }

            // атрибут без значения (allowfullscreen) — так и оставляем
            if (! isset($pair[2])) {
                $out .= ' '.$name;
                continue;
            }

            $value = html_entity_decode(trim($pair[2], '"\''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($name === 'src') {
                // только абсолютный http(s), протокол-относительный или свой
                // путь: остальные схемы исполняются в origin сайта
                if (! preg_match('#^(https?:)?//#i', $value) && ! str_starts_with($value, '/')) {
                    return null;
                }
                $hasSrc = true;
            }

            $out .= ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'"';
        }

        return $hasSrc ? $out : null;
    }

    /**
     * Карточка-заглушка для редактора: живую вставку там не показать (Trix
     * санитизирует содержимое вложения), поэтому показываем сам код текстом.
     * Близнец embedCard() в admin.js — правки нужны в обоих местах.
     */
    private function card(string $code): string
    {
        $preview = mb_strimwidth(trim($code), 0, 400, '…');

        return '<div class="xi-embed-card">'
            .'<div class="xi-embed-card__label">HTML-вставка</div>'
            .'<pre class="xi-embed-card__code">'.htmlspecialchars($preview, ENT_QUOTES, 'UTF-8').'</pre>'
            .'</div>';
    }
}
