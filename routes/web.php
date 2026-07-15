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
Route::get('/search', Site\SearchController::class)->name('search');
Route::get('/glossary', Site\GlossaryController::class)->name('glossary');

// Персональная страница автора/хранителя — фиксированный slug вне разделов
Route::get('/fesoterika', [Site\PageController::class, 'fesoterika'])->name('fesoterika');

// Архив форума phpBB (слепок 2015 года) — только чтение; регистрируется ДО
// динамических маршрутов разделов, чтобы /forum не ушёл в section.show
Route::get('/forum', [Site\ForumController::class, 'index'])->name('forum.index');
// Поиск — ДО маршрута темы, иначе «search» был бы принят за slug темы
Route::get('/forum/search', [Site\ForumController::class, 'search'])->name('forum.search');
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
        Route::resource('media', Admin\MediaController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('glossary', Admin\GlossaryTermController::class)->only(['index', 'store', 'update', 'destroy']);

        // Загрузка файлов из Trix-редактора (картинки, аудио, PDF)
        Route::post('editor/upload', [Admin\EditorUploadController::class, 'store'])->name('editor.upload');

        // Редиректы и меню — только администратор
        Route::middleware('can:admin')->group(function () {
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
