<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Section;

class HomeController extends Controller
{
    public function __invoke()
    {
        return view('site.home', [
            'sections' => Section::where('is_visible', true)
                ->orderBy('position')
                ->withCount(['pages as published_pages_count' => fn ($q) => $q->where('status', 'published')])
                ->get(),
            'latestPages' => Page::published()
                ->where('page_type', 'page')
                ->with('section')
                ->orderByDesc('published_at')
                ->limit(6)
                ->get(),
        ]);
    }
}
