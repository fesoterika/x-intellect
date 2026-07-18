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

        // Режим техработ - после StartSession (нужна проверка «залогинен ли
        // редактор»), но ДО SubstituteBindings: иначе несуществующий slug
        // кинет 404 раньше заглушки и посетитель увидит страницу 404 с меню
        // сайта. Removals применяются до appends (см. getMiddlewareGroups),
        // поэтому remove+append переставляет SubstituteBindings в конец группы.
        $middleware->web(
            remove: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            append: [
                \App\Http\Middleware\MaintenanceMode::class,
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ],
        );

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

        // URL, не совпавший ни с одним маршрутом, отдаёт 404 ДО запуска
        // web-группы - middleware техработ там не срабатывает. Чтобы страница
        // 404 (с меню сайта) не светилась посетителям во время работ,
        // подменяем её той же заглушкой 503
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if (! $request->expectsJson() && \App\Http\Middleware\MaintenanceMode::shouldBlock($request)) {
                return \App\Http\Middleware\MaintenanceMode::stub();
            }
        });
    })->create();
