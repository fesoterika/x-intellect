<x-app-layout>
    <x-slot name="title">Меню</x-slot>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Навигация сайта</h2>
    </x-slot>

    <div class="py-8 max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        <form method="POST" action="{{ route('admin.menu.store') }}" class="bg-white rounded-lg shadow p-6 grid md:grid-cols-5 gap-4 items-end">
            @csrf
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Название *</label>
                <input type="text" name="label" required class="w-full rounded-md border-gray-300">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">URL *</label>
                <input type="text" name="url" required placeholder="/wiki" class="w-full rounded-md border-gray-300">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Расположение</label>
                <select name="location" class="w-full rounded-md border-gray-300">
                    <option value="header">Шапка</option>
                    <option value="footer">Футер</option>
                </select>
            </div>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700">Добавить</button>
        </form>

        <div class="bg-white rounded-lg shadow divide-y">
            @forelse ($items as $item)
                <form method="POST" action="{{ route('admin.menu.update', $item) }}" class="p-4 grid md:grid-cols-6 gap-3 items-center">
                    @csrf @method('PUT')
                    <input type="text" name="label" value="{{ $item->label }}" class="rounded-md border-gray-300 text-sm">
                    <input type="text" name="url" value="{{ $item->url }}" class="md:col-span-2 rounded-md border-gray-300 text-sm">
                    <select name="location" class="rounded-md border-gray-300 text-sm">
                        <option value="header" @selected($item->location === 'header')>Шапка</option>
                        <option value="footer" @selected($item->location === 'footer')>Футер</option>
                    </select>
                    <input type="number" name="position" value="{{ $item->position }}" class="rounded-md border-gray-300 text-sm">
                    <div class="flex gap-2">
                        <button class="text-indigo-600 hover:underline text-xs">Сохранить</button>
                        <button formaction="{{ route('admin.menu.destroy', $item) }}"
                                formmethod="POST"
                                name="_method" value="DELETE"
                                onclick="return confirm('Удалить пункт «{{ $item->label }}»?')"
                                class="text-red-600 hover:underline text-xs">Удалить</button>
                    </div>
                </form>
            @empty
                <p class="p-6 text-center text-gray-400 text-sm">Пунктов меню пока нет</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
