@extends('layouts.site')

@section('title', 'X-Intellect — архив проекта «Сфера Разума» / X-Интеллект')

@section('meta')
    <meta name="description" content="Восстановленный архив проекта X-Intellect (ранее — «Сфера Разума», основан А. Г. Глазом): вики, глоссарий, библиотека, аудиозаписи курсов, история проекта 1982–2017.">
    <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}/">
    <meta property="og:type" content="website">
    <meta property="og:title" content="X-Intellect — архив проекта">
    <meta property="og:description" content="Вики, библиотека, записи курсов и история проекта «Сфера Разума» / X-Интеллект.">
@endsection

@section('content')
    <section class="xi-card home-hero" style="padding: 40px 34px;">
        <h1 class="page-title">Информационный ресурс X-Intellect</h1>
        <p style="color: var(--xi-ink-soft); font-size: 17.5px; max-width: 720px;">
            Архив проекта <strong style="color: var(--xi-ink);">X-Интеллект</strong> (ранее — «Сфера Разума»),
            основанного в 2012 году Александром Георгиевичем Глазом. Материалы о сотрудничестве
            с представителями Внеземного Разума: исследования, статьи Посредников, обучающие
            программы и рекомендуемая литература.
        </p>
        <p style="color: var(--xi-ink-faint); font-style: italic; margin-top: 18px;">
            «Сомневайтесь во всем, но не отрицайте слепо» — В. Зорев, «За окраиной мира, бытия и сознания»
        </p>
    </section>

    <section style="margin-top: 36px;">
        <h2 class="section-title">Разделы архива</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px;">
            @foreach ($sections as $section)
                <a class="page-card" href="{{ $section->url() }}">
                    <h3>{{ $section->title }}</h3>
                    @if ($section->description)
                        <p>{{ Str::limit($section->description, 110) }}</p>
                    @endif
                    <p class="meta" style="margin-top: 10px;">{{ trans_choice('{0} нет материалов|{1} :count материал|[2,4] :count материала|[5,*] :count материалов', $section->published_pages_count) }}</p>
                </a>
            @endforeach
        </div>
    </section>

    @if ($latestPages->isNotEmpty())
        <section style="margin-top: 40px;">
            <h2 class="section-title">Последние материалы</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px;">
                @foreach ($latestPages as $page)
                    @include('site.partials.page-card', ['page' => $page])
                @endforeach
            </div>
        </section>
    @endif
@endsection
