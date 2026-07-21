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
        // Корневые с детьми — для отображения вложенности
        $roots = MenuItem::root()->with('children')->orderBy('position')->get();

        return view('admin.menu.index', [
            // Шапка и футер — отдельными блоками: это два независимых меню,
            // вперемешку их не отредактировать.
            'headerItems' => $roots->where('location', 'header'),
            'footerItems' => $roots->where('location', 'footer'),
            // Кандидаты в родители: только корневые пункты шапки — выпадающее
            // подменю есть лишь у неё, ребёнок футерного пункта не отрисуется.
            'parents' => $roots->where('location', 'header')->values(),
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
                $parent = MenuItem::find($data['parent_id']);
            }

            // Подменю есть только у шапки, и подпункт всегда живёт там же, где
            // родитель, — иначе он пропадёт с сайта, оставшись в базе.
            $data['location'] = $parent?->location ?? $data['location'];
        }

        $data['parent_id'] = $data['parent_id'] ?? null;

        return $data;
    }
}
