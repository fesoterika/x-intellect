@extends('layouts.site')

@section('title', 'Архив форума (2012–2019) — X-Intellect')

@section('meta')
    <meta name="description" content="Архивная копия форума проекта X-Intellect за 2012–2019 годы: {{ $topicsCount }} тем и {{ $postsCount }} сообщений участников. Форум неактивен, материалы доступны только для чтения.">
    <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}/forum">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Архив форума X-Intellect">
    <meta property="og:description" content="Темы и сообщения форума проекта 2012–2015 годов. Только чтение.">
@endsection

@section('content')
    @include('site.partials.breadcrumbs', ['crumbs' => [
        'Главная' => '/',
        'Архив форума' => null,
    ]])

    <h1 class="page-title">Архив форума</h1>
    <hr class="title-rule" aria-hidden="true">
    <p class="forum-stats">
        {{ trans_choice('{1} :count тема|[2,4] :count темы|[5,*] :count тем', $topicsCount) }} ·
        {{ trans_choice('{1} :count сообщение|[2,4] :count сообщения|[5,*] :count сообщений', $postsCount) }} ·
        2012–2019 годы
    </p>

    @include('site.partials.forum-disclaimer')

    @foreach ($groups as $group => $forums)
        <section class="forum-group">
            <h2 class="section-title">{{ $group }}</h2>
            @foreach ($forums as $forumTitle => $topics)
                <div class="xi-card forum-block">
                    <h3 class="forum-block-title">
                        <span class="forum-block-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                            </svg>
                        </span>
                        <span class="forum-block-name">{{ $forumTitle }}</span>
                        <span class="forum-block-count">{{ trans_choice('{1} :count тема|[2,4] :count темы|[5,*] :count тем', $topics->count()) }}</span>
                    </h3>
                    <ul class="forum-topic-list">
                        @foreach ($topics as $topic)
                            <li>
                                <a class="forum-topic-link" href="{{ $topic->url() }}">{{ $topic->title }}</a>
                                <span class="forum-topic-meta">
                                    {{ trans_choice('{1} :count сообщение|[2,4] :count сообщения|[5,*] :count сообщений', $topic->posts_count) }}
                                    @if ($topic->started_at) · {{ $topic->started_at->format('d.m.Y') }} @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </section>
    @endforeach
@endsection
