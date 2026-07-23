<x-app-layout>
    <x-slot name="title">Форум</x-slot>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Архив форума</h2>
    </x-slot>

    <div class="py-8 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        <p class="text-sm text-gray-500">
            Структура повторяет публичный <a href="{{ url('/forum') }}" target="_blank" class="text-indigo-600 hover:underline">/forum ↗</a>:
            категория → раздел → темы. Название темы, её адрес, дисклеймер и сообщения правятся на странице темы
            (клик по названию). Переименование категории или раздела применяется ко всем их темам;
            удаление категории или раздела удаляет всё её содержимое — вложенные разделы, темы и сообщения.
            Всего: {{ $topicsCount }} {{ \App\Support\RussianText::plural($topicsCount, 'тема', 'темы', 'тем') }},
            {{ $postsCount }} {{ \App\Support\RussianText::plural($postsCount, 'сообщение', 'сообщения', 'сообщений') }}.
        </p>

        @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-md p-4">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @foreach ($groups as $group => $forums)
            <section class="bg-white rounded-lg shadow">
                {{-- Категория: заголовок + переименование --}}
                <header class="px-6 py-4 border-b">
                    @php
                        $groupValue = $forums->first()->first()->forum_group;
                        $groupTopics = $forums->flatten();
                    @endphp
                    <details>
                        <summary class="flex items-center gap-3 cursor-pointer select-none">
                            <h3 class="font-semibold text-gray-800">{{ $group }}</h3>
                            <span class="text-xs uppercase tracking-wide text-gray-400">категория</span>
                            <span class="text-xs text-indigo-500 hover:underline ml-auto shrink-0">изменить</span>
                        </summary>
                        <div class="flex flex-wrap items-center gap-2 mt-3">
                            <form method="POST" action="{{ route('admin.forum.group.rename') }}" class="flex items-center gap-2 grow">
                                @csrf @method('PUT')
                                <input type="hidden" name="old" value="{{ $groupValue }}">
                                <input type="text" name="name" value="{{ $group }}" required class="rounded-md border-gray-300 text-sm grow max-w-sm">
                                <button class="px-3 py-1.5 bg-indigo-600 text-white rounded-md text-xs font-medium hover:bg-indigo-700">Сохранить</button>
                            </form>
                            <form method="POST" action="{{ route('admin.forum.group.destroy') }}"
                                  onsubmit="return confirm('Удалить категорию «{{ $group }}» целиком: {{ $forums->count() }} {{ \App\Support\RussianText::plural($forums->count(), 'раздел', 'раздела', 'разделов') }}, {{ $groupTopics->count() }} {{ \App\Support\RussianText::plural($groupTopics->count(), 'тема', 'темы', 'тем') }} и {{ $groupTopics->sum('posts_count') }} {{ \App\Support\RussianText::plural((int) $groupTopics->sum('posts_count'), 'сообщение', 'сообщения', 'сообщений') }}? Это действие необратимо.')">
                                @csrf @method('DELETE')
                                <input type="hidden" name="old" value="{{ $groupValue }}">
                                <button class="text-red-600 hover:underline text-xs">Удалить категорию…</button>
                            </form>
                        </div>
                    </details>
                </header>

                {{-- Разделы категории --}}
                @foreach ($forums as $forumTitle => $topics)
                    <div class="border-b last:border-b-0">
                        <div class="px-6 py-3 bg-gray-50">
                            <details>
                                <summary class="flex items-center gap-3 cursor-pointer select-none">
                                    <span class="font-medium text-gray-700">{{ $forumTitle }}</span>
                                    <span class="text-xs text-gray-400">{{ $topics->count() }} {{ \App\Support\RussianText::plural($topics->count(), 'тема', 'темы', 'тем') }}</span>
                                    <span class="text-xs text-indigo-500 hover:underline ml-auto shrink-0">изменить</span>
                                </summary>
                                <div class="flex flex-wrap items-center gap-2 mt-3">
                                    <form method="POST" action="{{ route('admin.forum.section.rename') }}" class="flex items-center gap-2 grow">
                                        @csrf @method('PUT')
                                        <input type="hidden" name="group" value="{{ $topics->first()->forum_group }}">
                                        <input type="hidden" name="old" value="{{ $forumTitle }}">
                                        <input type="text" name="name" value="{{ $forumTitle }}" required class="rounded-md border-gray-300 text-sm grow max-w-sm">
                                        <button class="px-3 py-1.5 bg-indigo-600 text-white rounded-md text-xs font-medium hover:bg-indigo-700">Сохранить</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.forum.section.destroy') }}"
                                          onsubmit="return confirm('Удалить раздел «{{ $forumTitle }}» со всеми его темами ({{ $topics->count() }}) и сообщениями? Это действие необратимо.')">
                                        @csrf @method('DELETE')
                                        <input type="hidden" name="group" value="{{ $topics->first()->forum_group }}">
                                        <input type="hidden" name="old" value="{{ $forumTitle }}">
                                        <button class="text-red-600 hover:underline text-xs">Удалить раздел…</button>
                                    </form>
                                </div>
                            </details>
                        </div>

                        {{-- Темы раздела --}}
                        <ul class="divide-y divide-gray-100">
                            @foreach ($topics as $topic)
                                <li class="flex items-center gap-3 px-6 py-2.5 hover:bg-gray-50">
                                    <a href="{{ route('admin.forum.edit', $topic) }}" class="text-sm text-indigo-700 hover:underline truncate">{{ $topic->title }}</a>
                                    @if ($topic->disclaimer)
                                        <span class="text-xs text-amber-600 bg-amber-50 rounded-full px-2 py-0.5 shrink-0" title="У темы есть дисклеймер">дисклеймер</span>
                                    @endif
                                    <span class="text-xs text-gray-400 ml-auto shrink-0">{{ $topic->posts_count }} сообщ.</span>
                                    <span class="text-xs text-gray-400 shrink-0 hidden sm:inline">{{ $topic->started_at?->format('d.m.Y') }}</span>
                                    <a href="{{ url($topic->url()) }}" target="_blank" class="text-xs text-gray-400 hover:text-gray-600 shrink-0" title="Открыть на сайте">↗</a>
                                    <form method="POST" action="{{ route('admin.forum.destroy', $topic) }}" class="shrink-0"
                                          onsubmit="return confirm('Удалить тему «{{ $topic->title }}» и все её сообщения ({{ $topic->posts_count }})?')">
                                        @csrf @method('DELETE')
                                        <button class="text-gray-300 hover:text-red-600 text-sm leading-none" title="Удалить тему">✕</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </section>
        @endforeach
    </div>
</x-app-layout>
