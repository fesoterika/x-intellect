@extends('layouts.site')

@section('title', 'X-Intellect — архив проекта «Сфера Разума» / X-Интеллект')

@section('meta')
    <meta name="description" content="Восстановленный архив проекта X-Intellect (ранее — «Сфера Разума», основан А. Г. Глазом): вики, глоссарий, библиотека, аудиозаписи курсов, история проекта 1982–2017.">
    <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}/">
    <meta property="og:type" content="website">
    <meta property="og:title" content="X-Intellect — архив проекта">
    <meta property="og:description" content="Вики, библиотека, записи курсов и история проекта «Сфера Разума» / X-Интеллект.">
    <meta property="og:image" content="{{ asset('images/x-intellect_logo.webp') }}">
@endsection

@section('content')
    <section class="xi-card home-hero" style="padding: 40px 34px;">
        <h1 class="page-title">Информационный ресурс X-Intellect</h1>
        <p style="color: var(--xi-ink-soft); font-size: 16px; max-width: 720px;">
            Архив проекта <strong style="color: var(--xi-ink);">X-Интеллект</strong> (ранее — «Сфера Разума»),
            основанного в 2012 году Александром Георгиевичем Глазом. Материалы о сотрудничестве
            с представителями Внеземного Разума: исследования, статьи Посредников, обучающие
            программы и рекомендуемая литература.
        </p>
        <p style="color: var(--xi-ink-faint); font-style: italic; margin-top: 18px;">
            «Сомневайтесь во всем, но не отрицайте слепо» — В. Зорев, «За окраиной мира, бытия и сознания»
        </p>
    </section>

    {{-- Разделы — навигационные плитки (перейти в раздел), визуально
         отличаются от карточек материалов: акцентная полоса слева и стрелка --}}
    <section style="margin-top: 36px;">
        <div class="home-section-head">
            <h2 class="section-title" style="margin: 0;">Разделы архива</h2>
            <span class="home-section-hint">навигация по разделам проекта</span>
        </div>
        <div class="section-tiles">
            @foreach ($sections as $section)
                <a class="section-tile" href="{{ $section->url() }}">
                    <span class="section-tile-head">
                        <span class="section-tile-title">{{ $section->title }}</span>
                        <span class="section-tile-arrow" aria-hidden="true">→</span>
                    </span>
                    @if ($section->description)
                        <span class="section-tile-desc">{{ Str::limit($section->description, 100) }}</span>
                    @endif
                    <span class="section-tile-count">{{ trans_choice('{0} нет материалов|{1} :count материал|[2,4] :count материала|[5,*] :count материалов', $section->published_pages_count) }}</span>
                </a>
            @endforeach
        </div>
    </section>

    {{-- Материалы — карточки контента (открыть страницу) с бейджем эпохи --}}
    @if ($latestPages->isNotEmpty())
        <section style="margin-top: 44px;">
            <div class="home-section-head">
                <h2 class="section-title" style="margin: 0;">Последние материалы</h2>
                <span class="home-section-hint">свежие опубликованные страницы</span>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px;">
                @foreach ($latestPages as $page)
                    @include('site.partials.page-card', ['page' => $page])
                @endforeach
            </div>
        </section>
    @endif
@endsection
