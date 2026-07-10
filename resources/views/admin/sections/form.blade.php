<x-app-layout>
    <x-slot name="title">{{ $section->exists ? 'Правка раздела' : 'Новый раздел' }}</x-slot>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $section->exists ? 'Правка раздела' : 'Новый раздел' }}
        </h2>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <form method="POST"
              action="{{ $section->exists ? route('admin.sections.update', $section) : route('admin.sections.store') }}"
              class="bg-white rounded-lg shadow p-6 space-y-4">
            @csrf
            @if ($section->exists) @method('PUT') @endif

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Название *</label>
                <input type="text" name="title" value="{{ old('title', $section->title) }}" required class="w-full rounded-md border-gray-300">
            </div>

            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Slug (URL-путь)</label>
                    <input type="text" name="slug" value="{{ old('slug', $section->slug) }}" placeholder="wiki, library, mag…" class="w-full rounded-md border-gray-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Порядок</label>
                    <input type="number" name="position" value="{{ old('position', $section->position ?? 0) }}" class="w-full rounded-md border-gray-300">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Описание</label>
                <textarea name="description" rows="3" class="w-full rounded-md border-gray-300">{{ old('description', $section->description) }}</textarea>
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="hidden" name="is_visible" value="0">
                <input type="checkbox" name="is_visible" value="1" @checked(old('is_visible', $section->is_visible)) class="rounded border-gray-300">
                Показывать на сайте (иначе раздел и его страницы недоступны — 404)
            </label>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="hidden" name="show_on_home" value="0">
                <input type="checkbox" name="show_on_home" value="1" @checked(old('show_on_home', $section->show_on_home ?? true)) class="rounded border-gray-300">
                Показывать плитку на главной
            </label>

            <div class="flex items-center gap-3 pt-2">
                <button class="px-6 py-2 bg-indigo-600 text-white rounded-md font-medium hover:bg-indigo-700">Сохранить</button>
                <a href="{{ route('admin.sections.index') }}" class="text-gray-500 hover:underline text-sm">Отмена</a>
            </div>
        </form>
    </div>
</x-app-layout>
