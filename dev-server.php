<?php

/**
 * Роутер php -S для dev-превью (.claude/launch.json).
 *
 * Встроенный сервер PHP отвечает на Range-запросы кодом 200 и полным файлом —
 * браузер не может перематывать аудио: клик по таймлайну и ±15с сбрасывают
 * позицию на ноль. Здесь статика отдаётся с поддержкой HTTP Range (206).
 * Прод не затрагивает: там статику раздаёт веб-сервер хостинга.
 */
$publicPath = __DIR__.'/public';

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
$path = $publicPath.$uri;

if ($uri !== '/' && ! str_contains($uri, '..') && is_file($path)) {
    xiServeStatic($path);
    exit;
}

// динамика — Laravel (cwd = public, как делает artisan serve)
chdir($publicPath);
require $publicPath.'/index.php';

function xiServeStatic(string $path): void
{
    $types = [
        'css' => 'text/css', 'js' => 'application/javascript', 'mjs' => 'application/javascript',
        'json' => 'application/json', 'html' => 'text/html; charset=UTF-8', 'htm' => 'text/html; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8', 'xml' => 'application/xml',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg',
        'wav' => 'audio/wav', 'flac' => 'audio/flac', 'aac' => 'audio/aac',
        'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'pdf' => 'application/pdf',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
    ];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $type = $types[$ext] ?? (mime_content_type($path) ?: 'application/octet-stream');

    $size = filesize($path);
    $start = 0;
    $end = $size - 1;
    $partial = false;

    // Одиночный диапазон (браузеры не шлют множественные для медиа)
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') {
            $start = max(0, $size - (int) $m[2]); // суффикс: последние N байт
        } else {
            $start = (int) $m[1];
            if ($m[2] !== '') {
                $end = min($end, (int) $m[2]);
            }
        }
        if ($start > $end || $start >= $size) {
            header('HTTP/1.1 416 Range Not Satisfiable');
            header("Content-Range: bytes */{$size}");

            return;
        }
        $partial = true;
    }

    if ($partial) {
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes {$start}-{$end}/{$size}");
    }
    header('Content-Type: '.$type);
    header('Content-Length: '.($end - $start + 1));
    header('Accept-Ranges: bytes');
    header('Cache-Control: no-cache');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        return;
    }

    $fp = fopen($path, 'rb');
    fseek($fp, $start);
    $left = $end - $start + 1;
    while ($left > 0 && ! feof($fp)) {
        $chunk = fread($fp, (int) min(512 * 1024, $left));
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        $left -= strlen($chunk);
        flush();
    }
    fclose($fp);
}
