<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Section;

class SectionController extends Controller
{
    public function show(Section $section)
    {
        abort_unless($section->is_visible, 404);

        return view('site.section', [
            'section' => $section,
            'pages' => $section->publishedPages()->with('media')->paginate(20),
        ]);
    }
}
