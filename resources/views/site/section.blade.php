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
                <div class="xi-card" style="padding: 18px 20px; position: sticky; top: 20px;">
                    <h2 class="section-title" style="margin-bottom: 10px;">Страницы вики</h2>
                    <nav style="display: grid; gap: 4px;">
                        <a href="{{ route('glossary') }}" style="color: var(--xi-accent); text-decoration: none; font-size: 14px; padding: 4px 0;">Глоссарий</a>
                        @foreach ($pages as $page)
                            <a href="{{ url($page->url()) }}" style="color: var(--xi-ink-soft); text-decoration: none; font-size: 14px; padding: 4px 0;">{{ $page->title }}</a>
                        @endforeach
                    </nav>
                </div>
            </aside>
            <div style="display: grid; gap: 14px; align-content: start;">
                @forelse ($pages as $page)
                    @include('site.partials.page-card', ['page' => $page])
                @empty
                    <p style="color: var(--xi-ink-faint);">Материалы раздела готовятся к публикации из архива.</p>
                @endforelse
                {{ $pages->links() }}
            </div>
        </div>
        <style>@media (max-width: 760px) { .wiki-layout { grid-template-columns: 1fr !important; } }</style>
    @else
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px;">
            @forelse ($pages as $page)
                @include('site.partials.page-card', ['page' => $page])
            @empty
                <p style="color: var(--xi-ink-faint);">Материалы раздела готовятся к публикации из архива.</p>
            @endforelse
        </div>
        <div style="margin-top: 20px;">{{ $pages->links() }}</div>
    @endif
@endsection
