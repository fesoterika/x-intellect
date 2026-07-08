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
            // Корневые с детьми — для отображения вложенности
            'items' => MenuItem::root()->with('children')->orderBy('location')->orderBy('position')->get(),
            // Кандидаты в родители (один уровень вложенности: только корневые)
            'parents' => MenuItem::root()->orderBy('location')->orderBy('position')->get(),
        ]);
    }

    public function store(Request $request)
    {
        MenuItem::create($this->validated($request));

        return back()->with('status', 'Пункт меню добавлен.');
    }

    public function update(Request $request, MenuItem $menu)
    {
        $data = $this->validated($request, $menu);

        // Пункт с детьми нельзя сделать чьим-то ребёнком (один уровень)
        if ($data['parent_id'] && $menu->children()->exists()) {
            return back()->withErrors(['parent_id' => 'У пункта есть подменю — сначала перенесите его детей.']);
        }

        $menu->update($data);

        return back()->with('status', 'Пункт меню обновлён.');
    }

    public function destroy(MenuItem $menu)
    {
        // Дети удаляемого пункта поднимаются на верхний уровень
        $menu->children()->update(['parent_id' => null]);
        $menu->delete();

        return back()->with('status', 'Пункт меню удалён.');
    }

    protected function validated(Request $request, ?MenuItem $menu = null): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:2048'],
            'location' => ['required', Rule::in(['header', 'footer'])],
            'position' => ['nullable', 'integer', 'min:0'],
            'parent_id' => [
                'nullable',
                Rule::exists('menu_items', 'id'),
                // сам себе родителем быть не может
                $menu ? Rule::notIn([$menu->id]) : Rule::notIn([]),
            ],
        ]);

        // Родитель должен быть корневым (один уровень вложенности)
        if (! empty($data['parent_id'])) {
            $parent = MenuItem::find($data['parent_id']);

            if ($parent?->parent_id !== null) {
                $data['parent_id'] = $parent->parent_id;
            }
        }

        $data['parent_id'] = $data['parent_id'] ?? null;

        return $data;
    }
}
