<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Подача адресов в IndexNow — протокол мгновенного уведомления поисковиков
 * об изменениях. Яндекс поддерживает его официально; Bing использует тот же
 * протокол и обменивается поданными адресами, поэтому хватает одной отправки.
 *
 * Работает только при заданном INDEXNOW_KEY: без ключа отправка молча
 * пропускается, чтобы локальная разработка и тесты не ходили наружу.
 *
 * Ключ подтверждается файлом public/<key>.txt (команда `indexnow:key`).
 */
class IndexNow
{
    public function enabled(): bool
    {
        return filled($this->key());
    }

    public function key(): string
    {
        return (string) config('indexnow.key');
    }

    /**
     * Базовый адрес сайта — единственный источник и для абсолютных адресов,
     * и для keyLocation. Раньше host() брался из indexnow.host, а адреса
     * достраивались от app.url: стоило им разойтись, и normalize() отбрасывал
     * вообще всё — хост достроенного адреса не совпадал с проверяемым.
     */
    public function baseUrl(): string
    {
        return rtrim((string) (config('indexnow.host') ?: config('app.url')), '/');
    }

    public function host(): string
    {
        return (string) parse_url($this->baseUrl(), PHP_URL_HOST);
    }

    /**
     * Адрес файла-подтверждения ключа.
     */
    public function keyLocation(): string
    {
        return $this->baseUrl().'/'.$this->key().'.txt';
    }

    /**
     * Путь к файлу-подтверждению внутри public.
     */
    public function keyFilePath(): string
    {
        return public_path($this->key().'.txt');
    }

    /**
     * Приводит адреса к абсолютному виду и отбрасывает чужие хосты:
     * поисковик отклонит весь пакет, если в нём есть посторонний домен.
     *
     * @param  iterable<string>  $urls
     * @return list<string>
     */
    public function normalize(iterable $urls): array
    {
        $base = $this->baseUrl();
        $host = $this->host();
        $out = [];

        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }

            if (! preg_match('~^https?://~i', $url)) {
                $url = $base.'/'.ltrim($url, '/');
            }

            if (parse_url($url, PHP_URL_HOST) !== $host) {
                continue;
            }

            $out[$url] = true;
        }

        return array_keys($out);
    }

    /**
     * Отправляет пакет адресов. Возвращает число фактически поданных.
     *
     * @param  iterable<string>  $urls
     */
    public function submit(iterable $urls): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $urls = $this->normalize($urls);
        if ($urls === []) {
            return 0;
        }

        $sent = 0;

        foreach (array_chunk($urls, (int) config('indexnow.batch', 10000)) as $chunk) {
            try {
                $response = Http::timeout((int) config('indexnow.timeout', 15))
                    ->acceptJson()
                    ->post((string) config('indexnow.endpoint'), [
                        'host' => $this->host(),
                        'key' => $this->key(),
                        'keyLocation' => $this->keyLocation(),
                        'urlList' => array_values($chunk),
                    ]);

                if ($response->successful()) {
                    $sent += count($chunk);

                    continue;
                }

                // 403 — ключ не подтверждён файлом, 422 — адреса не с того хоста.
                Log::warning('IndexNow отклонил пакет', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 300),
                    'count' => count($chunk),
                ]);
            } catch (\Throwable $e) {
                // Недоступность поисковика не должна ронять сохранение страницы.
                Log::warning('IndexNow недоступен: '.$e->getMessage());
            }
        }

        return $sent;
    }
}
