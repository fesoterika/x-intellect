<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Редиректы (301 архивные URL, 302 /go/*-обёртки) — глобально,
        // до маршрутизации: старые адреса вроде /go/dzen.html не имеют
        // собственных маршрутов и иначе дали бы 404
        $middleware->prepend(\App\Http\Middleware\HandleRedirects::class);

        // Защитные заголовки — самым первым в стеке, ПОСЛЕ prepend редиректов
        // (prepend кладёт в начало, так что этот слой оборачивает предыдущий):
        // HandleRedirects отвечает, не вызывая $next, и заголовки на его 301/302
        // из более позднего слоя уже не попали бы
        $middleware->prepend(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // JSON-ошибки: для api/* и для XHR с Accept: application/json
        // (загрузки из Trix-редактора) — иначе валидация отвечает
        // редиректом и клиент не видит причину отказа
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
