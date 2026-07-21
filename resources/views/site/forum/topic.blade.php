@extends('layouts.site')

@section('title', $topic->title.' — Архив форума X-Intellect')

@php
    $firstPost = $topic->posts->first();
    $desc = Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags($firstPost?->body ?? ''))), 155);
@endphp

@section('meta')
    <meta name="description" content="{{ $desc !== '' ? $desc : 'Тема архивного форума X-Intellect: '.$topic->title }}">
    <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}{{ $topic->url() }}">
    <meta property="og:type" content="article">
    <meta property="og:title" content="{{ $topic->title }} — Архив форума X-Intellect">
    {{-- SEO-разметка обсуждения: schema.org DiscussionForumPosting --}}
    <script type="application/ld+json">
    {!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'DiscussionForumPosting',
        'headline' => $topic->title,
        'url' => rtrim(config('app.url'), '/').$topic->url(),
        'author' => ['@type' => 'Person', 'name' => $firstPost?->author ?? 'Участник форума'],
        'datePublished' => $topic->started_at?->toIso8601String(),
        'dateModified' => $topic->last_posted_at?->toIso8601String(),
        'commentCount' => max(0, $topic->posts_count - 1),
        'isPartOf' => ['@type' => 'WebPage', 'name' => 'Архив форума X-Intellect', 'url' => rtrim(config('app.url'), '/').'/forum'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
    </script>
@endsection

@section('content')
    @include('site.partials.breadcrumbs', ['crumbs' => [
        'Главная' => '/',
        'Архив форума' => '/forum',
        $topic->title => null,
    ]])

    <div class="forum-topic-head">
        <span class="forum-badge">{{ $topic->forum_title }}</span>
        @if ($topic->started_at)
            <span class="forum-topic-dates">{{ $topic->started_at->format('d.m.Y') }}@if ($topic->last_posted_at && ! $topic->last_posted_at->isSameDay($topic->started_at)) — {{ $topic->last_posted_at->format('d.m.Y') }}@endif</span>
        @endif
    </div>

    <h1 class="page-title">{{ $topic->title }}</h1>
    <hr class="title-rule" aria-hidden="true">

    @include('site.partials.forum-disclaimer')

    <div class="forum-posts">
        @foreach ($posts as $post)
            <article class="xi-card forum-post" @if($post->old_id) id="p{{ $post->old_id }}" @endif>
                <header class="forum-post-head">
                    <span class="forum-post-author">{{ $post->author }}</span>
                    @if ($post->posted_at)
                        <time class="forum-post-date" datetime="{{ $post->posted_at->toIso8601String() }}">
                            {{ $post->posted_at->format('d.m.Y H:i') }}
                        </time>
                    @endif
                </header>
                <div class="xi-prose forum-post-body">{!! $post->body !!}</div>
            </article>
        @endforeach
    </div>

    @if ($showMedicalNote)
        @include('site.partials.forum-medical-note')
    @endif

    @if ($posts->hasPages())
        <div class="forum-pagination">{{ $posts->links('site.partials.pagination') }}</div>
    @endif

    @include('site.partials.scroll-top')
@endsection
