<x-app-layout>
    <x-slot name="title">Обзор</x-slot>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Обзор</h2>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
        {{-- Режим технических работ --}}
        <div class="rounded-lg shadow overflow-hidden {{ $maintenance ? 'bg-amber-50 border border-amber-300' : 'bg-white' }}">
            <div class="px-5 py-4 flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full {{ $maintenance ? 'bg-amber-100 text-amber-700' : 'bg-indigo-50 text-indigo-600' }}">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14.7 6.3a4.5 4.5 0 0 0-6 5.6L3 17.6a2 2 0 1 0 2.8 2.8l5.7-5.7a4.5 4.5 0 0 0 5.6-6l-3 3-2.8-.7-.7-2.8 3.1-3z"/>
                        </svg>
                    </span>
                    <div>
                        <div class="font-semibold text-gray-800 flex items-center gap-2">
                            Технические работы
                            <span class="px-2 py-0.5 rounded text-xs font-medium {{ $maintenance ? 'bg-amber-200 text-amber-900' : 'bg-green-100 text-green-800' }}">
                                {{ $maintenance ? 'заглушка включена' : 'сайт открыт' }}
                            </span>
                        </div>
                        <p class="text-sm text-gray-500 mt-1 max-w-xl">
                            @if ($maintenance)
                                Посетители видят заглушку со статусом 503 (<a class="text-indigo-700 hover:underline" href="{{ route('home') }}" target="_blank" rel="noopener">посмотреть сайт</a> - вы, как редактор, видите его целиком).
                                Поисковики считают перерыв временным и не выбрасывают страницы из индекса.
                            @else
                                При включении публичная часть закрывается красивой заглушкой (503 + Retry-After - без потерь SEO);
                                залогиненным редакторам и администраторам сайт остаётся доступен полностью.
                            @endif
                            Анонсы работ публикуются в <a class="text-indigo-700 hover:underline" href="https://t.me/+H15kvUCtrUw4ODAy" target="_blank" rel="noopener">телеграм-канале</a>.
                        </p>
                    </div>
                </div>
                <form method="POST" action="{{ route('admin.maintenance.toggle') }}"
                      onsubmit="return confirm('{{ $maintenance ? 'Выключить режим техработ и снова открыть сайт посетителям?' : 'Включить режим техработ? Посетители увидят заглушку, сайт останется доступен только редакторам.' }}')">
                    @csrf
                    <input type="hidden" name="enabled" value="{{ $maintenance ? 0 : 1 }}">
                    <button type="submit" class="px-4 py-2 rounded-md text-sm font-semibold text-white {{ $maintenance ? 'bg-green-600 hover:bg-green-700' : 'bg-amber-600 hover:bg-amber-700' }}">
                        {{ $maintenance ? 'Выключить и открыть сайт' : 'Включить режим техработ' }}
                    </button>
                </form>
            </div>
        </div>

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
                            <td class="px-5 py-3 text-gray-500">{{ $page->section?->title ?? '-' }}</td>
                            <td class="px-5 py-3">
                                <span class="px-2 py-0.5 rounded text-xs {{ $page->isPublished() ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                                    {{ $page->isPublished() ? 'опубликовано' : 'черновик' }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-gray-400">{{ $page->updated_at->format('d.m.Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-5 py-4 text-gray-400">Страниц пока нет - начните с «Страницы → Создать» или импортируйте архив: <code>php artisan import:archive</code></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
