<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GlossaryTerm;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GlossaryTermController extends Controller
{
    public function index()
    {
        return view('admin.glossary.index', [
            'terms' => GlossaryTerm::with('page')->orderBy('term')->paginate(50),
            'pages' => Page::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function store(Request $request)
    {
        GlossaryTerm::create($this->validated($request));

        return back()->with('status', 'Термин добавлен.');
    }

    public function update(Request $request, GlossaryTerm $glossary)
    {
        $glossary->update($this->validated($request, $glossary));

        return back()->with('status', 'Термин обновлён.');
    }

    public function destroy(GlossaryTerm $glossary)
    {
        $glossary->delete();

        return back()->with('status', 'Термин удалён.');
    }

    protected function validated(Request $request, ?GlossaryTerm $term = null): array
    {
        // Пустой документ Trix (<div><br></div> и т.п.) — это отсутствие
        // определения: пусть сработает правило required
        if (trim(strip_tags((string) $request->input('definition'))) === '') {
            $request->merge(['definition' => null]);
        }

        $data = $request->validate([
            'term' => ['required', 'string', 'max:255', Rule::unique('glossary_terms', 'term')->ignore($term)],
            'definition' => ['required', 'string'],
            'page_id' => ['nullable', 'exists:pages,id'],
        ]);

        // Slug стабилен: переименование термина не меняет его адрес
        // /glossary?term=<slug> — иначе ломаются редиректы, sitemap и
        // внешние ссылки (см. кейс «Внеземные Цивилизации (ВЦ)»)
        $data['slug'] = $term?->slug ?? Str::slug($data['term']);
        // Ссылки на localhost из редактора → относительные
        $data['definition'] = app(\App\Services\LocalLinks::class)->relativize($data['definition']);

        return $data;
    }
}
