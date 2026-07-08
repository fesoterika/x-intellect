@props(['page'])

@php
    // Визуальные бейджи эпох контента (Этап 3 плана): читатель всегда
    // видит, из какого периода материал
    $classes = match ($page->source_type) {
        'archive_sferarazuma' => 'badge-sfera',
        'archive_xintellect' => 'badge-xintellect',
        default => 'badge-new',
    };
@endphp

<span {{ $attributes->merge(['class' => 'source-badge '.$classes]) }}>{{ $page->sourceLabel() }}</span>
