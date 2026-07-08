<x-app-layout>
    <x-slot name="title">Меню</x-slot>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Навигация сайта</h2>
    </x-slot>

    <div class="py-8 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        <p class="text-sm text-gray-500">
            Один уровень вложенности: пункт с родителем показывается в выпадающем подменю
            (на ПК — по наведению, на смартфоне — по тапу на стрелку). Родителем может быть
            только корневой пункт шапки.
        </p>

        <form method="POST" action="{{ route('admin.menu.store') }}" class="bg-white rounded-lg shadow p-6 grid md:grid-cols-6 gap-4 items-end">
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
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Родитель</label>
                <select name="parent_id" class="w-full rounded-md border-gray-300">
                    <option value="">— корневой —</option>
                    @foreach ($parents as $parent)
                        <option value="{{ $parent->id }}">{{ $parent->label }} ({{ $parent->location === 'header' ? 'шапка' : 'футер' }})</option>
                    @endforeach
                </select>
            </div>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700">Добавить</button>
        </form>

        <div class="bg-white rounded-lg shadow divide-y">
            @forelse ($items as $item)
                @include('admin.menu.row', ['item' => $item, 'parents' => $parents, 'nested' => false])
                @foreach ($item->children as $child)
                    @include('admin.menu.row', ['item' => $child, 'parents' => $parents, 'nested' => true])
                @endforeach
            @empty
                <p class="p-6 text-center text-gray-400 text-sm">Пунктов меню пока нет</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
