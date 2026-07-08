<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GlossaryTerm;
use App\Models\Media;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;

class DashboardController extends Controller
{
    public function __invoke()
    {
        return view('admin.dashboard', [
            'stats' => [
                'Разделы' => Section::count(),
                'Страницы' => Page::count(),
                'Опубликовано' => Page::published()->count(),
                'Черновики' => Page::where('status', 'draft')->count(),
                'Медиафайлы' => Media::count(),
                'Термины глоссария' => GlossaryTerm::count(),
                'Редиректы' => Redirect::count(),
            ],
            'recentPages' => Page::latest('updated_at')->with('section')->limit(10)->get(),
        ]);
    }
}
