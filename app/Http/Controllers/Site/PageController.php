<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Section;
use App\Services\PageRenderer;

class PageController extends Controller
{
    public function __construct(protected PageRenderer $renderer) {}

    public function show(Section $section, string $pageSlug)
    {
        $page = Page::where('slug', $pageSlug)
            ->where('section_id', $section->id)
            ->firstOrFail();

        abort_unless($page->isPublished() || auth()->user()?->isEditor(), 404);

        return view('site.page', [
            'section' => $section,
            'page' => $page->load(['media', 'revisions']),
            'body' => $this->renderer->render($page),
        ]);
    }

    /**
     * Фиксированная персональная страница автора/хранителя проекта —
     * /fesoterika, вне общей иерархии разделов (Этап 1 плана).
     */
    public function fesoterika()
    {
        $page = Page::where('slug', 'fesoterika')
            ->where('page_type', 'author')
            ->firstOrFail();

        abort_unless($page->isPublished() || auth()->user()?->isEditor(), 404);

        return view('site.author', [
            'page' => $page->load('media'),
            'body' => $this->renderer->render($page),
        ]);
    }
}
