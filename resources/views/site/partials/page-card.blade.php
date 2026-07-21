<a class="page-card epoch-{{ $page->source_type }}{{ $page->is_pinned ? ' is-pinned' : '' }}" href="{{ url($page->url()) }}">
    @if ($page->is_pinned)
        <span class="page-card-pin" title="Закреплённый материал">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Закреплённый материал">
                <path d="M12 17v5"/>
                <path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/>
            </svg>
        </span>
    @endif
    <div class="meta">
        <x-source-badge :page="$page" />
        @if ($page->archived_at)
            <span>из архива {{ $page->archived_at->format('Y') }} г.</span>
        @endif
        @if ($page->audio->count())
            <span class="meta-audio">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>
                аудио: {{ $page->audio->count() }}
            </span>
        @endif
    </div>
    <h3 style="margin-top: 10px;">{{ $page->title }}</h3>
    @if ($page->excerpt)
        <p>{{ Str::limit($page->excerpt, 140) }}</p>
    @endif
    <span class="page-card-more">Читать <span class="arr" aria-hidden="true">→</span></span>
</a>
