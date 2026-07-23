@extends('layouts.site')

@section('title', $page->seoValue('meta_title', $page->title.' - X-Intellect'))

@section('meta')
    @include('site.partials.seo', ['page' => $page])
@endsection

@section('content')
    @php
        // Шорткод [[audio:...]] встраивает записи прямо в текст — тогда отдельной
        // секции «Аудиозаписи» внизу нет, и подсказка-якорь не нужна.
        $shortcodeUsed = str_contains((string) $page->body, '[[audio:');
        $playlist = $page->audio;
        $hasAudioSection = $playlist->isNotEmpty() && ! $shortcodeUsed;
    @endphp

    @php
        // Крошки строятся от фактического раздела страницы: для страницы
        // подраздела между корневым разделом и материалом есть своя крошка.
        // Скрытый раздел (is_visible=false) не имеет доступной страницы-листинга —
        // крошка остаётся текстом, без ссылки на 404.
        $crumbSection = $page->section ?? $section;
        $crumbRoot = $crumbSection->rootAncestor();
        $crumbs = ['Главная' => '/'];
        $crumbs[$crumbRoot->title] = $crumbRoot->is_visible ? $crumbRoot->url() : null;
        if (! $crumbSection->isRoot()) {
            $crumbs[$crumbSection->title] = ($crumbRoot->is_visible && $crumbSection->is_visible)
                ? $crumbSection->url()
                : null;
        }
        $crumbs[$page->title] = null;
    @endphp

    <article>
        @include('site.partials.breadcrumbs', ['crumbs' => $crumbs])

        <div class="page-meta">
            <x-source-badge :page="$page" />
            @if ($page->archived_at)
                <span style="color: var(--xi-ink-faint);">из архива {{ $page->archived_at->format('Y') }} г.</span>
            @endif
            @if ($page->source_url)
                <a href="{{ $page->source_url }}" target="_blank" rel="noopener noreferrer"
                   style="color: var(--xi-ink-soft);">архивная копия ↗</a>
            @endif
        </div>

        <div class="title-with-edit">
            <h1 class="page-title">{{ $page->title }}</h1>
            <x-edit-link :href="route('admin.pages.edit', $page)" label="Редактировать статью" />
        </div>

        {{-- Строка с линией-акцентом: пустое место справа от затухающей линии
             занимает подсказка-якорь к аудио — верх страницы не перегружается --}}
        <div class="title-rule-row">
            <hr class="title-rule" aria-hidden="true">
            @if ($hasAudioSection)
                <a class="audio-hint" href="#audio">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>
                    {{ trans_choice('{1} :count аудиозапись|[2,4] :count аудиозаписи|[5,*] :count аудиозаписей', $playlist->count()) }}
                    <span class="sep" aria-hidden="true">—</span>
                    <span class="tail">ниже текста <span class="arr" aria-hidden="true">↓</span></span>
                </a>
            @endif
        </div>

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

        @if ($hasAudioSection)
            <section id="audio" style="margin-top: 22px;">
                <h2 class="section-title">Аудиозаписи</h2>
                @include('site.partials.audio-player', ['tracks' => $playlist, 'playerId' => 'page-playlist', 'page' => $page])
            </section>
        @endif

        {{-- Приватный блок «Поделиться» — под материалом и аудиоплеером --}}
        @include('site.partials.share', [
            'url' => $page->seoValue('canonical', rtrim(config('app.url'), '/').$page->url()),
            'title' => $page->title,
        ])

        @if ($page->revisions->isNotEmpty())
            <details class="xi-card" style="margin-top: 22px;">
                <summary style="cursor: pointer; color: var(--xi-ink-soft); font-weight: 600;">
                    История изменений ({{ $page->revisions->count() }})
                </summary>
                <div style="margin-top: 14px; display: grid; gap: 10px;">
                    @foreach ($page->revisions as $revision)
                        <div style="border-top: 1px solid var(--xi-line); padding-top: 10px; font-size: 14px;">
                            <div style="color: var(--xi-ink);">{{ $revision->title }}</div>
                            @if ($revision->reason)
                                <div style="color: var(--xi-ink-soft); font-size: 13px;">{{ $revision->reason }}</div>
                            @endif
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

        {{-- Плашка обратной связи «Нашли ошибку?» --}}
        @include('site.partials.feedback', [
            'url' => $page->seoValue('canonical', rtrim(config('app.url'), '/').$page->url()),
        ])

        {{-- Дисклеймер материала: неброская приписка, редактируется в админке --}}
        @if ($page->disclaimer)
            <div class="page-disclaimer" role="note">{{ $page->disclaimer }}</div>
        @endif
    </article>

    @include('site.partials.scroll-top')
@endsection
