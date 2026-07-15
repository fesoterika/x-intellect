<?php

namespace App\Http\Middleware;

use App\Models\Redirect;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Таблица redirects, проверяется до основной маршрутизации (Этап 5 плана):
 *  1) 301 со старых архивных URL на новые SEO-slug — сохранение ссылочного веса;
 *  2) обёртки /go/*.html → 302 на внешний ресурс — механизм обхода adblock,
 *     который вырезает прямые ссылки (Дзен, донат и т.п.).
 *
 * Сопоставление идёт и по чистому пути, и по пути с query-string
 * (rawurldecode) — так работают редиректы со старых MediaWiki-адресов
 * вида /wiki/index.php?title=Глоссарий.
 */
class HandleRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            $path = '/'.ltrim($request->path(), '/');

            $candidates = array_unique(array_filter([
                rawurldecode($request->getRequestUri()), // путь + query, юникод
                $path,
                rtrim($path, '/'),
            ]));

            // Более специфичное правило (например, с query-string) приоритетнее
            $redirect = Redirect::whereIn('from_path', $candidates)
                ->orderByRaw('LENGTH(from_path) DESC')
                ->first();

            if ($redirect) {
                $redirect->increment('hits');

                // Без заголовка браузеры кешируют 301 навечно — удалённый или
                // исправленный редирект продолжал бы срабатывать у посетителя.
                // Час кеша: SEO-вес передаётся, ошибки самоисправляются.
                return redirect()
                    ->away($redirect->to_url, $redirect->status_code)
                    ->header('Cache-Control', 'public, max-age=3600');
            }
        }

        return $next($request);
    }
}
