<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Support\RussianText;
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
                // page-card: audio-бейдж и url() через section.parent — без N+1
                ->with(['audio', 'section.parent']);

            // Подстрочный LIKE — общая часть для обеих БД: находит словоформы
            // («сеанс» → «сеансы») и короткие слова, которые InnoDB FULLTEXT
            // не индексирует (короче 3 символов). Регистронезависимость
            // кириллицы: на MySQL — collation utf8mb4, на SQLite — xi_lower()
            // из App\Support\RussianText. На MySQL FULLTEXT добирает совпадения
            // по словам целиком (морфология без подстрок) — вместе дают
            // одинаковую полноту локально и на проде.
            $builder->where(function ($q) use ($query) {
                RussianText::contains($q, 'title', $query);
                RussianText::contains($q, 'body', $query, 'or');

                if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'])) {
                    $q->orWhereFullText(['title', 'body'], $query);
                }
            });

            // Сначала совпадения по заголовку, затем по содержимому;
            // внутри групп — новые раньше (стабильный порядок для пагинации)
            RussianText::containsFirstOrder($builder, 'title', $query);
            $builder->orderByDesc('published_at')->orderByDesc('id');

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
}
