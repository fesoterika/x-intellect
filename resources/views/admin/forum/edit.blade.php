<x-app-layout>
    <x-slot name="title">{{ $topic->title }} — Форум</x-slot>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            <a href="{{ route('admin.forum.index') }}" class="text-gray-400 hover:text-gray-600 font-normal">Форум /</a>
            {{ $topic->title }}
        </h2>
    </x-slot>

    <div class="py-8 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-md p-4">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Свойства темы --}}
        <form method="POST" action="{{ route('admin.forum.update', $topic) }}" class="bg-white rounded-lg shadow p-6 space-y-4">
            @csrf @method('PUT')
            <div class="flex items-baseline gap-4">
                <h3 class="font-semibold text-gray-700">Тема</h3>
                <span class="text-xs text-gray-400">
                    {{ $topic->posts_count }} {{ \App\Support\RussianText::plural($topic->posts_count, 'сообщение', 'сообщения', 'сообщений') }}@if ($topic->started_at) · {{ $topic->started_at->format('d.m.Y') }}@if ($topic->last_posted_at && ! $topic->last_posted_at->isSameDay($topic->started_at)) — {{ $topic->last_posted_at->format('d.m.Y') }}@endif @endif
                </span>
                <a href="{{ url($topic->url()) }}" target="_blank" class="text-xs text-gray-500 hover:underline ml-auto">на сайте ↗</a>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Название *</label>
                <input type="text" name="title" value="{{ old('title', $topic->title) }}" required class="w-full rounded-md border-gray-300">
            </div>

            <div class="grid md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Адрес (slug) *</label>
                    <input type="text" name="slug" value="{{ old('slug', $topic->slug) }}" required
                           pattern="[a-z0-9-]+" class="w-full rounded-md border-gray-300 font-mono text-sm">
                    <p class="text-xs text-gray-400 mt-1">Строчные латинские буквы, цифры, дефис. При смене адреса со старого автоматически ставится 301-редирект.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Категория</label>
                    <input type="text" name="forum_group" value="{{ old('forum_group', $topic->forum_group) }}"
                           list="forum-groups" class="w-full rounded-md border-gray-300">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Раздел форума *</label>
                    <input type="text" name="forum_title" value="{{ old('forum_title', $topic->forum_title) }}" required
                           list="forum-sections" class="w-full rounded-md border-gray-300">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Дисклеймер</label>
                <textarea name="disclaimer" rows="3" class="w-full rounded-md border-gray-300 text-sm"
                          placeholder="Например: сообщения отражают личные мнения участников и не являются медицинскими рекомендациями…">{{ old('disclaimer', $topic->disclaimer) }}</textarea>
                <p class="text-xs text-gray-400 mt-1">Показывается внизу темы, под пагинацией. Пустое поле — приписки нет.</p>
            </div>

            <div class="flex items-center gap-4">
                <button class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700">Сохранить</button>
                <button formaction="{{ route('admin.forum.destroy', $topic) }}"
                        formmethod="POST" formnovalidate
                        name="_method" value="DELETE"
                        onclick="return confirm('Удалить тему «{{ $topic->title }}» и все её сообщения ({{ $topic->posts_count }})? Это действие необратимо.')"
                        class="text-red-600 hover:underline text-sm ml-auto">Удалить тему…</button>
            </div>
        </form>

        <datalist id="forum-groups">
            @foreach ($groupNames as $name)
                <option value="{{ $name }}">
            @endforeach
        </datalist>
        <datalist id="forum-sections">
            @foreach ($sectionNames as $name)
                <option value="{{ $name }}">
            @endforeach
        </datalist>

        {{-- Сообщения: правка в раскрывающейся карточке, как в глоссарии --}}
        <h3 class="font-semibold text-gray-700">Сообщения</h3>
        <div class="bg-white rounded-lg shadow divide-y">
            @forelse ($posts as $post)
                <details class="group">
                    <summary class="flex items-center gap-3 p-4 cursor-pointer select-none hover:bg-gray-50">
                        <span class="text-xs text-gray-400 w-8 shrink-0">#{{ $post->position }}</span>
                        <span class="font-medium text-gray-800 shrink-0">{{ $post->author }}</span>
                        <span class="text-sm text-gray-500 truncate grow">{{ Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags($post->body))), 110) }}</span>
                        <time class="text-xs text-gray-400 shrink-0 hidden sm:inline">{{ $post->posted_at?->format('d.m.Y H:i') }}</time>
                        <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>
                    </summary>
                    <form method="POST" action="{{ route('admin.forum.posts.update', $post) }}" class="px-4 pb-4 space-y-3 border-t bg-gray-50/50">
                        @csrf @method('PUT')
                        <div class="grid md:grid-cols-2 gap-3 pt-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Автор</label>
                                <input type="text" name="author" value="{{ $post->author }}" required class="w-full rounded-md border-gray-300 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Дата и время</label>
                                <input type="datetime-local" name="posted_at" value="{{ $post->posted_at?->format('Y-m-d\TH:i') }}" class="w-full rounded-md border-gray-300 text-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Текст сообщения (HTML)</label>
                            <textarea name="body" rows="10" required class="w-full rounded-md border-gray-300 font-mono text-xs leading-relaxed">{{ $post->body }}</textarea>
                        </div>
                        <div class="flex gap-4 items-center">
                            <button class="px-4 py-1.5 bg-indigo-600 text-white rounded-md text-xs font-medium hover:bg-indigo-700">Сохранить</button>
                            @if ($post->old_id)
                                <a href="{{ url($topic->url()) }}?page={{ intdiv($post->position, 25) + 1 }}#p{{ $post->old_id }}" target="_blank" class="text-xs text-gray-500 hover:underline">на сайте ↗</a>
                            @endif
                            <button formaction="{{ route('admin.forum.posts.destroy', $post) }}"
                                    formmethod="POST" formnovalidate
                                    name="_method" value="DELETE"
                                    onclick="return confirm('Удалить сообщение #{{ $post->position }} автора «{{ $post->author }}»?')"
                                    class="text-red-600 hover:underline text-xs ml-auto">Удалить</button>
                        </div>
                    </form>
                </details>
            @empty
                <p class="p-6 text-center text-gray-400 text-sm">В теме нет сообщений</p>
            @endforelse
        </div>

        {{ $posts->links() }}
    </div>
</x-app-layout>
