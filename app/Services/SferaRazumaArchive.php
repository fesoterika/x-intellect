<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Архив старого проекта «Сфера Разума» (sferarazuma.ru) из Wayback Machine.
 *
 * До 2012 года проект вёл А. Г. Глаз под именем «Сфера Разума»; его вики
 * (SphereWiki) хранила стенограммы ченнелингов по датам в категории «Стенограммы».
 * Аудиозаписи этих сеансов лежат в архиве Дмитрия Морозова, а описания — только
 * в веб-архиве. Здесь: список страниц категории и разбор отдельной страницы.
 *
 * Снимки берём не позднее конца 2012 года (граница задана пользователем).
 * Ответы кешируются на диск: страниц под сотню, а веб-архив легко отвечает 429.
 */
class SferaRazumaArchive
{
    private const SNAPSHOT = '20121231';

    private const CATEGORY = 'http://sferarazuma.ru/wiki/index.php/Категория:Стенограммы';

    public function __construct(private OfflineSnapshotIndex $index) {}

    /**
     * Карта «нормализованная дата → заголовок страницы» из категории «Стенограммы».
     *
     * @return array<string, string>  ключ 20070730a (кириллица→латиница), значение — исходный заголовок
     */
    public function transcriptPages(): array
    {
        $html = $this->fetch(self::CATEGORY);
        if ($html === null) {
            return [];
        }
        $content = $this->contentBody($html);

        $pages = [];
        if (preg_match_all('/<a\b[^>]*>(.*?)<\/a>/su', $content, $m)) {
            foreach ($m[1] as $inner) {
                $title = trim(preg_replace('/\s+/u', ' ', strip_tags($inner)));
                if (preg_match('/^(?:19|20)\d{6}[a-zA-Zа-яА-Я]{0,3}$/u', $title)) {
                    $pages[$this->normalizeKey($title)] = $title;
                }
            }
        }

        return $pages;
    }

    /**
     * Разбор страницы-стенограммы: метаданные и очищенное тело.
     *
     * @return array{title: string, meta: array<string,string>, body: string}|null
     */
    public function parsePage(string $title, ArchiveHtmlCleaner $cleaner): ?array
    {
        $url = 'http://sferarazuma.ru/wiki/index.php/'.rawurlencode($title);
        $html = $this->fetch($url);
        if ($html === null) {
            return null;
        }
        $content = $this->contentBody($html);
        if ($content === '') {
            return null;
        }

        // Оглавление MediaWiki (toc) — не контент; убираем, иначе его пункты
        // («Содержание», «Стенограмма») выдают себя за заголовок и мету.
        $content = preg_replace('#<table[^>]*id="toc".*?</table>#su', '', $content);
        $content = preg_replace('#<div[^>]*id="toc".*?</div>\s*#su', '', $content);

        // Заголовок — из ПЕРВОГО mw-headline: там либо «дата Название темы»
        // («20090119 Прогноз на 2009 год»), либо только дата. Глубже лезть нельзя:
        // следующие заголовки — «Стенограмма», «Диалог (после обработки)» и т.п.,
        // это разделы, а не название. Если сверх даты пусто — заголовок соберём
        // из метаданных (см. composePage).
        $heading = '';
        if (preg_match('/<span class="mw-headline"[^>]*>(.*?)<\/span>/su', $content, $hm)) {
            $t = trim(preg_replace('/\s+/u', ' ', strip_tags($hm[1])));
            $t = trim(preg_replace('/^(?:19|20)\d{6}[a-zA-Zа-яА-Я]{0,3}\s*/u', '', $t));
            $heading = trim($t, " \t\n\r\0\x0B.-—");
        }

        $meta = $this->extractMeta($content);

        // Ссылки на sferarazuma.ru не оставляем (требование пользователя):
        // mp3-ссылку убираем целиком (будет плеер), прочие разворачиваем в текст.
        $content = preg_replace('#<a\b[^>]*sferarazuma\.ru/mp3[^>]*>.*?</a>#su', '', $content);
        $content = preg_replace('#<a\b[^>]*sferarazuma\.ru[^>]*>(.*?)</a>#su', '$1', $content);
        // Внутренние вики-ссылки SphereWiki (/wiki/…) на нашем сайте ведут в
        // никуда — разворачиваем в текст. Пустые якоря разделов убираем.
        $content = preg_replace('#<a\b[^>]*href="/wiki/[^"]*"[^>]*>(.*?)</a>#su', '$1', $content);
        $content = preg_replace('#<a\b[^>]*></a>#su', '', $content);
        $body = $cleaner->clean($content, '/nonexistent', keepBlockquote: false);

        // Заголовки, начинающиеся с даты («20090119», «20090119 Прогноз…») —
        // служебные: дата и тема уже в названии страницы. Убираем ПОСЛЕ cleaner,
        // когда span-обёртки развёрнуты. Заголовки-разделы («Стенограмма») остаются.
        $body = preg_replace('#<h[1-6][^>]*>\s*(?:19|20)\d{6}[a-zA-Zа-яА-Я]{0,3}\b.*?</h[1-6]>#su', '', $body);

        // Вводный блок метаданных (Проект/Силы/Посредник/Ведущий списком) уберём
        // из тела: команда покажет их отдельной таблицей, как у сеансов вики.
        $body = preg_replace('#<ul>\s*(<li>\s*(?:Проект|Силы|Посредник|Ведущий|Дата)\b.*?)</ul>#su', '', $body, 1);

        return ['title' => $heading, 'meta' => $meta, 'body' => trim($body)];
    }

