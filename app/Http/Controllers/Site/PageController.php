<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Section;
use App\Services\PageRenderer;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function __construct(protected PageRenderer $renderer) {}

    public function show(Request $request, Section $section, string $pageSlug)
    {
        // Страницы подразделов доступны по URL корневого раздела
        // (/wiki/<slug> и для страниц подраздела «Сеансы»).
        $sectionIds = $section->children()->pluck('id')->push($section->id);

        $page = Page::where('slug', $pageSlug)
            ->whereIn('section_id', $sectionIds)
            ->first();

        if (! $page) {
            // /{раздел}/{подраздел} — листинг подраздела тем же маршрутом.
            $child = Section::where('slug', $pageSlug)
                ->where('parent_id', $section->id)
                ->first();

            if ($child) {
                return app(SectionController::class)->show($request, $child);
            }

            abort(404);
        }

        abort_unless($page->isPublished() || auth()->user()?->isEditor(), 404);

        return view('site.page', [
            'section' => $section,
            'page' => $page->load(['media', 'audio', 'revisions']),
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
