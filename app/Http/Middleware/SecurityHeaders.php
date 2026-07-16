<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Защитные заголовки для ответов приложения.
 *
 * ВАЖНО: сюда НЕ попадают файлы медиа — /storage/… это симлинк на
 * storage/app/public, его отдаёт веб-сервер, не проходя через PHP. Заголовки
 * для них задаются в public/.htaccess, а первая линия защиты — белый список
 * MIME при загрузке (Media::MIMETYPES).
 *
 * Content-Security-Policy намеренно НЕ ставится: на страницах инлайн-скрипты
 * (JSON-LD, Alpine), под которые политику пришлось бы ослабить до
 * 'unsafe-inline' — то есть почти до нуля.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Сайт нигде не встраивается во фреймы — запрещаем чужие (кликджекинг)
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        // Внешним сайтам отдаём только домен-источник, без полного пути
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // HSTS — только по HTTPS: на http-ответе заголовок игнорируется, а в
        // локальной разработке (http://localhost) навсегда переключил бы
        // браузер на https для всего localhost. Без includeSubDomains —
        // сертификаты поддоменов не проверялись.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
