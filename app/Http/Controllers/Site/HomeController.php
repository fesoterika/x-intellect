<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\ForumTopic;
use App\Models\GlossaryTerm;
use App\Models\Media;
use App\Models\Page;
use App\Models\Section;

class HomeController extends Controller
{
    public function __invoke()
    {
        return view('site.home', [
            'forumTopicsCount' => ForumTopic::count(),
            // Счётчики «Архив в цифрах»: только опубликованное/видимое читателю.
            // Аудио — прикреплённые к опубликованным страницам (без привязки
            // или на черновике запись со страниц сайта недоступна).
            'stats' => [
                'sections' => Section::where('is_visible', true)->count(),
                'pages' => Page::published()->count(),
                'audio' => Media::where('type', 'audio')
                    ->whereHas('page', fn ($q) => $q->where('status', 'published'))
                    ->count(),
                'terms' => GlossaryTerm::count(),
            ],
            // Счётчик плитки должен совпадать с тем, что реально покажет
            // листинг раздела (SectionController@show): для корня туда
            // входят и страницы подразделов, поэтому withCount('pages')
            // (только прямая связь section_id) тут не годится.
            'sections' => Section::root()
                ->where('is_visible', true)
                ->where('show_on_home', true)
                ->orderBy('position')
                ->with('children:id,parent_id')
                ->get()
                ->each(function (Section $section) {
                    $sectionIds = $section->children->pluck('id')->push($section->id);
                    $section->published_pages_count = Page::whereIn('section_id', $sectionIds)
                        ->where('status', 'published')
                        ->where('is_listed', true)
                        ->count();
                }),
            // «Свежее» — по «Дате материала» (published_at), как в листингах
            // разделов (SectionController, сортировка «new»). created_at —
            // тай-брейкер для материалов с одинаковой датой
            'latestPages' => Page::published()
                ->listed()
                ->where('page_type', 'page')
                // Служебная «Правовая информация» (/rules/pravovaia-informaciia,
                // создана в админке прода со свежей датой) — не материал архива:
                // из «Свежего» исключается, но в листинге раздела и поиске остаётся
                ->where('slug', '!=', 'pravovaia-informaciia')
                // page-card: audio-бейдж и url() через section.parent — без N+1
                ->with(['audio', 'section.parent'])
                ->orderByDesc('published_at')
                ->orderByDesc('created_at')
                ->limit(6)
                ->get(),
        ]);
    }
}
