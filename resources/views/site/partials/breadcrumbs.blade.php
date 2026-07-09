@php
    // $crumbs - массив [label => url|null]; последний элемент - текущая страница
    $base = rtrim(config('app.url'), '/');
    $items = collect($crumbs);
@endphp

<nav class="breadcrumbs" aria-label="Хлебные крошки">
    @foreach ($items as $label => $url)
        @if ($url)
            <a href="{{ $url }}">{{ $label }}</a><span class="sep">→</span>
        @else
            <span>{{ $label }}</span>
        @endif
    @endforeach
</nav>

{{-- Хлебные крошки дублируются в JSON-LD BreadcrumbList (Этап 3/5 плана) --}}
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => $items->values()->map(fn ($url, $i) => [
        '@type' => 'ListItem',
        'position' => $i + 1,
        'name' => $items->keys()[$i],
    ] + ($url ? ['item' => $base.$url] : []))->all(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
