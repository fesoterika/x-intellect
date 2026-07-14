<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Section;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    public function show(Request $request, Section $section)
    {
        abort_unless($section->is_visible && ($section->isRoot() || $section->parent->is_visible), 404);

        // Корневой раздел листит и страницы своих подразделов,
        // подраздел — только собственные.
        $sectionIds = $section->isRoot()
            ? $section->children()->pluck('id')->push($section->id)
            : collect([$section->id]);

        $pages = Page::whereIn('section_id', $sectionIds)
            ->where('status', 'published')
            ->where('is_listed', true)
            ->orderBy('position')
            ->orderByDesc('published_at')
            ->with('media')
            ->paginate(20);

        $isWiki = $section->rootAncestor()->slug === 'wiki';

        // Прогрессивное улучшение «Показать ещё»: JS дозапрашивает
        // следующую страницу с ?partial=1 и получает только список карточек
        // (без общего layout), затем дописывает их в текущий список.
        if ($request->boolean('partial')) {
            return view('site.partials.section-list', [
                'pages' => $pages,
                'variant' => $isWiki ? 'wiki' : null,
            ]);
        }

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
            'menuGroups' => $menuGroups,
        ]);
    }
}
