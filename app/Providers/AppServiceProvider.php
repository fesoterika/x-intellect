<?php

namespace App\Providers;

use App\Models\MenuItem;
use App\Models\Page;
use App\Observers\PageObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Page::observe(PageObserver::class);

        // Отдельные права администратора (редиректы, меню, пользователи)
        Gate::define('admin', fn ($user) => $user->isAdmin());

        // Навигация из таблицы menu_items — доступна во всех публичных шаблонах
        View::composer('site.*', function ($view) {
            $view->with('headerMenu', MenuItem::location('header')->get())
                ->with('footerMenu', MenuItem::location('footer')->get());
        });
    }
}
