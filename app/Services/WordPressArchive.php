<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Разбор снимков основного сайта (WordPress) в Wayback Machine — материалов,
 * которых нет в офлайн-слепке 2015 года (записи 2013–2017).
 *
 * Слепок Offline Explorer обрывается на 2015 годе и не содержит ни поздних
 * записей, ни их картинок (wp-content/uploads есть только за 2012–2013),
 * поэтому и тело, и иллюстрации, и дату приходится брать из веб-архива.
 *
 * Даты. У записи в разметке темы есть только плашка «Мар 01» — без года.
 * Год даёт помесячный архив WordPress (/2015/03/), где та же плашка стоит
 * рядом со ссылкой на запись; это тот же источник, из которого берёт даты
 * content:sync-dates, только не из слепка, а из снимков.
 */
class WordPressArchive
{
    /** Плашка даты в теме: месяц сокращённо → номер. */
    private const MONTHS_SHORT = [
        'янв' => 1, 'фев' => 2, 'мар' => 3, 'апр' => 4, 'май' => 5, 'июн' => 6,
        'июл' => 7, 'авг' => 8, 'сен' => 9, 'окт' => 10, 'ноя' => 11, 'дек' => 12,
    ];

    /** Плашка «Мар 01» + ссылка на запись — и в архиве, и на самой странице. */
    private const DATEBOX_RE = "~datebox'>\s*<span class='month'>([^<]+)</span>\s*<span class='date'>(\d{1,2})</span>~su";

    private const ARCHIVE_ITEM_RE = "~datebox'>\s*<span class='month'>([^<]+)</span>\s*<span class='date'>(\d{1,2})</span>.*?<h2><a href=\"([^\"]+)\"~su";

    /** Сколько раз переспрашивать CDX, прежде чем признать сбой. */
    private const CDX_ATTEMPTS = 4;

    /** Пауза между запросами к веб-архиву, мкс. */
    public int $sleepUs = 700000;

    /** Основа растущей паузы перед повтором запроса к CDX, с (в тестах — 0). */
    public int $retrySleepS = 5;

    public int $imagesFetched = 0;

    public int $imagesMissing = 0;

    /** Корень диск-кеша скачанного (в тестах — временный каталог). */
    public string $cacheDir;

    /** @var array<string, string>|null путь картинки → метка снимка */
    private ?array $uploads = null;

    public function __construct()
    {
        $this->cacheDir = storage_path('app');
    }

    /**
     * Лучший снимок записи: последний удачный (200) — на нём материал полнее
     * всего, а ранние снимки могут застать запись недописанной.
     *
     * @return array{timestamp: string, original: string}|null
     */
    public function bestSnapshot(string $slug): ?array
    {
        $rows = $this->cdx('x-intellect.org/'.$slug.'/', ['filter' => 'statuscode:200']);

        $best = null;
        foreach ($rows as [$original, $timestamp]) {
            // Хост в CDX бывает с портом (…org:80/…) и с www и без — сверяем путь
            $path = trim((string) parse_url($original, PHP_URL_PATH), '/');
            if (mb_strtolower($path) !== mb_strtolower($slug)) {
                continue;
            }
            if ($best === null || strcmp($timestamp, $best['timestamp']) > 0) {
                $best = ['timestamp' => $timestamp, 'original' => $original];
            }
        }

        return $best;
    }

    /**
     * Заголовок записи — из <title>, как и при импорте из слепка (там его
     * берёт ImportOfflineExplorer::extractTitle): у соседних материалов
     * заголовки собраны так же, включая кавычки.
     *
     * Текст ссылки-заголовка (h2) — только запасной вариант: тема старого
     * сайта местами калечила в нём символы (в снимке 2017 года «Прогнозы на
     * 2015 год″» в h2 уже превратились в «…год?», тогда как в <title> ещё
     * целы). Порчу это не лечит — к последним снимкам сайт успел испортить
     * заголовок и в <title>, — но и не добавляет.
     */
    public function title(string $html): ?string
    {
        if (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
            // Суффикс сайта отрезаем ПОСЛЕ раскодирования: в разметке тире
            // записано мнемоникой (X &#8212; ИНТЕЛЛЕКТ)
            $t = $this->cleanTitle($m[1]);
            $t = $t === null ? null : trim(preg_replace('/\s*:\s*X\s*[—–-]\s*ИНТЕЛЛЕКТ\s*$/u', '', $t));
            if ($t !== null && $t !== '') {
                return $t;
            }
        }

        if (preg_match('~<div class="title">.*?<h2><a[^>]*>(.*?)</a>~su', $html, $m)) {
            return $this->cleanTitle($m[1]);
        }

        return null;
    }

