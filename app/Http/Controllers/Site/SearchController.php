<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    public function __invoke(Request $request)
    {
        $query = trim((string) $request->query('q', ''));
        $results = null;

        if (mb_strlen($query) >= 2) {
            // Архивные unlisted-страницы (стенограммы сеансов) скрыты из списков,
            // но должны находиться поиском; служебные страницы нового сайта
            // (политики и т.п., source_type=new + unlisted) — по-прежнему нет.
            $builder = Page::published()
                ->where(fn ($q) => $q->where('is_listed', true)->orWhere('source_type', '!=', 'new'))
                ->with('section');

            // MySQL FULLTEXT на проде, LIKE-фолбэк на локальном SQLite (Этап 1 плана)
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'])) {
                $builder->whereFullText(['title', 'body'], $query);
            } else {
                $builder->where(function ($q) use ($query) {
                    $q->where('title', 'like', "%{$query}%")
                        ->orWhere('body', 'like', "%{$query}%");
                });
            }

            // Номерная пагинация: результатов может быть много, а строку
            // поиска сохраняем в ссылках через withQueryString()
            $results = $builder->paginate(20)->withQueryString();
        }

        return view('site.search', [
            'query' => $query,
            'results' => $results,
        ]);
    }
}
