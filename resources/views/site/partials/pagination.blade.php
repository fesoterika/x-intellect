{{-- Пагинация в стилистике сайта (тёмная тема, акцентный цвет) --}}
@if ($paginator->hasPages())
    <nav class="xi-pagination" role="navigation" aria-label="Постраничная навигация">
        @if ($paginator->onFirstPage())
            <span class="xi-page is-disabled" aria-hidden="true">‹</span>
        @else
            <a class="xi-page" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Предыдущая страница">‹</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="xi-page is-dots">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="xi-page is-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="xi-page" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="xi-page" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Следующая страница">›</a>
        @else
            <span class="xi-page is-disabled" aria-hidden="true">›</span>
        @endif
    </nav>
@endif
