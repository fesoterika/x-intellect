@extends('layouts.site')

@section('title', $section->title.' — X-Intellect')

@section('meta')
    <meta name="description" content="{{ Str::limit($section->description ?: 'Раздел «'.$section->title.'» архива проекта X-Intellect.', 158) }}">
    <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}{{ $section->url() }}">
@endsection

@section('content')
    @include('site.partials.breadcrumbs', ['crumbs' => [
        'Главная' => '/',
        $section->title => null,
    ]])

    <h1 class="page-title">{{ $section->title }}</h1>

    @if ($section->description)
        <p style="color: var(--xi-ink-soft); max-width: 760px; margin-bottom: 26px;">{{ $section->description }}</p>
    @endif

    @if ($section->slug === 'wiki')
        {{-- Переосмысленная структура MediaWiki: боковая навигация по вики --}}
        <div style="display: grid; grid-template-columns: 240px 1fr; gap: 24px;" class="wiki-layout">
            <aside>
                <div class="xi-card" style="padding: 18px 20px; position: sticky; top: 90px;">
                    <h2 class="section-title" style="margin-bottom: 10px;">Страницы вики</h2>
                    <nav style="display: grid; gap: 4px;">
                        <a href="{{ route('glossary') }}" style="color: var(--xi-accent); text-decoration: none; font-size: 14px; padding: 4px 0;">Глоссарий</a>
                        @foreach ($pages as $page)
                            <a href="{{ url($page->url()) }}" style="color: var(--xi-ink-soft); text-decoration: none; font-size: 14px; padding: 4px 0;">{{ $page->title }}</a>
                        @endforeach
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
