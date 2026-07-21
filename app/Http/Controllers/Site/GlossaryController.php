<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\GlossaryTerm;
use Illuminate\Http\Request;

/**
 * Глоссарий: один список терминов на /glossary плюс адресуемый термин
 * /glossary?term=<slug> — собственный индексируемый URL, на который ведут
 * 301 со старых вики-адресов (/wiki/index.php?title=…).
 *
 * Свободный поиск живёт в ?q=<текст> и закрыт от индексации (см. шаблон):
 * в индекс должны попадать только /glossary и /glossary?term=…
 */
class GlossaryController extends Controller
{
    public function __invoke(Request $request)
    {
        $terms = GlossaryTerm::with('page.section.parent')->orderBy('term')->get();

        $active = null;
        $slug = trim((string) $request->query('term'));

        if ($slug !== '') {
            $active = $terms->firstWhere('slug', $slug);

            // Несуществующий термин — не отдаём 200 с дублем списка.
            // 302, а не 301: термин может появиться в глоссарии позже.
            if (! $active) {
                return redirect()->route('glossary');
            }
        }

        return view('site.glossary', [
            'terms' => $terms,
            'active' => $active,
            'q' => $active ? '' : trim((string) $request->query('q')),
        ]);
    }
}
