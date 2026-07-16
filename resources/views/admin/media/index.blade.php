<x-app-layout>
    <x-slot name="title">Медиа</x-slot>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Медиафайлы</h2>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        <form method="POST" action="{{ route('admin.media.store') }}" enctype="multipart/form-data"
              class="bg-white rounded-lg shadow p-6 grid md:grid-cols-6 gap-4 items-end">
            @csrf
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Название *</label>
                <input type="text" name="title" required class="w-full rounded-md border-gray-300">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Тип</label>
                <select name="type" class="w-full rounded-md border-gray-300">
                    <option value="audio">Аудио (mp3)</option>
                    <option value="pdf">Книга / PDF</option>
                    <option value="image">Изображение</option>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Страница</label>
                <select name="page_id" class="w-full rounded-md border-gray-300">
                    <option value="">- не привязан -</option>
                    @foreach ($pages as $p)
                        <option value="{{ $p->id }}">{{ $p->title }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Порядок</label>
                <input type="number" name="position" value="0" class="w-full rounded-md border-gray-300">
            </div>
            <div class="md:col-span-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">Файл (до 500 МБ)</label>
                <input type="file" name="file" class="w-full text-sm">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">…или внешний URL (S3-хранилище)</label>
                <input type="url" name="external_url" placeholder="https://…" class="w-full rounded-md border-gray-300">
            </div>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700">Добавить</button>
        </form>

        <form method="GET" class="bg-white rounded-lg shadow p-4 flex flex-wrap gap-3 items-end">
            <div class="w-full sm:w-auto">
                <label class="block text-xs text-gray-500 mb-1">Тип</label>
                <select name="type" class="w-full sm:w-auto rounded-md border-gray-300 text-sm">
                    <option value="">Все</option>
                    <option value="audio" @selected(request('type') === 'audio')>Аудио</option>
                    <option value="pdf" @selected(request('type') === 'pdf')>Книга / PDF</option>
                    <option value="image" @selected(request('type') === 'image')>Изображение</option>
                </select>
            </div>
            <div class="w-full sm:w-auto">
                <label class="block text-xs text-gray-500 mb-1">Страница</label>
                <select name="page_id" class="w-full sm:w-48 rounded-md border-gray-300 text-sm">
                    <option value="">Все</option>
                    @foreach ($pages as $p)
                        <option value="{{ $p->id }}" @selected(request('page_id') == $p->id)>{{ $p->title }}</option>
                    @endforeach
                </select>
            </div>
            <div class="w-full sm:flex-1 sm:min-w-40">
                <label class="block text-xs text-gray-500 mb-1">Поиск по названию и файлу</label>
                <input type="text" name="q" value="{{ request('q') }}" class="w-full rounded-md border-gray-300 text-sm">
            </div>
            <button class="w-full sm:w-auto px-4 py-2 bg-gray-800 text-white rounded-md text-sm">Фильтр</button>
        </form>

        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <th class="px-5 py-3">ID / short-код</th>
                        <th class="px-5 py-3">Название</th>
                        <th class="px-5 py-3">Тип</th>
                        <th class="px-5 py-3">Страница</th>
                        <th class="px-5 py-3">Файл</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($media as $item)
                        <tr class="border-t">
                            <td class="px-5 py-3">
                                <span class="text-xs text-gray-400">#{{ $item->id }}</span>
                                {{-- Short-код разворачивается в аудиоплеер только для типа audio (см. PageRenderer) --}}
                                @if ($item->type === 'audio')
                                    <code class="text-xs bg-gray-100 px-2 py-0.5 rounded">[[audio:{{ $item->id }}]]</code>
                                @endif
                            </td>
                            <td class="px-5 py-3">{{ $item->title }}
                                @if ($item->durationLabel())<span class="text-xs text-gray-400">({{ $item->durationLabel() }})</span>@endif
                            </td>
                            <td class="px-5 py-3 text-gray-500">{{ $item->type }}</td>
                            <td class="px-5 py-3 text-gray-500">{{ $item->page?->title ?? '-' }}</td>
                            <td class="px-5 py-3"><a class="text-indigo-600 hover:underline text-xs" href="{{ $item->url() }}" target="_blank">открыть ↗</a></td>
                            <td class="px-5 py-3 text-right">
                                <form method="POST" action="{{ route('admin.media.destroy', $item) }}" onsubmit="return confirm('Удалить файл «{{ $item->title }}»?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:underline text-xs">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-6 text-center text-gray-400">Файлов пока нет</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $media->links() }}
    </div>
</x-app-layout>
