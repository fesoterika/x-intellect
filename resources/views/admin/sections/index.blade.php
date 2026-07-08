<x-app-layout>
    <x-slot name="title">Разделы</x-slot>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Разделы</h2>
            <a href="{{ route('admin.sections.create') }}" class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700">+ Создать</a>
        </div>
    </x-slot>

    <div class="py-8 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <th class="px-5 py-3">Раздел</th>
                        <th class="px-5 py-3">Slug</th>
                        <th class="px-5 py-3">Страниц</th>
                        <th class="px-5 py-3">Видимость</th>
                        <th class="px-5 py-3">Порядок</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($sections as $section)
                        <tr class="border-t">
                            <td class="px-5 py-3">
                                <a class="text-indigo-700 hover:underline font-medium" href="{{ route('admin.sections.edit', $section) }}">{{ $section->title }}</a>
                            </td>
                            <td class="px-5 py-3 text-gray-500">/{{ $section->slug }}</td>
                            <td class="px-5 py-3">{{ $section->pages_count }}</td>
                            <td class="px-5 py-3">{{ $section->is_visible ? 'видим' : 'скрыт' }}</td>
                            <td class="px-5 py-3">{{ $section->position }}</td>
                            <td class="px-5 py-3 text-right">
                                <form method="POST" action="{{ route('admin.sections.destroy', $section) }}" onsubmit="return confirm('Удалить раздел «{{ $section->title }}»? Страницы останутся без раздела.')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:underline text-xs">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
