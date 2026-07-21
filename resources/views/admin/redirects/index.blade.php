<x-app-layout>
    <x-slot name="title">Редиректы</x-slot>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Редиректы</h2>
    </x-slot>

    <div class="py-8 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        <div class="flex flex-wrap gap-4 items-start justify-between">
            <p class="text-sm text-gray-500 flex-1 min-w-64">
                301 - постоянный перенос со старых архивных URL (сохранение ссылочного веса).
                302 - обёртки <code>/go/*.html</code> для обхода adblock: внутренняя ссылка того же домена,
                сервер перенаправляет на внешний ресурс (Дзен, донат и т.п.).
            </p>
            <form method="POST" action="{{ route('admin.redirects.fix-chains') }}"
                  onsubmit="return confirm('Проверить редиректы и схлопнуть цепочки? Правятся только однозначные случаи.')">
                @csrf
                <button class="px-4 py-2 bg-emerald-600 text-white rounded-md text-sm font-medium hover:bg-emerald-700">
                    Исправить цепочки
                </button>
            </form>
        </div>

        @if (session('redirects_report'))
            <x-modal name="redirects-report" :show="true" maxWidth="2xl">
                <div class="p-6 space-y-4">
                    <h3 class="text-lg font-semibold text-gray-800">Отчёт проверки редиректов</h3>
                    <pre class="bg-gray-50 border rounded-md p-4 text-xs font-mono whitespace-pre-wrap max-h-96 overflow-y-auto">{{ session('redirects_report') }}</pre>
                    <div class="text-right">
                        <button type="button" x-on:click="$dispatch('close')"
                                class="px-4 py-2 bg-gray-800 text-white rounded-md text-sm">Закрыть</button>
                    </div>
                </div>
            </x-modal>
        @endif

        <form method="POST" action="{{ route('admin.redirects.store') }}" class="bg-white rounded-lg shadow p-6 grid md:grid-cols-6 gap-4 items-end">
            @csrf
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Откуда (путь) *</label>
                <input type="text" name="from_path" required placeholder="/go/dzen.html" class="w-full rounded-md border-gray-300">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Куда (URL) *</label>
                <input type="text" name="to_url" required placeholder="https://dzen.ru/fesoterika" class="w-full rounded-md border-gray-300">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Код</label>
                <select name="status_code" class="w-full rounded-md border-gray-300">
                    <option value="301">301</option>
                    <option value="302">302</option>
                </select>
            </div>
            <button class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700">Добавить</button>
            <div class="md:col-span-6">
                <label class="block text-sm font-medium text-gray-700 mb-1">Комментарий</label>
                <input type="text" name="comment" class="w-full rounded-md border-gray-300">
            </div>
        </form>

        <form method="GET" class="bg-white rounded-lg shadow p-4 flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-40">
                <label class="block text-xs text-gray-500 mb-1">Поиск по пути, URL и комментарию</label>
                <input type="text" name="q" value="{{ request('q') }}" class="w-full rounded-md border-gray-300 text-sm">
            </div>
            <button class="px-4 py-2 bg-gray-800 text-white rounded-md text-sm">Фильтр</button>
        </form>

        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-500">
                    <tr>
                        <th class="px-5 py-3">Откуда</th>
                        <th class="px-5 py-3">Куда</th>
                        <th class="px-5 py-3">Код</th>
                        <th class="px-5 py-3">Переходы</th>
                        <th class="px-5 py-3">Комментарий</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($redirects as $redirect)
                        <tr class="border-t">
                            <td class="px-5 py-3 font-mono text-xs">{{ $redirect->from_path }}</td>
                            <td class="px-5 py-3 font-mono text-xs max-w-64 truncate">{{ $redirect->to_url }}</td>
                            <td class="px-5 py-3">{{ $redirect->status_code }}</td>
                            <td class="px-5 py-3">{{ $redirect->hits }}</td>
                            <td class="px-5 py-3 text-gray-500">{{ $redirect->comment }}</td>
                            <td class="px-5 py-3 text-right">
                                <form method="POST" action="{{ route('admin.redirects.destroy', $redirect) }}" onsubmit="return confirm('Удалить редирект {{ $redirect->from_path }}?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 hover:underline text-xs">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-6 text-center text-gray-400">Редиректов пока нет</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $redirects->links() }}
    </div>
</x-app-layout>