    /** Тело записи (div.entry) — без служебного обвеса темы. */
    public function entryHtml(string $html): ?string
    {
        $crawler = new Crawler($html);
        foreach (['.entry', '.post-content', '.entry-content'] as $sel) {
            $node = $crawler->filter($sel);
            if ($node->count() && filled(trim(strip_tags($node->first()->html(''))))) {
                return $node->first()->html('');
            }
        }

        return null;
    }

    /** Плашка даты на самой странице записи: [месяц, день] без года. */
    public function dateBox(string $html): ?array
    {
        if (! preg_match(self::DATEBOX_RE, $html, $m)) {
            return null;
        }
        $month = self::MONTHS_SHORT[mb_strtolower(trim($m[1]))] ?? null;

        return $month === null ? null : [$month, (int) $m[2]];
    }

    /**
     * Точные даты записей из помесячных архивов WordPress в веб-архиве.
     *
     * @return array<string, string> slug записи → Y-m-d
     */
    public function postDates(int $fromYear, int $toYear): array
    {
        // Обход стоит ~150 запросов к CDX, а ответ неизменен: архивы старого
        // сайта давно застыли. Без кеша каждый повторный прогон ждёт минуты.
        $cache = $this->cacheDir."/wayback-postdates-{$fromYear}-{$toYear}.json";
        if (is_file($cache)) {
            return json_decode((string) file_get_contents($cache), true) ?: [];
        }

        $dates = [];

        for ($year = $fromYear; $year <= $toYear; $year++) {
            for ($month = 1; $month <= 12; $month++) {
                $path = sprintf('%04d/%02d/', $year, $month);
                $snapshot = $this->bestSnapshot(rtrim($path, '/'));
                if ($snapshot === null) {
                    continue;
                }

                $html = $this->fetchSnapshot($snapshot['timestamp'], $snapshot['original']);
                if ($html === null) {
                    continue;
                }

                preg_match_all(self::ARCHIVE_ITEM_RE, $html, $posts, PREG_SET_ORDER);
                foreach ($posts as [, $monthName, $day, $href]) {
                    $m = self::MONTHS_SHORT[mb_strtolower(trim($monthName))] ?? null;
                    $slug = mb_strtolower(basename(rtrim((string) parse_url($href, PHP_URL_PATH), '/')));
                    if ($m === null || $slug === '' || $slug === '.') {
                        continue;
                    }
                    // Архив за месяц — источник года; месяц плашки надёжнее пути
                    $dates[$slug] ??= sprintf('%04d-%02d-%02d', $year, $m, (int) $day);
                }
            }
        }

        File::ensureDirectoryExists(dirname($cache));
        File::put($cache, json_encode($dates, JSON_UNESCAPED_SLASHES));

        return $dates;
    }

    /**
     * Переписывает src картинок на локальные файлы: сперва офлайн-слепок
     * (там лежат картинки 2012–2013), затем веб-архив (кеш на диске).
     *
     * Путь подставляется абсолютный, но без ведущего слэша — чистильщику
     * отдаётся baseDir='/', и realpath собирает исходный путь обратно.
     * Так имя файла в storage остаётся sha1(реальный путь), как у импорта
     * из слепка: повторный прогон не плодит дубли.
     */
    public function localizeImages(string $entryHtml, ?string $snapshotDir): string
    {
        return preg_replace_callback(
            '~(<img\b[^>]*\bsrc=")([^"]+)(")~i',
            function (array $m) use ($snapshotDir): string {
                $local = $this->localImage($m[2], $snapshotDir);
                if ($local === null) {
                    $this->imagesMissing++;

                    return $m[1].$m[2].$m[3];   // чистильщик выбросит внешний src
                }
                $this->imagesFetched++;

                return $m[1].ltrim($local, '/').$m[3];
            },
            $entryHtml,
        ) ?? $entryHtml;
    }

