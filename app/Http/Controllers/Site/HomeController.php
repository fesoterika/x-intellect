<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\ForumTopic;
use App\Models\Page;
use App\Models\Section;

class HomeController extends Controller
{
    public function __invoke()
    {
        return view('site.home', [
            'forumTopicsCount' => ForumTopic::count(),
            'sections' => Section::where('is_visible', true)
                ->where('show_on_home', true)
                ->orderBy('position')
                ->withCount(['pages as published_pages_count' => fn ($q) => $q->where('status', 'published')->where('is_listed', true)])
                ->get(),
            'latestPages' => Page::published()
                ->listed()
                ->where('page_type', 'page')
                ->with('section')
                ->orderByDesc('published_at')
                ->limit(6)
                ->get(),
        ]);
    }
}
