<x-app-layout>
    <x-slot name="title">{{ $page->exists ? 'Правка: '.$page->title : 'Новая страница' }}</x-slot>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $page->exists ? 'Правка страницы' : 'Новая страница' }}
            </h2>
            @if ($page->exists && $page->isPublished())
                <a href="{{ url($page->url()) }}" target="_blank" class="text-sm text-indigo-600 hover:underline">Открыть на сайте ↗</a>
            @endif
        </div>
    </x-slot>

    <div class="py-8 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8" x-data="{ confirmDelete: false }">
        @if ($errors->any())
            <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-700">
                <strong>Страница не сохранена - исправьте ошибки:</strong>
                <ul class="list-disc list-inside mt-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form method="POST"
              action="{{ $page->exists ? route('admin.pages.update', $page) : route('admin.pages.store') }}"
              class="space-y-6">
            @csrf
            @if ($page->exists) @method('PUT') @endif

            <div class="bg-white rounded-lg shadow p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Заголовок *</label>
                    <input type="text" name="title" value="{{ old('title', $page->title) }}" required class="w-full rounded-md border-gray-300">
                </div>

                <div class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Slug (SEO-url)</label>
                        <input type="text" name="slug" value="{{ old('slug', $page->slug) }}" placeholder="автоматически из заголовка" class="w-full rounded-md border-gray-300">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Раздел</label>
                        <select name="section_id" class="w-full rounded-md border-gray-300">
                            <option value="">- без раздела -</option>
                            @foreach ($sections as $section)
                                @if ($section->children->isEmpty())
                                    <option value="{{ $section->id }}" @selected(old('section_id', $page->section_id) == $section->id)>{{ $section->title }}</option>
                                @else
                                    <optgroup label="{{ $section->title }}">
                                        <option value="{{ $section->id }}" @selected(old('section_id', $page->section_id) == $section->id)>{{ $section->title }}</option>
                                        @foreach ($section->children as $child)
                                            <option value="{{ $child->id }}" @selected(old('section_id', $page->section_id) == $child->id)>↳ {{ $child->title }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Тип страницы</label>
                        <select name="page_type" class="w-full rounded-md border-gray-300">
                            <option value="page" @selected(old('page_type', $page->page_type) === 'page')>Обычная</option>
                            <option value="author" @selected(old('page_type', $page->page_type) === 'author')>Страница автора</option>
                        </select>
                    </div>
                </div>

                <div class="grid md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Статус</label>
                        <select name="status" class="w-full rounded-md border-gray-300">
                            <option value="draft" @selected(old('status', $page->status) === 'draft')>Черновик</option>
                            <option value="published" @selected(old('status', $page->status) === 'published')>Опубликовано</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Эпоха материала</label>
                        <select name="source_type" class="w-full rounded-md border-gray-300">
                            @foreach (\App\Models\Page::SOURCE_TYPES as $value => $label)
                                <option value="{{ $value }}" @selected(old('source_type', $page->source_type) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Дата архивной редакции</label>
                        <input type="date" name="archived_at" value="{{ old('archived_at', $page->archived_at?->format('Y-m-d')) }}" class="w-full rounded-md border-gray-300">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Дата материала</label>
                        <input type="datetime-local" name="published_at" value="{{ old('published_at', $page->published_at?->format('Y-m-d\TH:i')) }}" class="w-full rounded-md border-gray-300">
                        <span class="block text-xs text-gray-400 mt-1">
                            По ней идёт сортировка «по дате» в разделах. У архивных материалов - дата добавления на старом сайте.
                            Если очистить, при публикации проставится текущая дата.
                        </span>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Ссылка на архивную копию (source_url)</label>
                    <input type="url" name="source_url" value="{{ old('source_url', $page->source_url) }}" placeholder="https://web.archive.org/web/..." class="w-full rounded-md border-gray-300">
                </div>

                <label class="flex items-start gap-2 text-sm text-gray-700">
                    <input type="hidden" name="is_listed" value="0">
                    <input type="checkbox" name="is_listed" value="1" @checked(old('is_listed', $page->is_listed ?? true)) class="mt-0.5 rounded border-gray-300">
                    <span>
                        Показывать в списках
                        <span class="block text-xs text-gray-400">Если снять - страница доступна только по прямой ссылке (напр. юридические страницы), не выводится в разделах, «Последних материалах» и поиске.</span>
                    </span>
                </label>

                <label class="flex items-start gap-2 text-sm text-gray-700">
                    <input type="hidden" name="in_wiki_menu" value="0">
                    <input type="checkbox" name="in_wiki_menu" value="1" @checked(old('in_wiki_menu', $page->in_wiki_menu ?? false)) class="mt-0.5 rounded border-gray-300">
                    <span>
                        Выводить в меню вики
                        <span class="block text-xs text-gray-400">Боковое меню на страницах раздела «Вики» показывает только страницы с этой галочкой. По умолчанию выключено.</span>
                    </span>
                </label>

                <label class="flex items-start gap-2 text-sm text-gray-700">
                    <input type="hidden" name="is_pinned" value="0">
                    <input type="checkbox" name="is_pinned" value="1" @checked(old('is_pinned', $page->is_pinned ?? false)) class="mt-0.5 rounded border-gray-300">
                    <span>
                        Закрепить материал
                        <span class="block text-xs text-gray-400">Идёт первым в списке раздела и в админке; в карточке появляется значок булавки. Закреплённых может быть много - между собой они идут по выбранной на сайте сортировке.</span>
                    </span>
                </label>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Анонс (excerpt)</label>
                    <textarea name="excerpt" rows="2" class="w-full rounded-md border-gray-300">{{ old('excerpt', $page->excerpt) }}</textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Дисклеймер</label>
                    <textarea name="disclaimer" rows="3" class="w-full rounded-md border-gray-300 text-sm"
                              placeholder="Например: материал не является медицинской консультацией или рекомендацией…">{{ old('disclaimer', $page->disclaimer) }}</textarea>
                    <p class="text-xs text-gray-400 mt-1">Неброская приписка внизу страницы, под плашкой «Нашли ошибку?». Пусто - приписки нет.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Текст страницы</label>
                    {{-- Trix-редактор: клиентский JS-виджет без React/Vue-рантайма.
                         Кнопка «♪ Аудио» вставляет short-код [[audio:ID]].
                         Таблицы и HTML-вставки тела оборачиваются в
                         content-вложения Trix (TrixTables/TrixEmbeds::embed),
                         иначе Trix их вырезает; при сохранении PageObserver
                         разворачивает обратно --}}
                    @php
                        // Вставки сворачиваются первыми: их код уезжает в атрибут
                        // фигуры уже экранированным, и <table> внутри чужого кода
                        // не попадёт под разбор таблиц
                        $xiBody = old('body', app(\App\Services\TrixTables::class)->embed(
                            app(\App\Services\TrixEmbeds::class)->embed($page->body),
                        ));
                    @endphp
                    <input id="body" type="hidden" name="body" value="{{ $xiBody }}">
                    @php
                        // Карта выравнивания картинок для JS: Trix при разборе стирает
                        // класс xi-float-* у <img>, поэтому отдаём соответствие src→выравнивание
                        // с сервера (здесь класс ещё цел), чтобы восстановить его как атрибут Trix.
                        $xiAlignMap = [];
                        if (preg_match_all('/<img\b[^>]*>/i', (string) old('body', $page->body), $xiTags)) {
                            foreach ($xiTags[0] as $xiTag) {
                                if (! preg_match('/src="([^"]+)"/', $xiTag, $xiSrc) || ! preg_match('/class="([^"]*)"/', $xiTag, $xiCls)) {
                                    continue;
                                }
                                foreach (['xi-float-left' => 'left', 'xi-float-right' => 'right', 'xi-align-center' => 'center', 'xi-align-wide' => 'wide'] as $xiC => $xiA) {
                                    if (str_contains($xiCls[1], $xiC)) { $xiAlignMap[$xiSrc[1]] = $xiA; break; }
                                }
                            }
                        }
                    @endphp
                    <script type="application/json" id="xi-align-map">{!! json_encode($xiAlignMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
                    <trix-editor input="body" @if($page->exists) data-page-id="{{ $page->id }}" @endif class="trix-content bg-white border border-gray-300 rounded-md min-h-64"></trix-editor>
                    <p class="text-xs text-gray-400 mt-1">
                        Short-код <code>[[audio:ID]]</code> разворачивается в аудиоплеер на публичной странице.
                        ID - из раздела «Медиа»@if($page->exists && $page->media->isNotEmpty()): прикреплённые файлы перечислены ниже@endif.
                        Картинки, аудио и PDF (до 170 МБ) можно загружать прямо в редактор - кнопкой, скрепкой или перетаскиванием;
                        аудио при этом вставится short-кодом, файл попадёт в «Медиа».
                        Кнопка <code>&lt;/&gt;</code> вставляет код с другого сайта (плеер, видео, плейлист): на страницу пропускаются
                        только теги <code>&lt;iframe&gt;</code>, остальное вырезается при сохранении. В редакторе вставка показана
                        карточкой с кодом (двойной клик - правка) и выравнивается теми же кнопками, что картинки.
                    </p>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6 space-y-4">
                <h3 class="font-semibold text-gray-700">SEO <span class="text-xs font-normal text-gray-400">(пустые поля заполнятся автоматически при сохранении)</span></h3>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Meta title</label>
                        <input type="text" name="seo[meta_title]" value="{{ old('seo.meta_title', $page->seoValue('meta_title')) }}" class="w-full rounded-md border-gray-300">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Schema.org тип</label>
                        <select name="seo[schema_type]" class="w-full rounded-md border-gray-300">
                            @foreach (['Article', 'FAQPage', 'Person', 'WebPage'] as $type)
                                <option value="{{ $type }}" @selected(old('seo.schema_type', $page->seoValue('schema_type')) === $type)>{{ $type }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Meta description</label>
                    <textarea name="seo[meta_description]" rows="2" class="w-full rounded-md border-gray-300">{{ old('seo.meta_description', $page->seoValue('meta_description')) }}</textarea>
                </div>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">OG image (URL)</label>
                        <input type="text" name="seo[og_image]" value="{{ old('seo.og_image', $page->seoValue('og_image')) }}" class="w-full rounded-md border-gray-300">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Canonical URL</label>
                        <input type="text" name="seo[canonical]" value="{{ old('seo.canonical', $page->seoValue('canonical')) }}" class="w-full rounded-md border-gray-300">
                    </div>
                </div>
            </div>

            @if ($page->exists)
                <div class="bg-white rounded-lg shadow p-6">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Причина изменения</label>
                    <input type="text" name="revision_reason" value="{{ old('revision_reason') }}"
                           placeholder="например: уточнил даты по архивной копии"
                           class="w-full rounded-md border-gray-300">
                    <p class="text-xs text-gray-400 mt-1">
                        Попадёт в историю изменений - в админке и в блоке «История изменений» на самой странице.
                        Записывается, только если правится заголовок или текст. Поле каждый раз пустое.
                    </p>
                </div>
            @endif

            <div class="flex items-center gap-3">
                <button class="px-6 py-2 bg-indigo-600 text-white rounded-md font-medium hover:bg-indigo-700">Сохранить</button>
                <a href="{{ route('admin.pages.index') }}" class="text-gray-500 hover:underline text-sm">Отмена</a>
                <input type="hidden" name="position" value="{{ old('position', $page->position ?? 0) }}">
                @if ($page->exists)
                    <button type="button" @click="confirmDelete = true"
                            class="ml-auto px-6 py-2 bg-red-600 text-white rounded-md font-medium hover:bg-red-700">
                        Удалить статью
                    </button>
                @endif
            </div>
        </form>

        @if ($page->exists)
            {{-- Попап подтверждения удаления. Форма удаления вынесена из основной
                 формы правки (вложенные <form> недопустимы) и повторяет действие
                 кнопки «Удалить» в списке страниц: POST + @method('DELETE'). --}}
            <div x-show="confirmDelete" x-cloak style="display: none"
                 @keydown.escape.window="confirmDelete = false"
                 class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/50" @click="confirmDelete = false"></div>
                <div class="relative bg-white rounded-lg shadow-xl max-w-sm w-full p-6" role="dialog" aria-modal="true">
                    <h3 class="text-lg font-semibold text-gray-800">Удалить статью?</h3>
                    <p class="mt-2 text-sm text-gray-500">Вы уверены? Страница «{{ $page->title }}» будет удалена.</p>
                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" @click="confirmDelete = false"
                                class="px-5 py-2 text-sm text-gray-600 rounded-md hover:bg-gray-100">Нет</button>
                        <form method="POST" action="{{ route('admin.pages.destroy', $page) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="px-5 py-2 text-sm bg-red-600 text-white rounded-md font-medium hover:bg-red-700">Да</button>
                        </form>
                    </div>
                </div>
            </div>
        @endif

        @if ($page->exists)
            <div class="mt-8 grid md:grid-cols-2 gap-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="font-semibold text-gray-700 mb-3">Прикреплённые медиа</h3>
                    @forelse ($page->media as $item)
                        <div class="flex items-center justify-between gap-3 py-2 border-b last:border-0 text-sm">
                            <div class="flex items-center gap-2 min-w-0">
                                @if ($item->type === 'image')
                                    <img src="{{ $item->url() }}" alt="" class="w-10 h-10 object-cover rounded shrink-0">
                                @endif
                                <div class="min-w-0">
                                    <span class="text-gray-400">#{{ $item->id }}</span>
                                    {{ $item->title }}
                                    <span class="text-xs text-gray-400">({{ $item->type }}{{ $item->durationLabel() ? ', '.$item->durationLabel() : '' }})</span>
                                </div>
                            </div>
                            {{-- Short-код разворачивается в аудиоплеер только для типа audio (см. PageRenderer) --}}
                            @if ($item->type === 'audio')
                                <code class="text-xs bg-gray-100 px-2 py-0.5 rounded shrink-0">[[audio:{{ $item->id }}]]</code>
                            @else
                                <a href="{{ $item->url() }}" target="_blank" class="text-xs text-indigo-600 hover:underline shrink-0">открыть ↗</a>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-400">Нет файлов. Загрузите в разделе «Медиа» и привяжите к этой странице.</p>
                    @endforelse
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="font-semibold text-gray-700 mb-3">История изменений</h3>
                    {{-- Записи правятся по одной: каждая - отдельная форма (она вне
                         основной формы страницы, вложенные <form> недопустимы).
                         Служебная пометка note не редактируется: по ней импортёры
                         узнают вручную правленные страницы и не затирают их. --}}
                    @forelse ($page->revisions as $revision)
                        <div class="py-2 border-b last:border-0 text-sm" x-data="{ editing: false }">
                            <div x-show="!editing">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="text-gray-700">{{ $revision->title }}</div>
                                    <button type="button" @click="editing = true"
                                            class="text-xs text-indigo-600 hover:underline shrink-0">править</button>
                                </div>
                                @if ($revision->reason)
                                    <div class="text-gray-500">{{ $revision->reason }}</div>
                                @endif
                                <div class="text-xs text-gray-400">
                                    {{ $revision->sourceLabel() }}
                                    @if ($revision->archived_at) · редакция {{ $revision->archived_at->format('Y') }} г. @endif
                                    · {{ $revision->created_at->format('d.m.Y H:i') }}
                                    @if ($revision->note) · {{ $revision->note }} @endif
                                </div>
                            </div>

                            <div x-show="editing" x-cloak style="display: none" class="space-y-2 py-1">
                                <form method="POST" action="{{ route('admin.pages.revisions.update', [$page, $revision]) }}" class="space-y-2">
                                    @csrf
                                    @method('PUT')
                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">Заголовок редакции</label>
                                        <input type="text" name="title" value="{{ $revision->title }}" required class="w-full rounded-md border-gray-300 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">Причина изменения</label>
                                        <textarea name="reason" rows="2" class="w-full rounded-md border-gray-300 text-sm">{{ $revision->reason }}</textarea>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">Дата архивной редакции</label>
                                        <input type="date" name="archived_at" value="{{ $revision->archived_at?->format('Y-m-d') }}" class="w-full rounded-md border-gray-300 text-sm">
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <button class="px-4 py-1.5 bg-indigo-600 text-white rounded-md text-sm hover:bg-indigo-700">Сохранить</button>
                                        <button type="button" @click="editing = false" class="text-sm text-gray-500 hover:underline">Отмена</button>
                                    </div>
                                </form>
                                <form method="POST" action="{{ route('admin.pages.revisions.destroy', [$page, $revision]) }}"
                                      onsubmit="return confirm('Удалить эту запись истории? Отменить будет нельзя.')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-xs text-red-600 hover:underline">Удалить запись</button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400">Ревизий пока нет - они создаются автоматически при правке заголовка или текста.</p>
                    @endforelse
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
