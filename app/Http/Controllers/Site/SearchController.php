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
        $results = collect();

        if (mb_strlen($query) >= 2) {
            $builder = Page::published()->with('section');

            // MySQL FULLTEXT на проде, LIKE-фолбэк на локальном SQLite (Этап 1 плана)
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'])) {
                $builder->whereFullText(['title', 'body'], $query);
            } else {
                $builder->where(function ($q) use ($query) {
                    $q->where('title', 'like', "%{$query}%")
                        ->orWhere('body', 'like', "%{$query}%");
                });
            }

            $results = $builder->limit(50)->get();
        }

        return view('site.search', [
            'query' => $query,
            'results' => $results,
        ]);
    }
}
