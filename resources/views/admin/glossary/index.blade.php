<x-app-layout>
    <x-slot name="title">Глоссарий</x-slot>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Глоссарий</h2>
    </x-slot>

    <div class="py-8 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        <p class="text-sm text-gray-500">
            Термины автоматически превращаются в подсказки-тултипы в текстах статей при сохранении страницы.
        </p>

        <form method="POST" action="{{ route('admin.glossary.store') }}" class="bg-white rounded-lg shadow p-6 grid md:grid-cols-6 gap-4 items-end">
            @csrf
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Термин *</label>
                <input type="text" name="term" required class="w-full rounded-md border-gray-300">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Определение *</label>
                <textarea name="definition" rows="1" required class="w-full rounded-md border-gray-300"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Статья</label>
                <select name="page_id" class="w-full rounded-md border-gray-300">
                    <option value="">-</option>
                    @foreach ($pages as $p)
                        <option value="{{ $p->id }}">{{ $p->title }}</option>
                    @endforeach
                </select>
            </div>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700">Добавить</button>
        </form>

        <div class="bg-white rounded-lg shadow divide-y">
            @forelse ($terms as $term)
                <form method="POST" action="{{ route('admin.glossary.update', $term) }}" class="p-4 grid md:grid-cols-6 gap-3 items-start">
                    @csrf @method('PUT')
                    <input type="text" name="term" value="{{ $term->term }}" class="md:col-span-2 rounded-md border-gray-300 text-sm">
                    <textarea name="definition" rows="2" class="md:col-span-2 rounded-md border-gray-300 text-sm">{{ $term->definition }}</textarea>
                    <select name="page_id" class="rounded-md border-gray-300 text-sm">
                        <option value="">-</option>
                        @foreach ($pages as $p)
                            <option value="{{ $p->id }}" @selected($term->page_id == $p->id)>{{ $p->title }}</option>
                        @endforeach
                    </select>
                    <div class="flex gap-2">
                        <button class="text-indigo-600 hover:underline text-xs">Сохранить</button>
                        <button formaction="{{ route('admin.glossary.destroy', $term) }}"
                                formmethod="POST"
                                name="_method" value="DELETE"
                                onclick="return confirm('Удалить термин «{{ $term->term }}»?')"
                                class="text-red-600 hover:underline text-xs">Удалить</button>
                    </div>
                </form>
            @empty
                <p class="p-6 text-center text-gray-400 text-sm">Терминов пока нет</p>
            @endforelse
        </div>

        {{ $terms->links() }}
    </div>
</x-app-layout>
