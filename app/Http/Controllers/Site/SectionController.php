<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Section;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    public function show(Request $request, Section $section)
    {
        abort_unless($section->is_visible, 404);

        $pages = $section->publishedPages()->where('is_listed', true)->with('media')->paginate(20);

        // Прогрессивное улучшение «Показать ещё»: JS дозапрашивает
        // следующую страницу с ?partial=1 и получает только список карточек
        // (без общего layout), затем дописывает их в текущий список.
        if ($request->boolean('partial')) {
            return view('site.partials.section-list', [
                'pages' => $pages,
                'variant' => $section->slug === 'wiki' ? 'wiki' : null,
            ]);
        }

        return view('site.section', [
            'section' => $section,
            'pages' => $pages,
        ]);
    }
}
