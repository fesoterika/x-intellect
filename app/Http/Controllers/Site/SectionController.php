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
        'abc' => 'по алфавиту: А → Я',
        'zyx' => 'по алфавиту: Я → А',
        'new' => 'по дате: сначала новые',
        'old' => 'по дате: сначала старые',
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
            $sort = 'abc';
        }

        $query = Page::whereIn('section_id', $sectionIds)
            ->where('status', 'published')
            ->where('is_listed', true)
            ->with('media');

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

        // Меню вики: подразделы корня с их страницами — отдельным
        // НЕпагинированным запросом (сайдбар не зависит от текущей страницы списка)
        $menuGroups = null;
        if ($isWiki) {
            $menuGroups = $section->rootAncestor()
                ->children()
                ->where('is_visible', true)
                ->with(['publishedPages' => fn ($q) => $q->where('is_listed', true)
                    ->select('id', 'section_id', 'title', 'slug', 'page_type', 'position', 'published_at')])
                ->get();
        }

        return view('site.section', [
            'section' => $section,
            'pages' => $pages,
            'sort' => $sort,
            'menuGroups' => $menuGroups,
        ]);
    }
}
