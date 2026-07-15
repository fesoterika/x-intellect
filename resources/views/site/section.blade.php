@extends('layouts.site')

@section('title', $section->title.' - X-Intellect')

@section('meta')
    <meta name="description" content="{{ Str::limit($section->descriptionPlain() ?: 'Раздел «'.$section->title.'» архива проекта X-Intellect.', 158) }}">
    <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}{{ $section->url() }}">
@endsection

@section('content')
    @include('site.partials.breadcrumbs', ['crumbs' => [
        'Главная' => '/',
        ...($section->isRoot() ? [] : [$section->parent->title => $section->parent->url()]),
        $section->title => null,
    ]])

    <h1 class="page-title">{{ $section->title }}</h1>

    @if ($section->description)
        {{-- Панель «О разделе»: визуально отделена от заголовка и плиток.
             Длинное описание сворачивается до нескольких строк; «Подробнее»
             разворачивает. Без JS текст просто показан целиком. --}}
        <div class="section-desc-card" x-data="sectionDesc()" :class="{ 'is-collapsed': collapsed }">
            <span class="section-desc-label">О разделе</span>
            <div class="section-desc-body" x-ref="body" :style="bodyStyle">
                <div class="section-desc">{!! $section->descriptionHtml() !!}</div>
            </div>
            <button type="button" class="section-desc-toggle" x-show="collapsible" x-cloak
                    :aria-expanded="(!collapsed).toString()" @click="collapsed = !collapsed">
                <span x-text="collapsed ? 'Подробнее' : 'Свернуть'"></span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
        </div>
    @endif

    @if ($section->rootAncestor()->slug === 'wiki')
        {{-- Переосмысленная структура MediaWiki: боковая навигация по вики --}}
        <div style="display: grid; grid-template-columns: 240px 1fr; gap: 24px;" class="wiki-layout">
            <aside>
                <div class="xi-card" style="padding: 18px 20px; position: sticky; top: 90px;">
                    <h2 class="section-title" style="margin-bottom: 10px;">Страницы вики</h2>
                    <nav style="display: grid; gap: 4px;">
                        @forelse ($menuGroups ?? [] as $group)
                            @php $hasPages = $group->publishedPages->isNotEmpty(); @endphp
                            @if ($hasPages || $group->slug === 'obshhii-razdel')
                                <a href="{{ url($group->url()) }}" style="color: var(--xi-ink); text-decoration: none; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; padding: 10px 0 2px;">{{ $group->title }}</a>
                                @if ($group->slug === 'obshhii-razdel' && ! $group->publishedPages->contains('slug', 'pravila-vikipedii'))
                                    <a href="{{ route('glossary') }}" style="color: var(--xi-accent); text-decoration: none; font-size: 14px; padding: 4px 0;">Глоссарий</a>
                                @endif
                                @foreach ($group->publishedPages as $page)
                                    <a href="{{ url($page->url()) }}" style="color: var(--xi-ink-soft); text-decoration: none; font-size: 14px; padding: 4px 0;">{{ $page->title }}</a>
                                    {{-- Глоссарий — отдельная страница; в меню стоит после «Правил Википедии» --}}
                                    @if ($group->slug === 'obshhii-razdel' && $page->slug === 'pravila-vikipedii')
                                        <a href="{{ route('glossary') }}" style="color: var(--xi-accent); text-decoration: none; font-size: 14px; padding: 4px 0;">Глоссарий</a>
                                    @endif
                                @endforeach
                            @endif
                        @empty
                            <a href="{{ route('glossary') }}" style="color: var(--xi-accent); text-decoration: none; font-size: 14px; padding: 4px 0;">Глоссарий</a>
                        @endforelse
                    </nav>
                </div>
            </aside>
            <div x-data="sectionPager()">
                @include('site.partials.section-list', ['pages' => $pages, 'variant' => 'wiki'])
            </div>
        </div>
        <style>@media (max-width: 760px) { .wiki-layout { grid-template-columns: 1fr !important; } }</style>
    @else
        <div x-data="sectionPager()">
            @include('site.partials.section-list', ['pages' => $pages])
        </div>
    @endif
@endsection

@push('scripts')
<script>
    /* Сворачивание длинного описания раздела: короткий текст показывается
       целиком без кнопки; порог с запасом, чтобы не прятать пару строк */
    function sectionDesc() {
        const LIMIT = 112;  // высота свёрнутого описания, ~4 строки
        const SLACK = 44;   // сворачиваем, только если скрыто заметно больше

        return {
            collapsible: false,
            collapsed: false,

            init() {
                this.collapsible = this.$refs.body.scrollHeight > LIMIT + SLACK;
                this.collapsed = this.collapsible;
            },

            get bodyStyle() {
                if (!this.collapsible) return '';
                // Явный max-height в обе стороны — ради плавной анимации
                return 'max-height: ' + (this.collapsed ? LIMIT : this.$refs.body.scrollHeight) + 'px';
            },
        };
    }

    /* «Показать ещё»: дозагрузка следующей страницы раздела без перезагрузки.
       Без JS ссылка .load-more просто ведёт на ?page=N (полная страница). */
    function sectionPager() {
        return {
            loading: false,
            async next(link) {
                if (this.loading) return;
                this.loading = true;
                try {
                    const url = new URL(link.href, window.location.origin);
                    url.searchParams.set('partial', '1');

                    const res = await fetch(url, { headers: { 'X-Requested-With': 'fetch' } });
                    if (!res.ok) throw new Error('HTTP ' + res.status);

                    const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
                    const incoming = doc.querySelector('#section-items');
                    const current = this.$root.querySelector('#section-items');
                    if (incoming && current) {
                        current.append(...incoming.children);
                    }

                    // Обновляем кнопку на следующую страницу или убираем её
                    const nextLink = doc.querySelector('.load-more');
                    if (nextLink) {
                        link.setAttribute('href', nextLink.getAttribute('href'));
                    } else {
                        link.closest('.load-more-wrap')?.remove();
                    }
                } catch (e) {
                    // Мягкий откат: обычный переход на следующую страницу
                    window.location.href = link.href;
                } finally {
                    this.loading = false;
                }
            },
        };
    }
</script>
@endpush
