<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Режим технических работ, включаемый из админки (вкладка «Обзор»).
 *
 * Посетители на любом URL получают заглушку со статусом 503 + Retry-After:
 * поисковики трактуют это как временную недоступность и не выбрасывают
 * страницы из индекса (в отличие от 200/404 на заглушке). robots.txt и
 * sitemap.xml - статические файлы в public/, веб-сервер отдаёт их сам,
 * поэтому режим на них не влияет. Редиректы (HandleRedirects) стоят раньше
 * в стеке и продолжают работать - карта 301 для поисковиков сохраняется.
 */
class MaintenanceMode
{
    /**
     * Пути, открытые и в режиме техработ: вход для редакторов, сама
     * админка (её защищает auth+role) и healthcheck. Маска admin/* нужна,
     * чтобы гость по прямой ссылке в админку попал на редирект к /login,
     * а не на заглушку.
     */
    private const ALLOWED_PATHS = [
        'up',
        'login',
        'logout',
        'forgot-password',
        'reset-password/*',
        'admin',
        'admin/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return static::shouldBlock($request) ? static::stub() : $next($request);
    }

    /** Используется и из обработчика 404 (URL вне маршрутов - web-стек не запускается) */
    public static function shouldBlock(Request $request): bool
    {
        return Setting::maintenanceEnabled()
            && ! $request->user()?->isEditor()
            && ! $request->is(...self::ALLOWED_PATHS);
    }

    public static function stub(): Response
    {
        return response()
            ->view('errors.503', [], 503)
            ->header('Retry-After', '3600')
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
