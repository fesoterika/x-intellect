<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\GlossaryTerm;

class GlossaryController extends Controller
{
    public function __invoke()
    {
        return view('site.glossary', [
            'terms' => GlossaryTerm::with('page.section')->orderBy('term')->get(),
        ]);
    }
}
