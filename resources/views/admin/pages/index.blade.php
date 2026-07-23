<x-app-layout>
    <x-slot name="title">Страницы</x-slot>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Страницы</h2>
            <a href="{{ route('admin.pages.create') }}" class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700">+ Создать</a>
        </div>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        <form method="GET" class="bg-white rounded-lg shadow p-4 flex flex-wrap gap-3 items-start">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Раздел</label>
                <select name="section" class="rounded-md border-gray-300 text-sm">
                    <option value="">Все</option>
                    @foreach ($sections as $section)
                        @if ($section->children->isEmpty())
                            <option value="{{ $section->id }}" @selected(request('section') == $section->id)>{{ $section->title }}</option>
                        @else
                            <optgroup label="{{ $section->title }}">
                                <option value="{{ $section->id }}" @selected(request('section') == $section->id)>{{ $section->title }} - всё</option>
                                @foreach ($section->children as $child)
                                    <option value="{{ $child->id }}" @selected(request('section') == $child->id)>↳ {{ $child->title }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Статус</label>
                <select name="status" class="rounded-md border-gray-300 text-sm">
                    <option value="">Все</option>
                    <option value="draft" @selected(request('status') === 'draft')>Черновики</option>
                    <option value="published" @selected(request('status') === 'published')>Опубликованные</option>
                </select>
            </div>
            <div class="flex-1 min-w-40">
                <label class="block text-xs text-gray-500 mb-1">Поиск по заголовку и содержимому</label>
                <input type="text" name="q" value="{{ request('q') }}" class="w-full rounded-md border-gray-300 text-sm">
            </div>
            <div class="flex flex-col gap-1.5">
                <span class="block text-xs text-gray-500 mb-0.5">Признаки</span>
                <label class="flex items-center gap-2 text-xs text-gray-600">
                    <input type="checkbox" name="unlisted" value="1" @checked(request()->boolean('unlisted')) class="rounded border-gray-300">
                    НЕ показывается
                </label>
                <label class="flex items-center gap-2 text-xs text-gray-600">
                    <input type="checkbox" name="wiki_menu" value="1" @checked(request()->boolean('wiki_menu')) class="rounded border-gray-300">
                    Выводить в меню вики
                </label>
            </div>
            {{-- Пустая подпись держит кнопку на одной линии с полями ввода --}}
            <div>
                <span class="block text-xs mb-1" aria-hidden="true">&nbsp;</span>
                <button class="px-4 py-2 bg-gray-800 text-white rounded-md text-sm">Фильтр</button>
            </div>
        </form>

        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <th class="px-5 py-3">Заголовок</th>
                        <th class="px-5 py-3">Раздел</th>
                        <th class="px-5 py-3">Эпоха</th>
                        <th class="px-5 py-3">Статус</th>
                        <th class="px-5 py-3">Обновлено</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pages as $page)
                        <tr class="border-t">
                            <td class="px-5 py-3">
                                @if ($page->is_pinned)
                                    <svg class="inline-block w-3.5 h-3.5 -mt-0.5 mr-1 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Закреплено"><title>Закреплено</title><path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/></svg>
                                @endif
                                @if ($page->disclaimer)
                                    {{-- Весы: у материала есть дисклеймер (правовая приписка внизу страницы) --}}
                                    <svg class="inline-block w-3.5 h-3.5 -mt-0.5 mr-1 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Есть дисклеймер"><title>Есть дисклеймер</title><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/></svg>
                                @endif
                                <a class="text-indigo-700 hover:underline font-medium" href="{{ route('admin.pages.edit', $page) }}">{{ $page->title }}</a>
                                <div class="text-xs text-gray-400">/{{ $page->section?->slug }}/{{ $page->slug }}</div>
                            </td>
                            <td class="px-5 py-3 text-gray-500">{{ $page->section?->title ?? '-' }}</td>
                            <td class="px-5 py-3"><x-source-badge :page="$page" /></td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded text-xs {{ $page->isPublished() ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                                    {{ $page->isPublished() ? 'опубликовано' : 'черновик' }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-gray-400">{{ $page->updated_at->format('d.m.Y') }}</td>
                            <td class="px-5 py-3 text-right">
                                <form method="POST" action="{{ route('admin.pages.destroy', $page) }}" onsubmit="return confirm('Удалить страницу «{{ $page->title }}»?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:underline text-xs">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-6 text-center text-gray-400">Ничего не найдено</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $pages->links() }}
    </div>
</x-app-layout>