    /** Абсолютный путь к локальной копии картинки записи, если она есть. */
    private function localImage(string $src, ?string $snapshotDir): ?string
    {
        $path = ltrim((string) parse_url($src, PHP_URL_PATH), '/');
        if ($path === '' || ! Str::contains($path, 'wp-content/')) {
            return null;
        }
        // Снимки Wayback подставляют свой префикс — оставляем путь сайта
        if (preg_match('~/(wp-content/.*)$~', $path, $m)) {
            $path = $m[1];
        }

        foreach ($this->candidates($path) as $candidate) {
            if ($snapshotDir && is_file($file = $snapshotDir.'/'.$candidate)) {
                return realpath($file) ?: null;
            }
        }

        foreach ($this->candidates($path) as $candidate) {
            if ($local = $this->fetchUpload($candidate)) {
                return $local;
            }
        }

        return null;
    }

    /**
     * Файл картинки и его запасной вариант: в разметке стоит миниатюра
     * (…-150x150.jpg), а веб-архив местами снял только полноразмерную —
     * лучше показать её, чем потерять иллюстрацию.
     *
     * @return array<int, string>
     */
    private function candidates(string $path): array
    {
        $full = preg_replace('~-\d+x\d+(\.\w+)$~', '$1', $path);

        return $full !== null && $full !== $path ? [$path, $full] : [$path];
    }

    /** Качает картинку из веб-архива в кеш; null — снимка нет. */
    private function fetchUpload(string $path): ?string
    {
        $cache = $this->cacheDir.'/wayback-uploads/'.$path;
        if (is_file($cache)) {
            return realpath($cache) ?: null;
        }
        if (is_file($cache.'.404')) {
            return null;
        }

        $timestamp = $this->uploadsIndex()[mb_strtolower($path)] ?? null;
        if ($timestamp === null) {
            File::ensureDirectoryExists(dirname($cache));
            File::put($cache.'.404', '');

            return null;   // веб-архив этот файл не снимал
        }

        try {
            $resp = Http::retry(2, 3000)->timeout(60)
                ->get('https://web.archive.org/web/'.$timestamp.'id_/http://www.x-intellect.org/'.$path);
            if ($resp->status() === 429) {
                sleep(max(1, $this->retrySleepS * 3));   // веб-архив просит подождать
                $resp = Http::timeout(60)
                    ->get('https://web.archive.org/web/'.$timestamp.'id_/http://www.x-intellect.org/'.$path);
            }
        } catch (\Throwable) {
            return null;
        }
        usleep($this->sleepUs);

        if ($resp->ok() && $resp->body() !== '') {
            File::ensureDirectoryExists(dirname($cache));
            File::put($cache, $resp->body());

            return realpath($cache) ?: null;
        }

        // Вечный маркер — ТОЛЬКО на настоящий 404: файл в архиве числится
        // (мы пришли сюда по индексу CDX), но снимка нет. Троттлинг и сбои
        // (429/5xx) — временные, пометка сделала бы потерю картинки вечной.
        if ($resp->status() === 404) {
            File::ensureDirectoryExists(dirname($cache));
            File::put($cache.'.404', '');
        }

        return null;
    }

    /**
     * Карта заснятых веб-архивом файлов wp-content/uploads: путь → метка.
     * Один префиксный запрос вместо запроса на каждую картинку.
     *
     * Публична, чтобы вызывающий мог построить её заранее: пустая карта
     * означает «веб-архив не снимал картинок», и строить её посреди импорта
     * рискованно — сбой запроса оставил бы страницы без иллюстраций.
     *
     * @return array<string, string>
     *
     * @throws \RuntimeException если веб-архив не ответил
     */
    public function uploadsIndex(): array
    {
        if ($this->uploads !== null) {
            return $this->uploads;
        }

        $cache = $this->cacheDir.'/wayback-uploads/index.json';
        if (is_file($cache)) {
            return $this->uploads = json_decode((string) file_get_contents($cache), true) ?: [];
        }

        // Звёздочку в url CDX не принимает вместе с matchType=prefix — молча
        // отдаёт пустой ответ; префикс задаём только matchType.
        $this->uploads = [];
        foreach ($this->cdx('x-intellect.org/wp-content/uploads/', [
            'matchType' => 'prefix',
            'filter' => 'statuscode:200',
            'collapse' => 'original',
        ]) as [$original, $timestamp]) {
            $path = ltrim((string) parse_url($original, PHP_URL_PATH), '/');
            if ($path !== '') {
                $this->uploads[mb_strtolower($path)] = $timestamp;
            }
        }

        File::ensureDirectoryExists(dirname($cache));
        File::put($cache, json_encode($this->uploads, JSON_UNESCAPED_SLASHES));

        return $this->uploads;
    }

