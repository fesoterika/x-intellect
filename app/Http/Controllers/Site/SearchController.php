<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\GlossaryTerm;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    /** Сколько терминов глоссария показываем в выдаче */
    protected const GLOSSARY_LIMIT = 12;

    public function __invoke(Request $request)
    {
        $query = trim((string) $request->query('q', ''));
        $results = null;
        $glossaryTerms = collect();

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
                // LIKE в SQLite не сворачивает регистр кириллицы («Аура» ≠ «аура») —
                // сравниваем через PHP-функцию mb_strtolower, зарегистрированную
                // в соединении; спецсимволы LIKE в запросе экранируем
                $this->registerSqliteLower();
                $needle = '%'.addcslashes(mb_strtolower($query, 'UTF-8'), '%_\\').'%';
                $builder->where(function ($q) use ($needle) {
                    $q->whereRaw("xi_lower(title) LIKE ? ESCAPE '\\'", [$needle])
                        ->orWhereRaw("xi_lower(body) LIKE ? ESCAPE '\\'", [$needle]);
                });
            }

            // Номерная пагинация: результатов может быть много, а строку
            // поиска сохраняем в ссылках через withQueryString()
            $results = $builder->paginate(20)->withQueryString();

            $glossaryTerms = $this->glossaryMatches($query);
        }

        return view('site.search', [
            'query' => $query,
            'results' => $results,
            'glossaryTerms' => $glossaryTerms,
        ]);
    }

    /**
     * Термины глоссария по запросу: регистронезависимо (mb_stripos), сначала
     * совпадения в названии термина, затем — в тексте определения.
     */
    protected function glossaryMatches(string $query): \Illuminate\Support\Collection
    {
        return GlossaryTerm::orderBy('term')->get()
            ->filter(fn ($term) => mb_stripos($term->term, $query) !== false
                || mb_stripos($term->definitionPlain(), $query) !== false)
            ->sortBy(fn ($term) => mb_stripos($term->term, $query) === false ? 1 : 0)
            ->take(self::GLOSSARY_LIMIT)
            ->values();
    }

    /** Регистронезависимость для кириллицы в SQLite: xi_lower() = mb_strtolower. */
    protected function registerSqliteLower(): void
    {
        $pdo = DB::connection()->getPdo();

        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('xi_lower', fn ($value) => mb_strtolower((string) $value, 'UTF-8'), 1);
        }
    }
}
