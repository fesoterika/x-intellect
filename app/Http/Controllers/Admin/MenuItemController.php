<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MenuItemController extends Controller
{
    public function index()
    {
        return view('admin.menu.index', [
            'items' => MenuItem::orderBy('location')->orderBy('position')->get(),
        ]);
    }

    public function store(Request $request)
    {
        MenuItem::create($this->validated($request));

        return back()->with('status', 'Пункт меню добавлен.');
    }

    public function update(Request $request, MenuItem $menu)
    {
        $menu->update($this->validated($request));

        return back()->with('status', 'Пункт меню обновлён.');
    }

    public function destroy(MenuItem $menu)
    {
        $menu->delete();

        return back()->with('status', 'Пункт меню удалён.');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048'],
            'location' => ['required', Rule::in(['header', 'footer'])],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
