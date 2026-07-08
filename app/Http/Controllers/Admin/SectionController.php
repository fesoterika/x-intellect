<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SectionRequest;
use App\Models\Section;

class SectionController extends Controller
{
    public function index()
    {
        return view('admin.sections.index', [
            'sections' => Section::withCount('pages')->orderBy('position')->get(),
        ]);
    }

    public function create()
    {
        return view('admin.sections.form', ['section' => new Section(['is_visible' => true])]);
    }

    public function store(SectionRequest $request)
    {
        Section::create($request->sectionData());

        return redirect()->route('admin.sections.index')->with('status', 'Раздел создан.');
    }

    public function edit(Section $section)
    {
        return view('admin.sections.form', ['section' => $section]);
    }

    public function update(SectionRequest $request, Section $section)
    {
        $section->update($request->sectionData());

        return redirect()->route('admin.sections.index')->with('status', 'Раздел сохранён.');
    }

    public function destroy(Section $section)
    {
        $section->delete();

        return redirect()->route('admin.sections.index')->with('status', 'Раздел удалён (страницы остались без раздела).');
    }
}
