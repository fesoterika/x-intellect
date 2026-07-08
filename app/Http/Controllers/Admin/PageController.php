<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PageRequest;
use App\Models\Page;
use App\Models\Section;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function index(Request $request)
    {
        $pages = Page::query()
            ->with('section')
            ->when($request->query('section'), fn ($q, $s) => $q->where('section_id', $s))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $term) => $q->where('title', 'like', "%{$term}%"))
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.pages.index', [
            'pages' => $pages,
            'sections' => Section::orderBy('position')->get(),
        ]);
    }

    public function create()
    {
        return view('admin.pages.form', [
            'page' => new Page(['status' => 'draft', 'source_type' => 'new', 'page_type' => 'page']),
            'sections' => Section::orderBy('position')->get(),
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
            'sections' => Section::orderBy('position')->get(),
        ]);
    }

    public function update(PageRequest $request, Page $page)
    {
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
