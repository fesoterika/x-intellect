<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Site;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Публичная часть (Blade, серверный рендер — SEO из коробки)
|--------------------------------------------------------------------------
| Плоская адресация в поддиректориях одного домена: /wiki/, /library/ и
| т.д., без поддоменов (см. план, «Адресация нового сайта»).
*/

Route::get('/', Site\HomeController::class)->name('home');
// Поиск — LIKE по телам страниц, самый дорогой запрос сайта; throttle
// защищает CPU шареда от ботов (человеку 30 запросов в минуту за глаза)
Route::get('/search', Site\SearchController::class)
    ->middleware('throttle:30,1')
    ->name('search');
Route::get('/glossary', Site\GlossaryController::class)->name('glossary');

// Персональная страница автора/хранителя — фиксированный slug вне разделов
Route::get('/fesoterika', [Site\PageController::class, 'fesoterika'])->name('fesoterika');

// Архив форума phpBB (слепок 2015 года) — только чтение; регистрируется ДО
// динамических маршрутов разделов, чтобы /forum не ушёл в section.show
Route::get('/forum', [Site\ForumController::class, 'index'])->name('forum.index');
// Поиск — ДО маршрута темы, иначе «search» был бы принят за slug темы.
// Живые подсказки с debounce 300мс укладываются в лимит throttle
Route::get('/forum/search', [Site\ForumController::class, 'search'])
    ->middleware('throttle:30,1')
    ->name('forum.search');
Route::get('/forum/{topic:slug}', [Site\ForumController::class, 'show'])->name('forum.topic');

/*
|--------------------------------------------------------------------------
| Админка: /admin/*, закрыта auth + role, noindex в layout
|--------------------------------------------------------------------------
*/

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'role:admin,editor'])
    ->group(function () {
        Route::get('/', Admin\DashboardController::class)->name('dashboard');

        Route::resource('sections', Admin\SectionController::class)->except(['show']);
        Route::resource('pages', Admin\PageController::class)->except(['show']);
        // История изменений страницы — правка из формы страницы
        Route::resource('pages.revisions', Admin\PageRevisionController::class)->only(['update', 'destroy']);
        Route::resource('media', Admin\MediaController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('glossary', Admin\GlossaryTermController::class)->only(['index', 'store', 'update', 'destroy']);

        // Загрузка файлов из Trix-редактора (картинки, аудио, PDF)
        Route::post('editor/upload', [Admin\EditorUploadController::class, 'store'])->name('editor.upload');

        // Тумблер режима технических работ (блок на вкладке «Обзор»)
        Route::post('maintenance', Admin\MaintenanceController::class)->name('maintenance.toggle');

        // Редиректы и меню — только администратор
        Route::middleware('can:admin')->group(function () {
            // Схлопывание цепочек редиректов (artisan redirects:check --fix)
            Route::post('redirects/fix-chains', [Admin\RedirectController::class, 'fixChains'])->name('redirects.fix-chains');
            Route::resource('redirects', Admin\RedirectController::class)->only(['index', 'store', 'update', 'destroy']);
            Route::resource('menu', Admin\MenuItemController::class)->only(['index', 'store', 'update', 'destroy']);
        });
    });

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

/*
|--------------------------------------------------------------------------
| Динамические разделы и страницы — регистрируются последними,
| чтобы не перехватывать фиксированные маршруты выше
|--------------------------------------------------------------------------
*/

Route::get('/{section:slug}', [Site\SectionController::class, 'show'])
    ->where('section', '[a-z0-9-]+')
    ->name('section.show');

Route::get('/{section:slug}/{pageSlug}', [Site\PageController::class, 'show'])
    ->where(['section' => '[a-z0-9-]+', 'pageSlug' => '[a-z0-9-]+'])
    ->name('page.show');
