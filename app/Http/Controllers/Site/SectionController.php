<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Section;
use App\Support\RussianText;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    /** Варианты сортировки листинга: значение параметра → подпись в селекторе. */
    public const SORTS = [
        'new' => 'по дате: сначала новые',
        'old' => 'по дате: сначала старые',
        'abc' => 'по алфавиту: А → Я',
        'zyx' => 'по алфавиту: Я → А',
    ];

    /** Сортировка по умолчанию. Выбор пользователя запоминается в localStorage
     *  (ключ xi-sort) и подставляется в ?sort= ранним скриптом — см. site/section. */
    public const DEFAULT_SORT = 'new';

    /**
     * Обязательный порядок первых пунктов бокового меню вики (по slug).
     * Отмеченные галочкой страницы с этими slug идут в этом порядке;
     * прочие отмеченные — после них. Структурные ссылки «Общий раздел» и
     * «Глоссарий» выводятся до этого списка (в шаблоне).
     */
    public const WIKI_MENU_ORDER = [
        'proekty-2005-2012',
        'texniki',
        'seansy-1991-2008',
        'seansy-2009-2010',
        'seansy-2011',
        'seansy-2012',
        'seansy-2013',
        'seansy-2014',
        'seansy-2015',
        'seansy-2016',
        'seansy-2017',
    ];

    public function show(Request $request, Section $section)
    {
        abort_unless($section->is_visible && ($section->isRoot() || $section->parent->is_visible), 404);

        // Корневой раздел листит и страницы своих подразделов,
        // подраздел — только собственные.
        $sectionIds = $section->isRoot()
            ? $section->children()->pluck('id')->push($section->id)
            : collect([$section->id]);

        $sort = $request->query('sort');
        if (! array_key_exists($sort ?? '', self::SORTS)) {
            $sort = self::DEFAULT_SORT;
        }

        // Карточка листинга зовёт $page->audio и $page->url() (section →
        // rootAncestor → parent) — без eager load это N+1 на каждую строку.
        $query = Page::whereIn('section_id', $sectionIds)
            ->where('status', 'published')
            ->where('is_listed', true)
            ->with(['audio', 'section.parent']);

        // Закреплённые — первыми, но выбранную сортировку не ломают:
        // внутри закреплённых и внутри остальных порядок один и тот же.
        $query->orderByDesc('is_pinned');

        // Даты — published_at (дата добавления материала на старом сайте,
        // см. content:sync-dates); алфавит — с учётом русской коллации
        match ($sort) {
            'zyx' => $query->orderByRaw(RussianText::titleOrder('title', 'desc')),
            'new' => $query->orderByDesc('published_at')->orderByRaw(RussianText::titleOrder('title')),
            'old' => $query->orderBy('published_at')->orderByRaw(RussianText::titleOrder('title')),
            default => $query->orderByRaw(RussianText::titleOrder('title')),
        };

        $pages = $query->orderBy('id')->paginate(20)->withQueryString();

        $isWiki = $section->rootAncestor()->slug === 'wiki';

        // Меню вики: только страницы, явно отмеченные галочкой «Выводить в
        // меню вики» (in_wiki_menu). Ничего больше в сайдбаре не выводится.
        // Отдельный НЕпагинированный запрос — сайдбар не зависит от текущей
        // страницы списка.
        $wikiMenuPages = null;
        $wikiMenuTop = null;
        if ($isWiki) {
            $wikiMenuPages = Page::query()
                ->where('status', 'published')
                ->where('in_wiki_menu', true)
                ->with('section.parent') // url() каждого пункта — без N+1
                ->orderBy('position')
                ->orderByRaw(RussianText::titleOrder('title'))
                ->get(['id', 'section_id', 'title', 'slug', 'page_type']);

            // Обязательный порядок по slug: перечисленные страницы идут первыми
            // в заданной последовательности, прочие отмеченные — после них
            // (стабильная сортировка сохраняет position/алфавит внутри «прочих»).
            $order = array_flip(self::WIKI_MENU_ORDER);
            $wikiMenuPages = $wikiMenuPages
                ->sortBy(fn ($p) => $order[$p->slug] ?? PHP_INT_MAX)
                ->values();

            // Первый пункт меню — раздел «Общий раздел» (/wiki/obshhii-razdel)
            $wikiMenuTop = Section::with('parent')->where('slug', 'obshhii-razdel')->first();
        }

        return view('site.section', [
            'section' => $section,
            'pages' => $pages,
            'sort' => $sort,
            'wikiMenuPages' => $wikiMenuPages,
            'wikiMenuTop' => $wikiMenuTop,
        ]);
    }
}
