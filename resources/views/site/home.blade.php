@extends('layouts.site')

@section('title', 'X-Intellect - архив проекта «Сфера Разума» / X-Интеллект')

@section('meta')
    <meta name="description" content="Восстановленный архив проекта X-Intellect (ранее - «Сфера Разума», основан А. Г. Глазом): вики, глоссарий, библиотека, аудиозаписи курсов, история проекта 1982-2017.">
    <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}/">
    <meta property="og:type" content="website">
    <meta property="og:title" content="X-Intellect - архив проекта">
    <meta property="og:description" content="Вики, библиотека, записи курсов и история проекта «Сфера Разума» / X-Интеллект.">
    <meta property="og:image" content="{{ asset('images/x-intellect_logo.webp') }}">
@endsection

@section('content')
@php use App\Support\RussianText; @endphp
    <section class="xi-card home-hero" style="padding: 40px 34px;">
        {{-- Переключатель темы в углу блока: иконка солнца/луны по теме.
             Синхронизирован с переключателем в шапке через событие xi-theme. --}}
        <button type="button" class="hero-theme-toggle"
                x-data="{ theme: document.documentElement.getAttribute('data-theme') || 'light' }"
                @click="theme = (theme === 'dark' ? 'light' : 'dark');
                        document.documentElement.setAttribute('data-theme', theme);
                        try { localStorage.setItem('xi-theme', theme); } catch (e) {}
                        $dispatch('xi-theme', theme)"
                @xi-theme.window="theme = $event.detail"
                :aria-label="theme === 'dark' ? 'Включить светлую тему' : 'Включить тёмную тему'"
                :title="theme === 'dark' ? 'Светлая тема' : 'Тёмная тема'">
            <template x-if="theme === 'dark'">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
            </template>
            <template x-if="theme === 'light'">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
            </template>
        </button>
        <h1 class="page-title">Информационный ресурс X-Intellect</h1>
        <p style="color: var(--xi-ink-soft); font-size: 16px; max-width: 720px;">
            Архив проекта <strong style="color: var(--xi-ink);">X-Интеллект</strong> (ранее - «Сфера Разума»),
            основанного в 2012 году Александром Георгиевичем Глазом. Материалы о сотрудничестве
            с представителями Внеземного Разума: исследования, проекты, обучающие
            программы и рекомендуемая литература.
        </p>
        <p style="color: var(--xi-ink-faint); font-style: italic; margin-top: 18px;">
            «Все, чем ты владеешь в этом бренном мире, в день твоей смерти станет собственностью
            других.<br> И только то, чем являешься ты, навсегда останется при тебе» - Генри Ван Дайк
        </p>
    </section>

    {{-- Разделы - навигационные плитки (перейти в раздел), визуально
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
                    <span class="section-tile-rule" aria-hidden="true"></span>
                    @if ($section->description)
                        <span class="section-tile-desc">{{ Str::limit($section->descriptionPlain(), 100) }}</span>
                    @endif
                    {{-- Склонение через хелпер, а не trans_choice: явные диапазоны
                         ошибаются на 21-24, 31-34 («21 материалов») --}}
                    <span class="section-tile-count">
                        @if ($section->published_pages_count === 0)
                            нет материалов
                        @else
                            {{ $section->published_pages_count }} {{ RussianText::plural($section->published_pages_count, 'материал', 'материала', 'материалов') }}
                        @endif
                    </span>
                </a>
            @endforeach

            {{-- Архив форума - отдельная плитка (не раздел страниц, а слепок phpBB 2015 года) --}}
            @if (($forumTopicsCount ?? 0) > 0)
                <a class="section-tile" href="{{ route('forum.index') }}">
                    <span class="section-tile-head">
                        <span class="section-tile-title">Архив форума</span>
                        <span class="section-tile-arrow" aria-hidden="true">→</span>
                    </span>
                    <span class="section-tile-rule" aria-hidden="true"></span>
                    <span class="section-tile-desc">Темы и сообщения форума проекта 2012-2019 годов. Только чтение - архивная копия.</span>
                    <span class="section-tile-count">{{ $forumTopicsCount }} {{ RussianText::plural($forumTopicsCount, 'тема', 'темы', 'тем') }}</span>
                </a>
            @endif
        </div>
    </section>

    {{-- Архив в цифрах - счётчики проделанной работы (только опубликованное) --}}
    @include('site.partials.home-stats')

    {{-- Материалы - карточки контента (открыть страницу) с бейджем эпохи --}}
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
