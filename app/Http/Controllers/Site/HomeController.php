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
            'sections' => Section::root()
                ->where('is_visible', true)
                ->where('show_on_home', true)
                ->orderBy('position')
                ->withCount(['pages as published_pages_count' => fn ($q) => $q->where('status', 'published')->where('is_listed', true)])
                ->get(),
            // «Свежее» — по дате добавления на НОВЫЙ сайт (created_at):
            // published_at хранит дату добавления материала на старом сайте
            // (content:sync-dates) и для блока новинок не подходит
            'latestPages' => Page::published()
                ->listed()
                ->where('page_type', 'page')
                ->with('section')
                ->orderByDesc('created_at')
                ->limit(6)
                ->get(),
        ]);
    }
}
