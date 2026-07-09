{{-- Строка пункта меню (общая для корневых и вложенных) --}}
<form method="POST" action="{{ route('admin.menu.update', $item) }}"
      class="p-4 grid md:grid-cols-7 gap-3 items-center {{ $nested ? 'bg-gray-50' : '' }}">
    @csrf @method('PUT')

    <div class="flex items-center gap-2">
        @if ($nested)
            <span class="text-gray-300" aria-hidden="true">└</span>
        @endif
        <input type="text" name="label" value="{{ $item->label }}" class="w-full rounded-md border-gray-300 text-sm">
    </div>

    <input type="text" name="url" value="{{ $item->url }}" class="md:col-span-2 rounded-md border-gray-300 text-sm">

    <select name="location" class="rounded-md border-gray-300 text-sm">
        <option value="header" @selected($item->location === 'header')>Шапка</option>
        <option value="footer" @selected($item->location === 'footer')>Футер</option>
    </select>

    <select name="parent_id" class="rounded-md border-gray-300 text-sm" @disabled($item->children->isNotEmpty())>
        <option value="">- корневой -</option>
        @foreach ($parents as $parent)
            @continue($parent->id === $item->id)
            <option value="{{ $parent->id }}" @selected($item->parent_id === $parent->id)>{{ $parent->label }}</option>
        @endforeach
    </select>

    <input type="number" name="position" value="{{ $item->position }}" class="rounded-md border-gray-300 text-sm">

    <div class="flex gap-2">
        <button class="text-indigo-600 hover:underline text-xs">Сохранить</button>
        <button formaction="{{ route('admin.menu.destroy', $item) }}"
                formmethod="POST"
                name="_method" value="DELETE"
                onclick="return confirm('Удалить пункт «{{ $item->label }}»?{{ $item->children->isNotEmpty() ? ' Его подпункты станут корневыми.' : '' }}')"
                class="text-red-600 hover:underline text-xs">Удалить</button>
    </div>
</form>
