@extends('layouts.site')

@php
    $base = rtrim(config('app.url'), '/');
@endphp

@section('title', $active
    ? $active->term.' - глоссарий проекта X-Intellect'
    : 'Глоссарий - толкователь терминов проекта X-Intellect')

@section('meta')
    <meta name="description" content="{{ $active
        ? Str::limit($active->termWithDefinition(), 300)
        : 'Глоссарий X-Intellect: толкователь специфических терминов и понятий, посредством которых происходит диалог с Силами.' }}">

    {{-- В индекс идут только /glossary и /glossary?term=<slug>; состояние
         свободного поиска (?q=) — служебное, закрываем от роботов. --}}
    @if ($q !== '')
        <meta name="robots" content="noindex, follow">
    @endif
    <link rel="canonical" href="{{ $base }}{{ $active ? $active->url() : '/glossary' }}">

    {{-- FAQPage JSON-LD для глоссария (Этап 5 плана) --}}
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $terms->map(fn ($term) => [
            '@type' => 'Question',
            'name' => $term->term,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $term->definitionPlain()],
        ])->values()->all(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

    {{-- Адресуемый термин описывается отдельной сущностью DefinedTerm --}}
    @if ($active)
        <script type="application/ld+json">{!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'DefinedTerm',
            'name' => $active->term,
            'description' => $active->definitionPlain(),
            'url' => $base.$active->url(),
            'inDefinedTermSet' => [
                '@type' => 'DefinedTermSet',
                'name' => 'Глоссарий X-Intellect',
                'url' => $base.'/glossary',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endif
@endsection

@section('content')
    @include('site.partials.breadcrumbs', ['crumbs' => array_filter([
        'Главная' => '/',
        'Вики' => '/wiki',
        'Глоссарий' => $active ? '/glossary' : null,
        $active?->term => null,
    ], fn ($k) => $k !== '', ARRAY_FILTER_USE_KEY)])

    <h1 class="page-title">Глоссарий</h1>
    <p style="color: var(--xi-ink-soft); max-width: 740px; margin-bottom: 24px;">
        Толкователь специфических терминов и понятий, которые используются в материалах
        сайта и посредством которых происходит диалог с Силами, понимание информации,
        передаваемой ими для людей.
    </p>

    @php
        // Группировка по первой букве термина (термины уже отсортированы в контроллере)
        $groups = $terms->groupBy(fn ($t) => mb_strtoupper(mb_substr($t->term, 0, 1)));
        $letters = $groups->keys();
    @endphp

    @if ($terms->isEmpty())
        <p style="color: var(--xi-ink-faint);">Термины глоссария готовятся к публикации из архива вики.</p>
    @else
        <div x-data="glossaryFilter(@js(['q' => $q, 'term' => $active?->slug ?? '']))">
            {{-- Поиск по глоссарию --}}
            <div class="glossary-search">
                <svg class="glossary-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="search" x-model="q" placeholder="Поиск по глоссарию…" aria-label="Поиск по глоссарию">
                <button type="button" class="glossary-search-clear" x-show="q !== ''" x-cloak @click="q = ''" aria-label="Очистить">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>
                    </svg>
                </button>
            </div>

            {{-- Алфавитный указатель (при поиске выдача плоская — прячем) --}}
            <nav class="glossary-alphabet" aria-label="Алфавитный указатель" x-show="!searching()">
                @foreach ($letters as $letter)
                    <a href="#g-{{ $letter }}">{{ $letter }}</a>
                @endforeach
            </nav>

            {{-- Выдача поиска: сюда layout() переносит карточки плоским списком,
                 совпадения в названии термина — выше совпадений в определении --}}
            <div class="glossary-list" x-ref="results" x-show="searching()" x-cloak></div>

            {{-- Группы терминов по буквам --}}
            <div class="glossary-groups" x-show="!searching()">
                @foreach ($letters as $letter)
                    <section class="glossary-group" id="g-{{ $letter }}">
                        <h2 class="glossary-letter" aria-hidden="true">{{ $letter }}</h2>
                        <div class="glossary-list">
                            @foreach ($groups[$letter] as $term)
                                @php
                                    $termUrl = $base.$term->url();
                                    $termText = $term->termWithDefinition();
                                @endphp
                                <div class="xi-card glossary-item" id="{{ $term->slug }}"
                                     data-term="{{ mb_strtolower($term->term) }}"
                                     data-search="{{ mb_strtolower($term->term.' '.$term->definitionPlain()) }}"
                                     :class="{ 'is-active': active === @js($term->slug) }"
                                     x-show="cardVisible($el)">
                                    <h3 class="glossary-term-title">
                                        {{-- Настоящая ссылка: краулер находит по ней адрес термина,
                                             человеку меняем URL без перезагрузки списка. --}}
                                        <a href="{{ url($term->url()) }}"
                                           @click.prevent="openTerm(@js($term->slug))">{{ $term->term }}</a>
                                    </h3>
                                    <span class="glossary-term-rule" aria-hidden="true"></span>
                                    {{-- Разметка из редактора определения (абзацы, списки, ссылки) --}}
                                    <div class="glossary-def">{!! $term->definitionHtml() !!}</div>

                                    {{-- Подвал: «Подробнее» слева, копирование справа --}}
                                    <div class="glossary-actions">
                                        @if ($term->page && $term->page->isPublished())
                                            <a href="{{ url($term->page->url()) }}" class="glossary-more">Подробнее <span class="arr" aria-hidden="true">→</span></a>
                                        @endif

                                        <span class="glossary-copy">
                                            <span class="share-copied" x-show="copied.slug === @js($term->slug)" x-cloak
                                                  aria-live="polite" x-text="copied.msg"></span>

                                            <button type="button" class="share-btn"
                                                    title="Скопировать термин с определением"
                                                    aria-label="Скопировать термин с определением"
                                                    @click="copy(@js($termText), @js($term->slug), 'text', 'Термин скопирован')">
                                                <svg x-show="!isCopied(@js($term->slug), 'text')" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15 1H5a2 2 0 0 0-2 2v13h2V3h10V1z"/><path d="M19 5H9a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm0 16H9V7h10v14z"/></svg>
                                                <svg x-show="isCopied(@js($term->slug), 'text')" x-cloak viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.55 17.6 4 12.05l1.4-1.4 4.15 4.15L18.6 5.4 20 6.8z"/></svg>
                                            </button>

                                            <button type="button" class="share-btn"
                                                    title="Скопировать ссылку на термин"
                                                    aria-label="Скопировать ссылку на термин"
                                                    @click="copy(@js($termUrl), @js($term->slug), 'link', 'Ссылка скопирована')">
                                                <svg x-show="!isCopied(@js($term->slug), 'link')" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                                <svg x-show="isCopied(@js($term->slug), 'link')" x-cloak viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9.55 17.6 4 12.05l1.4-1.4 4.15 4.15L18.6 5.4 20 6.8z"/></svg>
                                            </button>
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>

            <p class="glossary-empty" x-show="q !== '' && !anyVisible()" x-cloak>
                По запросу «<span x-text="q"></span>» ничего не найдено.
            </p>
        </div>
    @endif

    @include('site.partials.feedback', ['url' => $base.($active ? $active->url() : '/glossary')])
@endsection

@push('scripts')
<script>
    function glossaryFilter(initial) {
        return {
            q: initial.q || '',
            active: initial.term || '',
            copied: { slug: '', kind: '', msg: '' },
            copyTimer: null,
            home: [],

            init() {
                // Родная позиция каждой карточки — чтобы вернуть алфавитный
                // порядок по буквам после очистки поиска (layout() ниже)
                this.home = [...this.$root.querySelectorAll('[data-search]')]
                    .map((el) => ({ el, parent: el.parentElement }));
                if (this.searching()) this.$nextTick(() => this.layout());

                // Пришли по /glossary?term=… (в т.ч. по 301 со старой вики) —
                // подсветить и подвести к карточке. Ждём window.load: до
                // догрузки шрифтов и картинок высоты плывут и карточка
                // уезжает из вида сразу после прокрутки.
                if (this.active) {
                    const reveal = () => this.$nextTick(() => this.scrollToTerm(this.active, 'auto'));
                    if (document.readyState === 'complete') reveal();
                    else window.addEventListener('load', reveal, { once: true });
                }

                // Набор в поиске уводит от конкретного термина: URL становится
                // ?q=… (replaceState — не засоряем историю на каждой букве).
                this.$watch('q', () => {
                    if (this.active) this.active = '';
                    this.syncUrl(true);
                    this.layout();
                });

                // «Назад»/«Вперёд» — восстановить состояние из адреса
                window.addEventListener('popstate', () => {
                    const params = new URLSearchParams(location.search);
                    this.active = params.get('term') || '';
                    this.q = params.get('q') || '';
                    if (this.active) this.$nextTick(() => this.scrollToTerm(this.active, 'smooth'));
                });
            },

            /** Клик по названию термина — свой адрес в строке, без перезагрузки.
                q намеренно не трогаем: иначе сработает watcher выше и сбросит
                active обратно (его колбэк выполняется после этой функции). */
            openTerm(slug) {
                this.active = slug;
                this.syncUrl(false);
                this.scrollToTerm(slug, 'smooth');
            },

            syncUrl(replace) {
                let url = '/glossary';
                if (this.active) {
                    url += '?term=' + encodeURIComponent(this.active);
                } else if (this.q.trim() !== '') {
                    url += '?q=' + encodeURIComponent(this.q.trim());
                }
                history[replace ? 'replaceState' : 'pushState']({}, '', url);
            },

            scrollToTerm(slug, behavior) {
                const card = document.getElementById(slug);
                if (card) card.scrollIntoView({ behavior, block: 'center' });
            },

            isCopied(slug, kind) {
                return this.copied.slug === slug && this.copied.kind === kind;
            },

            copy(text, slug, kind, msg) {
                const done = () => {
                    this.copied = { slug, kind, msg };
                    clearTimeout(this.copyTimer);
                    this.copyTimer = setTimeout(() => (this.copied = { slug: '', kind: '', msg: '' }), 1800);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(done).catch(() => this.fallback(text, done));
                } else {
                    this.fallback(text, done);
                }
            },

            fallback(text, done) {
                const ta = document.createElement('textarea');
                ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); done(); } catch (e) {} finally { ta.remove(); }
            },

            norm(s) { return (s || '').toLowerCase().trim(); },
            searching() { return this.norm(this.q) !== ''; },

            /** Выдача поиска ранжируется: совпадения в названии термина —
                выше совпадений только в определении. Карточки физически
                переносятся в плоский контейнер results (сортировка стабильная,
                внутри групп сохраняется алфавит); при очистке поиска
                возвращаются в свои буквенные группы. */
            layout() {
                if (!this.searching()) {
                    this.home.forEach(({ el, parent }) => parent.appendChild(el));
                    return;
                }
                const q = this.norm(this.q);
                const rank = (el) => ((el.dataset.term || '').includes(q) ? 0 : 1);
                this.home.map(({ el }) => el)
                    .sort((a, b) => rank(a) - rank(b))
                    .forEach((el) => this.$refs.results.appendChild(el));
            },

            cardVisible(el) {
                const q = this.norm(this.q);
                return q === '' || (el.dataset.search || '').includes(q);
            },
            anyVisible() {
                return [...this.$root.querySelectorAll('[data-search]')].some((c) => this.cardVisible(c));
            },
        };
    }
</script>
@endpush
