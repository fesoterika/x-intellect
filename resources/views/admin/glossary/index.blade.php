<x-app-layout>
    <x-slot name="title">Глоссарий</x-slot>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Глоссарий</h2>
    </x-slot>

    <div class="py-8 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        <p class="text-sm text-gray-500">
            Термины автоматически превращаются в подсказки-тултипы в текстах статей при сохранении страницы.
            Определение поддерживает оформление (жирный, курсив, ссылки, списки) — оно отображается
            на странице глоссария; в тултипы и поиск попадает только текст.
        </p>

        {{-- Добавление термина --}}
        <form method="POST" action="{{ route('admin.glossary.store') }}" class="bg-white rounded-lg shadow p-6 space-y-4">
            @csrf
            <h3 class="font-semibold text-gray-700">Новый термин</h3>
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Термин *</label>
                    <input type="text" name="term" required class="w-full rounded-md border-gray-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Статья «Подробнее»</label>
                    <select name="page_id" class="w-full rounded-md border-gray-300">
                        <option value="">-</option>
                        @foreach ($pages as $p)
                            <option value="{{ $p->id }}">{{ $p->title }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="xi-def-editor">
                <label class="block text-sm font-medium text-gray-700 mb-1">Определение *</label>
                <input id="def-new" type="hidden" name="definition">
                <trix-editor input="def-new" class="trix-content bg-white border border-gray-300 rounded-md" style="min-height: 5rem;"></trix-editor>
            </div>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700">Добавить</button>
        </form>

        {{-- Список терминов: правка в раскрывающейся карточке --}}
        <div class="bg-white rounded-lg shadow divide-y">
            @forelse ($terms as $term)
                <details class="group" @if ($errors->any() && old('_term_id') == $term->id) open @endif>
                    <summary class="flex items-center gap-3 p-4 cursor-pointer select-none hover:bg-gray-50">
                        <span class="font-medium text-gray-800 shrink-0">{{ $term->term }}</span>
                        <span class="text-sm text-gray-500 truncate grow">{{ Str::limit($term->definitionPlain(), 120) }}</span>
                        @if ($term->page)
                            <span class="text-xs text-indigo-500 bg-indigo-50 rounded-full px-2 py-0.5 shrink-0" title="Статья «Подробнее»: {{ $term->page->title }}">статья</span>
                        @endif
                        <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>
                    </summary>
                    <form method="POST" action="{{ route('admin.glossary.update', $term) }}" class="px-4 pb-4 space-y-3 border-t bg-gray-50/50">
                        @csrf @method('PUT')
                        <input type="hidden" name="_term_id" value="{{ $term->id }}">
                        <div class="grid md:grid-cols-2 gap-3 pt-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Термин</label>
                                <input type="text" name="term" value="{{ $term->term }}" class="w-full rounded-md border-gray-300 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Статья «Подробнее»</label>
                                <select name="page_id" class="w-full rounded-md border-gray-300 text-sm">
                                    <option value="">-</option>
                                    @foreach ($pages as $p)
                                        <option value="{{ $p->id }}" @selected($term->page_id == $p->id)>{{ $p->title }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="xi-def-editor">
                            <label class="block text-xs font-medium text-gray-500 mb-1">Определение</label>
                            <input id="def-{{ $term->id }}" type="hidden" name="definition" value="{{ $term->definitionHtml() }}">
                            <trix-editor input="def-{{ $term->id }}" class="trix-content bg-white border border-gray-300 rounded-md text-sm" style="min-height: 5rem;"></trix-editor>
                        </div>
                        <div class="flex gap-4 items-center">
                            <button class="px-4 py-1.5 bg-indigo-600 text-white rounded-md text-xs font-medium hover:bg-indigo-700">Сохранить</button>
                            <a href="{{ url($term->url()) }}" target="_blank" class="text-xs text-gray-500 hover:underline">на сайте ↗</a>
                            <button formaction="{{ route('admin.glossary.destroy', $term) }}"
                                    formmethod="POST"
                                    name="_method" value="DELETE"
                                    onclick="return confirm('Удалить термин «{{ $term->term }}»?')"
                                    class="text-red-600 hover:underline text-xs ml-auto">Удалить</button>
                        </div>
                    </form>
                </details>
            @empty
                <p class="p-6 text-center text-gray-400 text-sm">Терминов пока нет</p>
            @endforelse
        </div>

        {{ $terms->links() }}
    </div>
</x-app-layout>
