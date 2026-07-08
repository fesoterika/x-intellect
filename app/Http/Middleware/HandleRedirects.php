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
 */
class HandleRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            $path = '/'.ltrim($request->path(), '/');

            $redirect = Redirect::where('from_path', $path)
                ->orWhere('from_path', rtrim($path, '/'))
                ->first();

            if ($redirect) {
                $redirect->increment('hits');

                return redirect()->away($redirect->to_url, $redirect->status_code);
            }
        }

        return $next($request);
    }
}
