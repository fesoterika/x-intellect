<x-app-layout>
    <x-slot name="title">Медиа</x-slot>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Медиафайлы</h2>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        {{-- Флеш session('status') показывает layout; здесь — только ошибки валидации --}}
        @if ($errors->any())
            <div class="rounded-md bg-red-50 border border-red-200 text-red-800 text-sm px-4 py-3">
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

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
                @forelse ($media as $item)
                    {{-- Свой tbody на запись: строка данных + скрытая строка правки (Alpine).
                         После ошибки валидации форма открывается снова (_media_id из old) --}}
                    <tbody x-data="{ edit: @js($errors->any() && old('_media_id') == $item->id) }">
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
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <button type="button" @click="edit = !edit" class="text-indigo-600 hover:underline text-xs mr-3" x-text="edit ? 'Свернуть' : 'Изменить'">Изменить</button>
                                <form method="POST" action="{{ route('admin.media.destroy', $item) }}" class="inline" onsubmit="return confirm('Удалить файл «{{ $item->title }}»?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:underline text-xs">Удалить</button>
                                </form>
                            </td>
                        </tr>
                        <tr x-show="edit" x-cloak class="border-t bg-gray-50/70">
                            <td colspan="6" class="px-5 py-4">
                                <form method="POST" action="{{ route('admin.media.update', $item) }}" class="grid md:grid-cols-6 gap-3 items-end">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="_media_id" value="{{ $item->id }}">
                                    <div class="md:col-span-2">
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Название</label>
                                        <input type="text" name="title" required value="{{ old('_media_id') == $item->id ? old('title') : $item->title }}" class="w-full rounded-md border-gray-300 text-sm">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Страница</label>
                                        <select name="page_id" class="w-full rounded-md border-gray-300 text-sm">
                                            <option value="">- не привязан -</option>
                                            @foreach ($pages as $p)
                                                <option value="{{ $p->id }}" @selected((old('_media_id') == $item->id ? old('page_id') : $item->page_id) == $p->id)>{{ $p->title }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Порядок</label>
                                        <input type="number" name="position" min="0" value="{{ old('_media_id') == $item->id ? old('position') : $item->position }}" class="w-full rounded-md border-gray-300 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Длительность, сек</label>
                                        <input type="number" name="duration" min="0" value="{{ old('_media_id') == $item->id ? old('duration') : $item->duration }}" class="w-full rounded-md border-gray-300 text-sm">
                                    </div>
                                    <div class="md:col-span-6 flex items-center gap-4">
                                        <button class="px-4 py-1.5 bg-indigo-600 text-white rounded-md text-xs font-medium hover:bg-indigo-700">Сохранить</button>
                                        <span class="text-xs text-gray-400">Файл заменить нельзя — удалите запись и загрузите заново.</span>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    </tbody>
                @empty
                    <tbody>
                        <tr><td colspan="6" class="px-5 py-6 text-center text-gray-400">Файлов пока нет</td></tr>
                    </tbody>
                @endforelse
            </table>
        </div>

        {{ $media->links() }}
    </div>
</x-app-layout>
