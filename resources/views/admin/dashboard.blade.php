<x-app-layout>
    <x-slot name="title">Обзор</x-slot>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Обзор</h2>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach ($stats as $label => $value)
                <div class="bg-white rounded-lg shadow p-5">
                    <div class="text-3xl font-bold text-indigo-700">{{ $value }}</div>
                    <div class="text-sm text-gray-500 mt-1">{{ $label }}</div>
                </div>
            @endforeach
        </div>

        <div class="bg-white rounded-lg shadow">
            <div class="px-5 py-4 border-b font-semibold text-gray-700">Последние изменения</div>
            <table class="w-full text-sm">
                <tbody>
                    @forelse ($recentPages as $page)
                        <tr class="border-b last:border-0">
                            <td class="px-5 py-3">
                                <a class="text-indigo-700 hover:underline" href="{{ route('admin.pages.edit', $page) }}">{{ $page->title }}</a>
                            </td>
                            <td class="px-5 py-3 text-gray-500">{{ $page->section?->title ?? '—' }}</td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded text-xs {{ $page->isPublished() ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                                    {{ $page->isPublished() ? 'опубликовано' : 'черновик' }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-gray-400">{{ $page->updated_at->format('d.m.Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-5 py-4 text-gray-400">Страниц пока нет — начните с «Страницы → Создать» или импортируйте архив: <code>php artisan import:archive</code></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