    /** Проект/Силы/Посредник/Ведущий из списка в начале страницы. */
    private function extractMeta(string $content): array
    {
        $meta = [];
        if (preg_match_all('/<li[^>]*>\s*(Проект|Силы|Посредник|Ведущий|Дата)\s*:?\s*(.*?)<\/li>/su', $content, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $value = trim(preg_replace('/\s+/u', ' ', strip_tags($row[2])));
                if ($value !== '' && ! isset($meta[$row[1]])) {
                    $meta[$row[1]] = $value;
                }
            }
        }

        return $meta;
    }

    private function contentBody(string $html): string
    {
        // SphereWiki (MediaWiki 1.15) — контейнер bodyContent/mw-content-text
        foreach ([
            '#<!-- start content -->(.*?)<!-- end content -->#s',
            '#<div id="mw-content-text"[^>]*>(.*?)<div class="printfooter">#s',
            '#<div id="bodyContent"[^>]*>(.*?)<div class="printfooter">#s',
        ] as $pat) {
            if (preg_match($pat, $html, $m)) {
                return $m[1];
            }
        }

        return '';
    }

    /** Кириллическая «а» в датах (20070730а) → латинская, регистр вниз. */
    public function normalizeKey(string $date): string
    {
        return mb_strtolower(strtr(trim($date), ['а' => 'a', 'А' => 'A', 'с' => 'c', 'е' => 'e', 'о' => 'o']));
    }

    /** Скачивает сырой снимок (id_) не позднее SNAPSHOT, с диск-кешем. */
    private function fetch(string $url): ?string
    {
        $cache = storage_path('app/sferarazuma/'.sha1($url).'.html');
        if (is_file($cache)) {
            return (string) file_get_contents($cache);
        }
        if (is_file($cache.'.404')) {
            return null; // уже знаем, что снимка нет
        }

        // Просим ближайший к концу 2012 снимок; Wayback сам отдаёт 302 на
        // реальную дату. Часть страниц веб-архив не снимал — тогда 404, и это
        // штатная ситуация (будет заглушка), а не повод падать.
        $wb = 'https://web.archive.org/web/'.self::SNAPSHOT.'id_/'.$url;
        try {
            $resp = Http::retry(2, 3000)->timeout(90)->get($wb);
        } catch (\Throwable) {
            return null;
        }
        if (! $resp->ok()) {
            File::ensureDirectoryExists(dirname($cache));
            File::put($cache.'.404', '');  // не долбить веб-архив повторно
            usleep(500000);

            return null;
        }
        $html = $resp->body();
        File::ensureDirectoryExists(dirname($cache));
        File::put($cache, $html);
        usleep(700000); // веб-архив не любит частых запросов

        return $html;
    }
}
