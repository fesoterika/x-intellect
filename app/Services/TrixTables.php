<?php

namespace App\Services;

/**
 * Таблицы в Trix-редакторе. Trix не умеет редактировать <table> и вырезает
 * их при разборе HTML, поэтому:
 *
 *  - embed():   перед показом формы каждая <table> тела оборачивается в
 *               content-вложение Trix (<figure data-trix-attachment="{json}">)
 *               с contentType-меткой. Такое вложение Trix сохраняет как
 *               непрозрачный блок и рендерит таблицу внутри редактора;
 *               правка — модальным редактором (admin.js).
 *  - extract(): при сохранении страницы вложения с нашей меткой
 *               разворачиваются обратно в чистый <table> (тело в БД и
 *               публичный рендер остаются без Trix-обвязки).
 *
 * Вложения других типов (картинки и пр.) не трогаются.
 */
class TrixTables
{
    /** Метка content-вложения таблицы (синхронизирована с admin.js). */
    public const CONTENT_TYPE = 'application/vnd.xi-table+html';

    /** <table> → content-вложение Trix (для загрузки тела в редактор). */
    public function embed(?string $body): string
    {
        if (blank($body) || ! str_contains($body, '<table')) {
            return (string) $body;
        }

        return preg_replace_callback('#<table\b.*?</table>#is', function ($m) {
            // contenteditable="false": таблица рендерится ВНУТРИ contenteditable-
            // области Trix — без запрета в неё можно печатать прямо в редакторе,
            // но Trix такие правки не отслеживает и молча откатывает; правка
            // только через модалку (admin.js)
            $table = preg_replace('/^<table\b/i', '<table contenteditable="false"', $m[0]);
            $json = json_encode(
                ['content' => $table, 'contentType' => self::CONTENT_TYPE],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );

            return '<figure data-trix-attachment="'.htmlspecialchars($json, ENT_QUOTES, 'UTF-8').'"></figure>';
        }, $body);
    }

    /** Content-вложение Trix с нашей меткой → чистый <table> (при сохранении). */
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
                    return $m[0]; // чужое вложение (картинка, файл) — не трогаем
                }

                return $this->sanitize((string) ($attrs['content'] ?? ''));
            },
            $body,
        );
    }

    /**
     * Санитизация HTML таблицы из редактора: активное содержимое и
     * inline-обработчики вырезаются (контент правят доверенные роли,
     * но данные проходят через клиентский DOM).
     */
    private function sanitize(string $html): string
    {
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|form)\b.*?</\s*\1\s*>#is', '', $html);
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|form)\b[^>]*/?>#i', '', $html);
        $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        $html = preg_replace('/\s(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\2/i', '', $html);
        // служебный атрибут редактора (см. embed) в БД не попадает
        $html = preg_replace('/\scontenteditable\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);

        return trim($html);
    }
}
