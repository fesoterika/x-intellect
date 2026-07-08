@extends('layouts.site')

@section('title', 'Глоссарий — толкователь терминов проекта X-Intellect')

@section('meta')
    <meta name="description" content="Глоссарий X-Intellect: толкователь специфических терминов и понятий, посредством которых происходит диалог с Силами.">
    <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}/glossarij">
    {{-- FAQPage JSON-LD для глоссария (Этап 5 плана) --}}
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $terms->map(fn ($term) => [
            '@type' => 'Question',
            'name' => $term->term,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $term->definition],
        ])->values()->all(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endsection

@section('content')
    @include('site.partials.breadcrumbs', ['crumbs' => [
        'Главная' => '/',
        'Вики' => '/wiki',
        'Глоссарий' => null,
    ]])

    <h1 class="page-title">Глоссарий</h1>
    <p style="color: var(--xi-ink-soft); max-width: 740px; margin-bottom: 28px;">
        Толкователь специфических терминов и понятий, которые используются в материалах
        сайта и посредством которых происходит диалог с Силами, понимание информации,
        передаваемой ими для людей.
    </p>

    <div style="display: grid; gap: 12px;">
        @forelse ($terms as $term)
            <div class="xi-card" id="{{ $term->slug }}" style="padding: 20px 24px;">
                <h2 style="margin: 0 0 8px; font-size: 18px; color: var(--xi-accent);">{{ $term->term }}</h2>
                <p style="margin: 0; color: var(--xi-ink-soft);">{{ $term->definition }}</p>
                @if ($term->page && $term->page->isPublished())
                    <a href="{{ url($term->page->url()) }}" style="display: inline-block; margin-top: 10px; font-size: 13.5px; color: var(--xi-accent); text-decoration: none;">Подробнее →</a>
                @endif
            </div>
        @empty
            <p style="color: var(--xi-ink-faint);">Термины глоссария готовятся к публикации из архива вики.</p>
        @endforelse
    </div>
@endsection
