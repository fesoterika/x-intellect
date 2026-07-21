<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PageRequest;
use App\Models\Page;
use App\Models\Section;
use App\Support\RussianText;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function index(Request $request)
    {
        $pages = Page::query()
            ->with('section')
            // Корневой раздел листит и материалы своих подразделов — как на сайте:
            // иначе «Проекты», где все страницы лежат в подразделах, выглядят пустыми.
            // Глубина иерархии — 1, поэтому у подраздела выборка сводится к нему самому.
            ->when($request->query('section'), fn ($q, $s) => $q->whereIn(
                'section_id',
                Section::where('id', $s)->orWhere('parent_id', $s)->pluck('id'),
            ))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            // Чекбоксы фильтра: отмечен — показываем только страницы с этим
            // признаком; снят — фильтр не применяется вовсе.
            // «НЕ показывается» — выборка скрытых из списков: их и надо проверять.
            ->when($request->boolean('unlisted'), fn ($q) => $q->where('is_listed', false))
            ->when($request->boolean('wiki_menu'), fn ($q) => $q->where('in_wiki_menu', true))
            ->when($request->query('q'), function ($q, $term) {
                // Регистронезависимо и с поддержкой кириллицы (SQLite LOWER()
                // не сворачивает регистр русских букв — см. RussianText).
                $q->where(function ($sub) use ($term) {
                    RussianText::contains($sub, 'title', $term);
                    RussianText::contains($sub, 'excerpt', $term, 'or');
                    RussianText::contains($sub, 'body', $term, 'or');
                });

                // Совпадения в заголовке — выше совпадений только в тексте
                RussianText::containsFirstOrder($q, 'title', $term);
            })
            ->orderByDesc('is_pinned')
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.pages.index', [
            'pages' => $pages,
            'sections' => Section::root()->with('children')->orderBy('position')->get(),
        ]);
    }

    public function create()
    {
        return view('admin.pages.form', [
            'page' => new Page([
                'status' => 'draft', 'source_type' => 'new', 'page_type' => 'page', 'is_listed' => true,
                'published_at' => now(),
            ]),
            'sections' => Section::root()->with('children')->orderBy('position')->get(),
        ]);
    }

    public function store(PageRequest $request)
    {
        $page = Page::create($request->pageData());

        return redirect()->route('admin.pages.edit', $page)
            ->with('status', 'Страница создана.');
    }

    public function edit(Page $page)
    {
        return view('admin.pages.form', [
            'page' => $page->load(['media', 'revisions']),
            'sections' => Section::root()->with('children')->orderBy('position')->get(),
        ]);
    }

    public function update(PageRequest $request, Page $page)
    {
        // Причина правки — не поле страницы: PageObserver кладёт её в ревизию,
        // которая создаётся при изменении заголовка или текста
        $page->revisionReason = $request->filled('revision_reason')
            ? trim($request->input('revision_reason'))
            : null;

        $page->update($request->pageData());

        return redirect()->route('admin.pages.edit', $page)
            ->with('status', 'Страница сохранена.');
    }

    public function destroy(Page $page)
    {
        $page->delete();

        return redirect()->route('admin.pages.index')
            ->with('status', 'Страница удалена.');
    }
}
