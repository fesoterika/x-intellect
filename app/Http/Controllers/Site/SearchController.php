<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Services\ArchiveSearch;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /** Сколько терминов глоссария показываем в выдаче */
    protected const GLOSSARY_LIMIT = 12;

    public function __invoke(Request $request)
    {
        $request->validate(['q' => ['nullable', 'string', 'max:200']]);
        $query = trim(preg_replace('/[\s\p{Z}]+/u', ' ', (string) $request->query('q', '')));
        $search = new ArchiveSearch($query);
        $results = null;
        $glossaryTerms = collect();

        if (mb_strlen($query) >= 2 && $search->terms !== []) {
            // Архивные unlisted-страницы (стенограммы сеансов) скрыты из списков,
            // но должны находиться поиском; служебные страницы нового сайта
            // (политики и т.п., source_type=new + unlisted) — по-прежнему нет.
            $builder = Page::published()
                ->where(fn ($q) => $q->where('is_listed', true)->orWhere('source_type', '!=', 'new'))
                // page-card: audio-бейдж и url() через section.parent — без N+1
                ->with(['audio', 'section.parent']);

            $search->apply($builder);
            $builder->orderByDesc('published_at')->orderByDesc('id');

            // Номерная пагинация: результатов может быть много, а строку
            // поиска сохраняем в ссылках через withQueryString()
            $results = $builder->paginate(20)->withQueryString();

            $glossaryTerms = $this->glossaryMatches($search);
        }

        return view('site.search', [
            'query' => $query,
            'search' => $search,
            'results' => $results,
            'glossaryTerms' => $glossaryTerms,
        ]);
    }

    /**
     * Те же обязательные части запроса, регистр и е/ё, что для материалов.
     * Сначала совпадения в названии термина, затем — в определении.
     */
    protected function glossaryMatches(ArchiveSearch $search): \Illuminate\Support\Collection
    {
        return GlossaryTerm::orderBy('term')->get()
            ->filter(fn ($term) => $search->matches($term->term.' '.$term->definitionPlain()))
            ->sortBy(fn ($term) => $search->matches($term->term) ? 0 : 1)
            ->take(self::GLOSSARY_LIMIT)
            ->values();
    }
}