    /** Скачивает сырой снимок (id_ — без тулбара веб-архива), с диск-кешем. */
    public function fetchSnapshot(string $timestamp, string $original): ?string
    {
        $url = 'https://web.archive.org/web/'.$timestamp.'id_/'.$original;
        $cache = $this->cacheDir.'/wayback-posts/'.sha1($url).'.html';
        if (is_file($cache)) {
            return (string) file_get_contents($cache);
        }
        if (is_file($cache.'.404')) {
            return null;
        }

        try {
            $resp = Http::retry(2, 5000)->timeout(90)->get($url);
            if ($resp->status() === 429) {
                sleep(max(1, $this->retrySleepS * 6));   // веб-архив просит подождать
                $resp = Http::timeout(90)->get($url);
            }
        } catch (\Throwable) {
            return null;
        }
        usleep($this->sleepUs);

        if (! $resp->ok()) {
            // Вечный маркер — только на настоящий 404; троттлинг и сбои временны
            if ($resp->status() === 404) {
                File::ensureDirectoryExists(dirname($cache));
                File::put($cache.'.404', '');
            }

            return null;
        }

        File::ensureDirectoryExists(dirname($cache));
        File::put($cache, $resp->body());

        return $resp->body();
    }

    /**
     * Раздел по слагу/заголовку (эвристика, согласована с пользователем).
     * null — служебная страница, не импортируем.
     */
    public function sectionFor(string $slug, string $title): ?string
    {
        $t = mb_strtolower($title);

        $has = fn (string $re) => (bool) preg_match('/'.$re.'/u', $t);

        if ($slug === 'hello' || $has('приветствие от представителей')) {
            return 'hello';
        }
        if ($has('связь с представителем|консультац|отзыв')) {
            return null; // сервис — скип
        }
        if ($has('правил|граммат|стенограмм')) {
            return 'rules';
        }
        if ($has('монографи|библиотек')) {
            return 'library';
        }
        if ($has('дайджест')) {
            return 'articles';
        }
        if ($has('кольцо|беседа с силами|взаимодействие и манипул')) {
            return 'courses';
        }
        if ($has('проект|ноосфер|изосфер|душа|картины учител|мужчина и женщина|параллел|биоэкран|целительств|эталониз')) {
            return 'projects';
        }
        if ($has('лотос|талисман|тандем|камни|инкарнац|чистк|частот|деструктивн|целительств')) {
            return 'articles';
        }
        if ($has('глаз|уш[её]л|день рождения|53 года|40 дней|памят|миссия|мнение сил о сайте|ченнелинг')) {
            return 'about';
        }

        return 'articles'; // прочее содержательное → Статьи
    }

    /**
     * Запрос к CDX API веб-архива.
     *
     * Пустой ответ означает «снимков нет», поэтому сбой запроса ни в коем
     * случае нельзя выдавать за пустой ответ: CDX регулярно отвечает 429/503,
     * и молчаливое [] превращало живой материал в «его нет в архиве».
     * Не смогли спросить — говорим об этом исключением.
     *
     * @return array<int, array{0: string, 1: string}> [original, timestamp]
     *
     * @throws \RuntimeException если веб-архив так и не ответил
     */
    private function cdx(string $url, array $params = []): array
    {
        $query = [
            'url' => $url,
            'output' => 'json',
            'fl' => 'original,timestamp',
            'limit' => '10000',
            ...$params,
        ];

        $lastError = 'нет ответа';
        for ($attempt = 1; $attempt <= self::CDX_ATTEMPTS; $attempt++) {
            try {
                $resp = Http::timeout(120)->get('https://web.archive.org/cdx/search/cdx', $query);
                if ($resp->ok()) {
                    usleep($this->sleepUs);
                    $rows = $resp->json() ?: [];
                    array_shift($rows);   // строка заголовков

                    return $rows;
                }
                $lastError = 'HTTP '.$resp->status();
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }

            if ($attempt < self::CDX_ATTEMPTS && $this->retrySleepS > 0) {
                sleep($attempt * $this->retrySleepS);   // веб-архив просит подождать
            }
        }

        throw new \RuntimeException("CDX не ответил ({$lastError}): {$url}");
    }

    /**
     * Кавычки в заголовке оставляем как есть, даже кривые («Прогнозы на 2015
     * год″): так они стоят и у страниц, импортированных из слепка.
     */
    private function cleanTitle(string $raw): ?string
    {
        $t = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = trim(preg_replace('/\s+/u', ' ', $t));

        return $t !== '' && ! preg_match('/Страница не найдена|File moved|403|Forbidden/iu', $t) ? $t : null;
    }
}
