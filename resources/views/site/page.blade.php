@extends('layouts.site')

@section('title', $page->seoValue('meta_title', $page->title.' - X-Intellect'))

@section('meta')
    @include('site.partials.seo', ['page' => $page])
@endsection

@section('content')
    <article>
        @include('site.partials.breadcrumbs', ['crumbs' => [
            'Главная' => '/',
            $section->title => $section->url(),
            $page->title => null,
        ]])

        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px;">
            <x-source-badge :page="$page" />
            @if ($page->archived_at)
                <span style="font-size: 12.5px; color: var(--xi-ink-faint);">Материал из архива {{ $page->archived_at->format('Y') }} года</span>
            @endif
            @if ($page->source_url)
                <a href="{{ $page->source_url }}" target="_blank" rel="noopener noreferrer"
                   style="font-size: 12.5px; color: var(--xi-ink-soft);">архивная копия ↗</a>
            @endif
        </div>

        <h1 class="page-title">{{ $page->title }}</h1>
        <hr class="title-rule" aria-hidden="true">

        @if ($section->slug === 'courses')
            {{-- Предупреждение об ответственном использовании техник -
                 обязательно на страницах раздела курсов (см. план) --}}
            <div class="warning-block">
                <strong>Важно!</strong> Использование методик А. Глаза и его единомышленников
                во вред другим или не по назначению влечёт за собой ответственность.
                Материалы публикуются в архивных целях - применяйте знания осознанно
                и только с созидательными намерениями.
            </div>
        @endif

        <div class="xi-card xi-prose xi-prose--article" style="margin-top: 18px;">
            {!! $body !!}
        </div>

        @php
            $shortcodeUsed = str_contains((string) $page->body, '[[audio:');
            $playlist = $page->audio;
        @endphp

        @if ($playlist->isNotEmpty() && ! $shortcodeUsed)
            <section style="margin-top: 22px;">
                <h2 class="section-title">Аудиозаписи</h2>
                @include('site.partials.audio-player', ['tracks' => $playlist, 'playerId' => 'page-playlist'])
            </section>
        @endif

        @if ($page->revisions->isNotEmpty())
            <details class="xi-card" style="margin-top: 22px;">
                <summary style="cursor: pointer; color: var(--xi-ink-soft); font-weight: 600;">
                    История изменений ({{ $page->revisions->count() }})
                </summary>
                <div style="margin-top: 14px; display: grid; gap: 10px;">
                    @foreach ($page->revisions as $revision)
                        <div style="border-top: 1px solid var(--xi-line); padding-top: 10px; font-size: 14px;">
                            <div style="color: var(--xi-ink);">{{ $revision->title }}</div>
                            <div style="color: var(--xi-ink-faint); font-size: 12.5px;">
                                {{ $revision->sourceLabel() }}
                                @if ($revision->archived_at) · редакция {{ $revision->archived_at->format('Y') }} г. @endif
                                · сохранено {{ $revision->created_at->format('d.m.Y') }}
                                @if ($revision->note) · {{ $revision->note }} @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    </article>
@endsection
